#!/usr/bin/env python3
"""Verified source acquisition and native build receipts; no ambient source lookup."""
from __future__ import annotations

import argparse
import copy
import hashlib
import json
import os
import shutil
import subprocess
import tarfile
import tempfile
from pathlib import Path, PurePosixPath

HERE = Path(__file__).resolve().parent
MANIFEST = HERE / 'native-inputs.json'
MAX_ENTRIES = 50000
MAX_EXPANDED = 2 * 1024 ** 3


def digest(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def canonical(value: object) -> bytes:
    return (json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True) + '\n').encode()


def load_manifest() -> dict:
    data = json.loads(MANIFEST.read_text())
    if data['schemaVersion'] != 1 or data['productionReady'] is not False:
        raise ValueError('Unsupported native input contract')
    seen = set()
    for name, source in data['sources'].items():
        if not name.replace('-', '').isalnum() or not source['url'].startswith('https://'):
            raise ValueError('Unsafe source identity')
        if bool(source.get('sha256')) == bool(source.get('gitTree')):
            raise ValueError(f'{name}: require one archive or Git-tree identity')
        if not set(source.get('dependencies', [])).issubset(seen):
            raise ValueError(f'{name}: missing dependency or dependency cycle')
        seen.add(name)
    return data


def require_temporary(path: Path) -> Path:
    path = path.absolute()
    roots = [Path(tempfile.gettempdir()).resolve(), Path('/tmp').resolve()]
    parent = path.parent.resolve(strict=True)
    if not any(parent.is_relative_to(root) for root in roots) or path.is_symlink():
        raise ValueError('Use an OS temporary directory outside user workspaces')
    return parent / path.name


def archive_members(archive: tarfile.TarFile, strip_root: bool) -> dict[str, tarfile.TarInfo]:
    """Validate before extraction, including links and collisions after root removal."""
    result = {}
    root = None
    total = 0
    count = 0
    for item in archive:
        count += 1
        total += item.size
        if count > MAX_ENTRIES or total > MAX_EXPANDED or item.size > 256 * 1024 ** 2:
            raise ValueError('Source archive exceeds the extraction budget')
        parts = PurePosixPath(item.name).parts
        if (not parts or item.name.startswith('/') or '\\' in item.name
                or '..' in parts or any(ord(c) < 32 for c in item.name)):
            raise ValueError('Unsafe source archive path')
        if strip_root:
            root = root or parts[0]
            if parts[0] != root:
                raise ValueError('Source archive has multiple roots')
            parts = parts[1:]
        if not parts:
            if not item.isdir():
                raise ValueError('Source root is not a directory')
            continue
        name = '/'.join(parts)
        if name in result or not (item.isfile() or item.isdir() or item.issym() or item.islnk()):
            raise ValueError(f'Conflicting or unsupported archive member: {name}')
        result[name] = item
    # Check links lexically; tarfile's data filter separately checks resolved paths.
    for name, item in result.items():
        for parent in PurePosixPath(name).parents:
            previous = result.get(str(parent))
            if previous and not previous.isdir():
                raise ValueError('Archive member traverses a non-directory')
        if item.issym() or item.islnk():
            target = item.linkname
            if target.startswith('/') or '\\' in target or any(ord(c) < 32 for c in target):
                raise ValueError('Unsafe source archive link')
            base = list(PurePosixPath(name).parent.parts) if item.issym() else []
            target_parts = PurePosixPath(target).parts
            if item.islnk() and strip_root:
                if not target_parts or target_parts[0] != root:
                    raise ValueError('Hard link points outside source root')
                target_parts = target_parts[1:]
            for part in target_parts:
                if part == '..':
                    if not base:
                        raise ValueError('Source link escapes its root')
                    base.pop()
                elif part != '.':
                    base.append(part)
            # Some source trees link to files generated later. Internal dangling links
            # are safe as data; members may never be extracted through any link.
    return result


def git_tree(archive: tarfile.TarFile, members: dict[str, tarfile.TarInfo]) -> str:
    """Reconstruct Git's tree hash, including executable modes and symlink contents."""
    def obj(kind: str, body: bytes) -> bytes:
        return hashlib.sha1(kind.encode() + b' ' + str(len(body)).encode() + b'\0' + body).digest()
    tree = {}
    for name, item in members.items():
        if item.isdir():
            continue
        branch = tree
        parts = name.split('/')
        for part in parts[:-1]:
            branch = branch.setdefault(part, {})
        if item.issym():
            mode, body = '120000', item.linkname.encode()
        elif item.isfile():
            mode = '100755' if item.mode & 0o111 else '100644'
            body = archive.extractfile(item).read()
        else:
            raise ValueError('Git source trees cannot contain tar hard links')
        branch[parts[-1]] = (mode, obj('blob', body))
    def fold(branch: dict) -> bytes:
        entries = []
        for name, value in branch.items():
            directory = isinstance(value, dict)
            mode, identity = ('40000', fold(value)) if directory else value
            entries.append(((name + ('/' if directory else '')).encode(),
                            mode.encode() + b' ' + name.encode() + b'\0' + identity))
        return obj('tree', b''.join(body for _, body in sorted(entries)))
    return fold(tree).hex()


