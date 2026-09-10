<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Branding\OrganizationTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reads the organization colours and picks readable text for each', function () {
    $organization = Organization::factory()->create([
        'primary_color' => '#0F4C8A',
        'accent_color' => '#B45309',
    ]);

    $theme = OrganizationTheme::for($organization);

    expect($theme->primary)->toBe('#0F4C8A')
        ->and($theme->accent)->toBe('#B45309')
        ->and($theme->onPrimary)->toBe('#FFFFFF')
        ->and($theme->onAccent)->toBe('#FFFFFF');
});

it('switches to dark text on a light brand colour', function () {
    $organization = Organization::factory()->create([
        'primary_color' => '#BFE0F7',
        'accent_color' => '#F8FAFC',
    ]);

    $theme = OrganizationTheme::for($organization);

    expect($theme->onPrimary)->toBe('#111827')
        ->and($theme->onAccent)->toBe('#111827');
});

it('renders the four css custom properties the public layout consumes', function () {
    $theme = new OrganizationTheme('#176BB8', '#0F4C8A', '#FFFFFF', '#FFFFFF');

    expect($theme->cssVariables())->toBe(
        '--org-primary:#176BB8;--org-accent:#0F4C8A;--org-on-primary:#FFFFFF;--org-on-accent:#FFFFFF'
    );
});
