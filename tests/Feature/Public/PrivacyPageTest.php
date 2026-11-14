<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('says how long backups keep deleted data, and where they are kept', function () {
    get('/privacy')->assertOk()
        ->assertSeeText('Deleted data remains in our backups until they expire: the copies kept away from our server are encrypted and kept at most 90 days from the day they are sent, which is within a day of each backup.')
        ->assertSeeText('The service and its backups are kept in Saudi Arabia.');
});
