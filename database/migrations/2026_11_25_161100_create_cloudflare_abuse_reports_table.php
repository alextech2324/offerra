<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_abuse_reports', function (Blueprint $table) {
            $table->id();
            $table->string('cloudflare_account_id', 40);
            $table->string('cf_report_id', 64);
            $table->string('domain')->nullable();
            $table->string('type', 32)->nullable();
            $table->string('status', 32)->nullable();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['cloudflare_account_id', 'cf_report_id'], 'cf_abuse_account_report_unique');
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_abuse_reports');
    }
};
