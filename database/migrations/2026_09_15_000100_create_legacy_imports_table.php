<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_imports', function (Blueprint $table) {
            $table->id();
            // The legacy table's own name, as it appears in the dump:
            // conferences, users, submissions, reviews, evaluation_forms,
            // evaluation_questions, conference_reviewers,
            // reviewer_invitations.
            $table->string('legacy_table', 40);
            $table->unsignedBigInteger('legacy_id');

            // Polymorphic and deliberately WITHOUT a foreign key, like
            // short_links and activity_log: the row it points at may be in any
            // of eight tables, three of which soft-delete. Nothing deletes
            // these rows for you - a mapping whose target has been purged
            // answers null from the morph (LegacyImport::find()), and a later
            // import overwrites it in place rather than tripping the unique
            // index.
            $table->string('imported_type');
            $table->unsignedBigInteger('imported_id');
            $table->timestamp('imported_at');

            // THE idempotency guarantee. Spec 5.10: "Idempotent by legacy id."
            // A second run finds the row and skips, whatever the command's own
            // control flow does, because the database refuses the duplicate.
            $table->unique(['legacy_table', 'legacy_id']);
            $table->index(['imported_type', 'imported_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_imports');
    }
};
