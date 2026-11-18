<?php

namespace Tests\Unit;

use App\Jobs\DeployOfferJob;
use App\Jobs\InspectOfferGoogleIndexJob;
use App\Jobs\ProbeOfferAvailabilityJob;
use App\Jobs\PurgeOfferFromOriginJob;
use App\Jobs\PushOfferConfigJob;
use App\Jobs\RecheckInfrastructureDnsJob;
use App\Jobs\ScanDownOffersJob;
use App\Jobs\SubmitOfferToGscJob;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackgroundJobQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_mass_background_jobs_do_not_use_the_deploy_queue(): void
    {
        $this->assertSame('default', (new PushOfferConfigJob(1))->queue);
        $this->assertSame('default', (new InspectOfferGoogleIndexJob(1))->queue);
        $this->assertSame('gsc', (new SubmitOfferToGscJob(1))->queue);
        $this->assertSame('gsc-submit:1', (new SubmitOfferToGscJob(1))->uniqueId());
        $this->assertSame('gsc-resubmit:1', (new SubmitOfferToGscJob(1, true))->uniqueId());
        $this->assertSame('gsc', (new ProbeOfferAvailabilityJob(1))->queue);
        $this->assertSame('gsc', (new ScanDownOffersJob)->queue);
        $this->assertSame('deploy', (new DeployOfferJob(1))->queue);
        $this->assertSame('dns', (new RecheckInfrastructureDnsJob(1))->queue);
        $this->assertSame('purge', (new PurgeOfferFromOriginJob(1, '10.0.0.1'))->queue);
    }

    public function test_refresh_config_queue_push_stays_off_deploy(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->settings()->create([]);
        $offer = Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'queue-split.online',
            'brand' => 'Queue Split',
            'domain' => 'queue-split.online',
            'geo' => 'IT',
            'lang' => 'it',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
        ]);

        $this->artisan('offers:refresh-config', [
            'offer' => $offer->id,
            '--queue-push' => true,
        ])->assertSuccessful();

        Queue::assertPushedOn('default', PushOfferConfigJob::class);
        Queue::assertNotPushed(PushOfferConfigJob::class, function (PushOfferConfigJob $job) {
            return $job->queue === 'deploy';
        });
    }

    public function test_dns_recheck_command_moves_unreserved_jobs_to_dns(): void
    {
        $now = time();
        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => 'App\\Jobs\\RecheckInfrastructureDnsJob leftover',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ],
            [
                'queue' => 'deploy',
                'payload' => 'App\\Jobs\\RecheckInfrastructureDnsJob leftover-deploy',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ],
            [
                'queue' => 'deploy',
                'payload' => 'App\\Jobs\\RecheckInfrastructureDnsJob running',
                'attempts' => 1,
                'reserved_at' => $now,
                'available_at' => $now,
                'created_at' => $now,
            ],
        ]);

        $this->artisan('offers:recheck-infra-dns')
            ->expectsOutputToContain('moved DNS rechecks to dns queue: 2')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('jobs')->where('queue', 'dns')->whereNull('reserved_at')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'deploy')->whereNotNull('reserved_at')->count());
    }

    public function test_dns_recheck_command_queues_pending_offers_on_dns(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'pending-dns.test',
            'brand' => 'PendingDns',
            'domain' => 'pending-dns.test',
            'geo' => 'ZA',
            'lang' => 'en',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'availability_status' => 'unchecked',
            'infra_meta' => [
                'dns' => 'pending',
                'dynadot_ns' => 'done',
            ],
        ]);

        $this->artisan('offers:recheck-infra-dns')->assertSuccessful();

        Queue::assertPushedOn('dns', RecheckInfrastructureDnsJob::class);
    }

    public function test_pending_gsc_command_moves_jobs_and_queues_live_offers(): void
    {
        Queue::fake();
        config(['services.google.client_id' => 'test-client']);

        $now = time();
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => 'App\\Jobs\\SubmitOfferToGscJob leftover',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);

        $user = User::factory()->create();
        $user->settings()->create([
            'google_oauth_refresh_token' => 'refresh-token',
            'gsc_verification_filename' => 'googleabc123.html',
        ]);
        Offer::query()->create([
            'user_id' => $user->id,
            'folder' => 'pending-gsc.test',
            'brand' => 'PendingGsc',
            'domain' => 'pending-gsc.test',
            'geo' => 'ZA',
            'lang' => 'en',
            'template' => 'default',
            'status' => 'deployed',
            'from_search_team' => false,
            'provision_infrastructure' => true,
            'infra_status' => 'ready',
            'availability_status' => 'ok',
            'infra_meta' => [
                'dns' => 'done',
            ],
        ]);

        $this->artisan('offers:queue-gsc-pending')
            ->expectsOutputToContain('moved GSC jobs to gsc queue: 1')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->where('queue', 'gsc')->count());
        Queue::assertPushedOn('gsc', SubmitOfferToGscJob::class);
    }
}
