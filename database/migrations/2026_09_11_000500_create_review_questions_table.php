<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_questions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('review_form_id')->constrained()->restrictOnDelete();
            $table->text('prompt');
            $table->string('help_text', 500)->nullable();
            $table->string('type', 16);
            $table->unsignedTinyInteger('scale_min')->nullable();
            $table->unsignedTinyInteger('scale_max')->nullable();
            $table->json('options')->nullable();
            $table->decimal('weight', 5, 2)->default(1);
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['review_form_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_questions');
    }
};
