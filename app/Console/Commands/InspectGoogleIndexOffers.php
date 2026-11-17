<?php

namespace App\Console\Commands;

use App\Jobs\InspectOfferGoogleIndexJob;
use App\Services\OfferGoogleIndexInspector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class InspectGoogleIndexOffers extends Command
{
    protected $signature = 'offers:inspect-google-index
        {--limit=1500 : Max offers to queue this run}
        {--user=* : Restrict to user name or id (repeatable or comma-separated)}
        {--only= : Restrict to google_index_status (timeout)}
        {--force : Recheck even if a unique lock from a recent inspect is still held}
        {--dry-run : List due offers, do not inspect}';

    protected $description = 'Inspect GSC index status for submitted offers (scheduled 03:00 Kyiv; 24h after submit, then daily until indexed / duplicate / 7d unseen)';

    public function handle(OfferGoogleIndexInspector $inspector): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $only = strtolower(trim((string) $this->option('only')));
        if ($only !== '' && $only !== 'timeout') {
            $this->error('Unknown --only='.$only.' (supported: timeout)');

            return self::FAILURE;
        }

        $query = $only === 'timeout'
            ? $inspector->timeoutQuery()->orderBy('offers.indexed_at')
            : $inspector->dueQuery()->orderBy('offers.indexed_at');
        $this->restrictToUsers($query);

        $ids = $query
            ->limit($limit)
            ->pluck('offers.id')
            ->all();

        $this->info(
            'due='.count($ids)
            .' dry='.($dry ? 'yes' : 'no')
            .' force='.($force ? 'yes' : 'no')
            .($only !== '' ? ' only='.$only : '')
            .$this->userFilterLabel()
        );

        if ($dry || $ids === []) {
            return self::SUCCESS;
        }

        $queued = 0;
        foreach ($ids as $id) {
            InspectOfferGoogleIndexJob::dispatch((int) $id, $force);
            $queued++;
        }

        $this->info("queued={$queued}");

        return self::SUCCESS;
    }

    private function restrictToUsers(Builder $query): void
    {
        $tokens = collect((array) $this->option('user'))
            ->flatMap(fn ($value) => preg_split('/\s*,\s*/', trim((string) $value)) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $ids = $tokens->filter(fn ($token) => ctype_digit($token))->map(fn ($token) => (int) $token)->all();
        $names = $tokens->reject(fn ($token) => ctype_digit($token))->map(fn ($token) => strtolower($token))->all();

        $query->whereHas('user', function (Builder $builder) use ($ids, $names): void {
            $builder->where(function (Builder $inner) use ($ids, $names): void {
                if ($ids !== []) {
                    $inner->orWhereIn('users.id', $ids);
                }
                if ($names !== []) {
                    $inner->orWhereRaw('LOWER(users.name) in ('.$this->placeholders($names).')', $names);
                }
            });
        });
    }

    /**
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private function userFilterLabel(): string
    {
        $tokens = collect((array) $this->option('user'))
            ->flatMap(fn ($value) => preg_split('/\s*,\s*/', trim((string) $value)) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter();

        return $tokens->isEmpty() ? '' : ' users='.$tokens->implode(',');
    }
}
