<?php

declare(strict_types=1);

namespace App\Core\Sms;

use App\Core\Config\Repository as Config;
use App\Core\Drivers\Registry;
use App\Core\Forms\Fields\Field;
use Psr\Log\LoggerInterface;

/** Builds the sender SMS_DRIVER names from its SMS_* settings, once a process (the container's `sms.sender`). */
final class SmsSenders
{
    /** SMS_DRIVER's word for no SMS at all: it names no driver. */
    public const NONE = 'none';

    /**
     * The sender config.php names; null for `none`, and for settings no sender can be built from (logged).
     *
     * @param Registry<SmsDriver> $drivers
     */
    public static function fromConfig(Config $config, Registry $drivers, LoggerInterface $logger): ?SmsSender
    {
        $key = trim((string) $config->get('sms.driver', self::NONE));
        if ($key === self::NONE || $key === '') {
            return null;
        }

        $settings = (array) $config->get('sms.settings', []);
        try {
            $driver = $drivers->find($key) ?? throw new SmsSettingsException("SMS_DRIVER {$key} names no SMS driver");

            return $driver->sender(self::values($driver, $settings));
        } catch (SmsSettingsException $e) {
            $logger->warning('No SMS goes out: {reason}', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * A driver's form values read off settings kept by key (config.php's SMS_*), each field's default where none is.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function values(SmsDriver $driver, array $settings): array
    {
        return $driver->describe()->form->values(static fn(Field $field): mixed => $settings[$field->key] ?? null);
    }
}
