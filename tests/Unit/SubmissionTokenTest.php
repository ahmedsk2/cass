<?php

declare(strict_types=1);

use App\Actions\Submissions\IssueSubmissionToken;
use App\Models\Submission;
use App\Support\Tokens\SubmissionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates 64 url-safe characters that match the status route constraint', function () {
    $token = SubmissionToken::generate();

    expect(strlen($token))->toBe(64)
        // The /s/{token} route is constrained to [A-Za-z0-9]{64}; a token that
        // needed escaping would 404 on its own link.
        ->and($token)->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and($token)->not->toBe(SubmissionToken::generate());
});

it('stores only the sha-256 hash and never the plaintext', function () {
    $submission = Submission::factory()->create();

    $plain = app(IssueSubmissionToken::class)->handle($submission);

    $stored = (string) $submission->refresh()->access_token_hash;

    expect(strlen($stored))->toBe(64)
        ->and($stored)->toBe(hash('sha256', $plain))
        ->and($stored)->not->toBe($plain)
        // Spec section 9: the plaintext appears only in the emailed link, so
        // it must not be recoverable from any column on the row.
        ->and(json_encode($submission->refresh()->getAttributes()))->not->toContain($plain);
});

it('finds a submission from its plaintext token and only from the right one', function () {
    $mine = Submission::factory()->create();
    $theirs = Submission::factory()->create();

    $plain = app(IssueSubmissionToken::class)->handle($mine);
    app(IssueSubmissionToken::class)->handle($theirs);

    expect(Submission::findByPlainToken($plain)?->is($mine))->toBeTrue()
        ->and(Submission::findByPlainToken(str_repeat('0', 64)))->toBeNull()
        ->and(Submission::findByPlainToken(''))->toBeNull();
});

it('replaces the old hash when a link is reissued', function () {
    $submission = Submission::factory()->create();

    $first = app(IssueSubmissionToken::class)->handle($submission);
    $second = app(IssueSubmissionToken::class)->handle($submission);

    expect($first)->not->toBe($second)
        ->and(Submission::findByPlainToken($second)?->is($submission))->toBeTrue()
        // The point of "Resend status link": the old link stops working.
        ->and(Submission::findByPlainToken($first))->toBeNull();
});
