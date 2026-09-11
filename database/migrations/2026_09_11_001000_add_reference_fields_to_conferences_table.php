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
            // Nullable rather than defaulted: the value is derived from the
            // name and the start date, which a column default cannot express,
            // and conferences created before this migration have to keep
            // working. Conference::referencePrefix() derives one on the fly for
            // those; the creating hook fills it for everything new.
            $table->string('reference_prefix', 12)->nullable()->after('terms');
            // The per-conference sequence behind GPCC26-017. Incremented under
            // lockForUpdate() inside SubmitAbstract's transaction (Task 2), so
            // two authors pressing Submit at the same second cannot draw the
            // same number.
            $table->unsignedInteger('submission_counter')->default(0)->after('reference_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('conferences', function (Blueprint $table) {
            $table->dropColumn(['reference_prefix', 'submission_counter']);
        });
    }
};
