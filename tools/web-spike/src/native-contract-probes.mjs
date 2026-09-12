import manifest from '../../php-wasm-runtime/native-inputs.json' with { type: 'json' };

const check = 'function check(bool $ok): void { if (!$ok) { throw new RuntimeException("Native contract failed"); } }';
const probe = (id, code) => ({ id, code: '<?php ' + check + '\n' + code + '\necho "ok";', stdout: 'ok', exitCode: 0 });

// Finite test inputs, run in disposable workers. These do not change production
// capabilities, production entropy, guest network policy or error handling.
export const nativeContractProbes = [
  probe('native-platform', `
    check(PHP_VERSION === ${JSON.stringify(manifest.sources['php-src'].version)});
    check(PHP_INT_SIZE === 8 && PHP_INT_MAX === 9223372036854775807);
    check(intdiv(PHP_INT_MAX, 3) === 3074457345618258602);
    foreach (['openssl', 'zlib', 'zip', 'iconv', 'mbstring', 'libxml', 'dom', 'soap',
              'sqlite3', 'pdo_sqlite', 'gd', 'Phar', 'fileinfo', 'exif', 'Zend OPcache'] as $extension) {
      check(extension_loaded($extension));
    }
    check(str_starts_with(OPENSSL_VERSION_TEXT, ${JSON.stringify('OpenSSL ' + manifest.sources.openssl.version + ' ')}));
    check(LIBXML_DOTTED_VERSION === ${JSON.stringify(manifest.sources.libxml2.version)});
    check(SQLite3::version()['versionString'] === ${JSON.stringify(manifest.sources.sqlite.version)});
  `),
  probe('native-mbregex', `
    mb_regex_encoding('UTF-8');
    check(mb_ereg('世(界)', '你好世界', $matches));
    check($matches[1] === '界');
    check(mb_ereg_replace('世界', '朋友', '你好世界') === '你好朋友');
    check(@mb_ereg('[', 'text') === false);
    check(mb_convert_encoding(mb_convert_encoding('Café 世界', 'UTF-16LE', 'UTF-8'), 'UTF-8', 'UTF-16LE') === 'Café 世界');
    mb_regex_set_options('r');
    check(mb_ereg('^a+$', str_repeat('a', 4096)));
  `),
  probe('native-crypto-symmetric', `
    $key = random_bytes(32); $iv = random_bytes(12); $tag = '';
    check(strlen($key) === 32 && random_bytes(32) !== $key);
    $encrypted = openssl_encrypt('private local data', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'context');
    check(is_string($encrypted) && strlen($tag) === 16);
    check(openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'context') === 'private local data');
    check(openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'wrong') === false);
    check(!in_array('rc4', openssl_get_cipher_methods(), true));
    check(@openssl_pkey_get_public('not a key') === false);
  `),
  probe('native-crypto-keys-certificates', `
    file_put_contents('/workspace/openssl.cnf', "[req]\\ndistinguished_name=dn\\n[dn]\\n");
    $options = ['config' => '/workspace/openssl.cnf', 'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options); check($key !== false);
    check(openssl_sign('signed locally', $signature, $key, OPENSSL_ALGO_SHA256));
    $public = openssl_pkey_get_details($key)['key'];
    check(openssl_verify('signed locally', $signature, $public, OPENSSL_ALGO_SHA256) === 1);
    check(openssl_verify('changed', $signature, $public, OPENSSL_ALGO_SHA256) === 0);
    $csr = openssl_csr_new(['commonName' => 'example.invalid'], $key, $options); check($csr !== false);
    $cert = openssl_csr_sign($csr, null, $key, 1, $options); check($cert !== false);
    check(openssl_x509_parse($cert)['subject']['CN'] === 'example.invalid');
    check(@openssl_x509_read('not a certificate') === false);
  `),
  probe('native-compression-archives', `
    $data = str_repeat('compressed source ', 100);
    check(gzdecode(gzencode($data)) === $data);
    check(@gzdecode('invalid compressed input') === false);
    $zip = new ZipArchive(); check($zip->open('/workspace/source.zip', ZipArchive::CREATE) === true);
    check($zip->addFromString('source.txt', $data)); check($zip->close());
    check($zip->open('/workspace/source.zip') === true); check($zip->getFromName('source.txt') === $data); $zip->close();
    file_put_contents('/workspace/invalid.zip', 'invalid');
    check($zip->open('/workspace/invalid.zip') !== true);
    $archive = new PharData('/workspace/source.tar'); $archive['source.txt'] = $data;
    $archive->compress(Phar::GZ);
    check((new PharData('/workspace/source.tar.gz'))['source.txt']->getContent() === $data);
  `),
  // Upstream GHSA-x692-q9x7-8c3f regression (reported by Recep Asan),
  // plus both signs and a small arena allocation around the same truncation.
  probe('native-bcmath-truncated-fraction', `
    foreach ([1, 32, 300] as $zeros) {
      $number = '1.9' . str_repeat('0', $zeros) . '1';
      check(bccomp($number, '0', $zeros) === 1);
      check(bccomp('-' . $number, '0', $zeros) === -1);
      check(bccomp($number, '1.9', $zeros) === 0);
    }
  `),
  probe('native-phar-link-cycles', `
    function linkHeader(string $name, string $target): string {
      $header = str_pad($name, 100, "\\0") . "0000777\\0" . str_repeat("0000000\\0", 2)
        . str_repeat("00000000000\\0", 2) . '        ' . '2' . str_pad($target, 100, "\\0")
        . "ustar\\0" . '00' . str_repeat("\\0", 247);
      check(strlen($header) === 512);
      return substr_replace($header, sprintf("%06o\\0 ", array_sum(unpack('C*', $header))), 148, 8);
    }
    foreach ([[2, 0], [20, 10], [400, 0]] as [$count, $back]) {
      $tar = '';
      for ($index = 0; $index < $count; $index++) {
        $tar .= linkHeader('link_' . $index, 'link_' . ($index + 1 === $count ? $back : $index + 1));
      }
      $path = '/workspace/cycle-' . $count . '.tar';
      file_put_contents($path, $tar . str_repeat("\\0", 1024));
      $phar = new PharData($path);
      check($phar['link_0']->getContent() === '');
    }
  `),
  probe('native-xml-sqlite-iconv', `
    $doc = new DOMDocument(); check($doc->loadXML('<root><item>世界</item></root>'));
    check($doc->getElementsByTagName('item')->item(0)->textContent === '世界');
    libxml_use_internal_errors(true); check(!$doc->loadXML('<broken>')); libxml_clear_errors();
    $db = new SQLite3(':memory:');
    check($db->exec('CREATE VIRTUAL TABLE docs USING fts5(body)'));
    check($db->exec("INSERT INTO docs VALUES ('source pinned runtime')"));
    check($db->querySingle("SELECT count(*) FROM docs WHERE docs MATCH 'runtime'") === 1);
    $pdo = new PDO('sqlite::memory:'); check($pdo->query('SELECT 42')->fetchColumn() === 42);
    check(iconv('UTF-16LE', 'UTF-8', iconv('UTF-8', 'UTF-16LE', 'Café 世界')) === 'Café 世界');
    check(@iconv('UTF-8', 'UTF-16LE', "\\xff") === false);
  `),
  ...['png', 'jpeg', 'webp', 'avif', 'gif'].map(format => probe('native-image-' + format, `
    $image = imagecreatetruecolor(4, 4); check($image !== false);
    $red = imagecolorallocate($image, 255, 0, 0); imagefilledrectangle($image, 0, 0, 3, 3, $red);
    check(image${format}($image, '/workspace/image.${format}'));
    $decoded = imagecreatefrom${format}('/workspace/image.${format}'); check($decoded !== false);
    check(imagesx($decoded) === 4 && imagesy($decoded) === 4);
    check(@imagecreatefromstring('not an image') === false);
  `)),
  { id: 'native-entropy-failure', entropyDenied: true, code: '<?php echo bin2hex(random_bytes(32));' },
  { id: 'native-lint-valid', cliLint: true, code: '<?php file_put_contents(__DIR__ . "/side-effect", "executed");', exitCode: 0 },
  { id: 'native-lint-invalid', cliLint: true, code: '<?php function broken( {', exitCode: 255 },
];

export function assessNativeContract(data) {
  if (data?.suite !== 'native-contract' || data.done !== true || !Array.isArray(data.cases)
      || data.cases.length !== nativeContractProbes.length || new Set(data.cases.map(item => item.id)).size !== nativeContractProbes.length) return false;
  return nativeContractProbes.every(probe => {
    const result = data.cases.find(item => item.id === probe.id);
    if (!result || result.computeStarted !== true || result.semantics !== 'PASS') return false;
    if (probe.entropyDenied) return result.kind === 'trap' && result.entropyReads > 0 && /BP-7R entropy unavailable/.test(result.error);
    if (probe.cliLint) return result.kind === 'completed' && result.exitCode === probe.exitCode && result.sideEffect === false;
    return result.kind === 'completed' && result.exitCode === 0 && result.stdout === 'ok' && result.stderr === '';
  });
}
