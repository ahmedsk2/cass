<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // Cascade, unlike conferences: an invitation is bookkeeping with no
            // life of its own, exactly like the organization_members row it
            // turns into.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            // SHA-256 hex of the invitation token (spec section 9). The
            // plaintext exists only inside the emailed link, and `unique` is
            // what makes /invite/{token} a single indexed read.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Deliberately an index and NOT unique: a revoked invitation keeps
            // its row, so "invite, revoke, invite again" would fail on a unique
            // key. InviteMember updates the live row instead of inserting.
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
