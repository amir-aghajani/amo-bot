<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Secret;
use App\Core\Security\DecryptionException;
use App\Core\Security\Encrypter;
use App\Modules\Payments\Contracts\GatewayDriver;
use Psr\Log\LoggerInterface;

/**
 * A payment method row's settings (`payment_methods.config`) as its driver's form reads them and as they are kept: the
 * form's secrets (an online gateway's token) are kept encrypted with APP_KEY, marked so — the rest as they are. One that
 * no longer decrypts (APP_KEY changed) reads as empty, logged, as Core\Database\Casts\Encrypted reads one: the method's
 * form asks for it again, and its gateway refuses meanwhile.
 */
final class MethodSettings
{
    /** What an encrypted value begins with in the row. */
    private const ENCRYPTED = 'encrypted:';

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The row's config with its secrets readable, by field key.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function read(GatewayDriver $driver, array $config): array
    {
        foreach (self::secrets($driver) as $key) {
            $value = $config[$key] ?? null;
            if (is_string($value) && str_starts_with($value, self::ENCRYPTED)) {
                try {
                    $config[$key] = $this->encrypter->decrypt(substr($value, strlen(self::ENCRYPTED)));
                } catch (DecryptionException $e) {
                    $this->logger->warning('A {driver} payment method\'s {key} cannot be decrypted, so it reads as empty (was APP_KEY changed?): {message}', [
                        'driver' => $driver->key(),
                        'key' => $key,
                        'message' => $e->getMessage(),
                    ]);
                    $config[$key] = '';
                }
            }
        }

        return $config;
    }

    /**
     * The config to keep: its secrets encrypted (a blank one stays blank).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function write(GatewayDriver $driver, array $config): array
    {
        foreach (self::secrets($driver) as $key) {
            $value = $config[$key] ?? null;
            if (is_string($value) && $value !== '' && !str_starts_with($value, self::ENCRYPTED)) {
                $config[$key] = self::ENCRYPTED . $this->encrypter->encrypt($value);
            }
        }

        return $config;
    }

    /** @return list<string> The config keys of the driver's secrets */
    private static function secrets(GatewayDriver $driver): array
    {
        return array_values(array_map(
            static fn(Field $field): string => $field->key,
            array_filter($driver->describe()->form->fields, static fn(Field $field): bool => $field instanceof Secret),
        ));
    }
}
