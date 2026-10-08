<?php

declare(strict_types=1);

namespace App\Core\Mail;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email through Resend's API (resend.com) — for a host that sends no mail of its own, or whose mail lands in spam: each
 * message posted to its /emails endpoint as JSON, signed with the account's API key (MAIL_RESEND_API_KEY), through the
 * shop's one outgoing client (the tests answer it as they answer every other call). The sender's domain must be one the
 * account verified (Resend › Domains). A refusal is Resend's own words with its status — the owner's diagnosis on the
 * test send —; the key is never in one.
 */
final class ResendTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.resend.com/emails';

    /** The most of Resend's own words a refusal carries. */
    private const REASON_MAX = 300;

    public function __construct(
        private readonly ClientInterface $http,
        #[\SensitiveParameter]
        private readonly string $key,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'resend+api://api.resend.com';
    }

    /** @throws TransportException when Resend could not be asked, or refused the email */
    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new TransportException('Resend sends whole emails only.');
        }

        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'headers' => ['Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json'],
                // The key goes to Resend's endpoint and nowhere else: an answer that sends it on is no answer.
                'allow_redirects' => false,
                // A refusal is read here, in Resend's words, whatever the client does with one by default.
                'http_errors' => false,
                'json' => self::payload($email),
            ]);
        } catch (GuzzleException $e) {
            throw new TransportException('Resend could not be reached: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $answer = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300) {
            $said = is_array($answer) && is_string($answer['message'] ?? null) ? mb_substr($answer['message'], 0, self::REASON_MAX) : 'no reason given';

            throw new TransportException("Resend refused the email ({$status}): {$said}");
        }
        if (is_array($answer) && is_string($answer['id'] ?? null) && $answer['id'] !== '') {
            $message->setMessageId($answer['id']);
        }
    }

    /**
     * The email as Resend's API takes it: who from (by name), to whom, the subject and both bodies.
     *
     * @return array<string, string|list<string>>
     */
    private static function payload(Email $email): array
    {
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        return array_filter([
            'from' => self::address($email->getFrom()[0] ?? throw new TransportException('An email to send needs its sender.')),
            'to' => array_map(self::address(...), $email->getTo()),
            'subject' => (string) $email->getSubject(),
            'html' => is_string($html) ? $html : '',
            'text' => is_string($text) ? $text : '',
        ], static fn(string|array $value): bool => $value !== '' && $value !== []);
    }

    /** An address as Resend reads one: bare, or `"Name" <address>` — the name quoted, as written, not MIME-encoded. */
    private static function address(Address $address): string
    {
        $name = $address->getName();

        return $name === '' ? $address->getAddress() : sprintf('"%s" <%s>', addcslashes($name, '"\\'), $address->getAddress());
    }
}
