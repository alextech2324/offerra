<?php

namespace Tests\Unit;

use App\Services\DynadotClient;
use Tests\TestCase;

class DynadotClientTest extends TestCase
{
    private DynadotClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new DynadotClient;
    }

    public function test_expand_query_with_tld_searches_only_that_domain(): void
    {
        $domains = $this->client->expandQuery('pestotracer.com');

        $this->assertSame(['pestotracer.com'], $domains);
    }

    public function test_expand_query_without_tld_searches_all_configured_zones(): void
    {
        $domains = $this->client->expandQuery('pestotracer');
        $tlds = config('offerra.domain_search_tlds');

        $this->assertCount(count($tlds), $domains);
        $this->assertSame(
            array_map(fn (string $tld) => 'pestotracer.'.ltrim($tld, '.'), $tlds),
            $domains,
        );
        $this->assertNotContains('online', $tlds);
        $this->assertNotContains('pestotracer.online', $domains);
    }

    public function test_sort_search_results_by_price_desc(): void
    {
        $sorted = $this->client->sortSearchResultsByPriceDesc([
            ['domain' => 'a.online', 'available' => true, 'price' => '2.50 in USD', 'status' => 'available', 'message' => null],
            ['domain' => 'b.click', 'available' => true, 'price' => '12.99 in USD', 'status' => 'available', 'message' => null],
            ['domain' => 'c.com', 'available' => false, 'price' => null, 'status' => 'taken', 'message' => null],
            ['domain' => 'd.live', 'available' => true, 'price' => '7.99 in USD', 'status' => 'available', 'message' => null],
        ]);

        $this->assertSame(
            ['b.click', 'd.live', 'a.online', 'c.com'],
            array_column($sorted, 'domain'),
        );
    }

    public function test_expand_query_strips_protocol_and_www(): void
    {
        $this->assertSame(['example.net'], $this->client->expandQuery('https://www.example.net'));
    }

    public function test_fresh_dynadot_registration_is_treated_as_ns_not_ready(): void
    {
        $this->assertTrue(DynadotClient::isNsNotReadyError(
            'Cannot set nameservers to these domains ( urixen-hu.com : Domain initialization is still in progress. Please try again later.)',
        ));
        $this->assertFalse(DynadotClient::isNsNotReadyError('insufficient account balance'));
    }

    public function test_disabled_dynadot_domain_is_unmanageable(): void
    {
        $this->assertTrue(DynadotClient::isUnmanageableDomainError('haute-mondreve.sbs was disabled'));
        $this->assertTrue(DynadotClient::isUnmanageableDomainError('Domain example.com is disabled'));
        $this->assertTrue(DynadotClient::isUnmanageableDomainError('Домен вимкнено в Dynadot, NS змінити не можна.'));
        $this->assertFalse(DynadotClient::isUnmanageableDomainError('insufficient account balance'));
        $this->assertFalse(DynadotClient::isUnmanageableDomainError('Domain initialization is still in progress'));
    }
}
