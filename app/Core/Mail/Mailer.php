<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Config\Repository as Config;
use App\Core\Drivers\Registry;
use App\Core\Forms\FieldType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;

/**
 * The shop's one way to send an email — what config.php's MAIL_* settings set up (MailTransport, the container's
 * `mail.transport`), from its MAIL_FROM_ADDRESS. ready() says whether one can go at all; send() sends it, or says it
 * did not go (MailFailedException), the transport's reason logged once — with a mail driver's secret (the SMTP password,
 * Resend's key), should a mail server repeat it, taken out.
 */
final class Mailer
{
    /** @param Registry<MailDriver> $drivers The mail drivers, whose secrets a transport's words never carry */
    public function __construct(
        /** The transport config.php names; null for none (the container's `mail.transport`). */
        private readonly ?TransportInterface $transport,
        private readonly Config $config,
        private readonly Registry $drivers,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether an email can go: config.php names a transport, and an address the shop's emails come from. */
    public function ready(): bool
    {
        return $this->transport !== null && $this->fromAddress() !== '';
    }

    /**
     * From MAIL_FROM_ADDRESS, by MAIL_FROM_NAME — else the message's `fromName` (the shop it is about), else the shop's
     * name. The message goes to its address alone: one read as anything else — a name with an address in angle brackets
     * — is no address it can write to.
     *
     * @throws MailFailedException when the transport did not take it, or an address in it is none it can write to
     * @throws \LogicException while no email can go (ready() is false): the caller asks first
     */
    public function send(MailMessage $message): void
    {
        if ($this->transport === null || $this->fromAddress() === '') {
            throw new \LogicException('No email can go: Mailer::ready() is false.');
        }

        try {
            $this->transport->send((new Email())
                ->from(new Address($this->fromAddress(), $this->fromName($message)))
                ->to(new Address($message->to))
                ->subject($message->subject)
                ->text($message->text)
                ->html($message->html));
        } catch (TransportExceptionInterface|MimeException $e) {
            $reason = $this->redacted($e->getMessage());
            $this->logger->warning('An email to {to} ({subject}) did not go: {reason}', ['to' => $message->to, 'subject' => $message->subject, 'reason' => $reason]);

            throw MailFailedException::because($reason, $e);
        }
    }

    private function fromAddress(): string
    {
        return trim((string) $this->config->get('mail.from_address', ''));
    }

    private function fromName(MailMessage $message): string
    {
        $named = trim((string) $this->config->get('mail.from_name', ''));

        return $named !== '' ? $named : ($message->fromName ?? (string) $this->config->get('app.name', ''));
    }

    /** What a transport said, without a secret any mail driver keeps in config.php — the SMTP account's password, Resend's key. */
    private function redacted(string $said): string
    {
        $settings = (array) $this->config->get('mail.settings', []);
        foreach ($this->drivers->all() as $driver) {
            foreach ($driver->describe()->form->fields as $field) {
                $secret = $field->type() === FieldType::Secret && is_string($settings[$field->key] ?? null) ? $settings[$field->key] : '';
                if ($secret !== '') {
                    $said = str_replace($secret, '••••', $said);
                }
            }
        }

        return $said;
    }
}
