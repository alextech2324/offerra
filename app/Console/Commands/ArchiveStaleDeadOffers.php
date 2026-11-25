<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Models\User;
use App\Services\OfferTeardownService;
use App\Services\StaleDeadOfferService;
use Illuminate\Console\Command;

class ArchiveStaleDeadOffers extends Command
{
    protected $signature = 'offers:archive-stale-dead
        {--days=14 : Last brand GSC and last brand lead older than N days}
        {--dry-run : Only list count / sample, do not enqueue}
        {--limit=0 : Max offers to enqueue (0 = all)}';

    protected $description = 'Archive live offers of brands whose last GSC and last lead are older than N days';

    public function handle(StaleDeadOfferService $staleDead, OfferTeardownService $teardown): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = $staleDead->cutoff($days);
        $limit = max(0, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');

        $admin = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('id')
            ->first();

        if (! $admin && ! $dry) {
            $this->error('No admin user for archived_by.');

            return self::FAILURE;
        }

        $idsQuery = $staleDead->baseCandidatesQuery($days)->orderBy('id')->select('id');

        if ($limit > 0) {
            $idsQuery->limit($limit);
        }

        $ids = $idsQuery->pluck('id')->all();
        $total = count($ids);
        $this->info("cutoff={$cutoff->toDateTimeString()} candidates={$total} dry=".($dry ? 'yes' : 'no'));

        if ($total === 0) {
            return self::SUCCESS;
        }

        if ($dry) {
            foreach (array_slice($ids, 0, 5) as $id) {
                $offer = Offer::query()->find($id);
                if ($offer) {
                    $this->line("#{$offer->id} {$offer->domain} submitted={$offer->indexed_at}");
                }
            }

            return self::SUCCESS;
        }

        $enqueued = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($ids as $id) {
            $offer = Offer::query()->find($id);
            if (! $offer) {
                $skipped++;

                continue;
            }

            try {
                $teardown->enqueueArchive($offer, $admin);
                $enqueued++;
                if ($enqueued % 100 === 0) {
                    $this->line("enqueued={$enqueued}");
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'вже архівується') || str_contains($msg, 'в архіві')) {
                    $skipped++;
                } else {
                    $errors++;
                    $this->warn("#{$offer->id} {$offer->domain}: {$msg}");
                }
            }
        }

        $this->info("done enqueued={$enqueued} skipped={$skipped} errors={$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
