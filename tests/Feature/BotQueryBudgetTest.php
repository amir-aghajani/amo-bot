<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\ChangeFeed;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\Update;
use Illuminate\Database\Events\QueryExecuted;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * What an update costs the bot, in queries and in calls to Telegram — a budget for each screen a customer meets most.
 * Every update claims its id, reads its chat's pace (a chat faster than a person goes unanswered: ReceivedUpdates) and
 * reads the customer and their chat, a query each, writing nothing unless something changed; a screen then reads its
 * own rows in a fixed few queries however many there are; the settings — every text,
 * keyboard and rule — are not read per update at all, but once each time the poller reads them afresh. A message is
 * answered in one call, a tap in two (the screen put in place, the tap answered). A budget raised is a decision: say
 * what the new query buys.
 */
final class BotQueryBudgetTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A live shop has every area's number already: its first write there is not what an update costs.
        foreach (array_unique(ChangeFeed::AREAS) as $area) {
            $this->db()->table('change_versions')->insertOrIgnore(['area' => $area, 'version' => 1]);
        }
        // And the bot has its settings, as the poller holds them between two reads.
        $this->service(Settings::class)->get('bot.enabled');
    }

    public function testAStartIsTheUpdateTheCustomerAndTheirChat(): void
    {
        [$queries, $calls] = $this->cost($this->message('/start'));
        // A newcomer: their row made (and counted for the panel), their arrival offered to the report group, their chat made.
        self::assertLessThanOrEqual(9, count($queries), self::listed($queries));
        self::assertSame(['sendMessage'], $calls);

        [$queries, $calls] = $this->cost($this->message('/start'));
        self::assertLessThanOrEqual(4, count($queries), self::listed($queries));
        self::assertSame(['telegram_updates'], self::written($queries), 'a customer back: nothing written but the update\'s id');
        self::assertSame(['sendMessage'], $calls);

        [$queries, $calls] = $this->cost($this->message('سلام'));
        self::assertLessThanOrEqual(4, count($queries), 'any other text shows the menu: ' . self::listed($queries));
        self::assertSame(['sendMessage'], $calls);
    }

    public function testTheSettingsAreReadOnceForEveryTextKeyboardAndRuleNotPerUpdate(): void
    {
        $this->send($this->message('/start'));
        $this->service(Settings::class)->refresh();

        self::assertCount(5, $this->cost($this->message('/start'))[0], 'read afresh: one query for every one of them');
        self::assertCount(4, $this->cost($this->message('/start'))[0], 'then none, until the next time');
    }

    public function testEachScreenReadsItsRowsInAFixedFewQueriesWhateverTheirNumber(): void
    {
        $this->withoutQr();
        $germany = $this->sellingServer('آلمان');
        $plan = $this->plan(on: [$germany]);
        $customer = $this->wallet($this->customer(), '500000');
        $service = $this->subscription($customer, $plan, $germany, 'ali_1');
        FakeProvider::mirror($service);
        $this->send($this->message('/start'));

        $budgets = [
            MainMenu::PLANS => 10,
            PurchaseHandler::planCallback($plan->id) => 10,
            MainMenu::SUBSCRIPTIONS => 6,
            // The panel asked as the screen opens, the row synced.
            SubscriptionHandler::serviceCallback($service->id) => 11,
            MainMenu::WALLET => 6,
            MainMenu::HOME => 4,
            // Last: the chat waits at the checkout from here, and leaving it is a write.
            PurchaseHandler::serverCallback($plan->id, $germany->id) => 11,
        ];
        $few = [];
        foreach ($budgets as $tap => $budget) {
            [$queries, $calls] = $this->cost($this->tap($tap));
            self::assertLessThanOrEqual($budget, count($queries), "{$tap}:\n" . self::listed($queries));
            self::assertSame(['editMessageText', 'answerCallbackQuery'], $calls, $tap);
            $few[$tap] = count($queries);
        }

        // Many more of every row: plans on more servers, services, the wallet's lines.
        $holland = $this->sellingServer('هلند');
        foreach (range(2, 7) as $n) {
            $more = $this->plan(['name' => "پلن {$n}"], [$germany, $holland]);
            $this->subscription($customer, $more, $holland, "ali_{$n}");
            $this->wallet($customer, (string) (500000 + $n * 1000));
        }
        $this->send($this->message('/start'));

        foreach ($few as $tap => $queries) {
            self::assertLessThanOrEqual($queries, count($this->cost($this->tap($tap))[0]), "{$tap}: more rows, no more queries");
        }
    }

    public function testAPurchaseFromTheWalletStaysWithinItsBudget(): void
    {
        $this->withoutQr();
        $germany = $this->sellingServer('آلمان');
        $plan = $this->plan(on: [$germany]);
        $this->wallet($this->customer(), '500000');
        $checkout = PurchaseHandler::serverCallback($plan->id, $germany->id);
        $this->send($this->tap($checkout));

        [$queries, $calls] = $this->cost($this->tap(CallbackData::build($checkout, $this->walletMethod()->id)));

        // The server judged again, the order opened, paid and settled in one transaction, the panel's client made and the
        // service written in another, each commit's areas counted in one statement, the outcome in place of the checkout.
        self::assertLessThanOrEqual(51, count($queries), self::listed($queries));
        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $calls);
    }

    public function testWhatThePollerSendsAfterEveryBatchReadsTwoRowsWhenNothingWaits(): void
    {
        $sender = $this->service(ReportSender::class);
        self::assertCount(1, $this->queriesOf(static fn() => $sender->flush()), 'no report group: its row');

        $this->reportGroup();
        self::assertCount(2, $this->queriesOf(static fn() => $sender->flush()), 'a group: its row, and the queue');
    }

    /** @return array{list<string>, list<string>} The statements the update cost, and the calls to Telegram it made */
    private function cost(Update $update): array
    {
        return [$this->queriesOf(fn() => $this->send($update)), $this->calls()];
    }

    /**
     * @param \Closure(): mixed $work
     * @return list<string> The statements `$work` ran
     */
    private function queriesOf(\Closure $work): array
    {
        // The change feed counts what the setup wrote before the next statement: that one is not the work's.
        $this->db()->select('select 1');
        $queries = [];
        $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        }, $work);

        return $queries;
    }

    /**
     * @param list<string> $queries
     * @return list<string> The tables they wrote to
     */
    private static function written(array $queries): array
    {
        $tables = [];
        foreach ($queries as $sql) {
            if (preg_match('/^\s*(?:insert(?:\s+or\s+\w+)?\s+into|update|delete\s+from)\s+"(\w+)"/i', $sql, $match) === 1) {
                $tables[] = $match[1];
            }
        }

        return array_values(array_unique($tables));
    }

    /** @param list<string> $queries */
    private static function listed(array $queries): string
    {
        return implode("\n", $queries);
    }
}
