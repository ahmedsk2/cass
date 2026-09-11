<?php

declare(strict_types=1);

/**
 * `docker/nginx.conf` is not PHP, so nothing else in this suite reads it —
 * which is how a security control written in it rots unnoticed. The control:
 * the 64 characters after `/s/` are the author's bearer credential and the 64
 * after `/invite/` are the invitee's (spec section 9), and neither must ever
 * reach the log stream Coolify collects. Redacting the request line is only
 * half of it. `App\Http\Middleware\SecurityHeaders` sets
 * `Referrer-Policy: strict-origin-when-cross-origin`, which sends the *full*
 * URL on a same-origin request, so every subresource those pages pull and
 * every `/livewire/update` POST they make carries the whole token in
 * `Referer` — the same credential, one field to the right.
 */
$conf = static fn (): string => (string) file_get_contents(base_path('docker/nginx.conf'));

/** Turn an nginx map regex key (`~^…` or `~*^…`) into a PCRE pattern; nginx uses PCRE too. */
$toPcre = static function (string $key): string {
    $insensitive = str_starts_with($key, '~*');

    return '#'.substr($key, $insensitive ? 2 : 1).'#'.($insensitive ? 'i' : '');
};

/**
 * EVERY regex key of a `map $source $target { … }` block, in the order nginx
 * evaluates them. The block ends at a `}` in column 1, not at the first `}` —
 * the rules themselves contain `{64}`.
 *
 * All of them rather than the first: each map now carries one rule per
 * credential-bearing URL shape, and a helper that read only the first would
 * make the order of the rules decide which control is tested.
 *
 * @return list<string>
 */
$mapPatterns = static function (string $conf, string $source, string $target): array {
    preg_match('/map\s+\\'.$source.'\s+\\'.$target.'\s*\{(.*?)^\}/ms', $conf, $block);

    expect($block)->not->toBeEmpty("map {$source} {$target} is missing from docker/nginx.conf");

    preg_match_all('/"(~\*?[^"]+)"/', $block[1], $rules);

    expect($rules[1])->not->toBeEmpty("map {$source} {$target} has no regex rule");

    /** @var list<string> $keys */
    $keys = $rules[1];

    return $keys;
};

/** True when any rule of the map matches — which is what nginx itself answers. */
$redacts = static function (array $keys, string $candidate) use ($toPcre): bool {
    foreach ($keys as $key) {
        if (preg_match($toPcre((string) $key), $candidate) === 1) {
            return true;
        }
    }

    return false;
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

it('redacts a status page token in the request uri', function () use ($conf, $mapPatterns, $redacts) {
    $keys = $mapPatterns($conf(), '$request_uri', '$cass_logged_uri');

    expect($redacts($keys, '/s/'.bin2hex(random_bytes(32))))->toBeTrue()
        ->and($redacts($keys, '/c/gpcc/gpcc-2026'))->toBeFalse();
});

it('redacts an invitation token in the request uri', function () use ($conf, $mapPatterns, $redacts) {
    // Plan 4's second credential-carrying URL. The token behind it creates a
    // pre-verified account at the invitee's address - for an Owner invitation,
    // full control of the organization - so it belongs in this map exactly as
    // much as /s/ does.
    $keys = $mapPatterns($conf(), '$request_uri', '$cass_logged_uri');

    expect($redacts($keys, '/invite/'.bin2hex(random_bytes(32))))->toBeTrue()
        ->and($redacts($keys, '/invite/short'))->toBeFalse();
});

it('redacts a status page token in the referer', function () use ($conf, $mapPatterns, $redacts) {
    $keys = $mapPatterns($conf(), '$http_referer', '$cass_logged_referer');
    $token = bin2hex(random_bytes(32));

    // Every shape the status page really produces: the page itself as the
    // referer of a /files/{ulid} click, and of a /livewire/update POST.
    expect($redacts($keys, 'https://cass.towardpcc.com/s/'.$token))->toBeTrue()
        ->and($redacts($keys, 'http://localhost:8080/s/'.$token))->toBeTrue()
        ->and($redacts($keys, 'https://cass.towardpcc.com/c/gpcc/gpcc-2026'))->toBeFalse();
});

it('redacts an invitation token in the referer', function () use ($conf, $mapPatterns, $redacts) {
    // The accept page is a Livewire component, so it POSTs to /livewire/update
    // on every keystroke of the password field - each one carrying the whole
    // invitation URL in Referer.
    $keys = $mapPatterns($conf(), '$http_referer', '$cass_logged_referer');
    $token = bin2hex(random_bytes(32));

    expect($redacts($keys, 'https://cass.towardpcc.com/invite/'.$token))->toBeTrue()
        ->and($redacts($keys, 'http://localhost:8080/invite/'.$token))->toBeTrue();
});

it('gives each referer rule its own capture name', function () use ($conf, $mapPatterns) {
    // nginx compiles every rule of one map into the same variable namespace, so
    // two rules that both capture `cass_ref` is a configuration error and the
    // container refuses to start.
    $names = [];

    foreach ($mapPatterns($conf(), '$http_referer', '$cass_logged_referer') as $key) {
        preg_match_all('/\(\?<([a-z_][a-z0-9_]*)>/i', (string) $key, $captures);

        foreach ($captures[1] as $name) {
            $names[] = $name;
        }
    }

    expect($names)->toBe(array_values(array_unique($names)));
});
