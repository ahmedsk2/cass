<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('conference_id')->constrained()->restrictOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->string('help_text', 500)->nullable();
            $table->string('type', 16);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['conference_id', 'key']);
            $table->index(['conference_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
