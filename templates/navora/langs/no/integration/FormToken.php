<?php
declare(strict_types=1);

/**
 * One-time form tokens (file-backed) — easy to debug under integration/tokens/.
 * CSRF token for the lead form.
 *
 * Extra layers: UA bot block, per-IP rate limits, min age before consume.
 * Soft rejects are meant to look like success to the client (see send.php).
 */
final class FormToken
{
    private const TOKEN_DIR_NAME = 'tokens';
    private const RATE_DIR_NAME = 'rate';
    private const EXPIRED_DIR_NAME = 'expired';

    /** How long a consumed expired-token id stays remembered. */
    private const EXPIRED_MARK_TTL = 86400;

    /**
     * Issue a signed one-time token. Bot UA / issue-rate still get a token,
     * but marked drop=true so send.php can fake success without CRM.
     */
    public static function issue(): string
    {
        self::ensureStorage();
        self::gc();

        self::rememberChatGptHit();

        $drop = self::looksLikeBotUa() || self::rateExceeded('issue', self::issueLimit());
        self::hitRate('issue');

        $id = bin2hex(random_bytes(16));
        $now = time();
        $exp = $now + self::ttl();
        $payload = $id.'.'.$exp;
        $sig = hash_hmac('sha256', $payload, self::secret());
        $token = $payload.'.'.$sig;

        $meta = [
            'id' => $id,
            'exp' => $exp,
            'created' => $now,
            'created_at' => date('c'),
            'ip' => self::clientIp(),
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
            'drop' => $drop,
        ];

        $written = @file_put_contents(
            self::tokenPath($id),
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX,
        );

        if ($written === false) {
            self::debugLog('issue_write_failed', $id);
        } elseif ($drop) {
            self::debugLog('issue_marked_drop', $id);
        }

        return $token;
    }

    /**
     * @return array{ok: bool, drop?: bool, error?: string}
     */
    public static function consume(string $token): array
    {
        $token = trim($token);

        if ($token === '' || ! preg_match('/^[a-f0-9]{32}\.\d+\.[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'error' => 'malformed'];
        }

        [$id, $expRaw, $sig] = explode('.', $token, 3);
        $exp = (int) $expRaw;
        $payload = $id.'.'.$expRaw;
        $expected = hash_hmac('sha256', $payload, self::secret());

        if (! hash_equals($expected, $sig)) {
            self::debugLog('bad_signature', $id);

            return ['ok' => false, 'error' => 'bad_signature'];
        }

        if ($exp < time()) {
            self::deleteTokenFile($id);

            // First sighting stays FORM_TOKEN_EXPIRED so a slow reader is still
            // visible in spam. A later POST of the same id is a replay.
            if (! self::claimFirstExpired($id)) {
                self::debugLog('expired_replay', $id);

                return ['ok' => false, 'error' => 'missing_or_used'];
            }

            self::debugLog('expired', $id);

            return ['ok' => false, 'error' => 'expired'];
        }

        $path = self::tokenPath($id);

        if (! is_file($path)) {
            self::debugLog('missing_or_used', $id);

            return ['ok' => false, 'error' => 'missing_or_used'];
        }

        $raw = @file_get_contents($path);
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($meta)) {
            @unlink($path);
            self::debugLog('bad_meta', $id);

            return ['ok' => false, 'error' => 'bad_meta'];
        }

        $created = (int) ($meta['created'] ?? 0);
        if ($created <= 0 && isset($meta['created_at'])) {
            $created = (int) strtotime((string) $meta['created_at']);
        }

        $minAge = self::minAge();
        if ($created > 0 && (time() - $created) < $minAge) {
            // Burn token so they cannot retry instantly with the same one.
            @unlink($path);
            self::debugLog('too_fast', $id);

            return ['ok' => true, 'drop' => true, 'error' => 'too_fast'];
        }

        $drop = ! empty($meta['drop']) || self::looksLikeBotUa();

        if (! @unlink($path)) {
            self::debugLog('unlink_failed', $id);

            return ['ok' => false, 'error' => 'consume_failed'];
        }

        if ($drop) {
            self::debugLog('consumed_drop', $id);

            return ['ok' => true, 'drop' => true];
        }

        self::debugLog('consumed', $id);

        return ['ok' => true, 'drop' => false];
    }

