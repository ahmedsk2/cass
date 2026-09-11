<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conferences', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT, not CASCADE: spec section 3 says deleting a conference
            // is soft-delete only and the platform-admin hard purge cascades in
            // application code. A database cascade would silently wipe the tree
            // (and, once Plan 3 lands, orphan files in private storage) without
            // running any of that code.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('venue')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 8)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->timestamp('submission_opens_at')->nullable();
            $table->timestamp('submission_deadline')->nullable();
            $table->timestamp('review_deadline')->nullable();
            $table->string('review_mode', 16)->default('open_pool');
            $table->boolean('blind_review')->default(true);
            $table->unsignedTinyInteger('reviewers_per_submission')->default(2);
            $table->unsignedSmallInteger('word_limit')->default(500);
            $table->unsignedTinyInteger('max_files')->default(3);
            $table->json('allowed_file_types');
            $table->json('presentation_types');
            $table->text('terms')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conferences');
    }
};
