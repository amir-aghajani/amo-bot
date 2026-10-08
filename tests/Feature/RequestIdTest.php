<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\RequestId;
use Monolog\Level;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Tests\HttpTestCase;

/**
 * Every request has an id of its own, made by the app — never the one a request brings: every answer says it
 * (`X-Request-Id`), an error answer in its body too (`request_id`), and every line the request writes to the log carries
 * it — what the panel shows on a failure of the server's own, and what the owner looks for in the log.
 */
final class RequestIdTest extends HttpTestCase
{
    private const ID = '/^[0-9a-f]{16}$/';

    public function testEveryAnswerNamesItsRequestEachItsOwn(): void
    {
        $answers = [
            'a success' => $this->get('/health'),
            'a refusal' => $this->get('/api/admin/auth/me'),
            'an address nobody routes' => $this->get('/api/admin/does-not-exist'),
            'the redirect to the panel' => $this->get('/'),
            'a machine\'s address' => $this->send('POST', '/webhooks/telegram/wrong', ['update_id' => 1]),
        ];

        $ids = array_map(static fn(ResponseInterface $answer): string => $answer->getHeaderLine(RequestId::HEADER), $answers);
        foreach ($ids as $what => $id) {
            self::assertMatchesRegularExpression(self::ID, $id, $what);
        }
        self::assertSame(array_values($ids), array_values(array_unique($ids)), 'each request its own');
    }

    public function testAnErrorAnswerNamesItsRequestInItsBodyToo(): void
    {
        foreach ([$this->get('/api/admin/auth/me'), $this->get('/api/admin/does-not-exist'), $this->postJson('/api/admin/auth/login', ['username' => '', 'password' => ''])] as $refused) {
            self::assertGreaterThanOrEqual(400, $refused->getStatusCode());
            self::assertSame($refused->getHeaderLine(RequestId::HEADER), $this->decode($refused)['request_id']);
        }
    }

    public function testTheIdIsTheAppsNeverTheRequests(): void
    {
        $answer = $this->get('/api/admin/auth/me', [RequestId::HEADER => 'chosen-by-the-caller']);

        self::assertMatchesRegularExpression(self::ID, $answer->getHeaderLine(RequestId::HEADER));
        self::assertMatchesRegularExpression(self::ID, $this->decode($answer)['request_id']);
    }

    public function testEveryLineARequestWritesCarriesItsId(): void
    {
        $logs = $this->logs();

        $answer = $this->send('POST', '/webhooks/telegram/wrong', ['update_id' => 1]);

        self::assertSame(403, $answer->getStatusCode());
        self::assertTrue($logs->hasRecordThatContains('called with an invalid secret', Level::Warning));
        foreach ($logs->getRecords() as $record) {
            self::assertSame($answer->getHeaderLine(RequestId::HEADER), $record->extra['request_id'] ?? null, $record->message);
        }
    }

    public function testALineOutsideARequestNamesNone(): void
    {
        $logs = $this->logs();

        $this->service(LoggerInterface::class)->info('A task ran.');

        self::assertArrayNotHasKey('request_id', $logs->getRecords()[0]->extra, 'the command line, a task: no request');
    }
}
