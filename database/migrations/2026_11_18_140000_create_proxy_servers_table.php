<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('host')->unique();
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('health')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_servers');
    }
};
