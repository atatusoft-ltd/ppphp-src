// This module runs in both the package verifier and the actual browser worker.
// Only the documented regular-file USTAR subset is accepted; extensions are
// rejected rather than delegated to a second parser with different semantics.
export const archiveLimits = Object.freeze({ compressed: 20 * 1048576, expanded: 96 * 1048576, files: 8000, file: 32 * 1048576, path: 240 });
export const productionPackages = Object.freeze([
  'composer/semver', 'nikic/php-parser', 'phpstan/phpdoc-parser', 'phpstan/phpstan', 'psr/container',
  'symfony/console', 'symfony/deprecation-contracts', 'symfony/polyfill-ctype', 'symfony/polyfill-intl-grapheme',
  'symfony/polyfill-intl-normalizer', 'symfony/polyfill-mbstring', 'symfony/polyfill-php85', 'symfony/process',
  'symfony/service-contracts', 'symfony/string',
]);
const composerFiles = new Set(['autoload_classmap.php', 'autoload_files.php', 'autoload_namespaces.php', 'autoload_psr4.php',
  'autoload_real.php', 'autoload_static.php', 'ClassLoader.php', 'installed.json', 'installed.php', 'InstalledVersions.php', 'LICENSE', 'platform_check.php']);
export function compilerMember(path) {
  if (path.split('/').some(part => part.startsWith('.'))) return false;
  // PHPStan's optional native accelerator and Symfony's Windows prompt helper
  // cannot run in WASM. The two contract test classes are development inputs.
  // Keep the PHPStan PHAR and ordinary fallback analysis path unchanged.
  if (path.startsWith('vendor/phpstan/phpstan/turbo-ext/')
    || path.startsWith('vendor/symfony/service-contracts/Test/')
    || path === 'vendor/symfony/console/Resources/bin/hiddeninput.exe') return false;
  if (['bin/ppphp', 'composer.json', 'composer.lock', 'LICENSE.txt', 'NOTICE.txt', 'vendor/autoload.php'].includes(path)) return true;
  if (path.startsWith('src/') && path.endsWith('.php')) return true;
  if (path.startsWith('resources/')) return true;
  if (path.startsWith('vendor/composer/') && composerFiles.has(path.slice(16))) return true;
  return productionPackages.some(name => path.startsWith('vendor/' + name + '/'));
}
export function archivePath(path, maximum = archiveLimits.path) {
  if (typeof path !== 'string' || path.length > maximum || !/^[A-Za-z0-9_@.+/-]+$/.test(path)
    || path.split('/').some(part => !part || part === '.' || part === '..')) throw new Error('Unsafe archive path');
  return path;
}
function field(bytes, start, length) {
  const value = bytes.subarray(start, start + length), end = value.indexOf(0);
  if (end >= 0 && value.subarray(end).some(x => x !== 0)) throw new Error('Ambiguous archive field');
  const content = end < 0 ? value : value.subarray(0, end);
  if (content.some(x => x > 127)) throw new Error('Unsupported archive text');
  return String.fromCharCode(...content);
}
function octal(bytes, start, length) {
  const raw = bytes.subarray(start, start + length);
  if (raw.some(x => x !== 0 && x !== 32 && (x < 48 || x > 55))) throw new Error('Unsupported archive number');
  const text = String.fromCharCode(...raw).replace(/\0/g, ' ').trim();
  if (!/^[0-7]+$/.test(text)) throw new Error('Unsupported archive number');
  const value = Number.parseInt(text, 8);
  if (!Number.isSafeInteger(value)) throw new Error('Oversized archive number');
  return value;
}
export function inspectTar(bytes, { limits = archiveLimits, accept = compilerMember } = {}) {
  if (!(bytes instanceof Uint8Array) || bytes.length > limits.expanded || bytes.length % 512 !== 0) throw new Error('Archive expanded size');
  const members = [], paths = new Set(); let at = 0, dataBytes = 0;
  while (at + 512 <= bytes.length) {
    const header = bytes.subarray(at, at + 512);
    if (header.every(x => x === 0)) {
      if (at + 1024 > bytes.length || bytes.subarray(at).some(x => x !== 0)) throw new Error('Invalid archive end');
      if (!members.length) throw new Error('Empty archive');
      return { members, expandedBytes: bytes.length, dataBytes };
    }
    if (members.length >= limits.files) throw new Error('Archive member count');
    const expected = octal(header, 148, 8);
    const actual = header.reduce((sum, x, i) => sum + (i >= 148 && i < 156 ? 32 : x), 0);
    if (actual !== expected || field(header, 257, 6) !== 'ustar' || field(header, 263, 2) !== '00') throw new Error('Invalid USTAR header');
    if (header[156] !== 48 || field(header, 157, 100) !== '') throw new Error('Unsupported archive member kind');
    const prefix = field(header, 345, 155), name = field(header, 0, 100);
    const path = archivePath((prefix ? prefix + '/' : '') + name, limits.path);
    if (!accept(path) || paths.has(path)) throw new Error('Forbidden or duplicate archive member: ' + path);
    const parts = path.split('/');
    for (let i = 1; i < parts.length; i++) if (paths.has(parts.slice(0, i).join('/'))) throw new Error('Archive file/directory collision');
    if (members.some(member => member.path.startsWith(path + '/'))) throw new Error('Archive directory/file collision');
    const mode = octal(header, 100, 8), size = octal(header, 124, 12);
    if (![420, 493].includes(mode) || (accept === compilerMember && mode === 493 && path !== 'bin/ppphp')) throw new Error('Unsafe archive mode');
    if (octal(header, 108, 8) !== 0 || octal(header, 116, 8) !== 0 || octal(header, 329, 8) !== 0 || octal(header, 337, 8) !== 0) throw new Error('Unsupported archive ownership/device');
    if (size > limits.file || (dataBytes += size) > limits.expanded) throw new Error('Archive file size');
    const offset = at + 512, end = offset + Math.ceil(size / 512) * 512;
    if (end > bytes.length || bytes.subarray(offset + size, end).some(x => x !== 0)) throw new Error('Truncated or noncanonical archive member');
    paths.add(path); members.push({ path, bytes: size, mode, offset }); at = end;
  }
  throw new Error('Missing archive end');
}
export async function inspectCompilerArchive(compressed) {
  if (!(compressed instanceof Uint8Array) || !compressed.length || compressed.length > archiveLimits.compressed) throw new Error('Archive compressed size');
  const stream = new Blob([compressed]).stream().pipeThrough(new DecompressionStream('gzip'));
  const reader = stream.getReader(), chunks = []; let size = 0;
  try {
    for (;;) {
      const { done, value } = await reader.read(); if (done) break;
      if ((size += value.length) > archiveLimits.expanded || chunks.length >= 16384) throw new Error('Archive expansion limit');
      chunks.push(value);
    }
  } finally { await reader.cancel().catch(() => {}); reader.releaseLock(); }
  const bytes = new Uint8Array(size); let offset = 0;
  for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
  const result = inspectTar(bytes), names = new Set(result.members.map(m => m.path));
  for (const required of ['bin/ppphp', 'composer.json', 'composer.lock', 'src/Compiler/Compiler.php', 'vendor/autoload.php',
    'vendor/composer/installed.json', 'vendor/phpstan/phpstan/phpstan.phar', 'resources/phpstan/ppphp.neon']) {
    if (!names.has(required)) throw new Error('Incomplete compiler archive: ' + required);
  }
  return result;
}
