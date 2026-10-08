<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Application;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Http\RequestId;
use App\Core\Security\RateLimiter;
use App\Modules\Admin\Services\ClientErrors;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\HttpTestCase;

/**
 * A failure of a panel's own code, reported by its page (POST /client-errors, ClientErrors): one error line of the log
 * and nothing else, from a signed-in panel only. A report is the browser's word: each part is cut short and taken out of
 * any secret, its address keeps its path and its query's names alone, nothing in it begins a line of the log, the body
 * is capped, and a principal or an address sends only so many in a window.
 */
final class ClientErrorsTest extends HttpTestCase
{
    private const URL = '/api/admin/client-errors';

    private const TOKEN = '123456789:AAF' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    /** @var array<string, string> */
    private const REPORT = [
        'kind' => 'render',
        'message' => "TypeError: Cannot read properties of undefined (reading 'id')",
        'stack' => "TypeError: Cannot read properties of undefined (reading 'id')\n    at PlanRow (https://shop.example/admin/assets/plans-Bx1.js:1:200)\n    at ListView (https://shop.example/admin/assets/index-Cq2.js:3:90)",
        'component_stack' => "\n    at PlanRow\n    at ListView",
        'address' => '/admin/s/7/plans?status',
        'build' => '2026-10-06T12:00:00Z',
    ];

    public function testAReportIsOneErrorLineOfTheLog(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();

        $answer = $this->postJson(self::URL, self::REPORT);

        self::assertSame(204, $answer->getStatusCode());
        $record = $this->reported($logs->getRecords());
        self::assertSame("A panel page failed (render) at /admin/s/7/plans?status: TypeError: Cannot read properties of undefined (reading 'id')", $record->message);
        self::assertSame(['root', 1, '2026-10-06T12:00:00Z', Application::VERSION], [$record->context['principal'], $record->context['shop'], $record->context['build'], $record->context['version']]);
        self::assertSame($answer->getHeaderLine(RequestId::HEADER), $record->extra['request_id'], 'the report\'s own request, as every line of it');
        self::assertSame("    TypeError: Cannot read properties of undefined (reading 'id')\n        at PlanRow (https://shop.example/admin/assets/plans-Bx1.js:1:200)\n        at ListView (https://shop.example/admin/assets/index-Cq2.js:3:90)", $record->context['stack'], 'the stack indented under the line');
        self::assertSame("        at PlanRow\n        at ListView", $record->context['component_stack']);
    }

    public function testAnAgentsPanelReportsToo(): void
    {
        $bot = $this->agentBot();
        $this->loginAsAgent($bot);
        $logs = $this->logs();

        self::assertSame(204, $this->postJson('/api/agent/client-errors', ['address' => '/agent/plans'] + self::REPORT)->getStatusCode());

        $record = $this->reported($logs->getRecords());
        self::assertSame(['@agent_shop_bot', $bot->id], [$record->context['principal'], $record->context['shop']]);
    }

    public function testOnlyASignedInPanelReports(): void
    {
        $logs = $this->logs();

        self::assertSame(401, $this->postJson(self::URL, self::REPORT)->getStatusCode());
        self::assertSame(401, $this->postJson('/api/agent/client-errors', self::REPORT)->getStatusCode());
        $this->loginAsAdmin();
        self::assertSame(403, $this->send('POST', self::URL, self::REPORT)->getStatusCode(), 'without the CSRF header');

        self::assertFalse($logs->hasErrorRecords());
    }

    public function testNoSecretAndNoLineOfItsOwnReachTheLog(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();

        $this->postJson(self::URL, [
            'message' => 'Error: getMe failed: https://api.telegram.org/bot' . self::TOKEN . "/getMe\n[2026-10-06 12:00:00] app.ERROR: a forged line\u{1b}[31m",
            'stack' => 'Error at https://api.telegram.org/bot' . self::TOKEN . "/getMe\r\n[2026-10-06 12:00:00] app.INFO: another forged line",
            'address' => '/admin/settings/telegram',
        ] + self::REPORT);

        $record = $this->reported($logs->getRecords());
        $logged = $record->message . implode("\n", array_filter($record->context, is_string(...)));
        self::assertStringNotContainsString(self::TOKEN, $logged);
        self::assertStringContainsString('bot***/getMe', $record->message);
        self::assertStringNotContainsString("\n", $record->message, 'the message is one line');
        self::assertStringNotContainsString("\u{1b}", $logged, 'no terminal control codes');
        foreach (explode("\n", (string) $record->context['stack']) as $line) {
            self::assertStringStartsWith('    ', $line, 'no line of the report begins where the log\'s own do');
        }
    }

    public function testAnAddressKeepsItsPathAndItsQuerysNamesAlone(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();

        // What a page that sent its address whole would: a customer searched for, an agent's sign-in code.
        $this->postJson(self::URL, ['address' => '/agent/login?search=09121234567&page=2&search=sara#code=a1b2c3'] + self::REPORT);

        $record = $this->reported($logs->getRecords());
        self::assertSame('/agent/login?search&page', $record->context['address']);
        self::assertStringNotContainsString('0912', $record->message);
        self::assertStringNotContainsString('a1b2c3', $record->message);
    }

