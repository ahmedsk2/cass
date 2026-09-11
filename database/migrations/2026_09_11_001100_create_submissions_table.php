<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT for the same reason conferences restrict organizations:
            // spec section 3 makes deletion a soft delete, and the platform
            // admin's hard purge has to cascade in application code because it
            // also has to unlink files from private storage (backlog, Plan 6).
            $table->foreignId('conference_id')->constrained()->restrictOnDelete();
            // A deleted track must not take submissions with it. The author
            // picked a theme; losing the theme is not losing the abstract.
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            // Null until the abstract is actually submitted: a draft has no
            // number, and numbers are not handed out to rows that may never
            // come back.
            $table->string('reference', 32)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('title');
            // Plain text, not HTML: the abstract is typed into a textarea, the
            // word count has to match what the author sees, and nothing on the
            // public or panel side ever renders it unescaped.
            $table->text('abstract');
            $table->unsignedInteger('word_count')->default(0);
            $table->string('presentation_preference', 16)->nullable();
            $table->string('contact_phone', 40)->nullable();
            // Keyed by custom_fields.key, which CustomField derives once and
            // never re-derives precisely so that these stay readable.
            $table->json('custom_field_values')->nullable();
            // SHA-256 hex of the author's access token (spec section 9). The
            // plaintext exists only inside the emailed link. `unique` is what
            // makes the /s/{token} lookup a single indexed read.
            $table->char('access_token_hash', 64)->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('last_edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Spec section 8. The reference is unique inside one conference,
            // not globally: two organizations may both print GPCC26-001.
            $table->unique(['conference_id', 'reference']);
            $table->index(['conference_id', 'status']);
            // The admin panel and the nightly reports filter on status alone.
            $table->index('status');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
