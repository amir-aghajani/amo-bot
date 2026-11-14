<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportStage;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use Tests\Fakes\FakeProvider;

/**
 * An import written a few things a step: it goes on from where each step stopped and comes to exactly what one go would
 * — nothing twice, its tally whole —; a step on a cancelled import stops at the next thing; and a second import of the
 * same data brings nothing again, only linking what the first brought.
 */
final class ResumingTest extends ImportTestCase
{
    public function testAnImportWrittenAFewThingsAStepComesToWhatOneGoWould(): void
    {
        $choices = $this->data();

        $import = $this->started($choices);
        $steps = 0;
        $stages = [];
        while ($import->phase === ImportPhase::Importing) {
            self::assertLessThan(60, ++$steps, 'it ends');
            $import = $this->step($import, Budget::checks(2));
            $stages[] = $import->stage?->value;
            $state = $this->imports()->present($import);
            if ($import->phase === ImportPhase::Importing) {
                self::assertLessThanOrEqual((int) $state['progress']['total'], $state['progress']['done']);
            }
        }

        self::assertSame(ImportPhase::Done, $import->phase);
        self::assertGreaterThan(5, $steps, 'two things a step');
        self::assertContains(ImportStage::Services->value, $stages, 'it stopped inside a stage');
        $this->assertBroughtOnce();
        self::assertSame(['created' => 5, 'linked' => 0, 'skipped' => 1], self::counts($import)['customers']);
        self::assertSame(['created' => 4, 'linked' => 0, 'skipped' => 0], self::counts($import)['balances']);
        self::assertSame(['created' => 3, 'linked' => 0, 'skipped' => 0], self::counts($import)['services']);
        self::assertSame(['not_telegram'], array_column(self::problemRows($import), 'reason'), 'each problem named once');
    }

    public function testASecondImportOfTheSameDataBringsNothingAgain(): void
    {
        $choices = $this->data();
        $this->importAll($choices);

        $again = $this->importAll($choices);

        $this->assertBroughtOnce();
        self::assertSame(['created' => 0, 'linked' => 5, 'skipped' => 1], self::counts($again)['customers']);
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 0], self::counts($again)['balances']);
        self::assertSame(['created' => 0, 'linked' => 3, 'skipped' => 0], self::counts($again)['services']);
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($again)['plans']);
    }

    public function testAStepOnAnImportCancelledMeanwhileStopsAtTheNextThing(): void
    {
        $this->migrator->customers = array_map(static fn(int $i): ImportedCustomer => new ImportedCustomer((string) $i, 1000 + $i), range(1, 5));
        $import = $this->started();
        $this->migrator->beforeEach = static function (int $seq) use ($import): void {
            if ($seq === 3) {
                Import::query()->whereKey($import->id)->update(['phase' => ImportPhase::Cancelled->value]);
            }
        };

        $import = $this->step($import, Budget::unlimited());

        self::assertSame(ImportPhase::Cancelled, $import->phase);
        self::assertSame(3, User::query()->count(), 'the thing under way is written; nothing after it');
        self::assertSame(2, $import->cursor, 'the cursor of the last thing saved while importing');
    }

    public function testAThingThatFailsToWriteIsItsOwnProblemAndTheImportGoesOn(): void
    {
        $this->migrator->customers = [new ImportedCustomer('1', 1001, balance: '5000'), new ImportedCustomer('2', 1002)];
        $logs = $this->logs();

        $import = $this->whileListening('eloquent.creating: ' . User::class, static function (User $user): void {
            if ($user->telegram_id === 1001) {
                throw new \RuntimeException('The disk is full.');
            }
        }, fn() => $this->importAll());

        self::assertSame(ImportPhase::Done, $import->phase);
        self::assertSame([1002], User::query()->pluck('telegram_id')->all());
        self::assertSame(0, WalletTransaction::query()->count(), 'nothing of it is left half-written');
        self::assertSame([['kind' => 'customers', 'key' => '1', 'reason' => 'failed', 'detail' => null]], self::problemRows($import));
        self::assertTrue($logs->hasErrorThatContains("Import {$import->id} could not write customers 1"));
    }

    public function testAStepOnAnImportThatEndedDoesNothing(): void
    {
        $import = $this->importAll();
        $this->migrator->customers = [new ImportedCustomer('1', 1001)];

        self::assertSame(ImportPhase::Done, $this->step($import, Budget::unlimited())->phase);
        self::assertSame(0, User::query()->count());
    }

    /**
     * Customers (one without Telegram), their balances, a plan, three services on the fake panel and a code.
     *
     * @return array<string, mixed> The choices that bring it all
     */
    private function data(): array
    {
        $this->fakePanel();
        $server = $this->fakeServer('آلمان');
        $this->migrator->panels = [self::fakePanelOf('p1', 'آلمان', 3)];
        $this->migrator->customers = [
            new ImportedCustomer('1', 1001, balance: '1000'),
            new ImportedCustomer('2', 1002, balance: '2000', referrerKey: '1'),
            new ImportedCustomer('3', 0),
            new ImportedCustomer('4', 1004, balance: '-4000'),
            new ImportedCustomer('5', 1005, balance: '5000', referrerKey: '4'),
            new ImportedCustomer('6', 1006),
        ];
        $this->migrator->plans = [new ImportedPlan('pl', 'یک‌ماهه', 'ماهانه', '100000', null, '30', 30)];
        $this->migrator->services = [];
        foreach (['1', '2', '5'] as $customer) {
            FakeProvider::put($server, new ClientInfo("client_{$customer}", true, subscriptionUrl: "https://fake.test/sub/{$customer}"));
            $this->migrator->services[] = new ImportedService("s{$customer}", $customer, 'p1', "client_{$customer}", planKey: 'pl');
        }
        $this->migrator->discountCodes = [new ImportedDiscountCode('d1', 'SALE', 'percent', '10')];

        return ['panels' => ['p1' => ['action' => Choices::SERVER, 'server_id' => $server->id]]];
    }

    private function assertBroughtOnce(): void
    {
        self::assertSame(5, User::query()->count());
        self::assertSame(4, WalletTransaction::query()->count());
        self::assertSame(['1000.00', '2000.00', '-4000.00', '5000.00', '0.00'], User::query()->orderBy('telegram_id')->get()->map(static fn(User $user): string => $user->balance())->all());
        self::assertSame(1, Plan::query()->count());
        self::assertSame(3, Subscription::query()->count());
        self::assertSame(1, DiscountCode::query()->count());
        $referrer = User::query()->where('telegram_id', 1004)->value('id');
        self::assertSame($referrer, User::query()->where('telegram_id', 1005)->value('referred_by'));
    }
}
