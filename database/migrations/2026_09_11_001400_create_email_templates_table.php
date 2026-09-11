<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            // CASCADE: a template override is configuration of one conference
            // and is worthless without it.
            $table->foreignId('conference_id')->constrained()->cascadeOnDelete();
            // Not an enum column: EmailTemplateKey grows in Plans 4 and 5, and
            // a string keeps the migration out of that change. Rows whose key
            // no longer resolves are ignored by DefaultTemplates rather than
            // breaking a send.
            $table->string('key', 48);
            $table->string('subject');
            $table->text('body');
            $table->timestamps();

            // A row exists only when an organizer overrides a platform default,
            // so this is both the uniqueness rule and the lookup index.
            $table->unique(['conference_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
