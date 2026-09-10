<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 32);
            $table->string('country', 8);
            $table->string('website')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('purpose')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->text('status_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('logo_path')->nullable();
            // Default must pass WCAG AA on white (see App\Support\Branding\Contrast): #176BB8 = 5.48:1.
            $table->string('primary_color', 7)->default('#176BB8');
            $table->string('accent_color', 7)->default('#0F4C8A');
            $table->string('custom_domain')->nullable()->unique();
            $table->string('custom_domain_token', 64)->nullable();
            $table->timestamp('custom_domain_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
