<?php

declare(strict_types=1);

use App\Actions\Mail\SendTemplatedEmail;
use App\Enums\EmailTemplateKey;
use App\Mail\ContactMessage;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;

it('declares both mailables encrypted', function (string $mailable) {
    // SendQueuedMailable::__construct() sets shouldBeEncrypted from the
    // MAILABLE (:76), and Queue::jobShouldBeEncrypted() reads that property
    // (:293-300) - so the interface on the mailable is what encrypts the job.
    expect(is_subclass_of($mailable, ShouldBeEncrypted::class))->toBeTrue($mailable);
})->with([TemplatedMail::class, ContactMessage::class]);

it('encrypts the queued payload, so a token cannot be read out of the jobs table', function () {
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create();
    $token = str_repeat('t', 64);

    app(SendTemplatedEmail::class)->handle(
        EmailTemplateKey::SubmissionReceived,
        $conference,
        'author@example.org',
        [
            'author_name' => 'Sara Al-Harbi',
            'title' => 'Early mobilisation',
            'reference' => 'AAM26-001',
            'conference' => (string) $conference->name,
            'organization' => (string) $organization->name,
            'deadline' => '1 January 2027',
            'status_link' => url('/s/'.$token),
        ],
    );

    $payload = (string) DB::table('jobs')->value('payload');
    $decoded = json_decode($payload, true);

    expect($payload)->not->toBe('')
        // /s/{token} opens an abstract with no account at all
        // (routes/web.php:116), and failed_jobs keeps the same payload for 720
        // hours (routes/console.php:14).
        ->and($payload)->not->toContain($token)
        // displayName (Queue.php:178) and commandName (:207) are metadata
        // Laravel never encrypts - displayName is literally
        // get_class($this->mailable) (SendQueuedMailable::displayName()), so
        // 'TemplatedMail' IS in the payload and asserting otherwise can never
        // pass. `command` is the serialized mailable and is what carries the
        // body: it is an encrypted blob rather than a PHP serialization.
        ->and($decoded['data']['commandName'])->toBe(SendQueuedMailable::class)
        ->and($decoded['data']['command'])->not->toStartWith('O:')
        ->and(base64_decode($decoded['data']['command'], true))->not->toBeFalse();
});
