<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // RESTRICT everywhere in the conference tree; see the note on
            // conferences.organization_id.
            $table->foreignId('conference_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['conference_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
