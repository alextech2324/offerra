<?php

namespace Tests\Unit;

use App\Http\Requests\BulkOfferActionRequest;
use App\Jobs\RebindOfferDnsJob;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class BulkOfferActionRequestTest extends TestCase
{
    public function test_redeploy_accepts_ids(): void
    {
        $validator = Validator::make(
            ['ids' => [1, 2], 'action' => 'redeploy'],
            (new BulkOfferActionRequest)->rules(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_rebind_requires_ipv4(): void
    {
        $request = new BulkOfferActionRequest;
        $validator = Validator::make(
            ['ids' => [1], 'action' => 'rebind_dns'],
            $request->rules(),
            $request->messages(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ip', $validator->errors()->toArray());
    }

    public function test_rebind_accepts_ipv4(): void
    {
        $validator = Validator::make(
            ['ids' => [8], 'action' => 'rebind_dns', 'ip' => '91.224.92.135'],
            (new BulkOfferActionRequest)->rules(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_move_server_requires_target_host(): void
    {
        $request = new BulkOfferActionRequest;
        $validator = Validator::make(
            ['ids' => [1], 'action' => 'move_server'],
            $request->rules(),
            $request->messages(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('target_host', $validator->errors()->toArray());
    }

    public function test_move_server_accepts_target_host(): void
    {
        $validator = Validator::make(
            ['ids' => [8], 'action' => 'move_server', 'target_host' => '84.200.89.234'],
            (new BulkOfferActionRequest)->rules(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_submit_gsc_accepts_ids(): void
    {
        $validator = Validator::make(
            ['ids' => [1, 2], 'action' => 'submit_gsc'],
            (new BulkOfferActionRequest)->rules(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_switch_cloudflare_accepts_spare_pool_id(): void
    {
        $validator = Validator::make(
            ['ids' => [8], 'action' => 'switch_cloudflare', 'cloudflare_target' => 'spare:12'],
            (new BulkOfferActionRequest)->rules(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_switch_edge_accepts_proxy_id(): void
    {
        $validator = Validator::make(
            ['ids' => [8], 'action' => 'switch_edge', 'edge_target' => 'proxy:1'],
            (new BulkOfferActionRequest)->rules(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_switch_edge_accepts_cloudflare(): void
    {
        $validator = Validator::make(
            ['ids' => [8], 'action' => 'switch_edge', 'edge_target' => 'cloudflare'],
            (new BulkOfferActionRequest)->rules(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_check_availability_accepts_ids(): void
    {
        $validator = Validator::make(
            ['ids' => [4, 5], 'action' => 'check_availability'],
            (new BulkOfferActionRequest)->rules(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_dns_job_is_unique_per_offer_and_ip(): void
    {
        $job = new RebindOfferDnsJob(12, '91.224.92.135');

        $this->assertSame('12:91.224.92.135', $job->uniqueId());
        $this->assertSame('deploy', $job->queue);
    }
}
