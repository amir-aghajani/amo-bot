<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Core\Database\SortDirection;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * A paged list: what was asked for, read once from the query string, and the one shape every list answers.
 */
final class PageTest extends DatabaseTestCase
{
    public function testARequestReadsItsOrderAsOneOfTheListsKeysAndADirection(): void
    {
        $keys = ['joined' => 'id', 'name' => 'first_name'];
        $order = static fn(array $query): array => [($sort = PageRequest::fromQuery($query)->sort($keys, 'joined'))->key, $sort->dir];

        self::assertSame(['joined', SortDirection::Desc], $order([]), "the list's own, newest first");
        self::assertSame(['name', SortDirection::Desc], $order(['sort' => 'name']));
        self::assertSame(['name', SortDirection::Asc], $order(['sort' => ' name ', 'dir' => 'asc']));
        self::assertSame(['joined', SortDirection::Asc], $order(['sort' => 'nonsense', 'dir' => 'asc']), 'a key the list does not have: its own, still the direction asked');
        self::assertSame(['joined', SortDirection::Desc], $order(['sort' => ['name'], 'dir' => 'sideways']), 'not one of the words: none of them');

        $this->expectException(\LogicException::class);
        PageRequest::fromQuery([])->sort($keys, 'balance');
    }

    public function testASortedPageIsInItsKeysOrderTiesInTheOrderOfTheirIdsAndSaysSo(): void
    {
        foreach (range(1, Page::PER_PAGE + 5) as $n) {
            $this->customer(['telegram_id' => 1000 + $n, 'first_name' => $n % 3 === 0 ? 'b' : 'a', 'last_name' => (string) ($n % 2)]);
        }
        $keys = ['joined' => 'id', 'name' => 'first_name', 'full' => ['first_name', new Expression('last_name')]];
        $page = static function (array $query) use ($keys): Page {
            $request = PageRequest::fromQuery($query);

            return Page::fetch(User::query(), $request, static fn(User $user): array => ['id' => $user->id], $request->sort($keys, 'joined'));
        };
        $ids = static fn(array $query): array => array_column($page($query)->rows, 'id');

        self::assertSame([18, 21, 24, 27, 30], $ids(['sort' => 'name', 'dir' => 'asc', 'page' => '2']), "the a's, then the b's — each in the order of their ids");
        self::assertSame([7, 5, 4, 2, 1], $ids(['sort' => 'name', 'page' => '2']), 'the other way: the exact reverse');
        self::assertSame([3, 9, 15, 21, 27], $ids(['sort' => 'full', 'dir' => 'asc', 'page' => '2']), 'by each of its terms in turn');
        self::assertSame([26, 27, 28, 29, 30], $ids(['dir' => 'asc', 'page' => '2']), "the list's own, the other way");
        self::assertSame(
            ['page' => 2, 'per_page' => Page::PER_PAGE, 'total' => 30, 'last_page' => 2, 'sort' => 'name', 'dir' => 'asc', 'failed' => 3],
            $page(['sort' => 'name', 'dir' => 'asc', 'page' => '2'])->with(['failed' => 3])->meta(),
            "the order beside the pager's, the list's figures after it",
        );
    }

    public function testARequestReadsThePageTheTermAndEachFilterAsWhatItMayBe(): void
    {
        $request = PageRequest::fromQuery(['page' => '۲', 'search' => ' #۱۲ ', 'status' => 'paid', 'type' => 'nonsense', 'server' => '۳', 'group' => '0', 'level' => 'x', 'tab' => ' expiring ']);

        self::assertSame([2, '#12', 12], [$request->page, $request->term, $request->number()]);
        self::assertSame(OrderStatus::Paid, $request->enum('status', OrderStatus::class));
        self::assertNull($request->enum('type', OrderStatus::class), 'not a case: no filter');
        self::assertNull($request->enum('missing', OrderStatus::class));
        self::assertSame([3, null, null, null], [$request->id('server'), $request->id('group'), $request->id('level'), $request->id('missing')]);
        self::assertSame(['expiring', ''], [$request->text('tab'), $request->text('missing')]);

        foreach ([[], ['page' => '0'], ['page' => '-3'], ['page' => 'two'], ['page' => ['1']]] as $query) {
            self::assertSame(1, PageRequest::fromQuery($query)->page, json_encode($query) ?: '');
        }
        self::assertNull(PageRequest::fromQuery(['search' => 'ali_12'])->number(), 'a name is no row number');
        self::assertNull(PageRequest::fromQuery(['search' => '#'])->number());
        self::assertNull(PageRequest::fromQuery([])->number());
    }

