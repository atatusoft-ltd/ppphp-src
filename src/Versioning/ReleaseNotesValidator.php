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

        // Check explicit current-release claims, not every mention of a channel:
        // migration history and future Stable plans remain legitimate prose.
        $prose = str_replace(['*', '_', '`'], '', $releaseNotes);
        $channel = '(stable|release(?:\s+|-)candidate|rc|development|pre-?release)';
        $subject = '(?:this(?:\s+(?:release(?:\s+candidate)?|version|build))?'
            . '|the\s+(?:current|latest)\s+(?:release|version|build)'
            . '|(?:\+\+PHP\s+)?' . preg_quote($version->canonical, '~') . ')';
        $patterns = [
            '~(?<![\w.-])' . $subject . '\s+is\s+(?:(?:now|a|an|the|our|first|next|new)\s+)*' . $channel . '\b~i',
            '~\b(?:this|the\s+current)\s+' . $channel . '\s+(?:release|version|build)\b~i',
            '~^\h*(?:[-+]\h*)?(?:release\h+)?(?:channel|status)\h*:\h*' . $channel . '\b~im',
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $prose, $matches);
            foreach ($matches[1] as $claim) {
                $normalized = strtolower(preg_replace('/[\s-]+/', '', $claim) ?? $claim);
                $compatible = match ($normalized) {
                    'stable' => $version->isStable,
                    'releasecandidate', 'rc' => $version->isReleaseCandidate,
                    'development' => $version->isDevelopment,
                    'prerelease' => $version->isPrerelease,
                    default => false,
                };
                if (!$compatible) {
                    $failures[] = sprintf('release notes claim the current release is %s, contradicting channel %s for %s',
                        $claim, $version->channel->value, $version->canonical);
                }
            }
        }

        return $failures;
    }
}
