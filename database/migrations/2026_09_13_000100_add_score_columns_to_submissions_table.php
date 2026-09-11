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
            // Spec 5.6 normalises every answer to 0-100, so decimal(5,2) holds
            // the whole range with room to spare and keeps the value exact -
            // a float column would make "72.10" sort differently on two
            // drivers for no benefit anybody can see.
            $table->decimal('score', 5, 2)->nullable()->after('word_count');
            // Sample standard deviation of the submitted review scores. Null
            // with fewer than two of them: a spread of one observation is not
            // zero, it is undefined, and 0.00 would claim an agreement that was
            // never tested.
            $table->decimal('score_spread', 5, 2)->nullable()->after('score');
            // How many reviews were SUBMITTED. Not how many scored: a review of
            // an all-text form is still somebody's work, and the "fewer than N
            // reviews" filter is asking about people, not about arithmetic.
            $table->unsignedSmallInteger('review_count')->default(0)->after('score_spread');
            $table->timestamp('scored_at')->nullable()->after('review_count');

            // The ranking's default sort, scoped to one conference: spec
            // section 10 budgets 500 rows in under a second and this is the
            // index that makes it an index scan rather than a filesort.
            $table->index(['conference_id', 'score']);
            // The "fewer than N reviews" filter and the summary strip.
            $table->index(['conference_id', 'review_count']);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['conference_id', 'score']);
            $table->dropIndex(['conference_id', 'review_count']);
            $table->dropColumn(['score', 'score_spread', 'review_count', 'scored_at']);
        });
    }
};
