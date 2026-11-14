<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('says how long backups keep deleted data, and where they are kept', function () {
    get('/privacy')->assertOk()
        ->assertSeeText('Deleted data remains in our backups until they expire, at most 90 days later')
        ->assertSeeText('The service and its backups are kept in Saudi Arabia.');
});
