<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_decisions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT, like reviews.submission_id: a decision is the record of
            // what an author was told, and Plan 6's hard purge has to delete it
            // deliberately in application code rather than have a cascade do it
            // quietly (spec section 3).
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->string('decision', 16);
            // The decision survives the person. There is no user-deletion path
            // today; this is what keeps a decision from vanishing with an
            // account when Plan 6 adds one.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            // The organizer's own note. Never sent: it is the committee's
            // reason, written for the next organizer and for the audit, and an
            // author reads the letter instead.
            $table->text('note')->nullable();

            // The letter, rendered ONCE at send time and stored, so an
            // organizer who edits the template in March does not rewrite what
            // an author was told in September. Null until this decision's email
            // is queued; a change-and-resend appends a new row and leaves these
            // three on the superseded one.
            $table->string('letter_subject')->nullable();
            $table->mediumText('letter_markdown')->nullable();
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            // The history is read newest-first for one submission, and the
            // status page asks for "the notified one" by the same key.
            $table->index(['submission_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_decisions');
    }
};
