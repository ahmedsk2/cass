<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            // Spec 5.4 step 4: a blind reviewer must not be handed the author's
            // institution through a question the organizer wrote themselves.
            // Blinding the authors block is worth nothing if an organizer's own
            // "Institution", "Department" or "Funding source" field prints the
            // same answer two sections further down, and nothing in
            // `custom_fields` said which answers identify an author until now.
            // Default false: every field an organizer already created keeps
            // behaving exactly as it did, and hiding one is a deliberate act.
            $table->boolean('hide_from_reviewers')->default(false)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('hide_from_reviewers');
        });
    }
};
