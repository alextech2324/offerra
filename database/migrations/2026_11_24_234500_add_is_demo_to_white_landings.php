<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('white_landings', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('original_filename');
        });
    }

    public function down(): void
    {
        Schema::table('white_landings', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
