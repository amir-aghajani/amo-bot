<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Core\Application;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\Encrypter;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Backups\BackupContents;
use App\Modules\Backups\BackupReader;
use App\Modules\Backups\Backups;
use App\Modules\Backups\BackupScope;
use App\Modules\Backups\BackupTables;
use App\Modules\Backups\BackupWriter;
use App\Modules\Backups\Exceptions\BackupRefusedException;
use App\Modules\Backups\ForeignKeys;
use App\Modules\Backups\Restorer;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportLink;
use App\Modules\Imports\Models\ImportProblem;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\DatabaseTestCase;

/**
 * Backups and restores, the engine: a shop's backup holds its own rows and nothing of the owner's or another shop's; a
 * shop's restore puts its rows back, signs its customers out and leaves everything else as it is now — the owner's
 * terms, traffic and choices on its plans, what only the owner reads, every other shop —; the whole installation's puts
 * it all back and signs every customer out; a file anybody changed, of another installation, of another scope or shop,
 * of a newer version or one older than a restore takes is refused before anything moves. Every key holds after a
 * restore (RestoreCommittedTest restores outside a test's transaction, as a shop does).
 */
final class BackupsTest extends DatabaseTestCase
{
    private Server $berlin;
    private Plan $plan;
    private User $ali;
    private Bot $shop;
    private Plan $theirPlan;
    private User $reza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berlin = $this->sellingServer('Berlin');
        $this->plan = $this->plan(['name' => 'اصلی'], $this->berlin);
        $this->ali = $this->customer(['telegram_id' => 501]);
        $this->subscription($this->ali, $this->plan, $this->berlin, 'ali_1');

