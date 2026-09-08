<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Config;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\DiagnosticLabel;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/** Selects project PHP settings as data, independently of the compiler's host. */
final class ComposerPhpTargetResolver
{
    public function resolve(string $projectRoot, string $fallback, DiagnosticBag $diagnostics): ?string
    {
        $path = Path::join($projectRoot, 'composer.json');
        if (!file_exists($path) && !is_link($path)) {
            return $fallback;
        }
        $contents = !is_link($path) && is_file($path) && is_readable($path)
            ? @file_get_contents($path, length: 2_097_153) : false;
        if ($contents === false || strlen($contents) > 2_097_152) {
            $diagnostics->add(new Diagnostic(DiagnosticCode::InvalidComposerConfiguration,
                'The project composer.json must be a readable regular file no larger than 2 MiB, not a symbolic link.'));

            return null;
        }
        $source = new SourceFile($path, 'composer.json', FileKind::Configuration, $contents);
        try {
            $data = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            if (!$data instanceof \stdClass) {
                throw new \UnexpectedValueException('The root of composer.json must be an object.');
            }
            $config = property_exists($data, 'config') ? $data->config : new \stdClass();
            $platform = $config instanceof \stdClass
                ? (property_exists($config, 'platform') ? $config->platform : new \stdClass()) : null;
            $require = property_exists($data, 'require') ? $data->require : new \stdClass();
            if (!$platform instanceof \stdClass || !$require instanceof \stdClass) {
                throw new \UnexpectedValueException('Composer config, config.platform and require must be objects.');
            }
            $version = property_exists($platform, 'php') ? $platform->php : null;
            if (property_exists($platform, 'php') && (!is_string($version)
                || preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $version) !== 1)) {
                throw new \UnexpectedValueException('Composer config.platform.php must be an exact PHP version such as major.minor.patch.');
            }
            $target = is_string($version) ? implode('.', array_slice(explode('.', $version), 0, 2)) : $fallback;
            $constraint = $require->php ?? null;
            if (property_exists($require, 'php') && (!is_string($constraint) || trim($constraint) === '')) {
                throw new \UnexpectedValueException('Composer require.php must be a non-empty PHP version constraint.');
            }
            if (is_string($constraint)) {
                $parser = new VersionParser();
                try {
                    $required = $parser->parseConstraints($constraint);
                } catch (\UnexpectedValueException $error) {
                    throw new \UnexpectedValueException('Composer require.php is not a valid PHP version constraint.', previous: $error);
                }
                // An explicit platform is an exact runtime; a fallback target denotes
                // a minor syntax family, which may require a later maintenance release.
                $parts = explode('.', $target);
                $compatible = is_string($version)
                    ? Semver::satisfies($version, $constraint)
                    : (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])
                        && Intervals::haveIntersections($required, $parser->parseConstraints(sprintf(
                            '>=%s.0 <%s.%d.0', $target, $parts[0], (int) $parts[1] + 1,
                        ))));
                if (!$compatible) {
                    $this->addDiagnostic($diagnostics, $source, DiagnosticCode::ConflictingPhpTargetConfiguration, sprintf(
                        'The selected PHP target "%s" does not satisfy Composer require.php "%s".', is_string($version) ? $version : $target, $constraint,
                    ));

                    return null;
                }
            }
            if (is_string($version) && !in_array($target, PhpTarget::SUPPORTED, true)) {
                $this->addDiagnostic($diagnostics, $source, DiagnosticCode::UnsupportedTargetPhpVersion,
                    sprintf('Composer config.platform.php selects "%s", which this compiler does not support as a project target.', $version));

                return null;
            }

            return $target;
        } catch (\JsonException|\UnexpectedValueException $error) {
            $this->addDiagnostic($diagnostics, $source, DiagnosticCode::InvalidComposerConfiguration,
                $error instanceof \JsonException ? 'The project composer.json does not contain valid JSON.' : $error->getMessage());

            return null;
        }
    }

    private function addDiagnostic(DiagnosticBag $diagnostics, SourceFile $source, DiagnosticCode $code, string $message): void
    {
        $diagnostics->add(new Diagnostic($code, $message, new DiagnosticLabel($source->createSpan(0, 0), $message),
            help: $code === DiagnosticCode::UnsupportedTargetPhpVersion
                ? 'Use a compiler release that supports the PHP target selected by your project.'
                : 'Make composer.json PHP settings and the project target consistent with the runtime you intend to use.'));
    }
}