    public function testAHashAndANumberNameTheRowAloneABareNumberIsASearch(): void
    {
        self::assertSame([12, 12], [PageRequest::fromQuery(['search' => '#۱۲'])->row(), PageRequest::fromQuery(['search' => '#12'])->number()]);
        self::assertSame([null, 12], [PageRequest::fromQuery(['search' => '12'])->row(), PageRequest::fromQuery(['search' => '12'])->number()], 'a bare number searches');
        self::assertNull(PageRequest::fromQuery(['search' => '#ali'])->row());
        self::assertNull(PageRequest::fromQuery([])->row());
    }

    public function testTheSearchBoxIsTheRowNumberedAloneOrTheListsOwnSearch(): void
    {
        $ali = $this->customer(['telegram_id' => 900_001, 'first_name' => 'Ali']);
        // Her Telegram id is Ali's number: a bare number finds her, «#n» never.
        $this->customer(['telegram_id' => $ali->id, 'first_name' => 'Sara']);
        $asked = [];
        $found = static function (array $query, ?string $numbered = null) use (&$asked): array {
            $users = User::query();
            /** @param Builder<User> $users */
            $search = static function (Builder $users, string $term, ?int $number) use (&$asked): void {
                $asked[] = [$term, $number];
                User::matching($users, $term);
            };
            PageRequest::fromQuery($query)->search($users, $search, $numbered);

            return $users->orderBy('id')->pluck('first_name')->all();
        };

        self::assertSame(['Ali'], $found(['search' => "#{$ali->id}"]), 'the row numbered so, though another matches the number');
        self::assertSame(['Sara'], $found(['search' => "#{$ali->id}"], 'telegram_id'), 'numbered by another column');
        self::assertSame([], $asked, "the list's own search is not asked");

        self::assertSame(['Sara'], $found(['search' => (string) $ali->id]), "a bare number: the list's own search");
        self::assertSame(['Ali', 'Sara'], $found([]), 'an empty box narrows nothing');
        self::assertSame(['Ali'], $found(['search' => 'ali']));
        self::assertSame([[(string) $ali->id, $ali->id], ['ali', null]], $asked, 'the term, and the term as a number');
    }

