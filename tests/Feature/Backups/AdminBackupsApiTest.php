<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Modules\Backups\Exceptions\BackupRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Providers\Models\Server;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * «پشتیبان‌گیری» through the panels: the owner in the main shop works the whole installation, an agent their shop alone;
 * a backup downloads as it is made; a file is uploaded, described, then restored on a yes — the state before kept to
 * download —; a file of the wrong scope or shop, or a token of another shop's, restores nothing.
 */
final class AdminBackupsApiTest extends HttpTestCase
{
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
}
