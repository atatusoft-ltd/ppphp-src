<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\ReleasePublicationGuard;

test('publication accepts only successful complete catalogs with no matching release', function (): void {
    $guard = new ReleasePublicationGuard();
    $guard->verifyAbsent('2031.4.7', 0, '[[]]');
    $guard->verifyAbsent('2031.4.7', 0, '[[{"tag_name":"2031.4.6"}],[]]');
    expect(fn () => $guard->verifyAbsent('2031.4.7', 0, '[[],[{"tag_name":"2031.4.7","draft":true}]]'))
        ->toThrow(RuntimeException::class, 'already exists');
});

test('publication fails closed on API authentication and malformed response failures', function (int $exitCode, string $response): void {
    expect(fn () => (new ReleasePublicationGuard())->verifyAbsent('2031.4.7', $exitCode, $response))->toThrow(Exception::class);
})->with([
    [1, '[[]]'], [4, 'authentication required'], [1, '{"message":"Not Found"}'],
    [0, ''], [0, '[]'], [0, '{}'], [0, '[{}]'], [0, '[{"message":"rate limited"}]'], [0, '[[{}]]'],
]);
