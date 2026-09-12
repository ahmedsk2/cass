<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // The weighted mean of THIS review's scored answers, recomputed
            // whenever the answers change. Kept on the row rather than derived
            // on read so SubmissionScorer averages four columns instead of
            // re-walking review_answers and review_questions per review.
            //
            // Written for a draft as well as for a submitted review - it is the
            // score of the answers as they stand, and the aggregate reads only
            // submitted ones. That makes reopening a review a pure status
            // change and makes a rescore idempotent.
            $table->decimal('score', 5, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('score');
        });
    }
};
