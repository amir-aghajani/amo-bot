<?php

declare(strict_types=1);

namespace App\Core\Database\Casts;

use App\Core\Security\DecryptionException;
use App\Core\Security\Encrypter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Psr\Log\LoggerInterface;

/**
 * Stores an attribute encrypted at rest (a panel's password, a bot's token): `protected $casts = ['token' => Encrypted::class]`.
 * A value that no longer decrypts — APP_KEY changed since it was written — reads as empty, with a warning in the log:
 * the server or bot then asks for its secret again instead of taking the whole shop down.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class Encrypted implements CastsAttributes
{
    private static ?Encrypter $encrypter = null;

    private static ?LoggerInterface $logger = null;

    /** Eloquent makes its casts itself, so they are handed the encrypter and the log once, at boot (Application). */
    public static function use(Encrypter $encrypter, LoggerInterface $logger): void
    {
        self::$encrypter = $encrypter;
        self::$logger = $logger;
    }

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return self::encrypter()->decrypt((string) $value);
        } catch (DecryptionException $e) {
            self::$logger?->warning('{model} #{id}: {key} cannot be decrypted, so it reads as empty (was APP_KEY changed?): {message}', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'key' => $key,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::encrypter()->encrypt((string) $value);
    }

    private static function encrypter(): Encrypter
    {
        return self::$encrypter ?? throw new \LogicException('Encrypted cast used before the encrypter was configured.');
    }
}
