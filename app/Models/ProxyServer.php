<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProxyServer extends Model
{
    protected $fillable = [
        'host',
        'port',
        'username',
        'password',
        'label',
        'is_active',
        'health',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'is_active' => 'boolean',
            'health' => 'array',
        ];
    }

    public function displayLabel(): string
    {
        $label = trim((string) ($this->label ?? ''));

        return $label !== '' ? $label : (string) $this->host;
    }

    public function hasSshCredentials(): bool
    {
        return filled($this->host)
            && filled($this->username)
            && filled($this->password);
    }

    public function healthStatus(): string
    {
        $health = is_array($this->health) ? $this->health : [];

        return (string) ($health['status'] ?? 'unchecked');
    }

    /**
     * @return list<string>
     */
    public static function activeHosts(): array
    {
        return static::query()
            ->where('is_active', true)
            ->pluck('host')
            ->map(static fn ($host) => trim((string) $host))
            ->filter()
            ->values()
            ->all();
    }

    public static function isKnownHost(string $ip): bool
    {
        $ip = trim($ip);

        return $ip !== '' && in_array($ip, self::activeHosts(), true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function activeForSwitch()
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (self $server) => $server->hasSshCredentials())
            ->values();
    }

    public function offerCount(): int
    {
        return Offer::query()
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed'])
            ->where(function ($query): void {
                $query->where('infra_meta->edge_proxy_id', $this->id)
                    ->orWhere('infra_meta->options->edge_proxy_id', $this->id)
                    ->orWhere(function ($inner): void {
                        $inner->where('infra_meta->edge', 'proxy')
                            ->where('infra_meta->edge_ip', $this->host);
                    });
            })
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPanelArray(): array
    {
        $health = is_array($this->health) ? $this->health : [];

        return [
            'id' => $this->id,
            'host' => $this->host,
            'port' => (int) ($this->port ?: 22),
            'username' => $this->username ?? '',
            'label' => $this->label ?? '',
            'display_label' => $this->displayLabel(),
            'is_active' => (bool) $this->is_active,
            'has_password' => filled($this->password),
            'has_ssh' => $this->hasSshCredentials(),
            'offers_count' => $this->offerCount(),
            'health' => [
                'status' => (string) ($health['status'] ?? 'unchecked'),
                'checked_at' => $health['checked_at'] ?? null,
                'message' => $health['message'] ?? null,
            ],
        ];
    }
}
