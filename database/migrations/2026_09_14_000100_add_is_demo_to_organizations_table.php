<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // The flag `cass:demo-reset` asks before it hard-deletes a whole
            // tenant. It is deliberately NOT in Organization::$fillable: the
            // only thing that may set it is `cass:demo-seed`, with forceFill,
            // and a profile form that could flip it would turn a real
            // organization into something the purge command agrees to destroy.
            //
            // Indexed because the reset command's only lookup is
            // `where('slug', ...)` followed by a read of this column, and the
            // Plan 6 platform-admin screen will want "list the demo tenants".
            $table->boolean('is_demo')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
