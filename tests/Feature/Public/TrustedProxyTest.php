<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('ignores X-Forwarded-Host from untrusted clients', function () {
    get('/', ['X-Forwarded-Host' => 'evil.example'])->assertOk()->assertDontSee('evil.example');
});

it('does not let an untrusted client choose its own IP', function () {
    $response = get('/', ['X-Forwarded-For' => '1.2.3.4']);
    expect(request()->ip())->not->toBe('1.2.3.4');
    $response->assertOk();
});
