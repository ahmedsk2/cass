<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_authors', function (Blueprint $table) {
            $table->id();
            // CASCADE, unlike everything else in this plan: an author row has
            // no meaning without its submission, carries no file and is never
            // referenced from anywhere else, so the purge in Plan 6 does not
            // need application code to reach it.
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('name', 180);
            $table->string('email');
            $table->string('affiliation')->nullable();
            $table->boolean('is_presenter')->default(false);
            $table->boolean('is_corresponding')->default(false);
            $table->timestamps();

            $table->index(['submission_id', 'sort']);
            // The organizer table searches on author email (spec 5.6 asks for
            // it on the ranking table; the submission list needs it first).
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_authors');
    }
};
