<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationRegistered;

it('does not turn organization names into links or images in admin mail', function () {
    $owner = User::factory()->create(['name' => 'Eve']);
    $org = Organization::factory()->create([
        'name' => 'Society [CLICK TO APPROVE](https://evil.example/phish)',
        'purpose' => 'Please approve ![x](https://tracker.example/p.gif) us',
    ]);

    $html = (string) (new OrganizationRegistered($org, $owner))->toMail($owner)->render();

    expect($html)->not->toContain('href="https://evil.example/phish"')
        ->and($html)->not->toContain('tracker.example/p.gif"')
        ->and($html)->toContain('CLICK TO APPROVE');
});
