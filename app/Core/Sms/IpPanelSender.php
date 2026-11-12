<?php

declare(strict_types=1);

namespace App\Core\Sms;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Codes through IPPanel Edge's API: a pattern send (`POST /api/send`, `sending_type: pattern`) of the approved pattern
 * with the code as its one variable, and the account's credit (`GET /api/payment/credit/mine`) — JSON with the access
 * key as it is (no "Bearer"), through the shop's one outgoing client, no redirect followed. A refusal carries IPPanel's
 * own words, never the key.
 */
final class IpPanelSender implements SmsSender, ReportsCredit
{
    public const BASE_URL = 'https://edge.ippanel.com/v1';

    /** IPPanel keeps an account's credit in Rial. */
    public const CREDIT_UNIT = 'ریال';

    /** How long IPPanel may take: a customer waits for the code they asked for, not the client's whole timeout. */
    private const TIMEOUT_SECONDS = 15;

    private const CONNECT_SECONDS = 5;

    /** The most of IPPanel's own words a refusal carries. */
    private const REASON_MAX = 300;

    public function __construct(
        private readonly ClientInterface $http,
        #[\SensitiveParameter]
        private readonly string $key,
        /** The account's line, in E.164 ("+983000505"). */
        private readonly string $from,
        /** The approved pattern's code. */
        private readonly string $pattern,
        /** The pattern's variable the code fills ("code"). */
        private readonly string $variable,
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    public function sendCode(string $to, string $code): void
    {
        $this->call('POST', '/api/send', [
            'sending_type' => 'pattern',
            'from_number' => $this->from,
            'code' => $this->pattern,
            'recipients' => [$to],
            'params' => [$this->variable => $code],
        ]);
    }

    public function credit(): SmsCredit
    {
        $answer = $this->call('GET', '/api/payment/credit/mine');
        $data = $answer['data'] ?? null;
        $credit = is_array($data) ? ($data['credit'] ?? null) : null;
        if (!is_int($credit) && !is_float($credit) && !(is_string($credit) && is_numeric($credit))) {
            throw SmsFailedException::because('IPPanel اعتبار حساب را نگفت.');
        }

        return new SmsCredit((int) floor((float) $credit), self::CREDIT_UNIT);
    }

    /**
     * One call to IPPanel: its answer when it says it did (`meta.status` true), else a failure in its words.
     *
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     * @throws SmsFailedException
     */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $options = [
            'headers' => ['Authorization' => $this->key, 'Accept' => 'application/json'],
            // The key goes to IPPanel's address and nowhere else: an answer that sends it on is no answer.
            'allow_redirects' => false,
            // A refusal is read here, in IPPanel's words, whatever the client does with one by default.
            'http_errors' => false,
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::CONNECT_SECONDS,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, rtrim($this->baseUrl, '/') . $path, $options);
        } catch (GuzzleException $e) {
            // Not chained: the client's exception carries the request, and so the key in its header.
            throw SmsFailedException::because($this->redacted(self::unreachable($e)));
        }

        $status = $response->getStatusCode();
        $answer = json_decode((string) $response->getBody(), true);
        $meta = is_array($answer) && is_array($answer['meta'] ?? null) ? $answer['meta'] : [];
        if ($status >= 200 && $status < 300 && is_array($answer) && ($meta['status'] ?? null) === true) {
            return $answer;
        }

        throw SmsFailedException::because($this->redacted(self::refusal($status, $meta)));
    }

    /** IPPanel out of reach: given up on, or not reached at all. */
    private static function unreachable(GuzzleException $e): string
    {
        $said = $e->getMessage();
        if (str_contains($said, 'cURL error 28') || stripos($said, 'timed out') !== false) {
            return 'IPPanel به‌موقع جواب نداد.';
        }

        return 'به IPPanel وصل نشد: ' . mb_substr($said, 0, self::REASON_MAX);
    }

    /**
     * IPPanel's refusal in its words: the key refused (401), the request (422, each field's words), or anything else it
     * said no to (`meta.status` false: no credit, a pattern not approved, a line not the account's).
     *
     * @param array<array-key, mixed> $meta
     */
    private static function refusal(int $status, array $meta): string
    {
        $said = self::said($meta);

        return match (true) {
            $status === 401 || $status === 403 => 'IPPanel کلید دسترسی را نپذیرفت' . ($said !== '' ? " ({$said})" : '') . '؛ کلید را در پنل IPPanel، «توسعه‌دهندگان ← کلیدهای دسترسی»، ببینید.',
            $status === 422 => 'IPPanel درخواست را نپذیرفت: ' . ($said !== '' ? $said : 'بی توضیح.'),
            $said !== '' => 'IPPanel نپذیرفت: ' . $said,
            default => "IPPanel پاسخ نامعلومی داد (HTTP {$status}).",
        };
    }

    /**
     * What IPPanel said: each field's words of a refused request, else its message — cut short.
     *
     * @param array<array-key, mixed> $meta
     */
    private static function said(array $meta): string
    {
        $words = [];
        foreach (is_array($meta['errors'] ?? null) ? $meta['errors'] : [] as $messages) {
            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                if (is_string($message) && trim($message) !== '') {
                    $words[] = trim($message);
                }
            }
        }
        if ($words === [] && is_string($meta['message'] ?? null)) {
            $words[] = trim($meta['message']);
        }

        return mb_substr(implode('؛ ', array_unique($words)), 0, self::REASON_MAX);
    }

    /** Words without the key, whatever repeated it. */
    private function redacted(string $said): string
    {
        return $this->key === '' ? $said : str_replace($this->key, '••••', $said);
    }
}
