<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\ChangeFeed;
use App\Core\Database\Sequence;
use App\Core\Scheduling\Shops;
use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Models\User;
use cebe\openapi\spec\Schema;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Tests\HttpTestCase;

/**
 * The panels' live view: every committed write to a table a panel shows moves its area's number, whoever wrote it; a
 * write inside a transaction counts when the transaction commits and not when it rolls back; a write made quietly
 * counts for nothing; a number that cannot be written is logged and never fails the write; the owner reads every
 * shop's numbers from GET /api/admin/changes, an agent's panel and a website's admins their own shop's, its areas the
 * API description's.
 */
final class ChangeFeedTest extends HttpTestCase
{
    public function testACommittedWriteMovesItsAreasNumber(): void
    {
        $before = $this->versions();

        $user = $this->customer();
        $this->customer(['telegram_id' => 4040]);
        $user->forceFill(['first_name' => 'Reza'])->save();

        self::assertSame(($before['users'] ?? 0) + 3, $this->versions()['users']);
        self::assertSame($before['payments'] ?? 0, $this->versions()['payments'] ?? 0, 'an area nobody wrote to stays');
    }

    public function testAWriteInsideATransactionCountsWhenItCommitsAndNotWhenItRollsBack(): void
    {
        $before = $this->versions()['users'] ?? 0;

        $this->db()->transaction(function () use ($before): void {
            $this->customer();
            $this->customer(['telegram_id' => 4040]);
            self::assertSame($before, $this->versions()['users'] ?? 0, 'not before the commit');
        });
        self::assertSame($before + 1, $this->versions()['users'], 'once for the transaction');

        try {
            $this->db()->transaction(function (): void {
                $this->customer(['telegram_id' => 5050]);
                throw new \RuntimeException('changed my mind');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($before + 1, $this->versions()['users'], 'a rolled-back write moves nothing');
    }

    public function testAWriteASavepointTookBackStillCountsWhenItsTransactionCommits(): void
    {
        $before = $this->versions()['users'] ?? 0;

        $this->db()->transaction(function (): void {
            try {
                $this->db()->transaction(function (): void {
                    $this->customer();
                    throw new \RuntimeException('changed my mind');
                });
            } catch (\RuntimeException) {
            }
        });

        self::assertSame($before + 1, $this->versions()['users'], 'a reload for nothing rather than a stale screen');
    }

    public function testAQuietWriteMovesNothingAndWhatIsWrittenAroundItStillCounts(): void
    {
        $user = $this->customer();
        $feed = $this->service(ChangeFeed::class);
        $before = $this->versions()['users'] ?? 0;

        $feed->quietly(static fn(): bool => $user->forceFill(['last_seen_at' => now()])->save());
        self::assertSame($before, $this->versions()['users'] ?? 0, 'a visit alone is no news for the panels');

        $this->db()->transaction(function () use ($feed, $user): void {
            $feed->quietly(static fn(): bool => $user->forceFill(['last_seen_at' => now()->addMinute()])->save());
            $user->forceFill(['first_name' => 'Reza'])->save();
        });
        self::assertSame($before + 1, $this->versions()['users'], 'the write beside it in its transaction counts');

        try {
            $feed->quietly(static fn() => throw new \RuntimeException('failed'));
        } catch (\RuntimeException) {
        }
        $user->forceFill(['first_name' => 'Ali'])->save();
        self::assertSame($before + 2, $this->versions()['users'], 'a quiet write that failed leaves the next one counted');
    }

    public function testEveryAreaTheFeedCountsIsOneTheApiDescriptionNames(): void
    {
        $versions = self::apiDescription()->components?->schemas['ChangesResponse']?->properties['versions'] ?? null;
        self::assertInstanceOf(Schema::class, $versions);

        $described = array_keys($versions->properties);
        $counted = array_values(array_unique(ChangeFeed::AREAS));
        sort($described);
        sort($counted);
        self::assertSame($counted, $described, 'the panels learn the areas from the description (lib/use-live-updates)');
    }

    public function testTablesThePanelDoesNotShowMoveNothing(): void
    {
        $before = $this->versions();

        $this->service(Sequence::class)->next('clients.test');
        $this->db()->table('telegram_sessions')->insert(['chat_id' => 1, 'state' => null, 'data' => '{}']);

        self::assertSame($before, $this->versions());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function statements(): iterable
    {
        yield 'mysql insert' => ['insert into `payments` (`amount`) values (?)', 'payments'];
        yield 'mysql insert ignore' => ['insert ignore into `orders` (`id`) values (?)', 'orders'];
        yield 'sqlite insert or ignore' => ['insert or ignore into "plans" ("id") values (?)', 'plans'];
        yield 'replace' => ['replace into `servers` (`id`) values (?)', 'servers'];
        yield 'update' => ['update `subscriptions` set `status` = ? where `id` = ?', 'subscriptions'];
        yield 'bare update' => ['UPDATE wallet_transactions SET amount = 1', 'users'];
        yield 'delete' => ['delete from "grant_parts" where "id" = ?', 'grants'];
        yield 'mysql delete with a join' => ['delete `plan_server_inbounds` from `plan_server_inbounds` inner join `plans` on 1', 'plans'];
        yield 'truncate' => ['truncate table `custom_emojis`', 'emoji'];
        yield 'a read' => ['select * from `payments` where `id` = ? for update', null];
        yield 'a table the panel does not show' => ['update `telegram_sessions` set `state` = ?', null];
    }

    #[DataProvider('statements')]
    public function testEveryKindOfWriteIsRecognised(string $sql, ?string $area): void
    {
        $before = $this->versions();

        $this->db()->getEventDispatcher()?->dispatch(new QueryExecuted($sql, [], 0.1, $this->db()));

        $expected = $before;
        if ($area !== null) {
            $expected[$area] = ($before[$area] ?? 0) + 1;
        }
        ksort($expected);
        $after = $this->versions();
        ksort($after);
        self::assertSame($expected, $after);
    }

    public function testATablesPrefixIsNotPartOfItsName(): void
    {
        // DB_PREFIX: the statements name amo_users, the area is still the users table's.
        $db = (new ConnectionFactory(new Container()))->make(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'amo_']);
        $db->setEventDispatcher(new Dispatcher(new Container()));
        $db->getSchemaBuilder()->create('change_versions', static function (Blueprint $table): void {
            $table->string('area', 32)->primary();
            $table->unsignedBigInteger('version')->default(0);
        });
        $db->getSchemaBuilder()->create('users', static fn(Blueprint $table) => $table->id());
        $feed = new ChangeFeed($db, $this->service(Shops::class), new NullLogger());
        $feed->listen();

        $db->table('users')->insert(['id' => 1]);

        self::assertSame(['users' => 1], $feed->versions());
        self::assertSame(['users' => 1], $feed->versions(1), "the main shop's write");
    }

    public function testAWriteTheFeedCannotCountStillHappens(): void
    {
        $logs = $this->logs();
        $this->db()->getSchemaBuilder()->drop('change_versions');

        $user = $this->customer();

        // The number would be written before this statement: it cannot be, which is only logged.
        self::assertSame(1, User::query()->whereKey($user->id)->count(), 'the write stood, and so does the next statement');
        self::assertTrue($logs->hasWarningThatContains('The change feed did not count a write to users'), 'the log says which area went uncounted');
    }

    public function testTheIdOfARowJustInsertedIsItsOwn(): void
    {
        // The driver's last insert id belongs to the INSERT that made the row: the feed's own writes come later.
        $first = $this->customer();
        $second = $this->customer(['telegram_id' => 4040]);

        self::assertSame(['Ali', 'Ali'], [User::query()->findOrFail($first->id)->first_name, User::query()->findOrFail($second->id)->first_name]);
        self::assertNotSame($first->id, $second->id);
        self::assertSame(4040, User::query()->findOrFail($second->id)->telegram_id);
    }

    /**
     * The owner follows every shop; an agent's panel its own shop — what is written across every shop, the servers, and
     * the main shop's agency with it —, never another shop's customers or sales.
     */
    public function testTheOwnerFollowsEveryShopAndAnAgentTheirOwn(): void
    {
        $agentBot = $this->agentBot();
        $this->loginAsAdmin();
        $this->loginAsAgent($agentBot);
        $owner = $this->panelVersions('/api/admin/changes');
        $agent = $this->panelVersions('/api/agent/changes');
        self::assertSame($this->versions(), $owner, 'the owner\'s are the installation\'s');
        self::assertSame($this->versions($agentBot->id), $agent, 'the agent\'s their shop\'s');

        $this->customer(['telegram_id' => 4040]);
        $this->db()->table('plans')->update(['updated_at' => now()]);
        self::assertSame([($owner['users'] ?? 0) + 1, ($owner['plans'] ?? 0) + 1], [$this->versions()['users'], $this->versions()['plans']], 'the main shop\'s customer and catalogue: the owner\'s to follow');
        self::assertSame($agent, $this->panelVersions('/api/agent/changes'), 'and nothing of the agent\'s');

        CurrentBot::run($agentBot, fn() => $this->customer(['telegram_id' => 5050]));
        self::assertSame(($agent['users'] ?? 0) + 1, $this->panelVersions('/api/agent/changes')['users'], 'their own shop\'s customer');
        self::assertSame(($owner['users'] ?? 0) + 2, $this->panelVersions('/api/admin/changes')['users'], 'the owner follows every shop');

        CurrentBot::everywhere(fn() => $this->db()->table('subscriptions')->update(['updated_at' => now()]));
        $this->fakeServer();
        $this->db()->table('agency_levels')->update(['updated_at' => now()]);
        $moved = $this->panelVersions('/api/agent/changes');
        self::assertSame(
            [($agent['subscriptions'] ?? 0) + 1, ($agent['servers'] ?? 0) + 1, ($agent['agency'] ?? 0) + 1],
            [$moved['subscriptions'], $moved['servers'], $moved['agency']],
            'what is written across every shop, the installation\'s servers, the main shop\'s agency',
        );
    }

    /** A website's admins follow their shop as its panel does: another shop's customers move nothing for them. */
    public function testAWebsitesAdminsFollowTheirOwnShop(): void
    {
        $agentBot = $this->agentBot();
        $website = $this->website();
        $this->loginAsStaff($website, $this->customer(['telegram_id' => 7001]));
        $read = fn(): array => $this->decode($this->get($this->storeApi($website, '/admin/changes')))['versions'];
        $before = $read();
        self::assertSame($this->versions(1), $before);

        CurrentBot::run($agentBot, fn() => $this->customer(['telegram_id' => 5050]));
        self::assertSame($before, $read(), "an agent's customer is no news for the main shop's admins");

        $this->customer(['telegram_id' => 4040]);
        self::assertSame(($before['users'] ?? 0) + 1, $read()['users']);
    }

    /** What the owner's agency writes in the main shop is about an agent's shop too: counted for every shop. */
    public function testTheOwnersAgencyMovesTheAgentsShop(): void
    {
        $agentBot = $this->agentBot();
        $server = $this->fakeServer();
        $this->loginAsAdmin();
        $before = $this->versions($agentBot->id)['plans'] ?? 0;

        $hidden = $this->putJson("/api/admin/agency/agents/{$agentBot->agent?->id}/hidden", ['server_ids' => [$server->id], 'plan_ids' => []]);

        self::assertSame(200, $hidden->getStatusCode(), (string) $hidden->getBody());
        self::assertSame($before + 1, $this->versions($agentBot->id)['plans'], 'the server hidden from their shop: their plans reload');
    }

    /** @return array<string, int> */
    private function panelVersions(string $path): array
    {
        $response = $this->get($path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return $this->decode($response)['versions'];
    }

    /** @return array<string, int> */
    private function versions(?int $shop = null): array
    {
        return $this->service(ChangeFeed::class)->versions($shop);
    }
}
