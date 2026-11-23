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
        $this->assertSame(0, $options['edge_proxy_id']);
    }

    public function test_proxy_edge_forces_zone_and_ns_without_origin_a(): void
    {
        $options = InfrastructureOptions::fromInput([
            'infra_edge' => 'proxy:4',
            'infra_cloudflare_zone' => false,
            'infra_cloudflare_dns' => true,
            'infra_dynadot_ns' => false,
            'infra_cloudflare_ssl' => true,
            'infra_cloudflare_https' => true,
            'infra_cloudflare_www_redirect' => true,
        ]);

        $this->assertTrue(InfrastructureOptions::wantsProxy($options));
        $this->assertSame(4, $options['edge_proxy_id']);
        $this->assertTrue($options['cloudflare_zone']);
        $this->assertFalse($options['cloudflare_dns']);
        $this->assertTrue($options['dynadot_ns']);
        $this->assertFalse($options['cloudflare_ssl']);
        $this->assertFalse($options['cloudflare_https']);
        $this->assertFalse($options['cloudflare_www_redirect']);
        $this->assertTrue(InfrastructureOptions::anyEnabled($options));
        $this->assertTrue(InfrastructureOptions::needsDnsWait($options));
    }

    public function test_from_meta_keeps_proxy_edge(): void
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

        $this->assertTrue(InfrastructureOptions::wantsProxy($options));
        $this->assertSame(9, $options['edge_proxy_id']);
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
