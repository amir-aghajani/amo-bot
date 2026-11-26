<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Modules\Backups\BackupReader;
use App\Modules\Backups\BackupScope;
use App\Modules\Backups\BackupWriter;
use App\Modules\Backups\Restorer;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Admin\SeatKind;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;

/**
 * A whole installation's restore puts the database back as the file has it — but not who is the owner in the bot, which
 * is the panel's login's as it is now, as no restore signs the owner out of the panel: an account the owner let go since
 * the backup stays let go, its admin role with it (an admin of the main bot manages it with the owner's seat); one linked
 * since stays linked, the role its link gave too; a role the owner gave by hand stays; a link the file held open opens
 * nothing; and where nothing is linked — a new host — nothing comes linked.
 */
final class RestoreKeepsTheOwnersAccountsTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheWholeInstallationsRestoreKeepsWhoIsTheOwnerInTheBotAsItIsNow(): void
    {
        $first = $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $second = $this->customer(['telegram_id' => 7002, 'first_name' => 'Amir 2']);
        $this->linkFromTheBot(7001);
        // A link made and left open when the backup is made.
        $open = self::payload($this->links()->link());
        self::assertSame([UserRole::Admin, UserRole::Customer], [$first->refresh()->role, $second->refresh()->role]);
        $path = $this->backup();

        // Since the backup: another account linked, and the first let go.
        $this->linkFromTheBot(7002);
        $this->links()->unlink(7001);
        self::assertSame([UserRole::Customer, UserRole::Admin], [$first->refresh()->role, $second->refresh()->role]);
        $before = $this->ownersRows();

        $this->restore($path);

        self::assertSame($before, $this->ownersRows(), 'the main shop\'s settings that say who is the owner, as they were just before');
        // What another request reads now: the settings from the database.
        $this->service(Settings::class)->refresh();
        $links = new OwnerLinks(new Settings(), $this->service(Bots::class));
        self::assertSame([7002], $links->ids(), 'the account let go is not linked again; the one linked since stays');
        self::assertNull($links->openUntil());
        self::assertFalse($links->opens($open), 'no link the file held opens');
        self::assertSame([UserRole::Customer, UserRole::Admin], [$first->refresh()->role, $second->refresh()->role], 'the role a link gave goes with it, and stays with the link since');

        $seats = $this->service(AdminSeats::class);
        self::assertNull($seats->of($first), 'the account let go manages nothing');
        $seat = $seats->of($second);
        self::assertSame([SeatKind::Owner, true], [$seat?->kind, $seat?->principal->isTheOwner()]);
    }

    public function testAnAdminRoleTheOwnerGaveByHandStaysWhenItsLinkGoes(): void
    {
        $first = $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $this->linkFromTheBot(7001);
        $path = $this->backup();
        // The owner made the account an admin by hand (OwnerLinks::chosen()), then let its link go: it stays an admin.
        $this->links()->chosen(7001);
        $this->links()->unlink(7001);
        self::assertSame(UserRole::Admin, $first->refresh()->role);

        $this->restore($path);

        $this->service(Settings::class)->refresh();
        self::assertSame([], (new OwnerLinks(new Settings(), $this->service(Bots::class)))->ids());
        self::assertSame(UserRole::Admin, $first->refresh()->role, 'an admin just before the restore, an admin after it');
    }

    /** On a new host nothing is linked yet: the restore links nothing either — the owner links their accounts again. */
    public function testARestoreWhereNothingIsLinkedLinksNothing(): void
    {
        $first = $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $this->linkFromTheBot(7001);
        $path = $this->backup();
        // As a new installation has it: no owner's setting at all, nobody's role a link's.
        $this->db()->table('settings')->where('bot_id', Bot::MAIN)->whereIn('key', OwnerLinks::KEYS)->delete();
        $first->refresh()->forceFill(['role' => UserRole::Customer])->save();

        $this->restore($path);

        self::assertSame(0, $this->db()->table('settings')->where('bot_id', Bot::MAIN)->whereIn('key', OwnerLinks::KEYS)->count(), 'none of the file\'s');
        $this->service(Settings::class)->refresh();
        self::assertSame([], (new OwnerLinks(new Settings(), $this->service(Bots::class)))->ids());
        self::assertSame(UserRole::Customer, $first->refresh()->role);
        self::assertNull($this->service(AdminSeats::class)->of($first));
    }

    /** `$telegramId` opens a new owner's link in the main bot, as the panel's «مدیریت از ربات» makes it. */
    private function linkFromTheBot(int $telegramId): void
    {
        $this->send($this->message('/start ' . self::payload($this->links()->link()), chat: $telegramId));
        self::assertTrue($this->links()->isOwner($telegramId));
    }

    private function links(): OwnerLinks
    {
        return $this->service(OwnerLinks::class);
    }

    /** @return array<string, mixed> The main shop's settings rows OwnerLinks keeps, by key: their value. */
    private function ownersRows(): array
    {
        $rows = $this->db()->table('settings')->where('bot_id', Bot::MAIN)->whereIn('key', OwnerLinks::KEYS)->orderBy('key')->pluck('value', 'key')->all();
        self::assertNotSame([], $rows);

        return $rows;
    }

    private function backup(): string
    {
        $path = $this->scratchDir() . '/' . bin2hex(random_bytes(4)) . '.json.gz';
        $this->service(BackupWriter::class)->write(BackupScope::installation(), $path);

        return $path;
    }

    private function restore(string $path): void
    {
        $contents = $this->service(BackupReader::class)->read($path);
        $restorer = $this->service(Restorer::class);
        $restorer->restore($path, $contents, $restorer->analyse($path, $contents));
        self::assertSame([], $this->db()->select('PRAGMA foreign_key_check'), 'every key holds after the restore');
    }

    /** @param array{url: string, expires_at: mixed}|null $link */
    private static function payload(?array $link): string
    {
        self::assertNotNull($link);

        return substr($link['url'], (int) strpos($link['url'], '=') + 1);
    }
}
