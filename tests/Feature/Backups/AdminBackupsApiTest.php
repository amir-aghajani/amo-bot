<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Core\Application;
use App\Core\Security\Encrypter;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Backups\Backups;
use App\Modules\Backups\BackupScope;
use App\Modules\Backups\BackupWriter;
use App\Modules\Backups\Exceptions\BackupRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Models\Setting;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * «پشتیبان‌گیری» through the panels: the owner in the main shop works the whole installation, an agent their shop alone;
 * a backup downloads as it is made; a file is uploaded, described, then restored on a yes — the state before kept to
 * download —; a file of the wrong scope or shop, or a token of another shop's, restores nothing. A file made before
 * 0.11.0 — each bot's webhook address whole in it — is never an admin's working a bot's management with its seat: not
 * listed, refused to download, send or restore; the owner, and an agent their own shop's, keep it.
 */
final class AdminBackupsApiTest extends HttpTestCase
{
    private const MAIN_SECRET = 'MAINSECRET0123456789abcdef';

    private Server $berlin;
    private Bot $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berlin = $this->sellingServer('Berlin');
        $this->plan(['name' => 'اصلی'], $this->berlin);
        $this->customer(['telegram_id' => 501]);
        $this->shop = $this->agentBot();
        CurrentBot::run($this->shop, function (): void {
            $this->customer(['telegram_id' => 601, 'first_name' => 'رضا']);
            $this->plan(['name' => 'نماینده'], $this->berlin);
        });
    }

    public function testTheOwnerInTheMainShopWorksTheWholeInstallationAndAnAgentTheirShop(): void
    {
        $this->loginAsAdmin();
        $status = $this->decode($this->get('/api/admin/backups'))['backups'];
        self::assertSame(['installation', null, true, false, 24 * 60, []], [$status['scope'], $status['shop'], $status['auto']['enabled'], $status['auto']['group'], $status['auto']['every_minutes'], $status['kept']]);
        self::assertSame('installation', $this->header($this->get('/api/admin/backups/download'))['scope']);

        $this->loginAsAgent($this->shop);
        $theirs = $this->decode($this->get('/api/agent/backups'))['backups'];
        self::assertSame(['shop', $this->shop->id], [$theirs['scope'], $theirs['shop']['id']]);
        self::assertSame(['shop', $this->shop->id], [$this->header($this->get('/api/agent/backups/download'))['scope'], $this->header($this->get('/api/agent/backups/download'))['shop']['id']]);
    }

    public function testAnAgentRestoresTheirShopOnAYesAndTheStateBeforeIsKeptToDownload(): void
    {
        $this->loginAsAgent($this->shop);
        $file = (string) $this->get('/api/agent/backups/download')->getBody();
        CurrentBot::run($this->shop, fn() => $this->customer(['telegram_id' => 602]));

        $inspection = $this->decode($this->upload('/api/agent/backups/inspect', 'file', 'backup.json.gz', $file))['inspection'];
        self::assertSame(['shop', $this->shop->id, []], [$inspection['scope'], $inspection['shop']['id'], $inspection['warnings']]);
        self::assertSame(1, array_column($inspection['tables'], 'rows', 'table')['users']);

        $refused = $this->unchecked()->postJson('/api/agent/backups/restore', ['token' => $inspection['token'], 'confirm' => false]);
        self::assertSame(422, $refused->getStatusCode(), 'a restore needs its yes');
        self::assertSame(['confirm'], array_keys($this->decode($refused)['errors']));
        self::assertSame(2, CurrentBot::run($this->shop, static fn(): int => User::query()->count()), 'nothing moved');

        $restored = $this->decode($this->postJson('/api/agent/backups/restore', ['token' => $inspection['token'], 'confirm' => true]))['restore'];
        self::assertSame(1, $restored['rows']['users']);
        self::assertSame(['601'], CurrentBot::run($this->shop, static fn(): array => User::query()->pluck('telegram_id')->map('strval')->all()), 'the shop as it was');

        $kept = $this->decode($this->get('/api/agent/backups'))['backups']['kept'];
        self::assertSame($restored['kept'], $kept[0]['name'] ?? null, 'the newest first');
        $before = $this->get('/api/agent/backups/kept/' . $restored['kept']);
        self::assertSame([200, 'shop'], [$before->getStatusCode(), $this->header($before)['scope']]);
        self::assertSame(404, $this->get('/api/agent/backups/kept/nothing.json.gz')->getStatusCode());

        $again = $this->postJson('/api/agent/backups/restore', ['token' => $inspection['token'], 'confirm' => true]);
        self::assertSame([BackupRefusedException::EXPIRED], $this->decode($again)['errors']['file'], 'a token restores once');
    }

    public function testAFileOfAnotherScopeOrShopIsRefusedAndAnotherShopsTokenRestoresNothing(): void
    {
        $this->loginAsAdmin();
        $whole = (string) $this->get('/api/admin/backups/download')->getBody();
        $theirs = (string) $this->get('/api/admin/backups/download?shop=' . $this->shop->id)->getBody();

        self::assertSame([BackupRefusedException::SHOP_ONLY], $this->refusal($this->upload('/api/admin/backups/inspect', 'file', 'b.json.gz', $theirs)), 'a shop\'s file in the owner\'s main panel');

        $this->loginAsAgent($this->shop);
        self::assertSame([BackupRefusedException::WHOLE_ONLY], $this->refusal($this->upload('/api/agent/backups/inspect', 'file', 'b.json.gz', $whole)));
        $token = $this->decode($this->upload('/api/agent/backups/inspect', 'file', 'b.json.gz', $theirs))['inspection']['token'];

        $other = $this->agentBot($this->agent(overrides: ['telegram_id' => 9002]), overrides: ['telegram_id' => 777001, 'username' => 'other_shop_bot']);
        self::assertSame(200, $this->postJson('/api/agent/auth/link', ['code' => $this->loginCode($other), 'replace' => true])->getStatusCode());
        self::assertSame([sprintf(BackupRefusedException::OTHER_SHOP, '@' . $this->shop->username)], $this->refusal($this->upload('/api/agent/backups/inspect', 'file', 'b.json.gz', $theirs)));
        self::assertSame([BackupRefusedException::EXPIRED], $this->refusal($this->postJson('/api/agent/backups/restore', ['token' => $token, 'confirm' => true])), 'another shop\'s upload is none of theirs');
        self::assertSame([BackupRefusedException::NOT_A_BACKUP], $this->refusal($this->upload('/api/agent/backups/inspect', 'file', 'b.json.gz', 'hello')));
    }

    public function testTheGroupsBackupIsTheReportTopicsSwitch(): void
    {
        $this->loginAsAgent($this->shop);

        self::assertFalse($this->decode($this->putJson('/api/agent/backups/auto', ['enabled' => false]))['backups']['auto']['enabled']);
        self::assertFalse($this->decode($this->get('/api/agent/bot/settings'))['settings']['report_backups']);
    }

    /**
     * A state kept before a restore by 0.10.x holds the main bot's webhook address whole — its secret alone lets an update
     * in under the owner's Telegram account —: one of the main shop's admins, from the bot with the owner's seat, neither
     * sees it listed nor gets it (as a file sent in the bot either) nor restores it; a state kept since stays theirs. An
     * agent's shop's, the same for an admin with the owner's or the agent's seat; the agent keeps it.
     */
    public function testAStateKeptBeforeZeroElevenIsNeverABotAdminsWithTheOwnersSeat(): void
    {
        $old = $this->keptBefore011(BackupScope::installation());
        $new = $this->keptNow(BackupScope::installation());

        $bridge = $this->bridge($this->admin(['telegram_id' => 5151]));
        self::assertSame([$new], array_column($bridge->get('/backups')['backups']['kept'], 'name'), 'the state kept before 0.11.0 not listed');
        $this->assertOwnersBackup(static fn() => $bridge->file('/backups/kept/' . $old), 'not sent');
        self::assertStringNotContainsString(self::MAIN_SECRET, (string) gzdecode($bridge->file('/backups/kept/' . $new)->bytes), 'one kept since is theirs');

        $bytes = (string) file_get_contents($this->keptFolder(BackupScope::installation()) . '/' . $old);
        $this->assertOwnersBackup(static fn() => $bridge->upload('/backups/inspect', 'file', $old, $bytes, 'application/gzip'), 'not restored');
        self::assertSame([], glob($this->app()->container()->get('backups.path') . '/uploads/*') ?: [], 'the upload not kept');
        self::assertFileExists($this->keptFolder(BackupScope::installation()) . '/' . $old, 'the owner\'s file left as it is');

        // Working an agent's shop from the bot, the same: the shop's old state holds its webhook's secret.
        $theirs = $this->keptBefore011(BackupScope::shop($this->shop->id));
        $there = $this->bridge(User::query()->where('telegram_id', 5151)->firstOrFail(), $this->shop->id);
        self::assertSame([], $there->get('/backups')['backups']['kept']);
        $this->assertOwnersBackup(static fn() => $there->file('/backups/kept/' . $theirs), 'not sent from another shop either');

        // Nor one of the agent's bot's admins, with the agent's seat: the secret would be the agent's bot's.
        $this->assertOwnersBackup(fn() => CurrentBot::run($this->shop, function () use ($theirs) {
            $seat = $this->service(AdminSeats::class)->of($this->admin(['telegram_id' => 6161]));
            self::assertNotNull($seat);
            self::assertSame([], $this->service(PanelBridge::class)->for($seat)->get('/backups')['backups']['kept']);

            return $this->service(PanelBridge::class)->for($seat)->file('/backups/kept/' . $theirs);
        }), 'not sent to an admin of the agent\'s bot');

        // The agent keeps their own shop's.
        $this->loginAsAgent($this->shop);
        self::assertSame([$theirs], array_column($this->decode($this->get('/api/agent/backups'))['backups']['kept'], 'name'));
        self::assertSame('0.10.4', $this->header($this->get('/api/agent/backups/kept/' . $theirs))['app_version']);
    }

    /** The owner themselves keeps every state kept before a restore, one made before 0.11.0 too: to download, and to restore. */
    public function testTheOwnerDownloadsAndRestoresAStateKeptBeforeZeroEleven(): void
    {
        $old = $this->keptBefore011(BackupScope::installation());
        $this->loginAsAdmin();

        self::assertSame([$old], array_column($this->decode($this->get('/api/admin/backups'))['backups']['kept'], 'name'));
        $download = $this->get('/api/admin/backups/kept/' . $old);
        self::assertSame('0.10.4', $this->header($download)['app_version']);
        self::assertStringContainsString(self::MAIN_SECRET, (string) gzdecode((string) $download->getBody()), 'the owner\'s file as it is');

        $inspection = $this->decode($this->upload('/api/admin/backups/inspect', 'file', $old, (string) $download->getBody()))['inspection'];
        self::assertSame('0.10.4', $inspection['app_version']);
        $this->decode($this->postJson('/api/admin/backups/restore', ['token' => $inspection['token'], 'confirm' => true]));
        self::assertSame(json_encode('https://shop.example.com/webhooks/telegram/***', JSON_UNESCAPED_SLASHES), Setting::query()->where('bot_id', Bot::MAIN)->where('key', 'telegram.webhook_url')->value('value'), 'restored without its secret');
    }

    /** @return array<string, mixed> The header line of a backup the response carried */
    private function header(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/gzip', $response->getHeaderLine('Content-Type'));
        $lines = explode("\n", (string) gzdecode((string) $response->getBody()));

        return json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function refusal(ResponseInterface $response): array
    {
        self::assertSame(422, $response->getStatusCode());

        return $this->decode($response)['errors']['file'] ?? [];
    }

    /**
     * A state of `$scope` kept before a restore by 0.10.x, as it stands on disk: its header's version 0.10.4 (signed as
     * this installation signs), and the bot's webhook address whole in it, as BotState kept it then — the row masked
     * since (database/upgrades/0.11.0.php).
     */
    private function keptBefore011(BackupScope $scope): string
    {
        $address = $scope->isShop() ? 'https://shop.example.com/webhooks/telegram/bot/' . $scope->shop . '/AGENTSECRET0123456789' : 'https://shop.example.com/webhooks/telegram/' . self::MAIN_SECRET;
        $masked = (string) preg_replace('~[^/]+$~', '***', $address);
        Setting::query()->updateOrCreate(['bot_id' => $scope->shop ?? Bot::MAIN, 'key' => 'telegram.webhook_url'], ['value' => json_encode($masked, JSON_UNESCAPED_SLASHES)]);
        $made = $this->scratchDir() . '/made.json.gz';
        $this->service(BackupWriter::class)->write($scope, $made);

        // As a writer before 0.11.0 wrote it: the address whole, the header its version.
        $lines = gzfile($made) ?: [];
        array_pop($lines);
        $lines = array_map(static fn(string $line): string => str_replace($masked, $address, $line), $lines);
        $lines[0] = str_replace('"app_version":"' . Application::VERSION . '"', '"app_version":"0.10.4"', $lines[0]);
        $signature = hash_init('sha256', HASH_HMAC, $this->service(Encrypter::class)->macKey(BackupWriter::PURPOSE));
        foreach ($lines as $line) {
            hash_update($signature, $line);
        }
        $name = 'amobot-backup-' . ($scope->isShop() ? 'shop-' . $scope->shop : 'installation') . '-before-restore-20261120-101010-abcd.json.gz';
        file_put_contents($this->keptFolder($scope) . '/' . $name, (string) gzencode(implode('', $lines) . json_encode(['signature' => hash_final($signature)]) . "\n"));
        // Older than any kept since.
        touch($this->keptFolder($scope) . '/' . $name, time() - 3600);

        return $name;
    }

    /** A state of `$scope` kept before a restore by this version. */
    private function keptNow(BackupScope $scope): string
    {
        $name = $this->service(Backups::class)->fileName($scope, 'before-restore');
        $this->service(BackupWriter::class)->write($scope, $this->keptFolder($scope) . '/' . $name);

        return $name;
    }

    private function keptFolder(BackupScope $scope): string
    {
        $folder = $this->app()->container()->get('backups.path') . '/kept/' . ($scope->isShop() ? 'shop-' . $scope->shop : 'installation');
        if (!is_dir($folder)) {
            mkdir($folder, 0o777, true);
        }

        return $folder;
    }

    /** The panel's API as the bot's management calls it for one of the main shop's admins, with the owner's seat. */
    private function bridge(User $admin, ?int $shop = null): PanelBridge
    {
        $seat = $this->service(AdminSeats::class)->of($admin, $shop);
        self::assertNotNull($seat);
        self::assertFalse($seat->principal->isTheOwner());

        return $this->service(PanelBridge::class)->for($seat);
    }

    private function assertOwnersBackup(\Closure $call, string $why): void
    {
        try {
            $call();
            self::fail($why);
        } catch (ApiRefusal $e) {
            self::assertSame([403, ActorRefusedException::OWNERS_BACKUP], [$e->status, $e->getMessage()], $why);
        }
    }
}
