<?php

use App\Models\CloudflareAbuseReport;
use App\Models\Offer;
use App\Services\CloudflareAbusePollService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->timestamp('cf_abuse_at')->nullable()->after('availability_fail_streak');
            $table->index('cf_abuse_at');
        });

        $inactive = ['archived', 'archiving', 'purging', 'teardown_failed'];
        $types = CloudflareAbusePollService::NOTIFY_TYPES;

        $offerIds = CloudflareAbuseReport::query()
            ->whereIn('type', $types)
            ->whereNotNull('offer_id')
            ->pluck('offer_id');

        $domains = CloudflareAbuseReport::query()
            ->whereIn('type', $types)
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->pluck('domain')
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->unique()
            ->values();

        if ($offerIds->isEmpty() && $domains->isEmpty()) {
            return;
        }

        Offer::query()
            ->whereNotIn('status', $inactive)
            ->whereNull('cf_abuse_at')
            ->where(function ($query) use ($offerIds, $domains): void {
                if ($offerIds->isNotEmpty()) {
                    $query->whereIn('id', $offerIds->all());
                }
                if ($domains->isNotEmpty()) {
                    $placeholders = implode(',', array_fill(0, $domains->count(), '?'));
                    $method = $offerIds->isNotEmpty() ? 'orWhereRaw' : 'whereRaw';
                    $query->{$method}('LOWER(domain) IN ('.$placeholders.')', $domains->all());
                }
            })
            ->update(['cf_abuse_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropIndex(['cf_abuse_at']);
            $table->dropColumn('cf_abuse_at');
        });
    }
};
