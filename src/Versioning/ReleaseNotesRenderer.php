<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

final readonly class ReleaseNotesRenderer
{
    public function render(string $authoredNotes, ReleaseMetadata $metadata): string
    {
        $failures = (new ReleaseNotesValidator())->validate($authoredNotes, $metadata->version);

        if ($failures !== []) {
            throw new \UnexpectedValueException(implode('; ', $failures));
        }

        $documentation = 'https://github.com/atatusoft-ltd/ppphp-src/blob/' . $metadata->tag;
        $channel = match ($metadata->channel) {
            Enumerations\ReleaseChannel::Stable => 'Stable',
            Enumerations\ReleaseChannel::ReleaseCandidate => 'Release Candidate',
            Enumerations\ReleaseChannel::Development => 'Development',
        };

        return rtrim($authoredNotes) . sprintf(
            "\n\n## Install This Release\n\nChannel: %s. Select this exact version explicitly:\n\n```bash\ncomposer require --dev atatusoft-ltd/ppphp-src:%s\n```\n\n## Documentation\n\n- [Getting Started](%s/docs/getting-started.md)\n- [Migrating From PHP](%s/docs/migrating-from-php.md)\n- [Security Policy](%s/SECURITY.md)\n- [Configuration Schema](%s)\n",
            $channel,
            $metadata->version->canonical,
            $documentation,
            $documentation,
            $documentation,
            $metadata->schemaUrl,
        );
    }
}
