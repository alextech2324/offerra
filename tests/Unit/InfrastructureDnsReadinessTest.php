<?php

namespace Tests\Unit;

use App\Support\InfrastructureDnsReadiness;
use Tests\TestCase;

class InfrastructureDnsReadinessTest extends TestCase
{
    public function test_empty_expected_ns_is_acceptable(): void
    {
        $this->assertTrue(InfrastructureDnsReadiness::nameserversAcceptable(
            ['ns1.dynadot.com'],
            [],
        ));
    }

    public function test_expected_pair_matches_live(): void
    {
        $this->assertTrue(InfrastructureDnsReadiness::nameserversAcceptable(
            ['pam.ns.cloudflare.com', 'randy.ns.cloudflare.com'],
            ['randy.ns.cloudflare.com', 'pam.ns.cloudflare.com'],
        ));
    }

    public function test_stale_cloudflare_pair_is_not_the_new_account(): void
    {
        $this->assertFalse(InfrastructureDnsReadiness::nameserversAcceptable(
            ['simon.ns.cloudflare.com', 'brianna.ns.cloudflare.com'],
            ['pam.ns.cloudflare.com', 'randy.ns.cloudflare.com'],
        ));
    }

    public function test_parking_ns_are_not_ready(): void
    {
        $this->assertFalse(InfrastructureDnsReadiness::nameserversAcceptable(
            ['ns1.dynadot.com', 'ns2.dynadot.com'],
            ['pam.ns.cloudflare.com', 'randy.ns.cloudflare.com'],
        ));
    }

    public function test_empty_live_ns_are_not_ready_when_expected(): void
    {
        $this->assertFalse(InfrastructureDnsReadiness::nameserversAcceptable(
            [],
            ['pam.ns.cloudflare.com', 'randy.ns.cloudflare.com'],
        ));
    }

    public function test_prefer_cloudflare_proxy_ips_drops_stale_parking(): void
    {
        $this->assertSame(
            ['104.21.74.45', '172.67.197.246'],
            InfrastructureDnsReadiness::preferCloudflareProxyIps([
                '137.131.40.164',
                '104.21.74.45',
                '172.67.197.246',
            ]),
        );
    }

    public function test_prefer_cloudflare_proxy_ips_keeps_origin_when_alone(): void
    {
        $this->assertSame(
            ['45.61.177.8'],
            InfrastructureDnsReadiness::preferCloudflareProxyIps(['45.61.177.8']),
        );
    }
}