        $this->shop = $this->agentBot();
        [$this->theirPlan, $this->reza] = CurrentBot::run($this->shop, function (): array {
            $plan = $this->plan(['name' => 'نماینده'], $this->berlin);
            $reza = $this->customer(['telegram_id' => 601, 'first_name' => 'رضا']);
            $this->subscription($reza, $plan, $this->berlin, 'reza_1');

            return [$plan, $reza];
        });
    }

    public function testAShopsBackupHoldsItsOwnRowsAndNothingOfTheOwnersOrAnotherShops(): void
    {
        $path = $this->backup(BackupScope::shop($this->shop->id));
        $contents = $this->service(BackupReader::class)->read($path);

        self::assertSame(['id' => $this->shop->id], array_intersect_key((array) $contents->shop, ['id' => 0]));
        self::assertSame([], array_values(array_intersect(array_keys($contents->rows), BackupTables::OWNER)), 'no server, no agent\'s terms or traffic');

        $users = [];
        foreach ($this->service(BackupReader::class)->rows($path) as [$table, $columns, $row]) {
            $values = array_combine($columns, $row);
            if (in_array($table, BackupTables::SHOP, true)) {
                self::assertSame((string) $this->shop->id, (string) $values['bot_id'], "{$table}: the shop's own rows only");
            }
            if ($table === 'users') {
                $users[] = (string) $values['telegram_id'];
            }
        }
        self::assertSame(['601'], $users, 'its customers, never the main shop\'s');
        self::assertSame(1, $contents->rows['subscriptions']);
        self::assertSame(1, $contents->rows['plan_servers'], 'its plan\'s entry on the server, found by its plan');
    }

    public function testAShopsRestorePutsItsRowsBackAndLeavesTheOwnersAndEveryOtherShopAsTheyAreNow(): void
    {
        $path = $this->backup(BackupScope::shop($this->shop->id));
        $order = CurrentBot::run($this->shop, fn() => $this->purchaseOrder($this->reza, $this->theirPlan, $this->berlin));
        $later = CurrentBot::run($this->shop, fn() => $this->purchaseOrder($this->reza, $this->theirPlan, $this->berlin));
        $this->db()->table('orders')->where('id', $later->id)->delete();
        $kept = $this->db()->table('orders')->where('id', $order->id)->first();
        self::assertNotNull($kept);

        // After the backup: the shop changes, and so do the owner and the main shop.
        CurrentBot::run($this->shop, function (): void {
            $this->reza->forceFill(['first_name' => 'تغییرکرده'])->save();
            $this->customer(['telegram_id' => 602]);
        });
        $this->theirPlan->forceFill(['hidden_by_owner' => true])->save();
        $this->shop->forceFill(['selling_suspended_at' => now()])->save();
        $sara = $this->customer(['telegram_id' => 502]);
        $this->customerSession($this->reza);
        $this->customerSession($sara);
        $ledger = $this->db()->table('traffic_transactions')->insertGetId(['bot_id' => $this->shop->id, 'type' => 'debit', 'bytes' => 1, 'balance_after' => 0, 'description' => 'x', 'order_id' => $order->id, 'created_at' => now()]);

        $this->restore($path);

        $theirs = CurrentBot::run($this->shop, static fn(): array => User::query()->orderBy('telegram_id')->pluck('first_name', 'telegram_id')->all());
        self::assertSame([601 => 'رضا'], $theirs, 'the shop as it was: its customer back as he was, the one since gone');
        self::assertSame(0, CurrentBot::run($this->shop, fn(): int => $this->db()->table('orders')->where('bot_id', $this->shop->id)->count()), 'its orders since gone');
        self::assertTrue((bool) $this->theirPlan->refresh()->hidden_by_owner, 'a plan the owner keeps from sale stays kept');
        self::assertNotNull($this->shop->refresh()->selling_suspended_at, 'the shop\'s terms are the owner\'s: untouched');
        self::assertTrue(User::query()->whereKey($sara->id)->exists(), 'the main shop as it is now');
        self::assertNull($this->db()->table('traffic_transactions')->where('id', $ledger)->value('order_id'), 'the owner\'s ledger names no order the restore left out');
        self::assertSame(1, Subscription::acrossShops()->where('bot_id', $this->shop->id)->count());
        self::assertSame([Bot::MAIN], $this->db()->table('customer_sessions')->pluck('bot_id')->map('intval')->all(), 'its customers sign in again; the main shop\'s stay signed in');
    }

    public function testTheOwnersLinesNamingARowTheFileBringsBackNameItStill(): void
    {
        $order = CurrentBot::run($this->shop, fn() => $this->purchaseOrder($this->reza, $this->theirPlan, $this->berlin));
        $ledger = $this->db()->table('traffic_transactions')->insertGetId(['bot_id' => $this->shop->id, 'type' => 'debit', 'bytes' => 1, 'balance_after' => 0, 'description' => 'x', 'order_id' => $order->id, 'created_at' => now()]);
        $path = $this->backup(BackupScope::shop($this->shop->id));

        $this->restore($path);

        self::assertSame((string) $order->id, (string) $this->db()->table('traffic_transactions')->where('id', $ledger)->value('order_id'));
    }

    public function testTheWholeInstallationsRestorePutsEverythingBackAndSignsEveryCustomerOut(): void
    {
        $path = $this->backup(BackupScope::installation());
        $everyone = User::query()->withoutGlobalScopes()->orderBy('telegram_id')->pluck('first_name', 'telegram_id')->all();
        $this->ali->forceFill(['first_name' => 'تغییرکرده'])->save();
        $this->customer(['telegram_id' => 503]);
        CurrentBot::run($this->shop, fn() => $this->customer(['telegram_id' => 603]));
        $this->fakeServer('Paris');
        $this->customerSession($this->ali);

        $this->restore($path);

        self::assertSame($everyone, User::query()->withoutGlobalScopes()->orderBy('telegram_id')->pluck('first_name', 'telegram_id')->all(), "every shop's customers, and the agents, as they were");
        self::assertSame(['Berlin'], Server::query()->pluck('name')->all(), 'the servers too: the owner\'s');
        self::assertSame(0, $this->db()->table('customer_sessions')->count(), 'every sign-in gone: a restored one would open again');
        self::assertSame(1, Bot::query()->whereKey($this->shop->id)->count());
    }

    public function testAnImportsRecordReportAndLinksComeBackWithTheWholeInstallationButNeverItsStagedCopy(): void
    {
        $done = Import::query()->create(['bot_id' => Bot::MAIN, 'migrator' => 'faoxima', 'source' => ImportSource::Backup, 'phase' => ImportPhase::Done, 'counts' => ['customers' => ['created' => 1]]]);
        ImportProblem::query()->create(['import_id' => $done->id, 'kind' => 'customers', 'source_key' => '7', 'reason' => ProblemReason::NotATelegramAccount]);
        ImportLink::query()->create(['bot_id' => Bot::MAIN, 'migrator' => 'faoxima', 'kind' => 'customer', 'source_key' => '501', 'target_id' => $this->ali->id, 'import_id' => $done->id]);
        $open = Import::query()->create(['bot_id' => Bot::MAIN, 'migrator' => 'faoxima', 'source' => ImportSource::Backup, 'phase' => ImportPhase::Reading]);
        ImportRow::query()->create(['import_id' => $open->id, 'table_name' => 'user', 'data' => '{"id":"501"}']);
        $theShops = $this->backup(BackupScope::shop($this->shop->id));
        $path = $this->backup(BackupScope::installation());

        $contents = $this->service(BackupReader::class)->read($path);
        self::assertSame([2, 1, 1], [$contents->rows['imports'] ?? null, $contents->rows['import_problems'] ?? null, $contents->rows['import_links'] ?? null], 'the owner\'s imports, their reports, what they brought');
        self::assertArrayNotHasKey('import_rows', $contents->rows, 'never the staged copy: it holds the other bot\'s panel passwords');

        $this->restore($theShops);
        self::assertSame(1, ImportRow::query()->count(), 'an agent\'s shop restored leaves the owner\'s import alone');

        ImportLink::query()->delete();
        ImportProblem::query()->delete();
        $this->restore($path);
        self::assertSame([$done->id, $open->id], Import::query()->orderBy('id')->pluck('id')->all());
        self::assertSame(1, ImportProblem::query()->where('import_id', $done->id)->count());
        self::assertSame($this->ali->id, ImportLink::query()->where('source_key', '501')->value('target_id'), 'a later import knows the customer again');
        self::assertSame(0, ImportRow::query()->count(), 'the staged copy is only true now: gone');
    }

    public function testTheWholeInstallationsRestoreBringsNoAgentsPanelSessionBack(): void
    {
        $path = $this->backup(BackupScope::installation());
        // Since the backup: the agent signed their other browsers out, and asked for a login link.
        $this->shop->forceFill(['panel_epoch' => $this->shop->panel_epoch + 1, 'login_code' => hash('sha256', 'code'), 'login_code_expires_at' => now()->addMinutes(5)])->save();
        $raised = $this->shop->panel_epoch;

        $this->restore($path);

        $bot = $this->shop->refresh();
        self::assertSame($raised, $bot->panel_epoch, 'a sign-out since the backup stands: the sessions it ended stay ended');
        self::assertNull($bot->login_code, 'no login link opens a panel from a restore');
    }

    /**
     * Nor any webhook secret the file held: «برداشتن Webhook» and «ثبت Webhook» renew an agent's, and Telegram calls its
     * bot with the secret it was last given — each bot keeps its own as it is now (none for one put off its webhook since),
     * and a bot that is gone now comes back with none.
     */
    public function testTheWholeInstallationsRestoreKeepsEachBotsWebhookSecretAsItIsNow(): void
    {
        $off = $this->agentBot($this->agent(overrides: ['telegram_id' => 9002]), overrides: ['telegram_id' => 777001, 'username' => 'off_shop_bot']);
        $gone = $this->agentBot($this->agent(overrides: ['telegram_id' => 9003]), overrides: ['telegram_id' => 777002, 'username' => 'gone_shop_bot']);
        foreach ([[$this->shop, 'old-secret-from-the-backup'], [$off, 'off-secret-from-the-backup'], [$gone, 'gone-secret-from-the-backup']] as [$bot, $secret]) {
            $bot->forceFill(['webhook_secret' => $secret])->save();
        }
        $path = $this->backup(BackupScope::installation());
        // Since the backup: the shop's renewed, the other's put off its webhook, and the third bot gone.
        $this->shop->forceFill(['webhook_secret' => 'new-secret-after-renewal'])->save();
        $stored = $this->db()->table('bots')->where('id', $this->shop->id)->value('webhook_secret');
        $off->forceFill(['webhook_secret' => null])->save();
        ForeignKeys::without($this->db(), fn() => $this->db()->table('bots')->where('id', $gone->id)->delete());

        $this->restore($path);

        self::assertSame('new-secret-after-renewal', $this->shop->refresh()->webhook_secret, 'the secret Telegram calls with now');
        self::assertSame($stored, $this->db()->table('bots')->where('id', $this->shop->id)->value('webhook_secret'), 'as it was stored');
        self::assertNull($off->refresh()->webhook_secret, 'none since: none still');
        $back = Bot::query()->find($gone->id);
        self::assertInstanceOf(Bot::class, $back, 'the bot gone since comes back');
        self::assertNull($back->webhook_secret, 'with no secret');
    }

    /**
     * A file made before 0.11.0 holds each bot's webhook address whole: without `$oldFiles` (an admin with a bot's seat,
     * BackupsController) it is refused at its check, and at its restore under a token checked for someone else — before
     * anything moves or any state is kept —; with it, restored.
     */
    public function testAFileMadeBeforeZeroElevenIsRestoredOnlyWithOldFiles(): void
    {
        $scope = BackupScope::installation();
        $backups = $this->service(Backups::class);
        $old = (string) file_get_contents($this->rewrite($this->backup($scope), static fn(string $line, int $i): string => $i === 0 ? str_replace('"app_version":"' . Application::VERSION . '"', '"app_version":"0.10.4"', $line) : $line));
        $upload = static fn(): UploadedFile => new UploadedFile((new StreamFactory())->createStream($old), 'b.json.gz', null, strlen($old));
        $refused = static function (\Closure $call, string $why): void {
            try {
                $call();
                self::fail($why);
            } catch (ActorRefusedException $e) {
                self::assertSame(ActorRefusedException::OWNERS_BACKUP, $e->getMessage(), $why);
            }
        };

        $refused(static fn() => $backups->inspect($upload(), $scope, false), 'not checked');
        $token = $backups->inspect($upload(), $scope, true)['token'];
        $since = $this->customer(['telegram_id' => 502]);
        $refused(static fn() => $backups->restore($token, $scope, false), 'not restored');
        self::assertTrue(User::query()->whereKey($since->id)->exists(), 'nothing moved');
        self::assertSame([], $backups->kept($scope, true), 'no state kept');

        $backups->restore($token, $scope, true);
        self::assertFalse(User::query()->whereKey($since->id)->exists(), 'restored');
    }

    /**
     * No backup holds a bot's webhook secret, whatever the database holds: one brought to this version by hand, or not
     * upgraded yet, may keep the address whole — the file's header names this version all the same.
     */
    public function testNoBackupHoldsAWebhookSecretWhateverTheDatabaseHolds(): void
    {
        $this->db()->table('settings')->insert([
            ['bot_id' => Bot::MAIN, 'key' => 'telegram.webhook_url', 'value' => json_encode('https://shop.example.com/webhooks/telegram/MAINSECRET0123456789abcdef'), 'updated_at' => now()],
            ['bot_id' => $this->shop->id, 'key' => 'telegram.webhook_url', 'value' => json_encode("https://shop.example.com/index.php/webhooks/telegram/bot/{$this->shop->id}/AGENTSECRET0123456789"), 'updated_at' => now()],
        ]);

        foreach ([BackupScope::installation(), BackupScope::shop($this->shop->id)] as $scope) {
            $path = $this->backup($scope);
            $addresses = array_column(array_filter($this->rowsIn($path, 'settings'), static fn(array $row): bool => $row['key'] === 'telegram.webhook_url'), 'value', 'bot_id');
            $masked = [$this->shop->id => "\"https://shop.example.com/index.php/webhooks/telegram/bot/{$this->shop->id}/***\""];
            self::assertEquals($scope->isShop() ? $masked : [Bot::MAIN => '"https://shop.example.com/webhooks/telegram/***"'] + $masked, $addresses, $scope->kind());
            self::assertStringNotContainsString('SECRET', (string) gzdecode((string) file_get_contents($path)), $scope->kind());
        }
        self::assertStringContainsString('MAINSECRET', (string) $this->db()->table('settings')->where('bot_id', Bot::MAIN)->where('key', 'telegram.webhook_url')->value('value'), 'the database as it is');
    }

    /**
     * A restore leaves no bot's webhook address in the settings with its secret, whatever the file held (one written
     * before 0.11.0 kept it whole): masked in the restore itself, for the whole installation and for a shop — never left
     * to the upgrades after it — so no backup made after it carries one.
     */
    public function testARestoreMasksTheWebhookAddressesItBringsBack(): void
    {
        $main = ['bot_id' => Bot::MAIN, 'key' => 'telegram.webhook_url'];
        $agent = ['bot_id' => $this->shop->id, 'key' => 'telegram.webhook_url'];
        $this->db()->table('settings')->insert([
            $main + ['value' => json_encode('https://shop.example.com/webhooks/telegram/***', JSON_UNESCAPED_SLASHES), 'updated_at' => now()],
            $agent + ['value' => json_encode("https://shop.example.com/webhooks/telegram/bot/{$this->shop->id}/***", JSON_UNESCAPED_SLASHES), 'updated_at' => now()],
        ]);
        // Files of this version holding the addresses whole, as a writer before 0.11.0 wrote them.
        $shop = $this->shop->id;
        $whole = static fn(string $line): string => str_replace(['telegram/***', "bot/{$shop}/***"], ['telegram/MAINSECRET0123456789abcdef', "bot/{$shop}/AGENTSECRET0123456789"], $line);
        $all = $this->rewrite($this->backup(BackupScope::installation()), $whole);
        $theirs = $this->rewrite($this->backup(BackupScope::shop($this->shop->id)), $whole);
        self::assertStringContainsString('MAINSECRET', (string) gzdecode((string) file_get_contents($all)));
        self::assertStringContainsString('AGENTSECRET', (string) gzdecode((string) file_get_contents($theirs)));
        $value = fn(array $row): mixed => $this->db()->table('settings')->where($row)->value('value');

        $this->restore($all);
        self::assertSame('"https://shop.example.com/webhooks/telegram/***"', $value($main));
        self::assertSame("\"https://shop.example.com/webhooks/telegram/bot/{$this->shop->id}/***\"", $value($agent));

        $this->db()->table('settings')->where($agent)->update(['value' => json_encode('https://elsewhere.example.com/webhooks/telegram/bot/1/***', JSON_UNESCAPED_SLASHES)]);
        $this->restore($theirs);
        self::assertSame("\"https://shop.example.com/webhooks/telegram/bot/{$this->shop->id}/***\"", $value($agent), 'a shop\'s restore too');
    }

    public function testAFileAnybodyChangedOrAnotherInstallationSignedIsRefused(): void
    {
        $path = $this->backup(BackupScope::shop($this->shop->id));

        $this->assertRefused(BackupRefusedException::BROKEN, $this->rewrite($path, static fn(string $line): string => str_replace('رضا', 'Hacker', $line), resign: false), 'a name changed by hand');
        $this->assertRefused(BackupRefusedException::BROKEN, $this->rewrite($path, static fn(string $line): string => $line, key: random_bytes(32)), 'another installation\'s key');
        $this->assertRefused(BackupRefusedException::BROKEN, $this->truncated($path), 'cut short');
        file_put_contents($this->scratchDir() . '/plain.txt', 'hello');
        $this->assertRefused(BackupRefusedException::NOT_A_BACKUP, $this->scratchDir() . '/plain.txt');
    }

    public function testAFileOfANewerVersionIsRefusedAndOneOlderThanARestoreTakesToo(): void
    {
        $path = $this->backup(BackupScope::shop($this->shop->id));

        $newer = $this->rewrite($path, static fn(string $line, int $i): string => $i === 0 ? str_replace('"app_version":"' . Application::VERSION . '"', '"app_version":"9.0.0"', $line) : $line);
        $this->assertRefused(sprintf(BackupRefusedException::NEWER, '9.0.0', Application::VERSION), $newer);

        $older = $this->rewrite($path, static fn(string $line, int $i): string => $i === 0 ? str_replace('"app_version":"' . Application::VERSION . '"', '"app_version":"0.7.2"', $line) : $line);
        $this->assertRefused(sprintf(BackupRefusedException::OLDER, '0.7.2', Application::VERSION, BackupTables::OLDEST_RESTORABLE), $older, 'its rows may sit in columns since moved');
    }

    /**
     * A file of an older version a restore takes goes through the upgrades since, after its rows are in — as a database
     * of that version would: here 0.8.0's puts back an index the database lacks.
     */
    public function testAFileOfAnOlderVersionARestoreTakesGoesThroughTheUpgradesSince(): void
    {
        $path = $this->backup(BackupScope::shop($this->shop->id));
        $schema = $this->db()->getSchemaBuilder();
        $schema->table('users', static fn($table) => $table->dropIndex(['bot_id', 'phone']));
        self::assertFalse($schema->hasIndex('users', ['bot_id', 'phone']));
        $read = $this->service(BackupReader::class)->read($path);
        $older = new BackupContents($read->scope, $read->shop, '0.7.2', $read->madeAt, $read->rows);

        $restorer = $this->service(Restorer::class);
        $restorer->restore($path, $older, $restorer->analyse($path, $older));

        self::assertTrue($schema->hasIndex('users', ['bot_id', 'phone']), "0.8.0's upgrade ran");
        self::assertSame(1, CurrentBot::run($this->shop, static fn(): int => User::query()->count()));
    }

    /**
     * What only the owner reads of a shop's rows never reaches its agent's file: why a delivery failed in full (the
     * panel's address), and the owner's login among those who decided — «پشتیبانی» there, as the agent reads it
     * everywhere. The shop's restore keeps both as they are now; the owner's own backup holds them as they are.
     */
    public function testAShopsBackupHoldsNothingOnlyTheOwnerReadsAndItsRestoreKeepsIt(): void
    {
        [$failed, $byAgent] = CurrentBot::run($this->shop, fn(): array => [
            $this->purchaseOrder($this->reza, $this->theirPlan, $this->berlin),
            $this->purchaseOrder($this->reza, $this->theirPlan, $this->berlin),
        ]);
        $diagnosis = 'cURL error 7: Failed to connect to secret-panel.example.com port 2053';
        $this->db()->table('orders')->where('id', $failed->id)->update(['notes' => 'تحویل ناموفق بود.', 'diagnosis' => $diagnosis, 'created_by' => 'admin']);
        $this->db()->table('orders')->where('id', $byAgent->id)->update(['created_by' => '@agent_shop_bot']);

        $path = $this->backup(BackupScope::shop($this->shop->id));
        $orders = $this->rowsIn($path, 'orders');
        self::assertNull($orders[$failed->id]['diagnosis'], "the panel's address is the owner's alone");
        self::assertSame('تحویل ناموفق بود.', $orders[$failed->id]['notes'], 'the word anyone may read stays');
        self::assertSame(Reviewers::SUPPORT, $orders[$failed->id]['created_by'], "the owner's login as anyone else reads it");
        self::assertSame('@agent_shop_bot', $orders[$byAgent->id]['created_by'], "the shop's own as they are");
        $whole = $this->rowsIn($this->backup(BackupScope::installation()), 'orders');
        self::assertSame([$diagnosis, 'admin'], [$whole[$failed->id]['diagnosis'], $whole[$failed->id]['created_by']], "the owner's own backup holds it all");

        // Since the backup: the owner took the agent's order over.
        $this->db()->table('orders')->where('id', $byAgent->id)->update(['created_by' => 'admin']);
        $this->restore($path);

        $now = $this->db()->table('orders')->whereIn('id', [$failed->id, $byAgent->id])->orderBy('id')->get(['diagnosis', 'created_by'])->map(static fn(object $row): array => (array) $row)->all();
        self::assertSame([['diagnosis' => $diagnosis, 'created_by' => 'admin'], ['diagnosis' => null, 'created_by' => '@agent_shop_bot']], $now, "what only the owner reads stays as it is; the file's own come back");
    }

    /** A table without an id is read by its primary key, a batch at a time: every row, past a batch and its first key. */
    public function testATableWithoutAnIdIsBackedUpWholeByItsKey(): void
    {
        $members = CurrentBot::run($this->shop, function (): array {
            $users = [];
            for ($i = 0; $i < 1200; $i++) {
                $users[] = ['bot_id' => $this->shop->id, 'telegram_id' => 700000 + $i, 'first_name' => 'م', 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($users, 100) as $chunk) {
                $this->db()->table('users')->insert($chunk);
            }

            return User::query()->pluck('id')->all();
        });
        [$vip, $gold] = CurrentBot::run($this->shop, fn(): array => [$this->customerGroup('VIP'), $this->customerGroup('Gold')]);
        foreach ([$vip, $gold] as $group) {
            foreach (array_chunk($members, 400) as $chunk) {
                $this->db()->table('customer_group_user')->insert(array_map(static fn(int $user): array => ['customer_group_id' => $group->id, 'user_id' => $user], $chunk));
            }
        }

        $path = $this->backup(BackupScope::shop($this->shop->id));
        self::assertSame(2 * count($members), $this->service(BackupReader::class)->read($path)->rows['customer_group_user']);
        $pairs = array_map(static fn(array $row): string => $row['customer_group_id'] . '-' . $row['user_id'], $this->rowsIn($path, 'customer_group_user'));
        self::assertCount(2 * count($members), array_unique($pairs), 'each once');

        $this->restore($path);
        self::assertSame(2 * count($members), $this->db()->table('customer_group_user')->whereIn('customer_group_id', [$vip->id, $gold->id])->count());
    }

    /**
     * The states kept from before a restore are the last KEEP — the older ones go once a restore went through, never
     * before: a restore that fails leaves every one of them, its own besides.
     */
    public function testARestoreThatFailsKeepsEveryStateKeptBeforeIt(): void
    {
        $scope = BackupScope::shop($this->shop->id);
        $backups = $this->service(Backups::class);
        $file = (string) file_get_contents($this->backup($scope));
        $restore = static fn(): array => $backups->restore($backups->inspect(new UploadedFile((new StreamFactory())->createStream($file), 'b.json.gz', null, strlen($file)), $scope, true)['token'], $scope, true);
        for ($i = 0; $i < Backups::KEEP; $i++) {
            $restore();
        }
        self::assertCount(Backups::KEEP, $backups->kept($scope, true));

        try {
            $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query): void {
                if (str_starts_with($query->sql, 'insert into "amo_users"')) {
                    throw new \RuntimeException('the restore failed');
                }
            }, $restore);
            self::fail('restored');
        } catch (\RuntimeException $e) {
            self::assertSame('the restore failed', $e->getMessage());
        }
        self::assertCount(Backups::KEEP + 1, $backups->kept($scope, true), 'none of the states before it gone');

        $restore();
        self::assertCount(Backups::KEEP, $backups->kept($scope, true));
    }

    /** A write that fails half-way leaves nothing: neither the file nor its half-made part. */
    public function testABackupThatFailsHalfWayLeavesNothingBehind(): void
    {
        $path = $this->scratchDir() . '/failed.json.gz';

        try {
            $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query): void {
                if (str_contains($query->sql, 'from "amo_subscriptions"')) {
                    throw new \RuntimeException('disk full');
                }
            }, fn() => $this->service(BackupWriter::class)->write(BackupScope::shop($this->shop->id), $path));
            self::fail('written');
        } catch (\RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        }

        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path . '.part');
    }

    /** Every table is read in one transaction of the writer's: the file is the database as it stood at one moment. */
    public function testEveryTableOfABackupIsReadInOneTransaction(): void
    {
        $outside = $this->db()->transactionLevel();
        $levels = [];
        $this->whileListening(QueryExecuted::class, function (QueryExecuted $query) use (&$levels): void {
            if (str_starts_with($query->sql, 'select') && (str_contains($query->sql, self::sqlTable('users')) || str_contains($query->sql, self::sqlTable('subscriptions')))) {
                $levels[] = $this->db()->transactionLevel();
            }
        }, fn() => $this->service(BackupWriter::class)->write(BackupScope::installation(), $this->scratchDir() . '/once.json.gz'));

        self::assertNotSame([], $levels);
        self::assertSame([$outside + 1], array_values(array_unique($levels)));
    }

    public function testAPlansEntryOnAServerGoneSinceIsLeftOutAndAServiceOnOneRefusesTheFile(): void
    {
        $paris = $this->fakeServer('Paris');
        $this->planEntry($this->theirPlan, $paris);
        $path = $this->backup(BackupScope::shop($this->shop->id));
        $paris->delete();

        $contents = $this->service(BackupReader::class)->read($path);
        $analysis = $this->service(Restorer::class)->analyse($path, $contents);
        self::assertSame(['کنار گذاشته می‌شود: ۱ سرور در پلن‌ها، روی سرورهایی که دیگر نیستند.'], $analysis->warnings());
        $this->service(Restorer::class)->restore($path, $contents, $analysis);
        self::assertSame([$this->berlin->id], $this->db()->table('plan_servers')->where('plan_id', $this->theirPlan->id)->pluck('server_id')->map('intval')->all());

        $tokyo = $this->fakeServer('Tokyo');
        CurrentBot::run($this->shop, fn() => $this->subscription($this->reza, $this->theirPlan, $tokyo, 'reza_2'));
        $withService = $this->backup(BackupScope::shop($this->shop->id));
        Subscription::acrossShops()->where('server_id', $tokyo->id)->delete();
        $tokyo->delete();
        try {
            $this->service(Restorer::class)->analyse($withService, $this->service(BackupReader::class)->read($withService));
            self::fail('restored');
        } catch (ValidationException $e) {
            self::assertSame([sprintf(BackupRefusedException::GONE_SERVERS, 1, '#' . $tokyo->id)], $e->errors()['file']);
        }
    }

    private function backup(BackupScope $scope): string
    {
        $path = $this->scratchDir() . '/' . bin2hex(random_bytes(4)) . '.json.gz';
        $this->service(BackupWriter::class)->write($scope, $path);

        return $path;
    }

    private function restore(string $path): void
    {
        $contents = $this->service(BackupReader::class)->read($path);
        $restorer = $this->service(Restorer::class);
        $restorer->restore($path, $contents, $restorer->analyse($path, $contents));
        self::assertSame([], $this->db()->select('PRAGMA foreign_key_check'), 'every key holds after the restore');
    }

    /**
     * A table's rows in a backup file, each by its column, by id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsIn(string $path, string $table): array
    {
        $rows = [];
        foreach ($this->service(BackupReader::class)->rows($path) as [$of, $columns, $row]) {
            if ($of === $table) {
                $values = array_combine($columns, $row);
                $rows[(int) ($values['id'] ?? count($rows))] = $values;
            }
        }

        return $rows;
    }

    private function assertRefused(string $message, string $path, string $why = ''): void
    {
        try {
            $this->service(BackupReader::class)->read($path);
            self::fail('read: ' . $why);
        } catch (ValidationException $e) {
            self::assertSame([$message], $e->errors()['file'], $why);
        }
    }

    /**
     * The file with each line but the signature changed by `$change`: signed again — with this installation's key, or
     * `$key` — or (`$resign` false) under its old signature, as a hand that edits it leaves it.
     */
    private function rewrite(string $path, \Closure $change, bool $resign = true, ?string $key = null): string
    {
        $lines = gzfile($path) ?: [];
        $old = (string) array_pop($lines);
        $signature = hash_init('sha256', HASH_HMAC, $key ?? $this->service(Encrypter::class)->macKey(BackupWriter::PURPOSE));
        $out = '';
        foreach ($lines as $i => $line) {
            $line = $change($line, $i);
            hash_update($signature, $line);
            $out .= $line;
        }
        $out .= $resign ? json_encode(['signature' => hash_final($signature)]) . "\n" : $old;

        $copy = $this->scratchDir() . '/' . bin2hex(random_bytes(4)) . '.json.gz';
        file_put_contents($copy, (string) gzencode($out));

        return $copy;
    }

    private function truncated(string $path): string
    {
        $lines = gzfile($path) ?: [];
        $copy = $this->scratchDir() . '/' . bin2hex(random_bytes(4)) . '.json.gz';
        file_put_contents($copy, (string) gzencode(implode('', array_slice($lines, 0, -3))));

        return $copy;
    }
}
