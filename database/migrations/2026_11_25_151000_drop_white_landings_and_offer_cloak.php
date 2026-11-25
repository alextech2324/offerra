<?php

use App\Models\Offer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('white_landing_id');
            $table->dropColumn('cloak_enabled');
        });

        Schema::dropIfExists('white_landings');

        Offer::query()
            ->whereNotNull('infra_meta')
            ->orderBy('id')
            ->chunkById(100, function ($offers): void {
                foreach ($offers as $offer) {
                    $meta = is_array($offer->infra_meta) ? $offer->infra_meta : [];
                    if (! array_key_exists('cloak', $meta)) {
                        continue;
                    }
                    unset($meta['cloak']);
                    $offer->forceFill(['infra_meta' => $meta === [] ? null : $meta])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        Schema::create('white_landings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('entry_file', 120)->default('index.html');
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('original_filename', 180)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('cloak_enabled')->default(false);
            $table->foreignId('white_landing_id')
                ->nullable()
                ->constrained('white_landings')
                ->nullOnDelete();
        });
    }
};
