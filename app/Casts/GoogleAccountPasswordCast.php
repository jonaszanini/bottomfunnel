<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dedicated encryption cast for Google account passwords.
 *
 * CRITICAL SECURITY NOTICE:
 * Passwords are encrypted using AES-256-CBC with the GOOGLE_ACCOUNTS_ENCRYPTION_KEY from .env.
 * Losing this encryption key makes all stored Google account passwords permanently unrecoverable.
 * This key MUST NEVER be committed to version control.
 */
class GoogleAccountPasswordCast implements CastsAttributes
{
    protected static ?Encrypter $encrypter = null;

    /**
     * Resolve the dedicated encrypter instance.
     */
    public static function getEncrypter(): Encrypter
    {
        if (static::$encrypter !== null) {
            return static::$encrypter;
        }

        $key = (string) (config('services.google_accounts.encryption_key') ?: env('GOOGLE_ACCOUNTS_ENCRYPTION_KEY'));

        if ($key === '') {
            $key = (string) config('app.key');
        }

        if ($key === '') {
            throw new RuntimeException('GOOGLE_ACCOUNTS_ENCRYPTION_KEY (or APP_KEY) must be set in .env to encrypt Google account passwords.');
        }

        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $cipher = (string) config('app.cipher', 'AES-256-CBC');

        return static::$encrypter = new Encrypter($key, $cipher);
    }

    /**
     * Reset cached encrypter (useful in tests when switching keys).
     */
    public static function resetEncrypter(): void
    {
        static::$encrypter = null;
    }

    /**
     * Cast the given value from storage.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return static::getEncrypter()->decryptString($value);
    }

    /**
     * Prepare the given value for storage.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return static::getEncrypter()->encryptString($value);
    }
}
