<?php

declare(strict_types=1);

use App\Enums\InvitationStatus;
use App\Models\Conference;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Support\Invitations\InvitationLookup;
use App\Support\Tokens\InvitationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('mints 64 hex characters and never the same one twice', function () {
    $tokens = collect(range(1, 25))->map(fn (): string => InvitationToken::generate());

    expect($tokens->unique())->toHaveCount(25);

    foreach ($tokens as $token) {
        expect($token)->toMatch('/^[0-9a-f]{64}$/');
    }
});

it('hashes with sha256 and stores nothing reversible', function () {
    $plain = InvitationToken::generate();

    expect(InvitationToken::hash($plain))->toBe(hash('sha256', $plain))
        ->and(InvitationToken::hash($plain))->toHaveLength(64)
        ->and(InvitationToken::hash($plain))->not->toBe($plain);
});

it('finds an invitation of either kind by its plaintext token', function () {
    $memberToken = InvitationToken::generate();
    $reviewerToken = InvitationToken::generate();

    $member = OrganizationInvitation::factory()->create(['token_hash' => InvitationToken::hash($memberToken)]);
    $reviewer = ReviewerInvitation::factory()->for(Conference::factory()->published())
        ->create(['token_hash' => InvitationToken::hash($reviewerToken)]);

    $lookup = app(InvitationLookup::class);

    expect($lookup->find($memberToken))->toBeInstanceOf(OrganizationInvitation::class)
        ->and($lookup->find($memberToken)?->getKey())->toBe($member->getKey())
        ->and($lookup->find($reviewerToken))->toBeInstanceOf(ReviewerInvitation::class)
        ->and($lookup->find($reviewerToken)?->getKey())->toBe($reviewer->getKey());
});

it('answers null for an unknown, empty or malformed token without a second shape of answer', function () {
    $lookup = app(InvitationLookup::class);

    expect($lookup->find(''))->toBeNull()
        ->and($lookup->find('not-a-token'))->toBeNull()
        ->and($lookup->find(InvitationToken::generate()))->toBeNull();
});

it('still resolves an expired or revoked invitation, because the page explains rather than 404s', function () {
    $expiredToken = InvitationToken::generate();
    $revokedToken = InvitationToken::generate();

    OrganizationInvitation::factory()->expired()->create(['token_hash' => InvitationToken::hash($expiredToken)]);
    OrganizationInvitation::factory()->revoked()->create(['token_hash' => InvitationToken::hash($revokedToken)]);

    $lookup = app(InvitationLookup::class);

    expect($lookup->find($expiredToken))->not->toBeNull()
        ->and($lookup->find($expiredToken)?->invitationStatus())->toBe(InvitationStatus::Expired)
        ->and($lookup->find($revokedToken)?->invitationStatus())->toBe(InvitationStatus::Revoked);
});
