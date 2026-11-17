<?php
declare(strict_types=1);

/**
 * Shared lander field limits — drop obvious bot payloads before CRM/Telegram.
 */
final class LeadFieldGuard
{
    public const FIRST_NAME_MAX_WORDS = 4;

    public const LAST_NAME_MAX_WORDS = 5;

    public const NAME_MIN_CHARS = 2;

    public const NAME_MAX_CHARS = 40;

    public const EMAIL_MIN_CHARS = 6;

    public const EMAIL_MAX_CHARS = 80;

    public const PHONE_MIN_DIGITS = 8;

    public const PHONE_MAX_DIGITS = 15;

    public static function error(string $firstName, string $lastName, string $email, string $phone): ?string
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $email = trim($email);
        $phone = trim($phone);

        if ($firstName === '' || $lastName === '' || $email === '' || $phone === '') {
            return 'Missing required fields';
        }

        if (self::nameError($firstName, self::FIRST_NAME_MAX_WORDS) !== null) {
            return 'Invalid first name';
        }

        if (self::nameError($lastName, self::LAST_NAME_MAX_WORDS) !== null) {
            return 'Invalid last name';
        }

        if (self::emailError($email) !== null) {
            return 'Invalid email';
        }

        if (self::phoneError($phone) !== null) {
            return 'Invalid phone';
        }

        return null;
    }

    public static function nameError(string $name, int $maxWords): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $len = self::charLen($name);
        $min = self::nameLooksCjk($name) ? 1 : self::NAME_MIN_CHARS;

        if ($len < $min || $len > self::NAME_MAX_CHARS) {
            return 'length';
        }

        if (preg_match('/\d/u', $name) === 1) {
            return 'digits';
        }

        if (preg_match('/^[\p{L}\p{M}\s.\'’-]+$/u', $name) !== 1) {
            return 'charset';
        }

        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? $words : [];

        if ($words === [] || count($words) > $maxWords) {
            return 'words';
        }

        return null;
    }

    public static function emailError(string $email): ?string
    {
        $len = self::charLen($email);

        if ($len < self::EMAIL_MIN_CHARS || $len > self::EMAIL_MAX_CHARS) {
            return 'length';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'format';
        }

        $at = strrpos($email, '@');
        if ($at === false) {
            return 'format';
        }

        $domain = strtolower(substr($email, $at + 1));
        if (! str_contains($domain, '.') || str_starts_with($domain, '.') || str_contains($domain, '..')) {
            return 'domain';
        }

        $tld = substr($domain, (int) strrpos($domain, '.') + 1);
        if (preg_match('/^[a-z]{2,24}$/', $tld) !== 1) {
            return 'tld';
        }

        if (in_array($tld, ['example', 'test', 'invalid', 'localhost'], true)) {
            return 'reserved';
        }

        return null;
    }

    public static function phoneError(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $len = strlen($digits);

        if ($len < self::PHONE_MIN_DIGITS || $len > self::PHONE_MAX_DIGITS) {
            return 'length';
        }

        return null;
    }

    private static function nameLooksCjk(string $name): bool
    {
        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $name) === 1;
    }

    private static function charLen(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
