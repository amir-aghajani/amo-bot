<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Imports\Staging\Table;
use App\Modules\Imports\Survey\Recognition;
use App\Modules\Imports\Survey\Survey;
use App\Modules\Imports\Survey\SurveyGroup;

/**
 * A migrator for the core's tests (and the API's): bot "fake", whose records are whatever a test sets on it — each kind
 * a list, its seq its place from 1 —, yielded after the cursor as a real migrator yields them. It reads one table,
 * `members` (id, name; `secret` a secret), and takes a database as its bot's when that table is there. Its survey is
 * `$survey` when a test sets one, else counted from the records, with `$panels` and the customers' groups.
 */
final class FakeMigrator implements Migrator
{
    public const KEY = 'fake';
    public const LABEL = 'ربات آزمایشی';
    public const TABLE = 'members';

    public ?Survey $survey = null;

    /** @var list<ImportedPanel> */
    public array $panels = [];

    /** @var list<ImportedCustomer> */
    public array $customers = [];

    /** @var list<ImportedPlan> */
    public array $plans = [];

    /** @var list<ImportedService> */
    public array $services = [];

    /** @var list<ImportedDiscountCode> */
    public array $discountCodes = [];

    /** @var list<ImportedGiftCode> */
    public array $giftCodes = [];

    /** @var list<ImportedChannel> */
    public array $channels = [];

    /** @var list<ImportedCard> */
    public array $cards = [];

    /** Runs before each record is handed over, given its seq: something done elsewhere between two of them. */
    public ?\Closure $beforeEach = null;

    /** What its survey throws, for a test of data the migrator cannot read; null: it reads. */
    public ?\Throwable $surveyFails = null;

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(self::KEY, self::LABEL, 'رباتی برای آزمون', new Form(self::KEY, []), ['نسخه ۱'], [self::MARK => 'FK', self::VENDOR => 'https://example.com/fake-bot']);
    }

    public function tables(): array
    {
        return [self::TABLE => new Table(['id', 'name', 'secret'], secrets: ['secret'])];
    }

    public function recognize(Schema $schema): Recognition
    {
        return $schema->has(self::TABLE) ? Recognition::yes('1.0') : Recognition::no([self::TABLE]);
    }

    public function survey(Staged $staged): Survey
    {
        if ($this->surveyFails !== null) {
            throw $this->surveyFails;
        }
        if ($this->survey !== null) {
            return $this->survey;
        }
        $groups = [];
        foreach ($this->customers as $customer) {
            if ($customer->group !== null) {
                $groups[$customer->group] = ($groups[$customer->group] ?? 0) + 1;
            }
        }
        $tests = count(array_filter($this->services, static fn(ImportedService $service): bool => $service->test));

        return new Survey(
            version: null,
            counts: [
                'customers' => count($this->customers),
                'balances' => count(array_filter($this->customers, static fn(ImportedCustomer $customer): bool => $customer->balance !== '0')),
                'services' => count($this->services) - $tests,
                'tests' => $tests,
                'plans' => count($this->plans),
                'discount_codes' => count($this->discountCodes),
                'gift_codes' => count($this->giftCodes),
                'channels' => count($this->channels),
                'cards' => count($this->cards),
            ],
            balanceTotal: '0',
            panels: $this->panels,
            groups: array_map(static fn(string $name, int $count): SurveyGroup => new SurveyGroup($name, true, $count), array_keys($groups), array_values($groups)),
        );
    }

    public function customers(Staged $staged, int $after = 0): iterable
    {
        return $this->after($this->customers, $after);
    }

    public function plans(Staged $staged, int $after = 0): iterable
    {
        return $this->after($this->plans, $after);
    }

    public function services(Staged $staged, int $after = 0): iterable
    {
        return $this->after($this->services, $after);
    }

    public function discountCodes(Staged $staged, int $after = 0): iterable
    {
        return $this->after($this->discountCodes, $after);
    }

    public function giftCodes(Staged $staged, int $after = 0): iterable
    {
        return $this->after($this->giftCodes, $after);
    }

    public function channels(Staged $staged, int $after = 0): iterable
    {
        return $this->after($this->channels, $after);
    }

    public function cards(Staged $staged, int $after = 0): iterable
    {
        return $this->after($this->cards, $after);
    }

    /**
     * @template T of object
     * @param list<T> $records
     * @return \Generator<int, T>
     */
    private function after(array $records, int $after): \Generator
    {
        foreach ($records as $index => $record) {
            $seq = $index + 1;
            if ($seq > $after) {
                if ($this->beforeEach !== null) {
                    ($this->beforeEach)($seq);
                }
                yield $seq => $record;
            }
        }
    }
}
