<?php

declare(strict_types=1);

use App\Support\Branding\Contrast;

it('computes WCAG contrast ratios', function () {
    expect(round(Contrast::ratio('#000000', '#FFFFFF'), 1))->toBe(21.0)
        ->and(round(Contrast::ratio('#1E7BD1', '#FFFFFF'), 2))->toBe(4.37)
        ->and(Contrast::passesAA('#0F4C8A', '#FFFFFF'))->toBeTrue()
        ->and(Contrast::passesAA('#BFE0F7', '#FFFFFF'))->toBeFalse();
});

it('picks readable text colour for a background', function () {
    expect(Contrast::textOn('#0F4C8A'))->toBe('#FFFFFF')
        ->and(Contrast::textOn('#BFE0F7'))->toBe('#111827');
});
