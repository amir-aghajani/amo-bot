<?php

declare(strict_types=1);

namespace App\Core\Captcha\Drivers;

use App\Core\Captcha\CaptchaAttempt;
use App\Core\Captcha\CaptchaDriver;
use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Captcha\CaptchaVerdict;
use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Cloudflare Turnstile: the page draws Cloudflare's widget with the site key, and its token is checked with Cloudflare's
 * siteverify — the site's secret, the token, the visitor's address when it came with them — through the shop's outgoing
 * client. Cloudflare takes a token once, and says the host it was solved on and the action the widget named: one solved
 * on none of the site's hosts, or for another action than the form's, does not pass (CaptchaVerdict::held()). Cloudflare
 * out of reach, or not answering as its API does, is a 503 (logged); a secret Cloudflare does not know refuses everyone,
 * which the log says, for the owner. Reachable from Iran.
 */
final class Turnstile implements CaptchaDriver
{
    public const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** A key of Cloudflare's as it shows one: printable Latin characters, no spaces. */
    private const KEY = '/^[\x21-\x7e]+$/';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {}

    public function key(): string
    {
        return 'turnstile';
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'Cloudflare Turnstile',
            description: 'ویجت تایید امنیتی Cloudflare. در داشبورد Cloudflare، بخش Turnstile، یک ویجت برای دامنه وب‌سایت بسازید و Site Key و Secret Key آن را اینجا وارد کنید؛ وب‌سایت ویجت را با Site Key نشان می‌دهد و فروشگاه پاسخ آن را با Secret Key نزد Cloudflare بررسی می‌کند.',
            form: new Form($this->key(), [
                new Text(
                    'site_key',
                    'site_key',
                    '',
                    label: 'Site Key',
                    max: 64,
                    required: true,
                    pattern: self::KEY,
                    mismatch: 'Site Key را همان‌طور که Cloudflare نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    spec: new FieldSpec('Site Key', hint: 'عمومی است؛ وب‌سایت ویجت را با آن نشان می‌دهد.', ltr: true),
                ),
                new Secret(
                    'secret_key',
                    'secret_key',
                    '',
                    pattern: '/^[\x21-\x7e]{1,255}$/',
                    mismatch: 'Secret Key را همان‌طور که Cloudflare نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.',
                    missing: 'Secret Key را هم از Cloudflare وارد کنید.',
                    boundTo: ['site_key' => null],
                    moved: 'با Site Key تازه، Secret Key همان ویجت را هم وارد کنید.',
                    spec: new FieldSpec('Secret Key', hint: 'فقط فروشگاه آن را دارد و توکن هر فرم را با آن نزد Cloudflare بررسی می‌کند.', ltr: true),
                ),
            ]),
            notes: ['Cloudflare هر توکن را یک بار می‌پذیرد؛ توکنی که روی دامنه‌ای جز آدرس وب‌سایت و Originهای آن حل شده باشد پذیرفته نمی‌شود.'],
        );
    }

    public function siteKey(array $values): ?string
    {
        return is_string($values['site_key'] ?? null) && $values['site_key'] !== '' ? $values['site_key'] : null;
    }

    public function verify(array $values, CaptchaAttempt $attempt): CaptchaVerdict
    {
        $form = ['secret' => is_string($values['secret_key'] ?? null) ? $values['secret_key'] : '', 'response' => $attempt->token];
        if ($attempt->visitor !== null && $attempt->visitor !== '') {
            $form['remoteip'] = $attempt->visitor;
        }

        try {
            $response = $this->http->request('POST', self::SITEVERIFY, [
                'allow_redirects' => false,
                'headers' => ['Accept' => 'application/json'],
                'form_params' => $form,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('Turnstile could not check a website\'s captcha: {message}', ['message' => $e->getMessage()]);

            throw new CaptchaUnavailableException($e);
        }

        $answer = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($answer) || !is_bool($answer['success'] ?? null)) {
            $this->logger->warning('Turnstile answered a captcha\'s check with {status}, not its JSON', ['status' => $response->getStatusCode()]);

            throw new CaptchaUnavailableException();
        }
        $action = is_string($answer['action'] ?? null) && $answer['action'] !== '' ? $answer['action'] : null;
        $hostname = is_string($answer['hostname'] ?? null) && $answer['hostname'] !== '' ? $answer['hostname'] : null;
        if ($answer['success']) {
            return CaptchaVerdict::held($attempt, $action, $hostname);
        }

        $reasons = is_array($answer['error-codes'] ?? null) ? array_values(array_filter($answer['error-codes'], is_string(...))) : [];
        if (array_intersect($reasons, ['missing-input-secret', 'invalid-input-secret']) !== []) {
            $this->logger->error('Turnstile does not know the website\'s captcha secret: every form that asks a captcha is refused until it is set right in the panel');
        }

        return CaptchaVerdict::refused($reasons === [] ? ['invalid-input-response'] : $reasons, $action, $hostname);
    }
}
