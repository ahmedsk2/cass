<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviewer_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conference_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // days_7 | days_3 | days_1 | overdue. Only the four automatic
            // thresholds live here; a manual "Send reminder now" is throttled by
            // conferences.reviewer_reminded_at instead, because it is allowed to
            // repeat and this unique key exists precisely to stop repeats.
            $table->string('threshold', 16);
            $table->timestamp('sent_at');
            $table->timestamps();

            // The whole point of the table: each threshold at most once per
            // reviewer per conference, enforced by the database rather than by
            // an hourly command remembering what it did last hour.
            $table->unique(['conference_id', 'user_id', 'threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewer_reminders');
    }
};
