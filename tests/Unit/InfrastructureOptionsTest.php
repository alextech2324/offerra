<?php

namespace Tests\Unit;

use App\Support\InfrastructureOptions;
use Tests\TestCase;

class InfrastructureOptionsTest extends TestCase
{
    public function test_from_input_maps_request_fields(): void
    {
        $options = InfrastructureOptions::fromInput([
            'infra_cloudflare_zone' => false,
            'infra_cloudflare_dns' => true,
            'infra_dynadot_ns' => false,
            'infra_cloudflare_ssl' => true,
            'infra_cloudflare_https' => false,
            'infra_cloudflare_www_redirect' => true,
        ]);

        $this->assertFalse($options['cloudflare_zone']);
        $this->assertTrue($options['cloudflare_dns']);
        $this->assertTrue($options['cloudflare_ssl']);
        $this->assertFalse($options['cloudflare_https']);
        $this->assertTrue($options['cloudflare_www_redirect']);
        $this->assertSame('cloudflare', $options['edge']);
    }

    public function test_from_input_ignores_legacy_proxy_edge(): void
    {
        $options = InfrastructureOptions::fromInput([
            'infra_edge' => 'proxy:4',
            'infra_cloudflare_zone' => true,
            'infra_cloudflare_dns' => true,
            'infra_dynadot_ns' => true,
            'infra_cloudflare_ssl' => true,
            'infra_cloudflare_https' => true,
            'infra_cloudflare_www_redirect' => true,
        ]);

        $this->assertSame('cloudflare', $options['edge']);
        $this->assertTrue($options['cloudflare_dns']);
        $this->assertTrue($options['cloudflare_ssl']);
        $this->assertTrue(InfrastructureOptions::anyEnabled($options));
        $this->assertTrue(InfrastructureOptions::needsDnsWait($options));
    }

    public function test_from_meta_normalizes_legacy_proxy_to_cloudflare(): void
    {
        $options = InfrastructureOptions::fromMeta([
            'options' => [
                'edge' => 'proxy',
                'edge_proxy_id' => 9,
                'cloudflare_zone' => true,
                'cloudflare_dns' => false,
                'dynadot_ns' => true,
                'cloudflare_ssl' => false,
                'cloudflare_https' => false,
                'cloudflare_www_redirect' => false,
            ],
        ]);

        $this->assertSame('cloudflare', $options['edge']);
        $this->assertTrue($options['cloudflare_zone']);
        $this->assertFalse($options['cloudflare_dns']);
        $this->assertArrayNotHasKey('edge_proxy_id', $options);
    }

    public function test_from_preferred_edge_is_always_cloudflare(): void
    {
        $options = InfrastructureOptions::fromPreferredEdge('proxy');

        $this->assertSame('cloudflare', $options['edge']);
        $this->assertTrue($options['cloudflare_dns']);
        $this->assertTrue($options['cloudflare_ssl']);
    }

    public function test_needs_cloudflare_zone_when_dns_or_ssl_enabled(): void
    {
        $this->assertTrue(InfrastructureOptions::needsCloudflareZone([
            'cloudflare_zone' => false,
            'cloudflare_dns' => true,
            'cloudflare_ssl' => false,
            'cloudflare_https' => false,
            'cloudflare_www_redirect' => false,
            'dynadot_ns' => false,
        ]));
    }
}
