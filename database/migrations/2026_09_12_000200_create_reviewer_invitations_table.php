<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviewer_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('conference_id')->constrained()->cascadeOnDelete();
            // The organizer types a name before any account exists (spec 5.4
            // step 1), and it is what {{reviewer_name}} renders. Nullable
            // because a pasted list may be bare addresses.
            $table->string('name')->nullable();
            $table->string('email');
            // Spec 5.5's conflict rule compares reviewer affiliation with
            // author affiliation, so the affiliation has to exist somewhere
            // before the reviewer has an account. Optional; a null affiliation
            // simply never matches.
            $table->string('affiliation')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['conference_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewer_invitations');
    }
};
