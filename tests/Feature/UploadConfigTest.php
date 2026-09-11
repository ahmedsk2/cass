<?php

declare(strict_types=1);

/**
 * config/livewire.php overrides the whole temporary-upload block, and two of
 * its values are derived from environment variables that are read somewhere
 * else as well. A config file cannot call config(), so the agreement is a
 * convention - and a convention with no test is a convention that drifts.
 */
it('caps a temporary upload at the same size the form does', function () {
    // SubmissionForm::uploadRules() derives its cap from cass.max_file_bytes
    // and the Livewire endpoint had `max:10240` written out, so raising
    // CASS_MAX_FILE_BYTES moved one knob and not the other: the form would
    // promise 20 MB and the upload endpoint would refuse at 10, with nothing
    // to say the two disagreed.
    $expected = 'max:'.(int) floor((int) config('cass.max_file_bytes') / 1024);

    expect(config('livewire.temporary_file_upload.rules'))->toContain($expected);
});

it('never builds a throttle string with an empty attempt count', function () {
    // `'throttle:'.env('CASS_UPLOAD_RATE_LIMIT', 20).',1'` concatenates
    // whatever the variable holds. An empty `CASS_UPLOAD_RATE_LIMIT=` line in
    // .env - which is how a variable gets disabled - produces `throttle:,1`,
    // and ThrottleRequests reads the missing count as zero attempts, so every
    // upload on the public form is a 429.
    expect(config('livewire.temporary_file_upload.middleware'))
        ->toMatch('/^throttle:[1-9][0-9]*,1$/');
});