    public function testARequestReadsItsDaysAsTheShopsMidnightsInUtc(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            $days = PageRequest::fromQuery(['from' => '2026-10-06', 'to' => '2026-10-07'])->dates();
            self::assertSame(['2026-10-05 20:30:00', '2026-10-07 20:30:00'], [$days->from?->toDateTimeString(), $days->until?->toDateTimeString()], "from the shop's midnight starting the first day to the one ending the last");
            self::assertSame(['UTC', 'UTC'], [$days->from?->getTimezone()->getName(), $days->until?->getTimezone()->getName()], 'as the database keeps moments');

            foreach ([[], ['from' => 'yesterday', 'to' => '2026-02-31'], ['from' => '2026-10-06 00:00', 'to' => '06-10-2026'], ['from' => ['2026-10-06'], 'to' => '']] as $query) {
                $none = PageRequest::fromQuery($query)->dates();
                self::assertSame([null, null], [$none->from, $none->until], (string) json_encode($query));
            }
            self::assertNull(PageRequest::fromQuery(['from' => '2026-10-06'])->dates()->until, 'either alone');
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testDaysNarrowAQueryAndATallyAddsUpItsMoney(): void
    {
        $customer = $this->customer();
        foreach (['2026-10-05 23:59:59' => '10.50', '2026-10-06 00:00:00' => '20.25', '2026-10-06 23:59:59' => '30.00', '2026-10-07 00:00:00' => '40.00'] as $at => $amount) {
            Carbon::setTestNow($at);
            $this->topUpOrder($customer, $amount);
        }
        $query = Order::query();
        PageRequest::fromQuery(['from' => '2026-10-06', 'to' => '2026-10-06'])->dates()->apply($query, 'created_at');

        self::assertSame([2, '50.25'], Page::tally(clone $query, 'amount'), 'both ends of the day, and nothing past them');
        self::assertSame([0, '0.00'], Page::tally(Order::query()->where('amount', '<', 0), 'amount'), 'nothing adds up to nothing');
    }

    public function testAPageIsTheRowsAskedForWithThePagersMetaAndTheListsOwnFigures(): void
    {
        foreach (range(1, Page::PER_PAGE + 5) as $n) {
            $this->customer(['telegram_id' => 1000 + $n, 'first_name' => "c{$n}"]);
        }
        $names = static fn(User $user): array => ['name' => $user->first_name];

        $second = Page::fetch(User::query()->orderBy('id'), PageRequest::fromQuery(['page' => '2']), $names);
        self::assertSame(['c26', 'c27', 'c28', 'c29', 'c30'], array_column($second->rows, 'name'));
        self::assertSame(['page' => 2, 'per_page' => Page::PER_PAGE, 'total' => 30, 'last_page' => 2], $second->meta());

        $past = Page::fetch(User::query()->orderBy('id'), PageRequest::fromQuery(['page' => '9']), $names);
        self::assertSame(2, $past->page, 'a page past the end is the last one');

        $answer = $second->with(['failed' => 3])->with(['awaiting' => 1])->toArray('customers');
        self::assertSame(['customers', 'meta'], array_keys($answer));
        self::assertSame(['page' => 2, 'per_page' => Page::PER_PAGE, 'total' => 30, 'last_page' => 2, 'failed' => 3, 'awaiting' => 1], $answer['meta'], 'the list\'s figures beside the pager\'s');
        self::assertSame(['page' => 2, 'per_page' => Page::PER_PAGE, 'total' => 30, 'last_page' => 2], $second->meta(), 'with() makes a new page');

        self::assertSame(['lines' => [], 'meta' => ['page' => 1, 'per_page' => Page::PER_PAGE, 'total' => 0, 'last_page' => 1]], Page::empty()->toArray('lines'));
    }

    public function testTheSearchTermIsTrimmedLatinDigitsCutToSixtyFour(): void
    {
        self::assertSame('', Page::term([]));
        self::assertSame('', Page::term(['search' => '   ']));
        self::assertSame('@ali', Page::term(['search' => ' @ali ']));
        self::assertSame('777001', Page::term(['search' => '۷۷۷۰۰۱']));
        self::assertSame(str_repeat('x', 64), Page::term(['search' => str_repeat('x', 80)]));
        self::assertSame('', Page::term(['search' => ['nested']]), 'not a scalar');
    }

    public function testASearchTakesTheTermsWildcardsLiterally(): void
    {
        foreach (['ali_r', 'alixr', '50%_off', '50xxoff', 'wow!', 'wow'] as $n => $name) {
            $this->customer(['telegram_id' => 100 + $n, 'first_name' => $name]);
        }
        $names = static fn(string $term): array => Page::whereContains(User::query(), 'first_name', $term)->orderBy('id')->pluck('first_name')->all();

        self::assertSame(['ali_r'], $names('ali_r'), 'an underscore is an underscore, not "any character"');
        self::assertSame(['50%_off'], $names('50%_'));
        self::assertSame(['wow!'], $names('w!'), 'and so is the escape character itself');
        self::assertSame(['ali_r', 'alixr'], $names('ali'));

        $either = Page::orWhereContains(Page::whereContains(User::query(), 'first_name', 'ali_'), 'first_name', '%_o');
        self::assertSame(['ali_r', '50%_off'], $either->orderBy('id')->pluck('first_name')->all(), 'a second match joined with OR, its wildcards literal too');
    }
}
