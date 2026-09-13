import { gzipSync } from 'node:zlib';
import { archivePath, inspectTar } from '../src/compiler-archive.mjs';

/** Canonical USTAR: sorted regular files, no extensions, uid/gid/mtime zero. */
export function createTar(files, limits) {
  const chunks = [], sorted = [...files].sort((a, b) => a.path < b.path ? -1 : a.path > b.path ? 1 : 0);
  for (const file of sorted) {
    archivePath(file.path);
    const header = Buffer.alloc(512), content = Buffer.from(file.content);
    let name = file.path, prefix = '';
    if (Buffer.byteLength(name) > 100) {
      const split = name.lastIndexOf('/', 155); prefix = name.slice(0, split); name = name.slice(split + 1);
      if (split < 1 || Buffer.byteLength(prefix) > 155 || Buffer.byteLength(name) > 100) throw new Error('USTAR path length');
    }
    const octal = (start, length, value) => {
      const number = value.toString(8); if (number.length >= length) throw new Error('USTAR numeric bound');
      header.write(number.padStart(length - 1, '0') + '\0', start, length, 'ascii');
    };
    header.write(name, 0, 100); octal(100, 8, file.mode ?? 420); octal(108, 8, 0); octal(116, 8, 0);
    octal(124, 12, content.length); octal(136, 12, 0); header.fill(32, 148, 156); header[156] = 48;
    header.write('ustar\0', 257, 6); header.write('00', 263, 2); octal(329, 8, 0); octal(337, 8, 0); header.write(prefix, 345, 155);
    const sum = header.reduce((a, b) => a + b, 0); header.write(sum.toString(8).padStart(6, '0') + '\0 ', 148, 8);
    chunks.push(header, content, Buffer.alloc((512 - content.length % 512) % 512));
  }
  chunks.push(Buffer.alloc(1024));
  const tar = Buffer.concat(chunks);
  inspectTar(tar, { accept: () => true, ...(limits ? { limits } : {}) });
  return tar;
}
export function createGzip(files, limits) {
  const bytes = gzipSync(createTar(files, limits), { level: 9 });
  // Fixed gzip OS field. Node/zlib emits mtime=0 and no filename/comment.
  bytes[9] = 255;
  return bytes;
}
