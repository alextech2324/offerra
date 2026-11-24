<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\CloudflareClient;
use App\Services\DynadotClient;
use App\Services\DeployService;
use App\Services\GoogleOAuthService;
use App\Services\OfferVerificationFileService;
use App\Services\SalesPostbackService;
use App\Support\SecretValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(SalesPostbackService $postbacks): Response
    {
        $authUser = auth()->user();
        $targetUser = $this->resolveSettingsUser($authUser);
        $settings = $targetUser->settings()->firstOrCreate([]);

        $settingsPayload = $settings->toEditArray();
        unset($settingsPayload['sales_postback_token']);

        if ($authUser->isAdmin()) {
            $settingsPayload['sales_postback_url'] = $postbacks->postbackUrl($settings);
            $settingsPayload['lead_postback_url'] = $postbacks->leadPostbackUrl($settings);
        }

        return Inertia::render('Panel/Settings/Index', [
            'settings' => $settingsPayload,
            'settingsUser' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'is_self' => $targetUser->id === $authUser->id,
            ],
            'users' => $authUser->isAdmin()
                ? User::query()->orderBy('name')->get(['id', 'name', 'email'])
                : [],
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $authUser = $request->user();
        $targetUser = $this->resolveSettingsUser($authUser, $request->integer('user_id') ?: null);

        $settings = $targetUser->settings()->firstOrCreate([]);
        $data = $request->validated();

        $settings->fill([
            'affiliate_tag' => $data['affiliate_tag'] ?? $settings->affiliate_tag ?? 'BRO',
            'tg_chat_id' => $data['tg_chat_id'] ?? $settings->tg_chat_id,
            'tg_group_chat_id' => $data['tg_group_chat_id'] ?? $settings->tg_group_chat_id,
            'dynadot_contact_id' => DynadotClient::normalizeContactId(
                (string) ($data['dynadot_contact_id'] ?? $settings->dynadot_contact_id ?? ''),
            ) ?: null,
            'dynadot_sandbox' => false,
            'dynadot_default_years' => 1,
            'dynadot_account_name' => trim((string) ($data['dynadot_account_name'] ?? '')) ?: null,
            'cloudflare_account_id' => $data['cloudflare_account_id'] ?? $settings->cloudflare_account_id,
            'cloudflare_default_proxied' => $request->boolean('cloudflare_default_proxied'),
            'cloudflare_account_name' => trim((string) ($data['cloudflare_account_name'] ?? '')) ?: null,
        ]);

        $this->assignSecret($settings, 'crm_api_key', $data['crm_api_key'] ?? null);
        $this->assignSecret($settings, 'tg_bot_token', $data['tg_bot_token'] ?? null);
        $this->assignSecret($settings, 'dynadot_api_key', $data['dynadot_api_key'] ?? null);
        $this->assignSecret($settings, 'dynadot_api_secret', $data['dynadot_api_secret'] ?? null);
        $this->assignSecret(
            $settings,
            'cloudflare_api_token',
            CloudflareClient::normalizeApiToken($data['cloudflare_api_token'] ?? null),
        );

        $settings->save();

        $redirect = redirect()->route('settings.index');

        if ($authUser->isAdmin() && $targetUser->id !== $authUser->id) {
            $redirect = redirect()->route('settings.index', ['user' => $targetUser->id]);
        }

        return $redirect->with('success', 'Налаштування збережено');
    }

    public function testCloudflare(UpdateSettingsRequest $request, CloudflareClient $cloudflare): JsonResponse
    {
        $authUser = $request->user();
        $targetUser = $this->resolveSettingsUser($authUser, $request->integer('user_id') ?: null);
        $settings = $targetUser->settings;
        $data = $request->validated();
        $slot = ($data['cloudflare_slot'] ?? 'primary') === 'backup' ? 'backup' : 'primary';

        if (! $settings) {
            return response()->json([
                'ok' => false,
                'message' => 'Спочатку збережіть налаштування.',
            ]);
        }

        if ($slot === 'backup') {
            $token = CloudflareClient::normalizeApiToken(
                $data['cloudflare_backup_api_token'] ?? $settings->cloudflare_backup_api_token,
            );
            $accountId = trim((string) (
                $data['cloudflare_backup_account_id'] ?? $settings->cloudflare_backup_account_id ?? ''
            ));
        } else {
            $token = CloudflareClient::normalizeApiToken(
                $data['cloudflare_api_token'] ?? $settings->cloudflare_api_token,
            );
            $accountId = trim((string) (
                $data['cloudflare_account_id'] ?? $settings->cloudflare_account_id ?? ''
            ));
        }

        if ($token === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Вкажіть Cloudflare API token (або збережіть його в налаштуваннях).',
            ]);
        }

        $probe = $settings->replicate();
        $probe->cloudflare_api_token = $token;
        $probe->cloudflare_account_id = $accountId !== '' ? $accountId : null;

        return response()->json($cloudflare->testConnection($probe));
    }

    public function storeGscVerification(
        UpdateSettingsRequest $request,
        OfferVerificationFileService $verification,
        DeployService $deploy,
    ): RedirectResponse {
        $authUser = $request->user();
        $targetUser = $this->resolveSettingsUser($authUser, $request->integer('user_id') ?: null);
        $settings = $targetUser->settings()->firstOrCreate([]);

        $request->validate([
            'verification_file' => ['required', 'file', 'max:64'],
        ]);

        try {
            $filename = $verification->storeForUser($settings, $request->file('verification_file'));
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->back()
                ->withErrors(['gsc_verification' => $e->getMessage()]);
        }

        $queued = $deploy->queueVerificationPushForUser($targetUser);

        $redirect = redirect()->route('settings.index');

        if ($authUser->isAdmin() && $targetUser->id !== $authUser->id) {
            $redirect = redirect()->route('settings.index', ['user' => $targetUser->id]);
        }

        $message = "Файл GSC збережено: {$filename}";
        if ($queued > 0) {
            $message .= ". Заливаємо на {$queued} живих оферів без GSC.";
        }

        return $redirect->with('success', $message);
    }

    public function destroyGscVerification(
        UpdateSettingsRequest $request,
        OfferVerificationFileService $verification,
    ): RedirectResponse {
        $authUser = $request->user();
        $targetUser = $this->resolveSettingsUser($authUser, $request->integer('user_id') ?: null);
        $settings = $targetUser->settings;

        if ($settings && filled($settings->gsc_verification_filename)) {
            $verification->deleteForUser($settings);
        }

        $redirect = redirect()->route('settings.index');

        if ($authUser->isAdmin() && $targetUser->id !== $authUser->id) {
            $redirect = redirect()->route('settings.index', ['user' => $targetUser->id]);
        }

        return $redirect->with('success', 'Файл GSC видалено');
    }

    public function redirectGoogle(Request $request, GoogleOAuthService $google): RedirectResponse
    {
        $authUser = $request->user();
        $targetUser = $this->resolveSettingsUser($authUser, $request->integer('user_id') ?: null);

        if (! $google->isConfigured()) {
            return redirect()
                ->route('settings.index', $authUser->isAdmin() && $targetUser->id !== $authUser->id ? ['user' => $targetUser->id] : [])
                ->withErrors(['google_oauth' => 'Google OAuth ще не налаштований на сервері (CLIENT_ID/SECRET).']);
        }

        $url = $google->authorizationUrl([
            'user_id' => $targetUser->id,
            'by' => $authUser->id,
            'nonce' => bin2hex(random_bytes(8)),
            'ts' => time(),
        ]);

        return redirect()->away($url);
    }

    public function callbackGoogle(Request $request, GoogleOAuthService $google): RedirectResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return redirect()->route('login');
        }

        if ($request->filled('error')) {
            return redirect()
                ->route('settings.index')
                ->withErrors(['google_oauth' => 'Google відхилив доступ: '.$request->string('error')]);
        }

        $code = trim((string) $request->query('code', ''));
        $stateRaw = (string) $request->query('state', '');
        if ($code === '' || $stateRaw === '') {
            return redirect()
                ->route('settings.index')
                ->withErrors(['google_oauth' => 'Неповна відповідь від Google OAuth.']);
        }

        try {
            $state = decrypt($stateRaw);
        } catch (\Throwable) {
            return redirect()
                ->route('settings.index')
                ->withErrors(['google_oauth' => 'Невірний OAuth state.']);
        }

        if (! is_array($state) || empty($state['user_id'])) {
            return redirect()
                ->route('settings.index')
                ->withErrors(['google_oauth' => 'Пошкоджений OAuth state.']);
        }

        $targetUser = $this->resolveSettingsUser($authUser, (int) $state['user_id']);
        if ((int) ($state['user_id'] ?? 0) !== $targetUser->id) {
            return redirect()
                ->route('settings.index')
                ->withErrors(['google_oauth' => 'Немає доступу до цього акаунта Settings.']);
        }

        try {
            $tokens = $google->exchangeCode($code);
        } catch (\Throwable $e) {
            return redirect()
                ->route('settings.index', $authUser->isAdmin() && $targetUser->id !== $authUser->id ? ['user' => $targetUser->id] : [])
                ->withErrors(['google_oauth' => $e->getMessage()]);
        }

        $settings = $targetUser->settings()->firstOrCreate([]);
        $email = strtolower(trim((string) ($tokens['email'] ?? '')));
        if ($email === '') {
            return redirect()
                ->route('settings.index', $authUser->isAdmin() && $targetUser->id !== $authUser->id ? ['user' => $targetUser->id] : [])
                ->withErrors(['google_oauth' => 'Google не повернув email акаунта.']);
        }

        $existing = GoogleAccount::query()
            ->where('user_id', $targetUser->id)
            ->where('email', $email)
            ->first();

        $refresh = $tokens['refresh_token'] ?: $existing?->refresh_token;
        if (! filled($refresh)) {
            return redirect()
                ->route('settings.index', $authUser->isAdmin() && $targetUser->id !== $authUser->id ? ['user' => $targetUser->id] : [])
                ->withErrors(['google_oauth' => 'Google не повернув refresh token. Відключіть доступ Offerra в акаунті Google і підключіть знову.']);
        }

        $isFirst = ! GoogleAccount::query()->where('user_id', $targetUser->id)->exists();

        if ($existing) {
            $existing->forceFill([
                'refresh_token' => $refresh,
                'connected_at' => now(),
            ])->save();
        } else {
            GoogleAccount::query()->create([
                'user_id' => $targetUser->id,
                'email' => $email,
                'refresh_token' => $refresh,
                'is_primary' => $isFirst,
                'connected_at' => now(),
            ]);
        }

        $settings->syncPrimaryGoogleMirror();

        $redirect = redirect()->route('settings.index');
        if ($authUser->isAdmin() && $targetUser->id !== $authUser->id) {
            $redirect = redirect()->route('settings.index', ['user' => $targetUser->id]);
        }

        $label = $email;

        return $redirect->with('success', $existing
            ? "Google оновлено: {$label}"
            : "Google підключено: {$label}");
    }

    public function setPrimaryGoogle(Request $request, GoogleAccount $googleAccount): RedirectResponse
    {
        $authUser = $request->user();
        $targetUser = $this->resolveSettingsUser($authUser, $request->integer('user_id') ?: null);

        if ($googleAccount->user_id !== $targetUser->id) {
            abort(403);
        }

        $googleAccount->makePrimary();
        $targetUser->settings()->firstOrCreate([])->syncPrimaryGoogleMirror();

        $redirect = redirect()->route('settings.index');
        if ($authUser->isAdmin() && $targetUser->id !== $authUser->id) {
            $redirect = redirect()->route('settings.index', ['user' => $targetUser->id]);
        }

        return $redirect->with('success', "Головний Google: {$googleAccount->email}");
    }

    public function disconnectGoogle(Request $request, GoogleAccount $googleAccount): RedirectResponse
    {
        $authUser = $request->user();
        $targetUser = $this->resolveSettingsUser($authUser, $request->integer('user_id') ?: null);

        if ($googleAccount->user_id !== $targetUser->id) {
            abort(403);
        }

        $label = $googleAccount->email;
        $googleAccount->promoteReplacementIfPrimary();
        $googleAccount->delete();

        $settings = $targetUser->settings;
        $settings?->syncPrimaryGoogleMirror();

        $redirect = redirect()->route('settings.index');
        if ($authUser->isAdmin() && $targetUser->id !== $authUser->id) {
            $redirect = redirect()->route('settings.index', ['user' => $targetUser->id]);
        }

        return $redirect->with('success', "Google відключено: {$label}");
    }

    private function resolveSettingsUser(User $authUser, ?int $userId = null): User
    {
        if ($authUser->isAdmin()) {
            $selectedId = $userId ?? request()->integer('user');

            if ($selectedId > 0) {
                return User::query()->findOrFail($selectedId);
            }
        }

        return $authUser;
    }

    private function assignSecret(UserSetting $settings, string $field, ?string $value): void
    {
        $normalized = SecretValue::normalize((string) $value);

        $settings->{$field} = $normalized === '' ? null : $normalized;
    }
}
