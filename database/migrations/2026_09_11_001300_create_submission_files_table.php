<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('original_name');
            // Content-addressed: {first 2 of sha256}/{ulid}.{ext} on the
            // private `local` disk (spec section 8). The prefix directory keeps
            // any single directory from growing past a few thousand entries on
            // the ext4 volume.
            $table->string('path');
            $table->string('mime', 128);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            // Dedupe inside one submission: re-uploading the same PDF is a
            // mistake, not a second file, and this turns it into a clean
            // constraint violation StoreSubmissionFile can answer for.
            $table->unique(['submission_id', 'sha256']);
            $table->index(['submission_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_files');
    }
};
