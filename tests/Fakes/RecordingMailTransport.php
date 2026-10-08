<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The tests' mail server: TestCase makes it the container's `mail.transport` as the app boots, so the Mailer built around
 * it sends here and nowhere else. It keeps every email it takes (sent(), to()), and refuses them while told to
 * (failing()) the way a mail server out of reach does — with a reason that names the SMTP password, as a careless one
 * might, for the Mailer to take out. Emptied after every test.
 */
final class RecordingMailTransport implements TransportInterface
{
    /** @var list<Email> */
    private array $sent = [];

    private ?string $failing = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if ($this->failing !== null) {
            throw new TransportException($this->failing);
        }
        if (!$message instanceof Email) {
            throw new \LogicException('The shop sends emails, nothing else.');
        }
        $this->sent[] = $message;

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'recording://tests';
    }

    /** @return list<Email> Every email taken, in order */
    public function sent(): array
    {
        return $this->sent;
    }

    /** @return list<Email> The emails to `$address` */
    public function to(string $address): array
    {
        return array_values(array_filter($this->sent, static fn(Email $email): bool => $email->getTo()[0]->getAddress() === $address));
    }

    /** The six-digit code an email carries (its plain text's), or the test stops. */
    public static function codeIn(Email $email): string
    {
        if (preg_match('/^(\d{6})$/m', (string) $email->getTextBody(), $code) !== 1) {
            throw new \LogicException('The email carries no code: ' . $email->getTextBody());
        }

        return $code[1];
    }

    /** Refuse every email from now on, saying `$reason` (null: take them again). */
    public function failing(?string $reason): void
    {
        $this->failing = $reason;
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->failing = null;
    }
}
