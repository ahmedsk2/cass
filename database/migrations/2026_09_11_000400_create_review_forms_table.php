<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_forms', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('conference_id')->constrained()->restrictOnDelete();
            $table->string('name')->default('Review form');
            $table->boolean('is_active')->default(true);
            // Set by Plan 4 when the first review is submitted. Spec section 3:
            // from that moment questions are locked and only new ones may be
            // appended.
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index(['conference_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_forms');
    }
};
