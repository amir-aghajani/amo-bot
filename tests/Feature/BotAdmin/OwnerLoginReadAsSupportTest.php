<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\Models\Bot;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Models\Import;
use App\Modules\Subscriptions\Enums\GrantAudience;
use App\Modules\Subscriptions\Enums\MigrationMode;
use App\Modules\Subscriptions\Enums\MigrationStatus;
use App\Modules\Subscriptions\Models\Grant;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Models\ServerMigration;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * The owner's login is half of what opens the panel (sign-in failures are counted per username). The main bot's admins
 * manage it with the owner's seat, the owner's own pages among them: there they read the owner's decisions as
 * «پشتیبانی» — an agency request decided, a grant on a server and a mass one, a move of a server's services, an import
 * started —, an admin's own as kept, by their name. The owner reads their own login as kept.
 */
final class OwnerLoginReadAsSupportTest extends BotTestCase
{
    private const LOGIN = 'qa-owner-7';

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['admin.username' => self::LOGIN, 'telegram.username' => 'amo_shop_bot']);
    }

    public function testTheMainBotsAdminsReadTheOwnersLoginAsSupportOnTheOwnersPages(): void
    {
        $sara = $this->customer(['telegram_id' => 7100, 'username' => 'sara_admin', 'first_name' => 'Sara', 'role' => UserRole::Admin]);
        $berlin = $this->sellingServer('Berlin');
        $paris = $this->sellingServer('Paris');

        $byOwner = $this->agencyRequest($this->customer(['telegram_id' => 501]));
        $byOwner->forceFill(['status' => AgencyRequestStatus::Rejected, 'reason' => 'کانال ندارد', 'reviewer' => self::LOGIN, 'decided_at' => now()])->save();
        $bySara = $this->agencyRequest($this->customer(['telegram_id' => 502]));
        $bySara->forceFill(['status' => AgencyRequestStatus::Rejected, 'reason' => 'کانال ندارد', 'reviewer' => '@sara_admin', 'decided_at' => now()])->save();
        $onServer = $this->grant(GrantAudience::Server, self::LOGIN, $berlin->id);
        $toAll = $this->grant(GrantAudience::All, '@sara_admin', $berlin->id);
        $move = ServerMigration::query()->create(['source_server_id' => $berlin->id, 'target_server_id' => $paris->id, 'mode' => MigrationMode::Migrate, 'status' => MigrationStatus::Done, 'reviewer' => self::LOGIN]);
        $import = Import::query()->create(['bot_id' => Bot::MAIN, 'migrator' => 'faoxima', 'source' => ImportSource::Backup, 'phase' => ImportPhase::Done, 'reviewer' => self::LOGIN, 'finished_at' => now()]);

        $read = static fn(PanelBridge $api): array => [
            'requests' => array_column($api->get('/agency/requests')['requests'], 'reviewer', 'id'),
            'request' => $api->get("/agency/requests/{$byOwner->id}")['request']['reviewer'],
            'mass grants' => array_column($api->get('/mass-grants')['grants'], 'reviewer', 'id'),
            'server grants' => array_column($api->get("/servers/{$berlin->id}/grants")['grants'], 'reviewer'),
            'moves' => array_column($api->get("/servers/{$berlin->id}/migrations")['migrations'], 'reviewer', 'id'),
            'move' => $api->get("/servers/{$berlin->id}/migrations/{$move->id}")['migration']['reviewer'],
            'imports' => array_column($api->get('/imports')['history'], 'reviewer', 'id'),
            'import' => $api->get("/imports/{$import->id}")['import']['reviewer'],
        ];

        self::assertSame([
            'requests' => [$bySara->id => '@sara_admin', $byOwner->id => Reviewers::SUPPORT],
            'request' => Reviewers::SUPPORT,
            'mass grants' => [$toAll->id => '@sara_admin', $onServer->id => Reviewers::SUPPORT],
            'server grants' => ['@sara_admin', Reviewers::SUPPORT],
            'moves' => [$move->id => Reviewers::SUPPORT],
            'move' => Reviewers::SUPPORT,
            'imports' => [$import->id => Reviewers::SUPPORT],
            'import' => Reviewers::SUPPORT,
        ], $read($this->bridge($sara)), "an admin on the owner's seat reads «پشتیبانی» for the owner, and an admin as kept");

        self::assertSame([
            'requests' => [$bySara->id => '@sara_admin', $byOwner->id => self::LOGIN],
            'request' => self::LOGIN,
            'mass grants' => [$toAll->id => '@sara_admin', $onServer->id => self::LOGIN],
            'server grants' => ['@sara_admin', self::LOGIN],
            'moves' => [$move->id => self::LOGIN],
            'move' => self::LOGIN,
            'imports' => [$import->id => self::LOGIN],
            'import' => self::LOGIN,
        ], $read($this->bridge($this->owner())), 'the owner reads their own login as kept');

        // An admin's own decision by their name, the owner's by none — whoever reads it.
        $admin = $this->bridge($sara);
        self::assertSame([$bySara->id => 'Sara', $byOwner->id => null], array_column($admin->get('/agency/requests')['requests'], 'reviewer_name', 'id'));
        self::assertSame([$toAll->id => 'Sara', $onServer->id => null], array_column($admin->get('/mass-grants')['grants'], 'reviewer_name', 'id'));
        self::assertSame(['Sara', null], array_column($admin->get("/servers/{$berlin->id}/grants")['grants'], 'reviewer_name'));
        self::assertNull($admin->get("/servers/{$berlin->id}/migrations/{$move->id}")['migration']['reviewer_name']);

        // Nowhere in what the admin reads, under any name.
        foreach (['/agency/requests', "/agency/requests/{$byOwner->id}", '/mass-grants', "/servers/{$berlin->id}/grants", "/servers/{$berlin->id}/migrations", "/servers/{$berlin->id}/migrations/{$move->id}", '/imports', "/imports/{$import->id}"] as $path) {
            self::assertStringNotContainsString(self::LOGIN, (string) json_encode($admin->get($path), JSON_UNESCAPED_UNICODE), $path);
        }
    }

    /** A grant that ended, under `$reviewer`, with one part on the server: what the grants' pages list. */
    private function grant(GrantAudience $audience, string $reviewer, int $server): Grant
    {
        $grant = Grant::query()->create(['days' => 3, 'traffic_bytes' => 0, 'reason' => 'قطعی', 'audience' => $audience, 'reviewer' => $reviewer]);
        GrantPart::query()->create(['grant_id' => $grant->id, 'server_id' => $server, 'status' => 'done', 'total' => 0]);

        return $grant;
    }

    /** The panels' API as `$user`'s seat in the main bot asks it, the seat as the bot gives it (AdminSeats::of()). */
    private function bridge(User $user): PanelBridge
    {
        $seat = $this->service(AdminSeats::class)->of($user);
        self::assertNotNull($seat);

        return $this->service(PanelBridge::class)->for($seat);
    }
}