    public static function looksLikeBotUa(): bool
    {
        if (function_exists('offer_is_preview') && offer_is_preview()) {
            return false;
        }

        $ua = strtolower(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        if ($ua === '') {
            return true;
        }

        $needles = [
            'bot', 'spider', 'crawler', 'crawl', 'slurp',
            'curl/', 'wget', 'python-requests', 'python-urllib', 'scrapy',
            'httpclient', 'libwww', 'go-http-client', 'java/',
            'reparser', 'serpstat', 'semrush', 'ahrefs', 'dotbot',
            'petalbot', 'bytespider', 'gptbot', 'claudebot', 'anthropic',
            'chatgpt', 'ccbot', 'dataforseo', 'headlesschrome', 'phantomjs',
            'puppeteer', 'selenium',
        ];

        foreach ($needles as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function submitRateExceeded(): bool
    {
        return self::rateExceeded('submit', self::submitLimit());
    }

    public static function hitSubmitRate(): void
    {
        self::hitRate('submit');
    }

    /**
     * Client-facing “success” without CRM / Telegram.
     *
     * @return array{ok: bool, crm_success: bool, lead_uuid: null, telegram_sent: bool, thank_you_url: string}
     */
    public static function silentSuccessPayload(): array
    {
        return [
            'ok' => true,
            'crm_success' => true,
            'lead_uuid' => null,
            'telegram_sent' => true,
            'thank_you_url' => defined('FORM_THANK_YOU') ? (string) FORM_THANK_YOU : 'Thanks.php',
        ];
    }

    public static function requestViaCloudflare(): bool
    {
        // Offerra preview / local — без CF edge.
        if (function_exists('offer_is_preview') && offer_is_preview()) {
            return true;
        }

        $cfIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));

        return $cfIp !== '';
    }

    public static function requestLooksSameOrigin(): bool
    {
        if (function_exists('offer_is_preview') && offer_is_preview()) {
            return true;
        }

        $host = strtolower((string) (parse_url((string) SITE_URL, PHP_URL_HOST) ?: ''));

        if ($host === '') {
            return false;
        }

        $allowed = [$host, 'www.'.$host];

        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '') {
            $originHost = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));

