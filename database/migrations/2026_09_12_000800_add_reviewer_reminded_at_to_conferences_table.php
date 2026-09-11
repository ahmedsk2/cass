<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conferences', function (Blueprint $table) {
            // The throttle on the organizer's manual "Send reminder now"
            // (Task 10). On the conference and not in reviewer_reminders,
            // because a manual send is per conference, is allowed to repeat,
            // and must not collide with the unique key that stops the automatic
            // thresholds repeating.
            $table->timestamp('reviewer_reminded_at')->nullable()->after('review_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('conferences', function (Blueprint $table) {
            $table->dropColumn('reviewer_reminded_at');
        });
    }
};
