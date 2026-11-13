<?php

namespace Tests\Unit;

use App\Models\Offer;
use Tests\TestCase;

class OfferDnsStatusTest extends TestCase
{
    public function test_dns_status_is_ready_when_dns_marked_done_without_infra(): void
    {
        $offer = new Offer([
            'provision_infrastructure' => false,
            'infra_status' => null,
            'infra_meta' => ['dns' => 'done'],
        ]);

        $this->assertSame('ready', $offer->dnsStatus());
    }

    public function test_dns_status_is_pending_when_infra_ready_and_dns_not_propagated(): void
    {
        $offer = new Offer([
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'options' => [
                    'cloudflare_zone' => true,
                    'dynadot_ns' => false,
                    'hestia' => true,
                    'cloudflare_dns' => true,
                    'cloudflare_ssl' => true,
                    'cloudflare_https' => true,
                    'cloudflare_www_redirect' => true,
                ],
                'dns' => 'pending',
            ],
        ]);

        $this->assertSame('pending', $offer->dnsStatus());
    }

    public function test_dns_status_stays_pending_while_dynadot_ns_cutover_is_open(): void
    {
        $offer = new Offer([
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'dns' => 'done',
                'dynadot_ns' => 'pending',
                'options' => [
                    'cloudflare_zone' => true,
                    'dynadot_ns' => true,
                ],
            ],
        ]);

        $this->assertSame('pending', $offer->dnsStatus());
    }

    public function test_dns_status_is_ready_when_public_ns_already_match(): void
    {
        $offer = new Offer([
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'dns' => 'done',
                'dns_via' => 'records',
                'dynadot_ns' => 'pending',
                'dynadot_ns_error' => 'Domain initialization is still in progress.',
                'options' => [
                    'cloudflare_zone' => true,
                    'dynadot_ns' => true,
                ],
            ],
        ]);

        $this->assertSame('ready', $offer->dnsStatus());
    }

    public function test_dns_status_reopens_when_done_but_public_a_record_is_gone(): void
    {
        $offer = new Offer([
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'availability_status' => 'down',
            'availability_error' => 'No A record (NS: julissa.ns.cloudflare.com, rajeev.ns.cloudflare.com)',
            'infra_meta' => [
                'dns' => 'done',
                'dns_via' => 'records',
                'dynadot_ns' => 'done',
            ],
        ]);

        $this->assertSame('pending', $offer->dnsStatus());
    }

    public function test_dns_status_reopens_when_done_but_public_dns_is_nxdomain(): void
    {
        $offer = new Offer([
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'availability_status' => 'down',
            'availability_error' => 'NXDOMAIN / no public DNS',
            'infra_meta' => [
                'dns' => 'done',
                'dns_via' => 'records',
                'dynadot_ns' => 'done',
            ],
        ]);

        $this->assertSame('pending', $offer->dnsStatus());
    }

    public function test_dns_status_is_skipped_without_dns_wait_options(): void
    {
        $offer = new Offer([
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'infra_meta' => [
                'options' => [
                    'hestia' => true,
                    'cloudflare_zone' => false,
                    'cloudflare_dns' => false,
                    'dynadot_ns' => false,
                    'cloudflare_ssl' => false,
                    'cloudflare_https' => false,
                    'cloudflare_www_redirect' => false,
                ],
            ],
        ]);

        $this->assertSame('skipped', $offer->dnsStatus());
    }
}
