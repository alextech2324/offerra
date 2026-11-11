<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkOfferActionRequest;
use App\Http\Requests\StoreBulkOffersRequest;
use App\Http\Requests\StoreOfferRequest;
use App\Http\Requests\UpdateOfferRequest;
use App\Jobs\RebindOfferDnsJob;
use App\Jobs\RecheckInfrastructureDnsJob;
use App\Jobs\ScanDownOffersJob;
use App\Jobs\SwitchOfferCloudflareJob;
use App\Models\CloudflareAccount;
use App\Models\Offer;
use App\Models\User;
use App\Services\DeployService;
use App\Services\InfrastructureProvisioner;
use App\Services\OfferAvailabilityProbe;
use App\Services\OfferGenerator;
use App\Services\OfferGoogleIndexInspector;
use App\Services\OfferGscSubmitter;
use App\Services\OfferRestoreService;
use App\Services\OfferTeardownService;
use App\Services\OfferZipExporter;
use App\Services\OriginEvacuationService;
use App\Services\StaleDeadOfferService;
use App\Services\TemplateCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OfferController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 30, 50, 100];

    public function index(DeployService $deploy, OriginEvacuationService $evacuation): Response
    {
        $user = auth()->user();
        $settings = $user->settings;

        $deploy->resetStuckDeploys();

        $deployReady = $deploy->poolReady();
        $today = now();
        $filters = $this->indexFilters($user);
        $baseQuery = $this->offerScopeQuery($user);

        $query = clone $baseQuery;
        $this->applyOfferFilters($query, $filters, $today);

        $offers = $query
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString()
            ->through(fn (Offer $offer) => array_merge($offer->toPanelArray(), [
                'deploy_ready' => $deployReady,
            ]));

        return Inertia::render('Panel/Offers/Index', [
            'offers' => $offers,
            'filters' => $filters,
            'filterOptions' => [
                'geos' => (clone $baseQuery)->reorder()->distinct()->orderBy('geo')->pluck('geo')->values()->all(),
                'langs' => (clone $baseQuery)->reorder()->distinct()->orderBy('lang')->pluck('lang')->values()->all(),
                'templates' => (clone $baseQuery)->reorder()->whereNotNull('template')->where('template', '!=', '')->distinct()->orderBy('template')->pluck('template')->values()->all(),
                'panels' => (clone $baseQuery)->reorder()->whereNotNull('deploy_panel_name')->where('deploy_panel_name', '!=', '')->distinct()->orderBy('deploy_panel_name')->pluck('deploy_panel_name')->values()->all(),
            ],
            'createdCounts' => $this->createdCounts($baseQuery, $today),
            'downCount' => $this->downOffersQuery($user)->count(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'canDeploy' => $deployReady,
            'hasCloudflarePrimary' => (bool) $settings?->hasCloudflareSlot('primary'),
            'cloudflarePrimaryName' => trim((string) ($settings?->cloudflare_account_name ?? '')) ?: 'Основний CF',
            'spareCloudflareAccounts' => $user->isAdmin()
                ? CloudflareAccount::activeForSwitch()->all()
                : [],
            'originServers' => $evacuation->moveTargets(),
            'geoPresets' => config('offerra.geo_presets'),
            'currencies' => config('offerra.currencies'),
            'templates' => app(TemplateCatalog::class)->forPicker(),
            'brandTemplateUsage' => $this->brandTemplateUsage($user),
            'showUserColumn' => $user->canSeeAllOffers(),
            'canManageAllOffers' => $user->canManageAllOffers(),
            'users' => $user->canSeeAllOffers()
                ? User::query()->orderBy('name')->get(['id', 'name', 'email'])
                : [],
            'dateFilters' => [
                'today' => $today->toDateString(),
                'yesterday' => $today->copy()->subDay()->toDateString(),
                'weekStart' => $today->copy()->startOfWeek()->toDateString(),
                'monthStart' => $today->copy()->startOfMonth()->toDateString(),
            ],
        ]);
    }

    /**
     * Active (non-archived) offers: lowercase brand → template_id → usage count.
     * Same visibility as the offers list / brand lookup (admins see every owner).
     *
     * @return array<string, array<string, int>>
     */
    private function brandTemplateUsage(User $user): array
    {
        $query = Offer::query()
            ->whereNotIn('status', ['archived', 'teardown_failed'])
            ->whereNotNull('template')
            ->where('template', '!=', '');

        $this->applyOfferVisibility($query, $user);

        return $this->templateCountsByBrand($query->get(['brand', 'template']));
    }

    /**
     * Restrict to the user's own offers unless they can see everyone's.
     */
    private function applyOfferVisibility(Builder $query, User $user): void
    {
        if (! $user->canSeeAllOffers()) {
            $query->where('user_id', $user->id);
        } elseif (request()->filled('user')) {
            $query->where('user_id', request()->integer('user'));
        }
    }

    /**
     * @param  iterable<Offer>  $rows
     * @return array<string, int>
     */
    private function templateCountsFromOffers(iterable $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $template = trim((string) $row->template);

            if ($template === '') {
                continue;
            }

            $counts[$template] = ($counts[$template] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  iterable<Offer>  $rows
     * @return array<string, array<string, int>>
     */
    private function templateCountsByBrand(iterable $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $brand = mb_strtolower(trim((string) $row->brand));
            $template = trim((string) $row->template);

            if ($brand === '' || $template === '') {
                continue;
            }

            $map[$brand][$template] = ($map[$brand][$template] ?? 0) + 1;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function indexFilters(User $user): array
    {
        $perPage = request()->integer('per_page', 30);

        return [
            'brand' => trim(request()->string('brand')->toString()),
            'domain' => $this->normalizeDomainFilter(request()->string('domain')->toString()),
            'geo' => strtoupper(request()->string('geo')->toString()),
            'lang' => strtolower(request()->string('lang')->toString()),
            'template' => trim(request()->string('template')->toString()),
            'panel' => trim(request()->string('panel')->toString()),
            'indexing' => request()->string('indexing')->toString(),
            'google_index' => request()->string('google_index')->toString(),
            'availability' => request()->string('availability')->toString(),
            'archive_status' => request()->string('archive_status')->toString(),
            'created' => request()->string('created')->toString(),
            'created_from' => request()->string('created_from')->toString(),
            'created_to' => request()->string('created_to')->toString(),
            'user' => $user->canSeeAllOffers() && request()->filled('user')
                ? (string) request()->integer('user')
                : '',
            'per_page' => $this->resolvePerPage($perPage),
            'page' => max(1, request()->integer('page', 1)),
        ];
    }

    private function whereLikeInsensitive(Builder $query, string $column, string $value): void
    {
        $needle = mb_strtolower(trim($value));
        if ($needle === '') {
            return;
        }

        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $needle,
        );

        $query->whereRaw('LOWER('.$column.') LIKE ? ESCAPE ?', ['%'.$escaped.'%', '\\']);
    }

    private function normalizeDomainFilter(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#^www\.#', '', $domain) ?? $domain;

        return rtrim($domain, '/');
    }

    private function resolvePerPage(int $perPage): int
    {
        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 30;
    }

    private function offerScopeQuery(User $user, bool $archived = false): Builder
    {
        $query = Offer::query()
            ->with('user.settings')
            ->orderByDesc($archived ? 'archived_at' : 'created_at');

        if ($archived) {
            $query->whereIn('status', ['archived', 'teardown_failed']);
        } else {
            $query->whereNotIn('status', ['archived', 'teardown_failed']);
        }

        $this->applyOfferVisibility($query, $user);

        return $query;
    }

    /**
     * Deployed offers currently marked red. Manage-all sees every owner; others only own.
     */
    private function downOffersQuery(User $user): Builder
    {
        $query = Offer::query()
            ->where('status', 'deployed')
            ->where('availability_status', 'down');

        if (! $user->canManageAllOffers()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyOfferFilters(Builder $query, array $filters, Carbon $today, string $dateColumn = 'created_at'): void
    {
        if ($filters['brand'] !== '') {
            $this->whereLikeInsensitive($query, 'brand', $filters['brand']);
        }

        if ($filters['domain'] !== '') {
            $this->whereLikeInsensitive($query, 'domain', $filters['domain']);
        }

        if ($filters['geo'] !== '') {
            $query->where('geo', $filters['geo']);
        }

        if ($filters['lang'] !== '') {
            $query->where('lang', $filters['lang']);
        }

        if ($filters['template'] !== '') {
            $query->where('template', $filters['template']);
        }

        if ($filters['panel'] !== '') {
            $panel = $filters['panel'];
            $query->where(function (Builder $builder) use ($panel) {
                $builder->where('deploy_panel_name', $panel)
                    ->orWhere('infra_meta->deploy_host', $panel);
            });
        }

        if ($filters['indexing'] === 'yes') {
            $query->where('submitted_for_indexing', true);
        } elseif ($filters['indexing'] === 'no') {
            $query->where('submitted_for_indexing', false);
        }

        if (($filters['google_index'] ?? '') === 'indexed') {
            $query->whereNotNull('google_indexed_at');
        } elseif (($filters['google_index'] ?? '') === 'pending') {
            OfferGoogleIndexInspector::applyPendingFilter($query);
        } elseif (in_array($filters['google_index'] ?? '', ['not_indexed', 'duplicate', 'timeout', 'unseen'], true)) {
            OfferGoogleIndexInspector::applyMissFilter($query);
        }

        if (($filters['availability'] ?? '') === 'ok') {
            $query->where('availability_status', 'ok');
        } elseif (($filters['availability'] ?? '') === 'down') {
            $query->where('availability_status', 'down');
        } elseif (($filters['availability'] ?? '') === 'unchecked') {
            $query->where(function (Builder $builder): void {
                $builder->whereNull('availability_status')
                    ->orWhere('availability_status', 'unchecked')
                    ->orWhere('availability_status', '');
            });
        }

        if (($filters['archive_status'] ?? '') === 'archived') {
            $query->where('status', 'archived');
        } elseif (($filters['archive_status'] ?? '') === 'teardown_failed') {
            $query->where('status', 'teardown_failed');
        }

        match ($filters['created']) {
            'today' => $query->whereDate($dateColumn, $today),
            'yesterday' => $query->whereDate($dateColumn, $today->copy()->subDay()),
            'week' => $query->whereDate($dateColumn, '>=', $today->copy()->startOfWeek()),
            'month' => $query->whereDate($dateColumn, '>=', $today->copy()->startOfMonth()),
            'custom' => $this->applyCustomDateFilter(
                $query,
                (string) $filters['created_from'],
                (string) $filters['created_to'],
                $dateColumn,
            ),
            default => null,
        };
    }

    private function applyCustomDateFilter(Builder $query, string $from, string $to, string $dateColumn = 'created_at'): void
    {
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $query->whereDate($dateColumn, '>=', $from);
        }

        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $query->whereDate($dateColumn, '<=', $to);
        }
    }

    /**
     * @return array{today: int, yesterday: int, week: int, month: int}
     */
    private function createdCounts(Builder $baseQuery, Carbon $today, string $dateColumn = 'created_at'): array
    {
        $clone = fn (): Builder => clone $baseQuery;

        return [
            'today' => $clone()->whereDate($dateColumn, $today)->count(),
            'yesterday' => $clone()->whereDate($dateColumn, $today->copy()->subDay())->count(),
            'week' => $clone()->whereDate($dateColumn, '>=', $today->copy()->startOfWeek())->count(),
            'month' => $clone()->whereDate($dateColumn, '>=', $today->copy()->startOfMonth())->count(),
        ];
    }

    public function create(TemplateCatalog $catalog): Response
    {
        $settings = auth()->user()->settings;
        $ready = $settings
            && filled($settings->crm_api_key)
            && filled($settings->tg_bot_token);

        return Inertia::render('Panel/Offers/Create', [
            'settingsReady' => $ready,
            'hasDynadotApiKey' => filled($settings?->dynadot_api_key),
            'hasDynadotContactId' => filled($settings?->dynadot_contact_id),
            'domainSearchTlds' => config('offerra.domain_search_tlds', []),
            'affiliateTag' => $settings?->affiliate_tag ?? 'BRO',
            'geoPresets' => config('offerra.geo_presets'),
            'currencies' => config('offerra.currencies'),
            'templates' => $catalog->forWizard(),
            'brandTemplateUsage' => $this->brandTemplateUsage(auth()->user()),
            'fresh' => request()->boolean('fresh'),
            'initialTemplate' => request()->string('template')->toString() ?: null,
            'initialBrand' => request()->string('brand')->toString() ?: null,
            'initialGeo' => request()->string('geo')->toString() ?: null,
            'initialLang' => request()->string('lang')->toString() ?: null,
            'initialFromSearch' => request()->boolean('from_search'),
            'canProvisionInfrastructure' => InfrastructureProvisioner::settingsReady($settings),
        ]);
    }

    public function brandLookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:120'],
        ]);

        $brand = trim($validated['brand']);
        if ($brand === '') {
            return response()->json([
                'brand' => '',
                'active' => 0,
                'archived' => 0,
            ]);
        }

        $user = $request->user();
        $needle = mb_strtolower($brand);

        $activeQuery = Offer::query()
            ->whereNotIn('status', ['archived', 'teardown_failed']);
        $this->applyOfferVisibility($activeQuery, $user);

        $activeRows = $activeQuery
            ->whereRaw('LOWER(TRIM(brand)) = ?', [$needle])
            ->get(['template']);

        $archived = $this->offerScopeQuery($user, archived: true)
            ->whereRaw('LOWER(TRIM(brand)) = ?', [$needle])
            ->count();

        $templates = $this->templateCountsFromOffers($activeRows);

        return response()->json([
            'brand' => $brand,
            'active' => $activeRows->count(),
            'archived' => $archived,
            'templates' => (object) $templates,
        ]);
    }

    public function store(
        StoreOfferRequest $request,
        OfferGenerator $generator,
        InfrastructureProvisioner $provisioner,
    ): RedirectResponse {
        try {
            $result = $generator->generate($request->user(), [
                'brand' => $request->string('brand')->toString(),
                'domain' => strtolower($request->string('domain')->toString()),
                'min_deposit' => $request->string('min_deposit')->toString(),
                'currency' => strtoupper($request->string('currency')->toString()),
                'geo' => strtoupper($request->string('geo')->toString()),
                'lang' => strtolower($request->string('lang')->toString()),
                'phone' => strtolower($request->string('phone')->toString()),
                'phone_countries' => $request->input('phone_countries', []),
                'template' => $request->string('template')->toString(),
                'vitals_enabled' => $request->boolean('vitals_enabled'),
                'from_search_team' => $request->boolean('from_search_team'),
                'infra_cloudflare_zone' => $request->boolean('infra_cloudflare_zone'),
                'infra_cloudflare_dns' => $request->boolean('infra_cloudflare_dns'),
                'infra_dynadot_ns' => $request->boolean('infra_dynadot_ns'),
                'infra_cloudflare_ssl' => $request->boolean('infra_cloudflare_ssl'),
                'infra_cloudflare_https' => $request->boolean('infra_cloudflare_https'),
                'infra_cloudflare_www_redirect' => $request->boolean('infra_cloudflare_www_redirect'),
            ]);
        } catch (\Throwable $e) {
            return redirect()
                ->route('offers.create')
                ->withErrors(['generate' => $this->generateErrorMessage($e)]);
        }

        $message = ! empty($result['already_existed'])
            ? "Оффер вже існує: {$result['folder']}"
            : "Оффер згенеровано: {$result['folder']}";

        if ($result['offer']->provision_infrastructure) {
            $provisioner->enqueue($result['offer']->fresh());
            $message .= ' · інфраструктура запущена у фоні';
        }

        $redirect = redirect()
            ->route('offers.index')
            ->with('success', $message);

        if ($request->boolean('download_zip') && $request->user()?->canDownloadOfferZip()) {
            $redirect->with('zip_offer_id', $result['offer']->id);
        }

        return $redirect;
    }

    public function storeBulk(
        StoreBulkOffersRequest $request,
        OfferGenerator $generator,
        InfrastructureProvisioner $provisioner,
    ): RedirectResponse {
        $shared = [
            'brand' => $request->string('brand')->toString(),
            'min_deposit' => $request->string('min_deposit')->toString(),
            'currency' => strtoupper($request->string('currency')->toString()),
            'geo' => strtoupper($request->string('geo')->toString()),
            'lang' => strtolower($request->string('lang')->toString()),
            'phone' => strtolower($request->string('phone')->toString()),
            'phone_countries' => $request->input('phone_countries', []),
            'vitals_enabled' => $request->boolean('vitals_enabled'),
            'from_search_team' => $request->boolean('from_search_team'),
            'infra_cloudflare_zone' => $request->boolean('infra_cloudflare_zone'),
            'infra_cloudflare_dns' => $request->boolean('infra_cloudflare_dns'),
            'infra_dynadot_ns' => $request->boolean('infra_dynadot_ns'),
            'infra_cloudflare_ssl' => $request->boolean('infra_cloudflare_ssl'),
            'infra_cloudflare_https' => $request->boolean('infra_cloudflare_https'),
            'infra_cloudflare_www_redirect' => $request->boolean('infra_cloudflare_www_redirect'),
        ];

        /** @var list<array{domain: string, template: string}> $items */
        $items = $request->input('items', []);
        $created = [];
        $failed = [];
        $infraQueued = 0;

        foreach ($items as $item) {
            $domain = strtolower((string) ($item['domain'] ?? ''));
            $template = (string) ($item['template'] ?? '');

            try {
                $result = $generator->generate($request->user(), [
                    ...$shared,
                    'domain' => $domain,
                    'template' => $template,
                ]);

                if ($result['offer']->provision_infrastructure) {
                    $provisioner->enqueue($result['offer']->fresh());
                    $infraQueued++;
                }

                $created[] = [
                    'domain' => $domain,
                    'folder' => $result['folder'],
                    'already_existed' => ! empty($result['already_existed']),
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'domain' => $domain,
                    'message' => $this->generateErrorMessage($e),
                ];
            }
        }

        if ($created === [] && $failed !== []) {
            $firstError = $failed[0]['message'] ?? 'Не вдалося згенерувати офери.';

            return redirect()
                ->route('offers.create')
                ->withErrors(['generate' => $firstError]);
        }

        $parts = [];
        $parts[] = 'Згенеровано '.count($created).' з '.count($items).' оферів';

        if ($created !== []) {
            $parts[] = collect($created)
                ->map(static fn (array $row) => $row['domain'].($row['already_existed'] ? ' (вже був)' : ''))
                ->implode(', ');
        }

        if ($failed !== []) {
            $parts[] = 'Помилки: '.collect($failed)
                ->map(static fn (array $row) => "{$row['domain']} — {$row['message']}")
                ->implode('; ');
        }

        if ($infraQueued > 0) {
            $parts[] = "інфраструктура запущена у фоні ({$infraQueued})";
        }

        return redirect()
            ->route('offers.index')
            ->with($failed === [] ? 'success' : 'warning', implode(' · ', $parts));
    }

    public function downloadZip(Offer $offer, OfferZipExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        $this->authorizeZipDownload();

        $offer->loadMissing('user.settings');

        try {
            $built = $exporter->build($offer);
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withErrors(['zip' => $e->getMessage()]);
        }

        return response()
            ->download($built['path'], $built['filename'], [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }

    public function deploy(Offer $offer, DeployService $deploy): RedirectResponse
    {
        $authUser = auth()->user();

        if (! $authUser->canManageOffer($offer)) {
            abort(403);
        }

        $offer->loadMissing('user');

        try {
            $deploy->enqueueDeploy($offer->user, $offer);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()
                ->back()
                ->withErrors(['deploy' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withErrors(['deploy' => 'Деплой не вдався: '.$e->getMessage()]);
        }

        return redirect()
            ->back()
            ->with('success', "Деплой запущено у фоні: {$offer->domain}. Статус оновиться автоматично.");
    }

    public function bulkAction(
        BulkOfferActionRequest $request,
        DeployService $deploy,
        OriginEvacuationService $evacuation,
        OfferGscSubmitter $gsc,
        InfrastructureProvisioner $provisioner,
    ): RedirectResponse {
        $authUser = $request->user();
        $action = $request->string('action')->toString();
        $ip = trim((string) $request->input('ip', ''));
        $cloudflareTarget = $request->string('cloudflare_target')->toString();
        $targetHost = trim((string) $request->input('target_host', ''));

        $query = Offer::query()
            ->with('user.settings')
            ->whereIn('id', $request->input('ids'))
            ->whereNotIn('status', ['archived', 'archiving', 'purging', 'teardown_failed']);

        if (! $authUser->canManageAllOffers()) {
            $query->where('user_id', $authUser->id);
        }

        $offers = $query->get();

        if ($offers->isEmpty()) {
            return redirect()
                ->back()
                ->withErrors(['bulk' => 'Немає доступних офферів серед обраних.']);
        }

        $queued = 0;
        $skipped = 0;
        $failed = [];

        if ($action === 'check_availability') {
            $ids = $offers
                ->where('status', 'deployed')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($ids === []) {
                return redirect()
                    ->back()
                    ->withErrors(['bulk' => 'Немає задеплоєних оферів серед обраних.']);
            }

            ScanDownOffersJob::dispatch(null, $ids);

            return redirect()
                ->back()
                ->with('success', 'Перевірка '.count($ids).' оферів у черзі. Кружечки оновляться самі.');
        }

        if ($action === 'move_server') {
            try {
                $target = $evacuation->movableTarget($targetHost);
            } catch (\InvalidArgumentException $e) {
                return redirect()
                    ->back()
                    ->withErrors(['bulk' => $e->getMessage()]);
            }

            $result = $evacuation->moveOffersTo($offers, $target);
            $queued = (int) $result['queued'];
            $skipped = (int) $result['skipped'];
            if ($skipped > 0) {
                $failed[] = 'вже на '.$target->host;
            }

            $parts = ["Перекид на {$target->host} у черзі: {$queued}"];
            if ($skipped > 0) {
                $parts[] = "пропущено {$skipped}";
            }
            if ($failed !== []) {
                $parts[] = implode('; ', array_slice($failed, 0, 8));
            }

            $level = $queued > 0 ? 'success' : 'warning';

            return redirect()
                ->back()
                ->with($level, implode(' · ', $parts));
        }

        foreach ($offers as $offer) {
            if ($action === 'submit_gsc') {
                if (! $gsc->canSubmit($offer)) {
                    $skipped++;
                    $failed[] = $offer->domain.': ще не готовий до GSC';

                    continue;
                }
                $gsc->queue($offer, force: true);
                $queued++;

                continue;
            }

            if ($action === 'redeploy') {
                try {
                    if (! $offer->user) {
                        $skipped++;
                        $failed[] = $offer->domain.': немає власника';

                        continue;
                    }
                    $deploy->enqueueDeploy($offer->user, $offer);
                    $queued++;
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $skipped++;
                    $failed[] = $offer->domain.': '.$e->getMessage();
                }

                continue;
            }

            if ($action === 'switch_cloudflare') {
                try {
                    if (str_starts_with($cloudflareTarget, 'spare:') && ! $authUser->isAdmin()) {
                        $skipped++;
                        $failed[] = $offer->domain.': запасний CF з пулу може перемикати лише адмін';

                        continue;
                    }
                    $settings = $offer->user?->settings;
                    if ($settings === null) {
                        $skipped++;
                        $failed[] = $offer->domain.': немає власника';

                        continue;
                    }
                    $resolved = $provisioner->resolveCloudflareSwitchTarget($settings, $cloudflareTarget);
                    $targetCreds = $resolved['creds'];
                    $currentAccountId = trim((string) ($offer->cloudflare_account_id ?? ''));
                    $targetAccountId = trim((string) ($targetCreds['account_id'] ?? ''));
                    $sameAccount = $currentAccountId !== ''
                        && $targetAccountId !== ''
                        && hash_equals($currentAccountId, $targetAccountId);

                    if ($sameAccount) {
                        $targetName = trim((string) ($targetCreds['name'] ?? ''));
                        $currentName = trim((string) ($offer->cloudflare_account_name ?? ''));
                        if ($targetName !== '' && $targetName !== $currentName) {
                            $offer->update(['cloudflare_account_name' => $targetName]);
                            $queued++;
                        } else {
                            $skipped++;
                            $failed[] = $offer->domain.': вже на цьому Cloudflare';
                        }

                        continue;
                    }

                    SwitchOfferCloudflareJob::dispatch($offer->id, $cloudflareTarget);
                    $queued++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $failed[] = $offer->domain.': '.$e->getMessage();
                }

                continue;
            }

            try {
                RebindOfferDnsJob::dispatch($offer->id, $ip);
                $queued++;
            } catch (\Throwable $e) {
                $skipped++;
                $failed[] = $offer->domain.': '.$e->getMessage();
            }
        }

        $parts = [];

        if ($action === 'submit_gsc') {
            $parts[] = "Переіндексація в GSC у черзі: {$queued}";
        } elseif ($action === 'redeploy') {
            $parts[] = "Редеплой у черзі: {$queued}";
        } elseif ($action === 'switch_cloudflare') {
            $label = $cloudflareTarget === 'primary'
                ? 'основний CF власника'
                : 'запасний CF';
            $parts[] = "Перехід на {$label} у черзі: {$queued}";
        } else {
            $parts[] = "A-запис → {$ip} у черзі: {$queued}";
        }

        if ($skipped > 0) {
            $parts[] = "пропущено {$skipped}";
        }

        if ($failed !== []) {
            $parts[] = implode('; ', array_slice($failed, 0, 8));
        }

        $level = $queued > 0 ? ($failed === [] ? 'success' : 'warning') : 'warning';

        return redirect()
            ->back()
            ->with($level, implode(' · ', $parts));
    }

    public function provision(
        Offer $offer,
        InfrastructureProvisioner $provisioner,
    ): RedirectResponse {
        $authUser = auth()->user();

        if (! $authUser->canManageOffer($offer)) {
            abort(403);
        }

        $wasDnsRecheck = $offer->dnsStatus() === 'pending' && $offer->infra_status === 'ready';

        $offer->update([
            'provision_infrastructure' => true,
            'infra_status' => $wasDnsRecheck ? 'ready' : 'pending',
            'infra_error' => null,
        ]);

        if ($wasDnsRecheck) {
            RecheckInfrastructureDnsJob::dispatchSync($offer->id);

            return redirect()
                ->back()
                ->with('success', "DNS для {$offer->domain} перевірено.");
        }

        $provisioner->enqueue($offer->fresh());

        return redirect()
            ->back()
            ->with('success', "Інфраструктура для {$offer->domain} запущена у фоні.");
    }

    public function update(
        UpdateOfferRequest $request,
        Offer $offer,
        OfferGenerator $generator,
        DeployService $deploy,
    ): RedirectResponse {
        $autoRedeploy = $request->boolean('auto_redeploy', true);

        try {
            $offer = $generator->updateSettings($offer, [
                'brand' => $request->string('brand')->toString(),
                'geo' => $request->string('geo')->toString(),
                'lang' => $request->string('lang')->toString(),
                'template' => $request->string('template')->toString(),
                'min_deposit' => $request->string('min_deposit')->toString(),
                'currency' => $request->string('currency')->toString(),
                'phone' => $request->string('phone')->toString(),
                'phone_countries' => $request->input('phone_countries', []),
                'vitals_enabled' => $request->boolean('vitals_enabled'),
            ]);
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withErrors(['edit' => $e->getMessage()]);
        }

        $message = 'Оффер оновлено.';

        if ($autoRedeploy) {
            try {
                $deploy->enqueueDeploy($offer->user, $offer->fresh());
                $message .= ' Деплой запущено у фоні.';
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $message .= ' Натисніть «Деплой», щоб застосувати на сервері. ('.$e->getMessage().')';
            }
        } else {
            $message .= ' Натисніть «Деплой», щоб застосувати на сервері.';
        }

        return redirect()
            ->back()
            ->with('success', $message);
    }

    public function updateIndexing(Offer $offer): RedirectResponse
    {
        $user = auth()->user();

        if (! $user->canManageOffer($offer)) {
            abort(403);
        }

        $submitted = request()->boolean('submitted_for_indexing');

        $offer->update([
            'submitted_for_indexing' => $submitted,
            'indexed_at' => $submitted ? ($offer->indexed_at ?? now()) : null,
            ...($submitted ? [] : [
                'google_index_status' => null,
                'google_indexed_at' => null,
                'google_index_checked_at' => null,
                'google_index_coverage' => null,
                'google_index_canonical' => null,
            ]),
        ]);

        return redirect()->back();
    }

    public function scanAvailability(): RedirectResponse
    {
        $user = auth()->user();
        $count = $this->downOffersQuery($user)->count();

        if ($count === 0) {
            return redirect()
                ->back()
                ->with('warning', 'Немає червоних оферів для перевірки.');
        }

        ScanDownOffersJob::dispatch($user->canManageAllOffers() ? null : $user->id);

        return redirect()
            ->back()
            ->with('success', "Перевірка {$count} червоних у черзі. Кружечки оновляться самі.");
    }

    public function checkAvailability(Offer $offer, OfferAvailabilityProbe $probe): RedirectResponse
    {
        $this->authorizeOfferManagement($offer);

        @set_time_limit(30);

        $result = $probe->checkAndUpdate($offer, immediate: true);
        $status = $result['status'];

        $message = match ($status) {
            'ok' => "{$offer->domain}: сайт відкривається",
            'down' => "{$offer->domain}: недоступний".($result['error'] ? ' — '.$result['error'] : ''),
            default => "{$offer->domain}: ще не підтверджено",
        };

        return redirect()
            ->back()
            ->with($status === 'ok' ? 'success' : 'warning', $message);
    }

    public function inspectIndex(Offer $offer, OfferGoogleIndexInspector $inspector): RedirectResponse
    {
        $this->authorizeOfferManagement($offer);

        @set_time_limit(120);

        try {
            $result = $inspector->inspect($offer->fresh() ?? $offer, true);
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->with('error', "{$offer->domain}: ".$e->getMessage());
        }

        $coverage = trim((string) ($result['coverage'] ?? ''));
        $status = (string) ($result['status'] ?? '');
        $indexed = (bool) ($result['indexed'] ?? false);

        if ($indexed) {
            return redirect()
                ->back()
                ->with('success', $offer->domain.': Indexed'.($coverage !== '' ? ' · '.$coverage : ''));
        }

        $canonical = trim((string) ($result['canonical'] ?? ''));
        $message = match ($status) {
            OfferGoogleIndexInspector::STATUS_DUPLICATE => $canonical !== ''
                ? $offer->domain.': Google індексує '.$canonical.' замість цього'
                : $offer->domain.': Google вважає цей URL дублем',
            OfferGoogleIndexInspector::STATUS_UNKNOWN => $offer->domain.': Google ще не бачив цей URL',
            OfferGoogleIndexInspector::STATUS_NOT_INDEXED => $offer->domain.': прокраулено, ще не в індексі',
            OfferGoogleIndexInspector::STATUS_TIMEOUT => $offer->domain.': не в індексі за 7 днів',
            default => $offer->domain.': не вдалося перевірити',
        };

        if ($coverage !== '' && ! str_contains($message, $coverage)) {
            $message .= ' · '.$coverage;
        }

        return redirect()
            ->back()
            ->with($status === OfferGoogleIndexInspector::STATUS_ERROR ? 'error' : 'warning', $message);
    }

    public function submitGsc(Offer $offer, OfferGscSubmitter $gsc): RedirectResponse
    {
        @set_time_limit(120);
        $user = auth()->user();

        if (! $user->canManageOffer($offer)) {
            abort(403);
        }

        try {
            $result = $gsc->submit($offer->fresh() ?? $offer);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()
                ->back()
                ->with('error', "GSC {$offer->domain}: ".OfferGscSubmitter::publicErrorLabel($e->getMessage()));
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->with('error', "GSC {$offer->domain}: ".OfferGscSubmitter::publicErrorLabel($e->getMessage()));
        }

        return redirect()
            ->back()
            ->with(
                'success',
                "GSC: {$offer->domain} додано в Search Console, sitemap відправлено ({$result['sitemap_url']}).",
            );
    }

    public function archiveIndex(DeployService $deploy, StaleDeadOfferService $staleDead): Response
    {
        $user = auth()->user();
        $today = now();
        $filters = $this->indexFilters($user);
        $baseQuery = $this->offerScopeQuery($user, archived: true);

        $query = clone $baseQuery;
        $this->applyOfferFilters($query, $filters, $today, 'archived_at');

        $offers = $query
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page'])
            ->withQueryString()
            ->through(fn (Offer $offer) => $offer->toPanelArray());

        return Inertia::render('Panel/Offers/Archive', [
            'offers' => $offers,
            'filters' => $filters,
            'filterOptions' => [
                'geos' => (clone $baseQuery)->reorder()->distinct()->orderBy('geo')->pluck('geo')->values()->all(),
                'langs' => (clone $baseQuery)->reorder()->distinct()->orderBy('lang')->pluck('lang')->values()->all(),
                'templates' => (clone $baseQuery)->reorder()->whereNotNull('template')->where('template', '!=', '')->distinct()->orderBy('template')->pluck('template')->values()->all(),
                'panels' => (clone $baseQuery)->reorder()->whereNotNull('deploy_panel_name')->where('deploy_panel_name', '!=', '')->distinct()->orderBy('deploy_panel_name')->pluck('deploy_panel_name')->values()->all(),
            ],
            'createdCounts' => $this->createdCounts($baseQuery, $today, 'archived_at'),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'showUserColumn' => $user->canSeeAllOffers(),
            'users' => $user->canSeeAllOffers()
                ? User::query()->orderBy('name')->get(['id', 'name', 'email'])
                : [],
            'dateFilters' => [
                'today' => $today->toDateString(),
                'yesterday' => $today->copy()->subDay()->toDateString(),
                'weekStart' => $today->copy()->startOfWeek()->toDateString(),
                'monthStart' => $today->copy()->startOfMonth()->toDateString(),
            ],
            'staleDeadHint' => $user->canArchiveOffers() ? $staleDead->hintFor($user) : null,
            'canManageAllOffers' => $user->canManageAllOffers(),
        ]);
    }

    public function archiveStaleDead(StaleDeadOfferService $staleDead): RedirectResponse
    {
        $this->authorizeArchive();

        $enqueued = $staleDead->archiveAllFor(auth()->user());

        if ($enqueued === 0) {
            return redirect()
                ->back()
                ->with('success', 'Немає оферів для архівації за цими критеріями.');
        }

        return redirect()
            ->back()
            ->with('success', "Поставлено в чергу архіву: {$enqueued}.");
    }

    public function archive(Offer $offer, OfferTeardownService $teardown): RedirectResponse
    {
        $this->authorizeArchive();

        try {
            $teardown->enqueueArchive($offer, auth()->user());
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withErrors(['archive' => $e->getMessage()]);
        }

        return redirect()
            ->back()
            ->with('success', "Архівація запущена: {$offer->domain}. Origin і Cloudflare будуть прибрані, домен лишиться в Dynadot.");
    }

    public function destroy(Offer $offer, OfferTeardownService $teardown): RedirectResponse
    {
        $this->authorizeOfferManagement($offer);

        $domain = $offer->domain;

        try {
            $teardown->enqueuePurge($offer, auth()->user());
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect()
            ->back()
            ->with('success', "Видалення запущено: {$domain}. Офер зникне після зняття з origin і Cloudflare. Відновити не вийде.");
    }

    public function retryArchive(Offer $offer, OfferTeardownService $teardown): RedirectResponse
    {
        $this->authorizeArchive();

        if ($offer->status !== 'teardown_failed') {
            return redirect()->back()->withErrors(['archive' => 'Повтор доступний лише для офферів з помилкою архівації.']);
        }

        try {
            $teardown->enqueueArchive($offer->fresh(), auth()->user());
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withErrors(['archive' => $e->getMessage()]);
        }

        return redirect()
            ->back()
            ->with('success', "Повтор архівації: {$offer->domain}.");
    }

    public function restore(Offer $offer, OfferRestoreService $restore): RedirectResponse
    {
        $this->authorizeOfferManagement($offer);

        try {
            $restored = $restore->restore($offer->fresh(), auth()->user());
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->withErrors(['archive' => $e->getMessage()]);
        }

        $message = "Оффер повернуто з архіву: {$restored->domain}. Інфраструктура й деплой запущені у фоні.";

        return redirect()
            ->back()
            ->with('success', $message);
    }

    private function generateErrorMessage(\Throwable $e): string
    {
        $message = $e->getMessage();

        if ($e instanceof UniqueConstraintViolationException
            || str_contains($message, 'offers_folder_unique')
            || str_contains($message, 'duplicate key value')
        ) {
            return 'Оффер з такою папкою вже існує. Спробуйте створити ще раз — буде підібрано унікальну назву.';
        }

        return $message;
    }

    private function authorizeOfferManagement(Offer $offer): void
    {
        $user = auth()->user();

        if (! $user->canManageOffer($offer)) {
            abort(403);
        }
    }

    private function authorizeArchive(): void
    {
        if (! auth()->user()?->canArchiveOffers()) {
            abort(403);
        }
    }

    private function authorizeZipDownload(): void
    {
        if (! auth()->user()?->canDownloadOfferZip()) {
            abort(403);
        }
    }
}
