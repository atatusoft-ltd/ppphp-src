<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

final readonly class ReleaseNotesValidator
{
    /** @return list<string> */
    public function validate(string $releaseNotes, ReleaseVersion $version): array
    {
        $failures = (new DocumentationPolicy())->validatePublic('RELEASE_NOTES.md', $releaseNotes);

        if (!str_starts_with($releaseNotes, '# ++PHP ' . $version->canonical . "\n")) {
            $failures[] = 'release notes must have the selected release identity in their title';
        }

        if (trim(substr($releaseNotes, (int) strpos($releaseNotes, "\n"))) === '') {
            $failures[] = 'release notes must contain authored change information';
        }

        if (preg_match('/\{\{[^}]*\}\}|<(?:release-tag|release-version|version)>/', $releaseNotes) === 1) {
            $failures[] = 'release notes contain unresolved generated substitutions';
        }

        return $failures;
    }
}
