<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `contact_email` is the address the platform uses to reach an
     * organization, so it may hold the owner's personal login address. The
     * public conference page renders it only when this opt-in is on, and it
     * stays off for every organization that already exists.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('publish_contact_email')->default(false)->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('publish_contact_email');
        });
    }
};
