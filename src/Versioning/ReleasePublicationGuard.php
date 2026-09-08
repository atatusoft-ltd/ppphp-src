<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

final readonly class ReleasePublicationGuard
{
    /** Accept only a successful, complete paginated GitHub release listing. */
    public function verifyAbsent(string $tag, int $exitCode, string $response): void
    {
        ReleaseVersion::parse($tag);

        if ($exitCode !== 0) {
            throw new \RuntimeException('The release catalog request failed; publication is blocked.');
        }

        $pages = json_decode($response, flags: JSON_THROW_ON_ERROR);

        if (!is_array($pages) || !array_is_list($pages) || $pages === []) {
            throw new \UnexpectedValueException('The release catalog response is invalid.');
        }

        foreach ($pages as $page) {
            if (!is_array($page) || !array_is_list($page)) {
                throw new \UnexpectedValueException('The release catalog page is invalid.');
            }

            foreach ($page as $release) {
                if (!$release instanceof \stdClass || !is_string($release->tag_name ?? null)) {
                    throw new \UnexpectedValueException('The release catalog entry is invalid.');
                }

                if ($release->tag_name === $tag) {
                    throw new \RuntimeException('This release already exists; its assets must not be overwritten.');
                }
            }
        }
    }
}
