<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiApiException;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A connector failure in words: the owner's diagnosis with its detail, and the summary everyone else reads — which never
 * carries anything of the panel itself.
 */
final class ProviderErrorPresenterTest extends TestCase
{
    /** @return iterable<string, array{ProviderException, list<string>}> */
    public static function failures(): iterable
    {
        yield 'bad token' => [AuthenticationException::rejectedToken(401), ['توکن', 'HTTP 401']];
        yield 'bad token with the driver\'s remedy' => [AuthenticationException::rejectedToken(401, 'توکن را از Settings → Security → API Token بسازید.'), ['نپذیرفت (HTTP 401). توکن را از Settings']];
        yield 'token scope' => [AuthenticationException::insufficientScope('this API token is not permitted', 'توکنی با دسترسی Admin بسازید.'), ['دسترسی لازم را ندارد', 'Admin', '(this API token is not permitted)']];
        yield 'wrong password' => [AuthenticationException::loginFailed('Invalid username or password', false), ['رمز عبور', '(Invalid username or password)']];
        yield 'two-factor' => [AuthenticationException::loginFailed('Invalid two-factor code', true), ['TOTP', 'دومرحله‌ای']];
        yield 'no credentials' => [AuthenticationException::missingCredentials(), ['وارد نشده']];
        yield 'csrf guard' => [AuthenticationException::csrfRejected(), ['CSRF', 'HTTP 403', 'ریورس‌پراکسی']];
        yield 'session refused' => [AuthenticationException::sessionRejected(401), ['Session', 'کوکی']];
        yield 'unknown route' => [new UnexpectedResponseException(404), ['نمی‌شناسد', 'HTTP 404']];
        yield 'unknown route with the driver\'s guess' => [new UnexpectedResponseException(404, hint: 'آدرس باید شامل مسیر پایه وب باشد.'), ['(HTTP 404). آدرس باید شامل مسیر پایه وب']];
        yield 'redirect' => [new UnexpectedResponseException(301, location: 'https://panel.test/abc/'), ['https://panel.test/abc/', 'HTTP 301', 'اصلاح کنید']];
        yield 'proxy error page' => [new UnexpectedResponseException(502, 'Bad gateway'), ['خطای سرور', 'HTTP 502', '(Bad gateway)']];
        yield 'html instead of the api' => [new UnexpectedResponseException(200, 'Welcome to nginx!'), ['قابل خواندن نبود', '(Welcome to nginx!)']];
        yield 'dns' => [new ConnectionException(ConnectionFailure::Dns, 'cURL error 6: Could not resolve host'), ['دامنه', '(cURL error 6: Could not resolve host)']];
        yield 'refused' => [new ConnectionException(ConnectionFailure::Refused, 'Connection refused'), ['پورت', 'فایروال']];
        yield 'timeout' => [new ConnectionException(ConnectionFailure::Timeout, 'cURL error 28'), ['تایم‌اوت اتصال']];
        yield 'tls' => [new ConnectionException(ConnectionFailure::Tls, 'cURL error 60'), ['گواهی TLS']];
        yield 'panel refusal' => [new ThreeXuiApiException('POST', '/panel/api/clients/add', 400, 'Duplicate email: amir_1'), ['پنل درخواست را رد کرد', 'Duplicate email: amir_1']];
        yield 'not found' => [new NotFoundException('3x-ui has no client "amir_1".'), ['روی پنل پیدا نشد', '(3x-ui has no client "amir_1".)']];
        yield 'unsupported' => [new UnsupportedOperationException('No host statistics.'), ['چنین امکانی ندارد', '(No host statistics.)']];
        yield 'anything else' => [new ProviderException('custom failure'), ['ارتباط با پنل به مشکل خورد', '(custom failure)']];
    }

    /** @param list<string> $expected */
    #[DataProvider('failures')]
    public function testEveryFailureGetsAPersianDiagnosisForTheOwner(ProviderException $e, array $expected): void
    {
        $text = ProviderErrorPresenter::describe($e);

        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $text);
        }
        self::assertMatchesRegularExpression('/^[^a-zA-Z]/u', $text, 'the sentence is Persian, whatever the driver said');
    }

    /** @param list<string> $expected */
    #[DataProvider('failures')]
    public function testAnyoneElseReadsOnlyWhatHappenedNothingOfThePanel(ProviderException $e, array $expected): void
    {
        $summary = ProviderErrorPresenter::summary($e);

        self::assertDoesNotMatchRegularExpression('/[A-Za-z0-9]/', $summary, 'no host, port, path, status or the panel\'s own words');
        self::assertStringEndsWith('.', $summary);
        foreach ($expected as $fragment) {
            if (preg_match('/[A-Za-z0-9]/', $fragment) === 1) {
                self::assertStringNotContainsString($fragment, $summary);
            }
        }
    }

    public function testTheOwnersDiagnosisNamesThePanelWhereTheSummaryNeverDoes(): void
    {
        $e = new ConnectionException(ConnectionFailure::Refused, 'cURL error 7: Failed to connect to de1.example.net port 2053');

        self::assertStringContainsString('de1.example.net', ProviderErrorPresenter::describe($e), 'the owner, who runs the panels, reads where');
        self::assertSame('سرور در دسترس نبود.', ProviderErrorPresenter::summary($e));
        self::assertSame('این سرویس روی سرور پیدا نشد.', ProviderErrorPresenter::summary(new NotFoundException('3x-ui has no client "amir_1".')));
        self::assertSame('سرور درخواست را نپذیرفت.', ProviderErrorPresenter::summary(new ThreeXuiApiException('POST', '/panel/api/clients/add', 400, 'Duplicate email')));
    }

    public function testIdentifiersKeepLatinDigitsAndTheSentenceHasNoDiacritics(): void
    {
        $text = ProviderErrorPresenter::describe(new UnexpectedResponseException(404, 'ignored detail', hint: 'مثل https://host:2053/AbCdEf'));

        self::assertStringContainsString('HTTP 404', $text, 'status codes are identifiers, not quantities');
        self::assertStringContainsString('https://host:2053/AbCdEf', $text);
        self::assertDoesNotMatchRegularExpression('/[۰-۹]/u', $text, 'nothing here is a quantity');
        self::assertDoesNotMatchRegularExpression('/[\x{064B}-\x{0655}]/u', $text, 'no diacritics');
    }
}