def verify_source(path: Path, source: dict) -> dict:
    size = path.stat().st_size
    if size > source.get('maxBytes', source.get('bytes', 0)):
        raise ValueError(f'{path.name}: oversized source')
    identity = digest(path)
    if source.get('sha256') and (identity != source['sha256'] or size != source['bytes']):
        raise ValueError(f'{path.name}: source archive checksum/size mismatch')
    with tarfile.open(path) as archive:
        members = archive_members(archive, source['stripRoot'])
        if source.get('gitTree') and git_tree(archive, members) != source['gitTree']:
            raise ValueError(f'{path.name}: immutable Git tree mismatch')
    return {'sha256': identity, 'bytes': size}


def acquire(destination: Path, manifest: dict) -> None:
    destination = require_temporary(destination)
    destination.mkdir(exist_ok=True)
    for name, source in manifest['sources'].items():
        path = destination / (name + ('.tar.xz' if source['url'].endswith('.xz') else '.tar.gz'))
        if not path.exists():
            partial = destination / (path.name + '.partial')
            # Never reuse a partial download, symlink or unrelated cache entry.
            with partial.open('xb'):
                pass
            try:
                subprocess.run(['curl', '--fail', '--location', '--proto', '=https', '--proto-redir', '=https',
                                '--silent', '--show-error', '--connect-timeout', '15', '--max-time', '600',
                                '--max-filesize', str(source.get('maxBytes', source.get('bytes'))),
                                source['url'], '--output', str(partial)], check=True, timeout=620)
                verify_source(partial, source)
                partial.rename(path)
            finally:
                partial.unlink(missing_ok=True)
        if path.is_symlink():
            raise ValueError('Source store entry must not be a symlink')
        print(json.dumps({'source': name, **verify_source(path, source)}), flush=True)


def extract_source(path: Path, destination: Path, source: dict, epoch: int) -> None:
    verify_source(path, source)
    if destination.exists() or destination.is_symlink():
        raise ValueError('Never extract over an existing tree')
    with tarfile.open(path) as archive:
        archive_members(archive, source['stripRoot'])
        transformed = []
        for item in archive.getmembers():
            item = copy.copy(item)
            item.mtime = epoch
            item.uid = item.gid = 0
            item.uname = item.gname = ''
            item.mode = (0o755 if item.isdir() or item.mode & 0o111 else 0o644)
            transformed.append(item)
        with tempfile.TemporaryDirectory(prefix='source-extraction-', dir=destination.parent) as temporary:
            extracted = Path(temporary) / 'tree'
            extracted.mkdir()
            archive.extractall(extracted, members=transformed, filter='data')
            if source['stripRoot']:
                extracted = extracted / PurePosixPath(transformed[0].name).parts[0]
            extracted.rename(destination)


def inventory(root: Path) -> list[dict]:
    files = []
    for path in sorted(root.rglob('*')):
        name = path.relative_to(root).as_posix()
        if path.is_symlink():
            if not path.resolve(strict=True).is_relative_to(root.resolve()):
                raise ValueError(f'Installed link escapes its dependency prefix: {name}')
            files.append({'path': name, 'link': os.readlink(path)})
        elif path.is_file():
            files.append({'path': name, 'bytes': path.stat().st_size, 'sha256': digest(path)})
    return files


def verify_wasm_archive(path: Path) -> int:
    """Reject native objects, thin archives and unidentified bitcode; inspect every member."""
    data = path.read_bytes()
    if data[:8] != b'!<arch>\n':
        raise ValueError(f'{path}: not a self-contained static archive')
    offset, objects = 8, 0
    while offset < len(data):
        header = data[offset:offset + 60]
        if len(header) != 60 or header[58:] != b'`\n':
            raise ValueError('Malformed static archive header')
        size = int(header[48:58])
        body = data[offset + 60:offset + 60 + size]
        if len(body) != size or size < 0:
            raise ValueError('Truncated static archive member')
        name = header[:16].decode().strip()
        if name not in ('/', '//', '/SYM64/'):
            if name.startswith('#1/'):
                body = body[int(name[3:]):]
            if not body.startswith(b'\0asm\x01\0\0\0'):
                raise ValueError(f'{path}: non-WASM target object {name}')
            objects += 1
        offset += 60 + size + size % 2
    if objects == 0 or offset != len(data):
        raise ValueError('Empty or malformed target archive')
    return objects


def assemble(prefixes: list[Path], destination: Path) -> None:
    """No last-writer wins, including equal bytes from different producers."""
    ownership = {}
    for prefix in prefixes:
        for item in inventory(prefix):
            name = item['path']
            if name in ownership:
                raise ValueError(f'Dependency installation collision: {name}')
            ownership[name] = prefix
    destination.mkdir()
    for name, prefix in sorted(ownership.items()):
        target = destination / name
        target.parent.mkdir(parents=True, exist_ok=True)
        source = prefix / name
        if source.is_symlink():
            target.symlink_to(os.readlink(source))
        else:
            shutil.copy2(source, target)


def main() -> None:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='command', required=True)
    fetch = sub.add_parser('acquire')
    fetch.add_argument('--store', type=Path, required=True)
    check = sub.add_parser('verify')
    check.add_argument('--store', type=Path, required=True)
    args = parser.parse_args()
    manifest = load_manifest()
    if args.command == 'acquire':
        acquire(args.store, manifest)
    else:
        for name, source in manifest['sources'].items():
            suffix = '.tar.xz' if source['url'].endswith('.xz') else '.tar.gz'
            print(json.dumps({'source': name, **verify_source(args.store / (name + suffix), source)}))


if __name__ == '__main__':
    main()
