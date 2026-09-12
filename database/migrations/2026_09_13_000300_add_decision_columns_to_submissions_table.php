<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // accepted_oral | accepted_poster | waitlisted | rejected, or null
            // for "not decided". A string and not a database enum, the same
            // rule every status column in this schema follows: a new case is a
            // deploy, not a migration.
            //
            // Denormalised from the newest submission_decisions row on purpose.
            // The history is what happened; this column is what is true now,
            // and it is what the ranking filters and sorts on.
            $table->string('decision', 16)->nullable()->after('status');
            // Set when the decision email is QUEUED, which is what makes
            // SendDecisionEmails idempotent and what gates the letter on
            // /s/{token}. Reset to null by a change-and-resend, so the new
            // decision goes out in the next run.
            $table->timestamp('decision_notified_at')->nullable()->after('decision');

            // The ranking's decision filter and the summary strip, and
            // SendDecisionEmails' "decided but not notified" query.
            $table->index(['conference_id', 'decision']);
            $table->index(['conference_id', 'decision_notified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['conference_id', 'decision']);
            $table->dropIndex(['conference_id', 'decision_notified_at']);
            $table->dropColumn(['decision', 'decision_notified_at']);
        });
    }
};
