<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\ProxyServer;
use App\Models\User;
use App\Services\ProxyPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyPoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_picks_fewest_of_same_brand_then_least_load(): void
    {
        $user = User::factory()->create();
        $heavy = $this->proxy('10.0.0.1', 'Heavy');
        $light = $this->proxy('10.0.0.2', 'Light');

        $this->liveOffer($user, 'Funnel', $heavy->id, 'funnel-a.live');
        $this->liveOffer($user, 'Funnel', $heavy->id, 'funnel-b.live');
        $this->liveOffer($user, 'Other', $light->id, 'other-a.live');

        $pool = app(ProxyPool::class);
        $picked = $pool->allocate($this->unsavedOffer('Funnel'));

        $this->assertSame($light->id, $picked->id);
    }

    public function test_assign_keeps_an_explicit_pin(): void
    {
        $user = User::factory()->create();
        $first = $this->proxy('10.0.0.1');
        $this->proxy('10.0.0.2');

        $offer = $this->liveOffer($user, 'PinMe', $first->id, 'pin-me.live', attached: false);

        $assigned = app(ProxyPool::class)->assign($offer);

        $this->assertSame($first->id, $assigned->id);
        $this->assertSame($first->id, $offer->fresh()->infra_meta['options']['edge_proxy_id']);
    }

    public function test_assign_spreads_a_brand_across_empty_proxies(): void
    {
        $user = User::factory()->create();
        $a = $this->proxy('10.0.0.1');
        $b = $this->proxy('10.0.0.2');

        $first = $this->liveOffer($user, 'Acme', 0, 'acme-one.live', attached: false);
        $second = $this->liveOffer($user, 'Acme', 0, 'acme-two.live', attached: false);

        $pool = app(ProxyPool::class);
        $one = $pool->assign($first);
        $two = $pool->assign($second);

        $this->assertSame($a->id, $one->id);
        $this->assertSame($b->id, $two->id);
    }

    public function test_spread_skips_already_counted_load(): void
    {
        $user = User::factory()->create();
        $busy = $this->proxy('10.0.0.1');
        $empty = $this->proxy('10.0.0.2');

        $this->liveOffer($user, 'Load', $busy->id, 'busy-1.live');
        $this->liveOffer($user, 'Load', $busy->id, 'busy-2.live');
        $fresh = $this->liveOffer($user, 'NewBrand', 0, 'fresh.live', attached: false);

        $assignment = app(ProxyPool::class)->spread([$fresh->id]);

        $this->assertSame($empty->id, $assignment[$fresh->id]);
    }

    private function proxy(string $host, string $label = ''): ProxyServer
    {
        return ProxyServer::query()->create([
            'host' => $host,
            'port' => 22,
            'username' => 'root',
            'password' => 'secret',
            'label' => $label,
            'is_active' => true,
        ]);
    }

    private function liveOffer(
        User $user,
        string $brand,
        int $proxyId,
        string $domain,
        bool $attached = true,
    ): Offer {
        $options = [
            'edge' => 'proxy',
            'edge_proxy_id' => $proxyId,
        ];
        $meta = ['options' => $options];
        if ($attached && $proxyId > 0) {
            $meta['edge'] = 'proxy';
            $meta['edge_proxy_id'] = $proxyId;
        }

        return Offer::query()->create([
            'user_id' => $user->id,
            'folder' => $domain,
            'brand' => $brand,
            'domain' => $domain,
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => $meta,
        ]);
    }

    private function unsavedOffer(string $brand): Offer
    {
        $offer = new Offer;
        $offer->brand = $brand;

        return $offer;
    }
}
