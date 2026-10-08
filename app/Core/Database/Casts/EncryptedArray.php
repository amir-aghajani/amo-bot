<?php

declare(strict_types=1);

namespace App\Core\Database\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores an array attribute — a driver's settings, a secret among them — as JSON encrypted at rest (Encrypted, which
 * it writes and reads through): `protected $casts = ['captcha_config' => EncryptedArray::class]`. Nothing, or an empty
 * array, is kept as null; a value that no longer decrypts (Encrypted logs it) or holds no array reads as null.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
final class EncryptedArray implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $json = (new Encrypted())->get($model, $key, $value, $attributes);
        $decoded = $json === null ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        return (new Encrypted())->set($model, $key, json_encode($value, JSON_THROW_ON_ERROR), $attributes);
    }
}