            return $originHost !== '' && in_array($originHost, $allowed, true);
        }

        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $refHost = strtolower((string) (parse_url($referer, PHP_URL_HOST) ?: ''));

            return $refHost !== '' && in_array($refHost, $allowed, true);
        }

        // fetch/XHR same-origin always sends Origin; empty = likely raw curl.
        return false;
    }

    public static function clientIp(): string
    {
        $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '') {
            return $cf;
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * ChatGPT appends ?utm_source=chatgpt.com when it cites a lander.
     * Cookie + IP mark keep the second submit (URL already stripped) silent too.
     */
    public static function requestFromChatGpt(): bool
    {
        if (self::hasChatGptMark()) {
            return true;
        }

        if (! self::currentRequestLooksLikeChatGpt()) {
            return false;
        }

        self::rememberChatGptHit();

        return true;
    }

    public static function rememberChatGptHit(): void
    {
        if (! self::currentRequestLooksLikeChatGpt() && ! self::hasChatGptMark()) {
            return;
        }

        if (! headers_sent()) {
            setcookie('ofr_src', 'chatgpt', [
                'expires' => time() + 86400,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['ofr_src'] = 'chatgpt';
        self::writeChatGptFileMark();
    }

    public static function urlLooksLikeChatGpt(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === 'chatgpt.com' || $host === 'chat.openai.com' || str_ends_with($host, '.chatgpt.com')) {
            return true;
        }

        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return false;
        }

        $params = [];
        parse_str($query, $params);
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            $value = strtolower(trim((string) ($params[$key] ?? '')));
            if ($value !== '' && (str_contains($value, 'chatgpt') || str_contains($value, 'openai'))) {
                return true;
            }
        }

        return false;
    }

    private static function currentRequestLooksLikeChatGpt(): bool
    {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '' && self::urlLooksLikeChatGpt($referer)) {
            return true;
        }

        $uri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if ($uri !== '' && self::urlLooksLikeChatGpt('https://local.invalid'.$uri)) {
            return true;
        }

        return false;
    }

    private static function hasChatGptMark(): bool
    {
        if (strtolower(trim((string) ($_COOKIE['ofr_src'] ?? ''))) === 'chatgpt') {
            return true;
        }

        $path = self::chatGptMarkPath();
        if (! is_file($path)) {
            return false;
        }

        $mtime = @filemtime($path);
        if ($mtime === false || (time() - $mtime) > 86400) {
            @unlink($path);

            return false;
        }

        return true;
    }

    private static function writeChatGptFileMark(): void
    {
        self::ensureStorage();
        $payload = [
            'ip' => self::clientIp(),
            'kind' => 'chatgpt',
            'updated_at' => date('c'),
        ];
        @file_put_contents(
            self::chatGptMarkPath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX,
        );
    }

    private static function chatGptMarkPath(): string
    {
        return self::rateDir().DIRECTORY_SEPARATOR.hash('sha256', 'chatgpt|'.self::clientIp()).'.json';
    }

    public static function minAge(): int
    {
        if (defined('FORM_TOKEN_MIN_AGE')) {
            $v = (int) FORM_TOKEN_MIN_AGE;

            return $v >= 0 ? $v : 3;
        }

        return 3;
    }

    private static function issueLimit(): int
    {
        if (defined('FORM_TOKEN_ISSUE_LIMIT')) {
            $v = (int) FORM_TOKEN_ISSUE_LIMIT;

            return $v > 0 ? $v : 8;
        }

        return 8;
    }

    private static function submitLimit(): int
    {
        if (defined('FORM_TOKEN_SUBMIT_LIMIT')) {
            $v = (int) FORM_TOKEN_SUBMIT_LIMIT;

            return $v > 0 ? $v : 3;
        }

        return 3;
    }

    private static function rateWindow(): int
    {
        if (defined('FORM_TOKEN_RATE_WINDOW')) {
            $v = (int) FORM_TOKEN_RATE_WINDOW;

            return $v > 0 ? $v : 600;
        }

        return 600;
    }

    private static function rateExceeded(string $kind, int $limit): bool
    {
        $data = self::readRate($kind);
        $now = time();
        $window = self::rateWindow();
        $hits = [];

        foreach ($data['hits'] ?? [] as $ts) {
            $t = (int) $ts;
            if ($t > 0 && ($now - $t) < $window) {
                $hits[] = $t;
            }
        }

        return count($hits) >= $limit;
    }

    private static function hitRate(string $kind): void
    {
        self::ensureStorage();
        $data = self::readRate($kind);
        $now = time();
        $window = self::rateWindow();
        $hits = [];

        foreach ($data['hits'] ?? [] as $ts) {
            $t = (int) $ts;
            if ($t > 0 && ($now - $t) < $window) {
                $hits[] = $t;
            }
        }

        $hits[] = $now;
        $payload = [
            'ip' => self::clientIp(),
            'kind' => $kind,
            'hits' => $hits,
            'updated_at' => date('c'),
        ];

        @file_put_contents(
            self::ratePath($kind),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function readRate(string $kind): array
    {
        $path = self::ratePath($kind);
        if (! is_file($path)) {
            return ['hits' => []];
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : ['hits' => []];
    }

    private static function ratePath(string $kind): string
    {
        $ip = self::clientIp();
        $hash = hash('sha256', $kind.'|'.$ip);

        return self::rateDir().DIRECTORY_SEPARATOR.$hash.'.json';
    }

    private static function rateDir(): string
    {
        return self::storageDir().DIRECTORY_SEPARATOR.self::RATE_DIR_NAME;
    }

    private static function ttl(): int
    {
        if (defined('FORM_TOKEN_TTL')) {
            $ttl = (int) FORM_TOKEN_TTL;

            return $ttl > 0 ? $ttl : 600;
        }

        return 600;
    }

    private static function secret(): string
    {
        if (defined('FORM_TOKEN_SECRET') && trim((string) FORM_TOKEN_SECRET) !== '') {
            return (string) FORM_TOKEN_SECRET;
        }

        // Fallback for older configs until redeploy regenerates config.php.
        $crm = defined('CRM_API_KEY') ? (string) CRM_API_KEY : '';

        return hash('sha256', (string) SITE_URL.'|'.$crm.'|form-token');
    }

    private static function storageDir(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.self::TOKEN_DIR_NAME;
    }

    private static function tokenPath(string $id): string
    {
        return self::storageDir().DIRECTORY_SEPARATOR.$id.'.json';
    }

    private static function ensureStorage(): void
    {
        $dir = self::storageDir();

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $rateDir = self::rateDir();
        if (! is_dir($rateDir)) {
            @mkdir($rateDir, 0755, true);
        }

        $expiredDir = self::expiredDir();
        if (! is_dir($expiredDir)) {
            @mkdir($expiredDir, 0755, true);
        }

        $deny = $dir.DIRECTORY_SEPARATOR.'.htaccess';
        if (! is_file($deny)) {
            @file_put_contents($deny, "Require all denied\n");
        }
    }

    private static function deleteTokenFile(string $id): void
    {
        $path = self::tokenPath($id);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function expiredDir(): string
    {
        return self::storageDir().DIRECTORY_SEPARATOR.self::EXPIRED_DIR_NAME;
    }

    private static function expiredMarkPath(string $id): string
    {
        return self::expiredDir().DIRECTORY_SEPARATOR.$id.'.json';
    }

    /**
     * True the first time this expired token id is submitted.
     * The mark survives token-file GC, so a replay still resolves as already used.
     */
    private static function claimFirstExpired(string $id): bool
    {
        self::ensureStorage();
        $path = self::expiredMarkPath($id);

        if (is_file($path)) {
            return false;
        }

        $dir = self::expiredDir();
        if (! is_dir($dir)) {
            return true;
        }

        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return ! is_file($path);
        }

        $payload = json_encode([
            'id' => $id,
            'ip' => self::clientIp(),
            'claimed_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($payload)) {
            @fwrite($handle, $payload."\n");
        }
        @fclose($handle);

        return true;
    }

    private static function gc(): void
    {
        $dir = self::storageDir();
        if (! is_dir($dir)) {
            return;
        }

        $now = time();
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $exp = is_array($data) ? (int) ($data['exp'] ?? 0) : 0;

            if ($exp > 0 && $exp < $now) {
                @unlink($file);
            }
        }

        $expiredDir = self::expiredDir();
        if (is_dir($expiredDir)) {
            foreach (glob($expiredDir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && ($now - $mtime) > self::EXPIRED_MARK_TTL) {
                    @unlink($file);
                }
            }
        }

        $rateDir = self::rateDir();
        if (! is_dir($rateDir)) {
            return;
        }

        $window = self::rateWindow();
        foreach (glob($rateDir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && ($now - $mtime) > ($window * 2)) {
                @unlink($file);
            }
        }
    }

    private static function debugLog(string $event, string $id): void
    {
        if (! defined('FORM_TOKEN_DEBUG') || ! FORM_TOKEN_DEBUG) {
            return;
        }

        self::ensureStorage();
        $line = date('c')."\t{$event}\t{$id}\t".self::clientIp()."\n";
        @file_put_contents(self::storageDir().DIRECTORY_SEPARATOR.'_debug.log', $line, FILE_APPEND | LOCK_EX);
    }
}
