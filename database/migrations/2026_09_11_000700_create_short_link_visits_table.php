<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A timestamp and nothing else: no IP, no user agent, no referrer.
        // Spec 5.7 asks for a scan count, not analytics, and this table is
        // therefore outside the scope of any personal-data request.
        Schema::create('short_link_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_link_id')->constrained()->cascadeOnDelete();
            $table->timestamp('visited_at');

            $table->index(['short_link_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_link_visits');
    }
};
