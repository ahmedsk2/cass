<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            // Polymorphic from day one so v2 can point a short link at a single
            // abstract (spec 5.7) without a migration. Written out instead of
            // morphs() because morphs() only adds a plain index: "one short
            // link per target" has to be the database's promise, not a
            // select-then-insert's. Two concurrent publishes would otherwise
            // print two codes for one conference, and Conference::shortLink()
            // is a morphOne that shows only one of them - so the poster
            // already in circulation would keep counting into a row the
            // sharing page never displays.
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->unique(['target_type', 'target_id']);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
