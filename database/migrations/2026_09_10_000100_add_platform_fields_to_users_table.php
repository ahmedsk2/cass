<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->string('locale', 8)->default('en')->after('is_platform_admin');
            $table->string('timezone', 64)->default('Asia/Riyadh')->after('locale');
            $table->text('app_authentication_secret')->nullable()->after('timezone');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_platform_admin', 'locale', 'timezone',
                'app_authentication_secret', 'app_authentication_recovery_codes',
            ]);
        });
    }
};
