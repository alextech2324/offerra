<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('proxy_servers');

        if (Schema::hasColumn('user_settings', 'preferred_edge')) {
            Schema::table('user_settings', function (Blueprint $table) {
                $table->dropColumn('preferred_edge');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_settings', 'preferred_edge')) {
            Schema::table('user_settings', function (Blueprint $table) {
                $table->string('preferred_edge', 20)->default('cloudflare');
            });
        }
    }
};
