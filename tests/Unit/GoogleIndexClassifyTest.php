<?php

namespace Tests\Unit;

use App\Models\Offer;
use App\Services\OfferGoogleIndexInspector;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GoogleIndexClassifyTest extends TestCase
{
    public function test_duplicate_uses_google_canonical_host(): void
    {
        $result = OfferGoogleIndexInspector::classifyInspection('turbo-hiberix-ai.live', [
            'coverage' => 'Duplicate, Google chose different canonical than user',
            'verdict' => 'NEUTRAL',
            'google_canonical' => 'https://turbohiberix-ai.com/',
            'referring_urls' => [],
        ]);

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame('turbohiberix-ai.com', $result['canonical']);
        $this->assertSame('Google індексує turbohiberix-ai.com замість цього', $result['coverage']);
    }

    public function test_self_canonical_is_indexed_even_with_referring_sibling(): void
    {
        $result = [
            'coverage' => 'Duplicate, Google chose different canonical than user',
            'verdict' => 'NEUTRAL',
            'indexing_state' => 'INDEXING_ALLOWED',
            'last_crawl_at' => '2026-10-19T10:26:20Z',
            'indexed' => false,
            'google_canonical' => 'https://immediate-connect.cyou/',
            'referring_urls' => ['https://immediate-connect-it.live/'],
        ];

        $this->assertTrue(OfferGoogleIndexInspector::isIndexedResult('immediate-connect.cyou', $result));
        $this->assertNull(OfferGoogleIndexInspector::chosenHost('immediate-connect.cyou', $result));
    }

    public function test_indexing_allowed_duplicate_counts_as_indexed(): void
    {
        $result = [
            'coverage' => 'Duplicate, Google chose different canonical than user',
            'verdict' => 'NEUTRAL',
            'indexing_state' => 'INDEXING_ALLOWED',
            'last_crawl_at' => '2026-10-12T22:58:52Z',
            'indexed' => false,
            'google_canonical' => 'https://rentelucavie.online/',
            'referring_urls' => [],
        ];

        $this->assertTrue(OfferGoogleIndexInspector::isIndexedResult('rentelucavie-fr.net', $result));
        $this->assertSame('rentelucavie.online', OfferGoogleIndexInspector::chosenHost('rentelucavie-fr.net', $result));
    }

    public function test_referring_urls_do_not_mark_a_5xx_as_duplicate(): void
    {
        $result = [
            'coverage' => 'Server error (5xx)',
            'verdict' => 'NEUTRAL',
            'indexing_state' => 'INDEXING_STATE_UNSPECIFIED',
            'last_crawl_at' => '2026-10-27T11:05:43Z',
            'indexed' => false,
            'google_canonical' => null,
            'referring_urls' => ['https://fr.gridinsoft.com/online-virus-scanner/url/pluris_a-online'],
        ];

        $this->assertFalse(OfferGoogleIndexInspector::isIndexedResult('pluris-a.online', $result));
        $this->assertNull(OfferGoogleIndexInspector::chosenHost('pluris-a.online', $result));
        $classified = OfferGoogleIndexInspector::classifyInspection('pluris-a.online', $result);
        $this->assertSame('not_indexed', $classified['status']);
        $this->assertSame('Server error (5xx)', $classified['coverage']);
    }

    public function test_unknown_url_is_not_a_timeout(): void
    {
        $result = OfferGoogleIndexInspector::classifyInspection('chattelance-uk.com', [
            'coverage' => 'URL is unknown to Google',
            'verdict' => 'NEUTRAL',
            'google_canonical' => null,
            'referring_urls' => [],
        ]);

        $this->assertSame('unknown', $result['status']);
        $this->assertNull($result['canonical']);
        $this->assertSame('Google ще не бачив цей URL', $result['coverage']);
    }

    public function test_fresh_unknown_is_not_a_miss(): void
    {
        $offer = new Offer([
            'google_index_status' => 'unknown',
            'indexed_at' => now()->subDay(),
        ]);

        $this->assertFalse(OfferGoogleIndexInspector::isMiss($offer));
    }

    public function test_week_old_unknown_is_a_miss(): void
    {
        $offer = new Offer([
            'google_index_status' => 'unknown',
            'indexed_at' => now()->subDays(8),
        ]);

        $this->assertTrue(OfferGoogleIndexInspector::isMiss($offer));
    }

    public function test_duplicate_is_a_miss_immediately(): void
    {
        $offer = new Offer([
            'google_index_status' => 'duplicate',
            'indexed_at' => now()->subDay(),
        ]);

        $this->assertTrue(OfferGoogleIndexInspector::isMiss($offer));
    }

    public function test_host_from_url_strips_www(): void
    {
        $this->assertSame('la-piattaforma.org', OfferGoogleIndexInspector::hostFromUrl('https://www.la-piattaforma.org/page'));
        $this->assertSame('girtrust-si.cfd', OfferGoogleIndexInspector::hostFromUrl('girtrust-si.cfd'));
    }

    public function test_next_inspect_at_is_03_after_the_24h_wait(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 18:00:00', 'Europe/Kyiv'));
        $at = app(OfferGoogleIndexInspector::class)->nextInspectAt();
        $this->assertSame('2026-11-19 03:00:00', $at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_next_inspect_at_same_night_when_24h_lands_before_3am(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'Europe/Kyiv'));
        $at = app(OfferGoogleIndexInspector::class)->nextInspectAt();
        $this->assertSame('2026-11-18 03:00:00', $at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }
}
