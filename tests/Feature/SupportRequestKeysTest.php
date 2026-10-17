<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\KeyReusedException;
use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * Support's changes by hand under an Idempotency-Key (IdempotencyKey::optional()): a wallet's credit or debit
 * (POST /users/{id}/wallet), an agent's traffic set right (POST /agency/agents/{id}/traffic) and a ticket's answer
 * (POST /tickets/{id}/messages). The same request again with its key — the answer lost, the admin pressing again — is
 * answered as the first was, with nothing done twice and never refused for what the first changed; the key sent with
 * another request is a 422 on `idempotency_key`; without a key, every request is done, as before. A key is its
 * customer's, agent's or ticket's, and an account merge keeps one line a key.
 */
final class SupportRequestKeysTest extends HttpTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->telegram();
        $this->loginAsAdmin();
    }

    public function testACreditOrADebitAgainWithItsKeyIsAnsweredByTheLineItWrote(): void
    {
        $ali = $this->customer();

        $credited = $this->adjustWallet($ali, ['type' => 'credit', 'amount' => '50000', 'description' => 'جبران قطعی'], 'credit-1');
        self::assertSame(201, $credited->getStatusCode(), (string) $credited->getBody());
        $line = $this->decode($credited)['transaction'];

        $again = $this->adjustWallet($ali, ['type' => 'credit', 'amount' => '۵۰٬۰۰۰', 'description' => 'جبران قطعی'], 'credit-1');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($line, $this->decode($again)['transaction'], 'the line the key wrote, as the first answer gave it');
        self::assertSame(['50000.00', 1], [$this->decode($again)['user']['balance'], $this->lines($ali)], 'credited once');

        self::assertSame(201, $this->adjustWallet($ali, ['type' => 'debit', 'amount' => 50000], 'debit-1')->getStatusCode());
        $debitAgain = $this->adjustWallet($ali, ['type' => 'debit', 'amount' => 50000], 'debit-1');
        self::assertSame(200, $debitAgain->getStatusCode(), 'answered, never refused for the balance its own debit spent');
        self::assertSame(['0.00', 2], [$ali->balance(), $this->lines($ali)]);
    }

    public function testAWalletsKeySentWithAnotherChangeIsRefusedAndNothingMoves(): void
    {
        $ali = $this->customer();
        $this->adjustWallet($ali, ['type' => 'credit', 'amount' => '50000', 'description' => 'جبران قطعی'], 'credit-1');

        foreach ([
            'another amount' => ['type' => 'credit', 'amount' => '60000', 'description' => 'جبران قطعی'],
            'another type' => ['type' => 'debit', 'amount' => '50000', 'description' => 'جبران قطعی'],
            'another note' => ['type' => 'credit', 'amount' => '50000', 'description' => 'هدیه'],
            'none for the note' => ['type' => 'credit', 'amount' => '50000'],
        ] as $change => $body) {
            $this->assertRefusedUnder('idempotency_key', KeyReusedException::MESSAGE, $this->adjustWallet($ali, $body, 'credit-1'), message: $change);
        }
        self::assertSame(['50000.00', 1], [$ali->balance(), $this->lines($ali)]);

        $malformed = $this->unchecked()->send('POST', "/api/admin/users/{$ali->id}/wallet", ['type' => 'credit', 'amount' => '1000'], ['X-Requested-With' => 'XMLHttpRequest', IdempotencyKey::HEADER => 'not a key!']);
        $this->assertRefusedUnder('idempotency_key', IdempotencyKey::MALFORMED, $malformed);
        self::assertSame(1, $this->lines($ali));
    }

    public function testAWalletChangeWithoutAKeyIsMadeEachTimeAndAKeyIsItsCustomersAlone(): void
    {
        $ali = $this->customer();
        $sara = $this->customer(['telegram_id' => 7002]);

        foreach ([1, 2] as $time) {
            $response = $this->postJson("/api/admin/users/{$ali->id}/wallet", ['type' => 'credit', 'amount' => '1000']);
            self::assertSame(201, $response->getStatusCode(), "an old caller's request, made each time ({$time})");
        }
        self::assertSame(['2000.00', [null, null]], [$ali->balance(), WalletTransaction::query()->where('user_id', $ali->id)->pluck('request_key')->all()]);

        self::assertSame(201, $this->adjustWallet($ali, ['type' => 'credit', 'amount' => '1000'], 'shared')->getStatusCode());
        self::assertSame(201, $this->adjustWallet($sara, ['type' => 'credit', 'amount' => '1000'], 'shared')->getStatusCode(), "another customer's line under the same key");
        self::assertSame(['3000.00', '1000.00'], [$ali->balance(), $sara->balance()]);
    }

    public function testTwoAccountsMergedKeepOneLineAKeyAndTheSurvivorsAnswersIt(): void
    {
        $ali = $this->customer();
        $this->adjustWallet($ali, ['type' => 'credit', 'amount' => '1000'], 'shared');
        Carbon::setTestNow('2026-10-16 12:05:00');
        $sara = $this->webCustomer();
        $this->adjustWallet($sara, ['type' => 'credit', 'amount' => '2000'], 'shared');
        $this->adjustWallet($sara, ['type' => 'credit', 'amount' => '3000'], 'hers');

        $survivor = $this->service(AccountMerger::class)->merge($ali, $sara, AccountMerger::BY_CUSTOMER);

        self::assertSame([$ali->id, '6000.00'], [$survivor->id, $survivor->balance()]);
        self::assertSame(['shared', null, 'hers'], WalletTransaction::query()->where('user_id', $ali->id)->orderBy('id')->pluck('request_key')->all(), "the survivor's line (1000) keeps the key both held");
        self::assertSame('1000.00', $this->decode($this->adjustWallet($survivor, ['type' => 'credit', 'amount' => '1000'], 'shared'))['transaction']['amount']);
        self::assertSame(200, $this->adjustWallet($survivor, ['type' => 'credit', 'amount' => '3000'], 'hers')->getStatusCode(), "the merged account's own key, answered by its line");
    }

    public function testATrafficAdjustmentAgainWithItsKeyIsMadeOnce(): void
    {
        $agent = $this->agent($this->agencyLevel());
        $bot = $this->agentBot($agent, traffic: 10);

        $added = $this->adjustTraffic($agent, ['gb' => '50', 'note' => 'هدیه شروع کار'], 'traffic-1');
        self::assertSame(200, $added->getStatusCode(), (string) $added->getBody());
        $again = $this->adjustTraffic($agent, ['gb' => '۵۰', 'note' => 'هدیه شروع کار'], 'traffic-1');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(Traffic::bytesOfGb(60), $this->decode($again)['agent']['bot']['traffic_balance']);
        self::assertSame(2, TrafficTransaction::query()->where('bot_id', $bot->id)->count(), 'its purchase and one adjustment');

        self::assertSame(200, $this->adjustTraffic($agent, ['gb' => '-60'], 'traffic-2')->getStatusCode());
        $takenAgain = $this->adjustTraffic($agent, ['gb' => '-60'], 'traffic-2');
        self::assertSame(200, $takenAgain->getStatusCode(), 'answered, never refused for the traffic its own adjustment took');
        self::assertSame([0, 3], [$bot->trafficBalance(), TrafficTransaction::query()->where('bot_id', $bot->id)->count()]);
        self::assertSame(TrafficPool::LINE_ADJUSTED, TrafficTransaction::query()->where('request_key', 'traffic-2')->value('description'));

        foreach (['another amount' => ['gb' => '-50'], 'another note' => ['gb' => '-60', 'note' => 'اصلاح']] as $change => $body) {
            $this->assertRefusedUnder('idempotency_key', KeyReusedException::MESSAGE, $this->adjustTraffic($agent, $body, 'traffic-2'), message: $change);
        }
        self::assertSame([0, 3], [$bot->trafficBalance(), TrafficTransaction::query()->where('bot_id', $bot->id)->count()]);

        foreach ([1, 2] as $time) {
            self::assertSame(200, $this->postJson("/api/admin/agency/agents/{$agent->id}/traffic", ['gb' => 5])->getStatusCode(), "without a key, made each time ({$time})");
        }
        self::assertSame(Traffic::bytesOfGb(10), $bot->trafficBalance());
    }

    public function testATicketsAnswerAgainWithItsKeyIsWrittenAndToldOnce(): void
    {
        $this->reportGroup();
        $ali = $this->customer();
        $ticket = $this->ticket($ali);

        $answered = $this->answer($ticket->id, ['body' => 'مشکل برطرف شد.'], 'answer-1');
        self::assertSame(200, $answered->getStatusCode(), (string) $answered->getBody());
        $again = $this->answer($ticket->id, ['body' => 'مشکل برطرف شد.'], 'answer-1');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($this->decode($answered)['ticket']['messages'], $this->decode($again)['ticket']['messages'], 'one answer in the conversation');
        self::assertCount(1, $this->telegram()->sentTo(self::TELEGRAM_ID), 'the customer told once');
        self::assertSame(1, ReportMessage::query()->count(), 'the report group too');

        foreach (['other words' => ['body' => 'لطفا دوباره امتحان کنید.']] as $change => $body) {
            $this->assertRefusedUnder('idempotency_key', KeyReusedException::MESSAGE, $this->answer($ticket->id, $body, 'answer-1'), message: $change);
        }
        self::assertSame(2, TicketMessage::query()->where('ticket_id', $ticket->id)->count());

        $other = $this->ticket($ali, 'سوال درباره تمدید');
        self::assertSame(2, count($this->decode($this->answer($other->id, ['body' => 'مشکل برطرف شد.'], 'answer-1'))['ticket']['messages']), "another ticket's answer under the same key");

        foreach ([1, 2] as $time) {
            self::assertSame(200, $this->postJson("/api/admin/tickets/{$ticket->id}/messages", ['body' => 'یک نکته دیگر'])->getStatusCode(), "without a key, written each time ({$time})");
        }
        self::assertSame(4, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    public function testAnAnswerWithAPictureAgainKeepsNoSecondFileAndAnotherPictureIsAnotherAnswer(): void
    {
        $ticket = $this->ticket($this->customer());
        $folder = (string) $this->app()->container()->get('tickets.path');
        $png = (string) base64_decode(self::PNG);
        $send = fn(string $name): ResponseInterface => $this->upload("/api/admin/tickets/{$ticket->id}/messages", 'file', $name, $png, ['body' => 'این راهنما را ببینید.'], [IdempotencyKey::HEADER => 'picture-1']);

        self::assertSame(200, $send('guide.png')->getStatusCode());
        $again = $send('guide.png');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(2, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        self::assertCount(1, glob($folder . '/*') ?: [], 'the picture kept once');

        $this->assertRefusedUnder('idempotency_key', KeyReusedException::MESSAGE, $send('other.png'), message: 'another picture');
        $this->assertRefusedUnder('idempotency_key', KeyReusedException::MESSAGE, $this->answer($ticket->id, ['body' => 'این راهنما را ببینید.'], 'picture-1'), message: 'none');
        self::assertSame(2, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        self::assertCount(1, glob($folder . '/*') ?: [], 'a refused answer keeps no picture');
    }

    public function testAnAnswerAgainIsAnsweredThoughTheTicketFilledUpSince(): void
    {
        $ticket = $this->ticket($this->customer());
        $this->answer($ticket->id, ['body' => 'مشکل برطرف شد.'], 'answer-1');
        $this->ticketMessages($ticket, Tickets::MESSAGES_MAX - 2);

        self::assertSame(200, $this->answer($ticket->id, ['body' => 'مشکل برطرف شد.'], 'answer-1')->getStatusCode());
        $this->assertRefusedUnder('status', Tickets::FULL, $this->answer($ticket->id, ['body' => 'مشکل برطرف شد.'], 'answer-2'));
    }

    /**
     * POST /users/{id}/wallet from the owner's panel under `$key`.
     *
     * @param array<string, mixed> $body
     */
    private function adjustWallet(User $customer, array $body, string $key): ResponseInterface
    {
        return $this->send('POST', "/api/admin/users/{$customer->id}/wallet", $body, ['X-Requested-With' => 'XMLHttpRequest', IdempotencyKey::HEADER => $key]);
    }

    /**
     * POST /agency/agents/{id}/traffic from the owner's panel under `$key`.
     *
     * @param array<string, mixed> $body
     */
    private function adjustTraffic(User $agent, array $body, string $key): ResponseInterface
    {
        return $this->send('POST', "/api/admin/agency/agents/{$agent->id}/traffic", $body, ['X-Requested-With' => 'XMLHttpRequest', IdempotencyKey::HEADER => $key]);
    }

    /**
     * POST /tickets/{id}/messages from the owner's panel under `$key`.
     *
     * @param array<string, mixed> $body
     */
    private function answer(int $ticket, array $body, string $key): ResponseInterface
    {
        return $this->send('POST', "/api/admin/tickets/{$ticket}/messages", $body, ['X-Requested-With' => 'XMLHttpRequest', IdempotencyKey::HEADER => $key]);
    }

    /** How many lines the customer's wallet holds. */
    private function lines(User $customer): int
    {
        return WalletTransaction::query()->where('user_id', $customer->id)->count();
    }
}
