<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Config\Repository as Config;
use App\Core\Drivers\Registry;
use App\Core\Forms\Fields\Field;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\ExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * How the shop's email goes out, as config.php says (config/mail.php): MAIL_TRANSPORT names a mail driver (MailDriver,
 * the container's `mail.drivers`), which makes its transport of its own MAIL_* settings — or `none`, no email at all.
 * Built once a process: the container's `mail.transport`.
 */
final class MailTransport
{
    /** MAIL_TRANSPORT's word for no email at all: it names no driver. */
    public const NONE = 'none';

    /**
     * The transport config.php names; null for none — and for settings no transport can be built of (logged): a driver
     * the shop does not have, a server or a key missing.
     *
     * @param Registry<MailDriver> $drivers
     */
    public static function fromConfig(Config $config, Registry $drivers, LoggerInterface $logger): ?TransportInterface
    {
        $key = (string) $config->get('mail.transport', self::NONE);
        if ($key === self::NONE) {
            return null;
        }

        $settings = (array) $config->get('mail.settings', []);
        try {
            $driver = $drivers->find($key) ?? throw new MailSettingsException("MAIL_TRANSPORT {$key} names no mail driver");

            return $driver->transport($driver->describe()->form->values(static fn(Field $field): mixed => $settings[$field->key] ?? null));
        } catch (MailSettingsException|ExceptionInterface $e) {
            $logger->warning('No mail goes out: {reason}', ['reason' => $e->getMessage()]);

            return null;
        }
    }
}
