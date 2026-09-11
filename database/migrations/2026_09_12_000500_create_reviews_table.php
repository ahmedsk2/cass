<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT on all three: a review is the record of what the
            // committee was told, and Plan 6's hard purge has to delete it
            // deliberately in application code rather than have a cascade do it
            // quietly (spec section 3).
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            // Which form this review answers. A conference has exactly one
            // active form today (spec section 3); recording the id is what
            // makes the section 14 "multiple active review forms" item a
            // migration of behaviour rather than of data.
            $table->foreignId('review_form_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            // Spec section 8 names this one explicitly.
            $table->unique(['submission_id', 'reviewer_user_id']);
            $table->index(['submission_id', 'status']);
            $table->index(['reviewer_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
