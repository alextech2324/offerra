<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('archive_reason', 32)->nullable()->after('archived_by');
            $table->index('archive_reason');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex(['archive_reason']);
            $table->dropColumn('archive_reason');
        });
    }
};
