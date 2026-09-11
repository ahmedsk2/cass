<?php

declare(strict_types=1);

/**
 * `docker/nginx.conf` is not PHP, so nothing else in this suite reads it —
 * which is how a security control written in it rots unnoticed. The control:
 * the 64 characters after `/s/` are the author's bearer credential (spec
 * section 9) and must never reach the log stream Coolify collects. Redacting
 * the request line is only half of it. `App\Http\Middleware\SecurityHeaders`
 * sets `Referrer-Policy: strict-origin-when-cross-origin`, which sends the
 * *full* URL on a same-origin request, so every subresource the status page
 * pulls and every `/livewire/update` POST it makes carries the whole token in
 * `Referer` — the same credential, one field to the right.
 */
$conf = static fn (): string => (string) file_get_contents(base_path('docker/nginx.conf'));

/** Turn an nginx map regex key (`~^…` or `~*^…`) into a PCRE pattern; nginx uses PCRE too. */
$toPcre = static function (string $key): string {
    $insensitive = str_starts_with($key, '~*');

    return '#'.substr($key, $insensitive ? 2 : 1).'#'.($insensitive ? 'i' : '');
};

/**
 * The first regex key of a `map $source $target { … }` block. The block ends
 * at a `}` in column 1, not at the first `}` — the rules themselves contain
 * `{64}`.
 */
$mapPattern = static function (string $conf, string $source, string $target): string {
    preg_match('/map\s+\\'.$source.'\s+\\'.$target.'\s*\{(.*?)^\}/ms', $conf, $block);

    expect($block)->not->toBeEmpty("map {$source} {$target} is missing from docker/nginx.conf");

    preg_match('/"(~\*?[^"]+)"/', $block[1], $rule);

    expect($rule)->not->toBeEmpty("map {$source} {$target} has no regex rule");

    return $rule[1];
};

it('logs through the redacting cass format', function () use ($conf) {
    expect($conf())->toContain('access_log /dev/stdout cass;');
});

it('keeps the raw request uri and the raw referer out of the cass log format', function () use ($conf) {
    preg_match('/log_format\s+cass(.*?);/s', $conf(), $format);

    expect($format)->not->toBeEmpty('log_format cass is missing from docker/nginx.conf');

    expect($format[1])
        ->toContain('$cass_logged_uri')
        ->toContain('$cass_logged_referer')
        ->not->toContain('$request_uri')
        ->not->toContain('$http_referer');
});

it('redacts a status page token in the request uri', function () use ($conf, $toPcre, $mapPattern) {
    $pattern = $toPcre($mapPattern($conf(), '$request_uri', '$cass_logged_uri'));

    expect(preg_match($pattern, '/s/'.bin2hex(random_bytes(32))))->toBe(1)
        ->and(preg_match($pattern, '/c/gpcc/gpcc-2026'))->toBe(0);
});

it('redacts a status page token in the referer', function () use ($conf, $toPcre, $mapPattern) {
    $pattern = $toPcre($mapPattern($conf(), '$http_referer', '$cass_logged_referer'));
    $token = bin2hex(random_bytes(32));

    // Every shape the status page really produces: the page itself as the
    // referer of a /files/{ulid} click, and of a /livewire/update POST.
    expect(preg_match($pattern, 'https://cass.towardpcc.com/s/'.$token))->toBe(1)
        ->and(preg_match($pattern, 'http://localhost:8080/s/'.$token))->toBe(1)
        ->and(preg_match($pattern, 'https://cass.towardpcc.com/c/gpcc/gpcc-2026'))->toBe(0);
});
