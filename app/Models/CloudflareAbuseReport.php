<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudflareAbuseReport extends Model
{
    protected $fillable = [
        'cloudflare_account_id',
        'cf_report_id',
        'domain',
        'type',
        'status',
        'offer_id',
        'user_id',
        'payload',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'notified_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function openForOffer(Offer $offer): bool
    {
        $domain = strtolower(trim((string) $offer->domain));
        if ($domain === '') {
            return false;
        }

        return self::query()
            ->where(function ($query) use ($offer, $domain): void {
                $query->where('offer_id', $offer->id)
                    ->orWhereRaw('LOWER(domain) = ?', [$domain]);
            })
            ->where('created_at', '>=', now()->subDays(7))
            ->exists();
    }
}
