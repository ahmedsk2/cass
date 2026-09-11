<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            // RESTRICT is the database-level twin of review_forms.locked_at:
            // once a question has an answer, no code path can hard-delete it.
            $table->foreignId('review_question_id')->constrained()->restrictOnDelete();
            // One column per answer type rather than one polymorphic `value`
            // string: Plan 5 averages the integers, and a text column that
            // sometimes holds "4" is a cast waiting to be got wrong.
            $table->integer('value_int')->nullable();
            $table->mediumText('value_text')->nullable();
            $table->boolean('value_bool')->nullable();
            // The chosen option of a Select question. The key IS the option's
            // label - see App\Models\ReviewQuestion::optionKey() for why.
            $table->string('choice_key', 200)->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'review_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_answers');
    }
};
