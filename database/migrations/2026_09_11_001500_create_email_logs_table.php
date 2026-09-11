<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            // The correlation id that travels in the X-CASS-Log header of the
            // real message (Task 3). A ULID rather than the primary key, so an
            // outgoing header never leaks how many emails the platform has
            // sent, and so it matches spec section 3 on public identifiers.
            $table->ulid('ulid')->unique();
            // All three are nullable and nullOnDelete: the log outlives what it
            // refers to. A platform-wide email (Plan 1's organization
            // notifications, a password reset) has all three null.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conference_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 48)->nullable();
            $table->string('mailable');
            $table->string('to_email');
            $table->string('subject');
            $table->string('status', 16)->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('to_email');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