    public function testEachPartIsCutToItsLength(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();

        $this->postJson(self::URL, ['message' => str_repeat('م', 600), 'stack' => str_repeat("at frame\n", 600), 'build' => str_repeat('b', 100)] + self::REPORT);

        $record = $this->reported($logs->getRecords());
        self::assertSame(500, mb_strlen((string) $record->context['error']));
        self::assertCount(intdiv(4_000, 9) + 1, explode("\n", (string) $record->context['stack']), 'the lines of its first 4000 characters');
        self::assertSame(40, mb_strlen((string) $record->context['build']));
    }

    public function testABodyPastItsCapIsRefused(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();

        $refused = $this->postJson(self::URL, ['stack' => str_repeat('x', ClientErrors::MAX_BYTES)] + self::REPORT);

        self::assertSame(413, $refused->getStatusCode());
        self::assertSame(ClientErrors::TOO_LARGE, $this->decode($refused)['message']);
        self::assertFalse($logs->hasErrorRecords());
    }

    public function testAReportThatSaysNothingIsRefused(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();

        $refused = $this->unchecked()->postJson(self::URL, ['kind' => 'other', 'message' => '   '] + self::REPORT);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['kind', 'message'], array_keys($this->decode($refused)['errors']));
        self::assertFalse($logs->hasErrorRecords());
    }

    public function testAPanelSendsSoManyReportsAWindowThenWaits(): void
    {
        $this->loginAsAdmin();
        $logs = $this->logs();
        for ($i = 0; $i < ClientErrors::MAX_REPORTS; $i++) {
            self::assertSame(204, $this->postJson(self::URL, self::REPORT)->getStatusCode());
        }

        $waiting = $this->postJson(self::URL, self::REPORT);

        self::assertSame(429, $waiting->getStatusCode());
        $wait = (int) $waiting->getHeaderLine('Retry-After');
        self::assertGreaterThan(0, $wait);
        self::assertLessThanOrEqual(ClientErrors::WINDOW_SECONDS, $wait);
        self::assertCount(ClientErrors::MAX_REPORTS, array_filter($logs->getRecords(), static fn(LogRecord $record): bool => $record->level === Level::Error));
    }

    /** Window after window, a principal — or an address — sends so many reports a day: the log it can fill stays small. */
    public function testAPanelSendsSoManyReportsADayThenWaitsForTheNext(): void
    {
        $reports = $this->service(ClientErrors::class);
        $limiter = $this->service(RateLimiter::class);
        $owner = new Principal(PrincipalKind::Owner, 'root', CurrentBot::main());
        // The day's reports sent, a window at a time: the last window long closed.
        for ($i = 0; $i < ClientErrors::MAX_REPORTS_A_DAY; $i++) {
            $limiter->hit('client-errors|day|principal|root', 86_400);
        }

        try {
            $reports->report(self::from('198.51.100.7'), $owner);
            self::fail('the day\'s reports were sent');
        } catch (TooManyAttemptsException $e) {
            self::assertGreaterThan(ClientErrors::WINDOW_SECONDS, $e->retryAfter, 'it waits for the next day, not the next window');
        }
        self::assertFalse(self::waits(fn() => $reports->report(self::from('198.51.100.8'), new Principal(PrincipalKind::Agent, '@agent_shop_bot', CurrentBot::main()))), 'another principal from another address');
    }

    public function testAPrincipalAndAnAddressAreEachCounted(): void
    {
        $reports = $this->service(ClientErrors::class);
        $owner = new Principal(PrincipalKind::Owner, 'root', CurrentBot::main());
        $agent = new Principal(PrincipalKind::Agent, '@agent_shop_bot', CurrentBot::main());
        for ($i = 0; $i < ClientErrors::MAX_REPORTS; $i++) {
            $reports->report(self::from('198.51.100.7'), $owner);
        }

        self::assertTrue(self::waits(fn() => $reports->report(self::from('198.51.100.8'), $owner)), 'the principal, from any address');
        self::assertTrue(self::waits(fn() => $reports->report(self::from('198.51.100.7'), $agent)), 'the address, whoever is signed in');
        self::assertFalse(self::waits(fn() => $reports->report(self::from('198.51.100.8'), $agent)), 'another principal from another address');
    }

    /** @param list<LogRecord> $records The one error line of the reports */
    private function reported(array $records): LogRecord
    {
        $errors = array_values(array_filter($records, static fn(LogRecord $record): bool => $record->level === Level::Error));
        self::assertCount(1, $errors);

        return $errors[0];
    }

    private static function from(string $address): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', self::URL, ['REMOTE_ADDR' => $address])
            ->withBody((new StreamFactory())->createStream((string) json_encode(self::REPORT)))
            ->withParsedBody(self::REPORT);
    }

    /** @param \Closure(): void $report Whether the report was refused for the window */
    private static function waits(\Closure $report): bool
    {
        try {
            $report();
        } catch (TooManyAttemptsException) {
            return true;
        }

        return false;
    }
}
