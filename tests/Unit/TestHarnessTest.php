<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Polling\LongPolls;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Tests\Support\FakeTelegram;
use Tests\TestCase;

/**
 * What every test leans on: the app under test touches nothing of this machine's — the files it writes are the run's
 * own, its outgoing HTTP reaches no network — and a test hands it a double by swapping a leaf, so what was built around
 * the real thing talks to the double without being built again.
 */
final class TestHarnessTest extends TestCase
{
    public function testTheFilesTheAppWritesAreTheRunsOwn(): void
    {
        $project = (string) realpath($this->app()->basePath());
        foreach (array_keys(self::FILES) as $entry) {
            $path = (string) $this->app()->container()->get($entry);
            self::assertStringStartsWith((string) realpath(sys_get_temp_dir()), (string) realpath(dirname($path)), $entry);
            self::assertStringStartsNotWith($project, (string) realpath(dirname($path)), "{$entry} is not this machine's");
        }
        self::assertSame($this->app()->container()->get('config.file'), $this->service(ConfigFile::class)->path(), 'the config.php the settings screens edit');
        self::assertFileDoesNotExist($this->service(ConfigFile::class)->path(), 'the one the app booted with is gone once read: a test starts with none');
        self::assertSame('sqlite', $this->service(Config::class)->get('database.driver'), 'booted on the suite\'s own configuration, never this machine\'s');
    }

    public function testOutgoingHttpReachesNoNetworkUntilATestHandsItAFake(): void
    {
        $http = $this->service(ClientInterface::class);

        try {
            $http->request('GET', 'https://panel.example/panel/api/server/status');
            self::fail('a request went out');
        } catch (ConnectException $e) {
            self::assertStringContainsString('No network in the tests', $e->getMessage(), 'as an unreachable host, at once');
        }

        $this->panelHttp()->ok(['cpu' => 1]);
        self::assertSame(200, $http->request('GET', 'https://panel.example/panel/api/server/status')->getStatusCode(), 'the same client, its transport the fake');
        self::assertSame(['GET /panel/api/server/status'], $this->panelHttp()->calls());
    }

    public function testThePollersLongPollsReachNoNetworkUntilTheFakeTelegram(): void
    {
        $polls = $this->service(LongPolls::class)->client();

        try {
            $polls->request('POST', 'https://api.telegram.org/bot1:x/getUpdates');
            self::fail('a poll went out');
        } catch (ConnectException) {
        }

        $this->telegram()->reply([]);
        self::assertSame(200, $polls->request('POST', 'https://api.telegram.org/bot1:x/getUpdates')->getStatusCode(), 'the poller\'s own transport is the fake too');
        self::assertSame(['getUpdates'], $this->telegram()->calls());
    }

    public function testTheBotApiTheAppHoldsTalksToTheFakeTelegram(): void
    {
        $api = $this->service(BotApi::class);
        self::assertFalse($api->hasToken(), 'no bot token until a test talks to Telegram');

        $this->telegram()->reply(['id' => FakeTelegram::BOT_ID, 'is_bot' => true, 'username' => 'amo_bot']);

        self::assertSame('amo_bot', $api->getMe()['username'], 'the client built before the fake speaks to it');
        self::assertSame([['getMe'], FakeTelegram::TOKEN], [$this->telegram()->calls(), $this->telegram()->tokenOf(0)]);
        self::assertSame(FakeTelegram::BOT_ID, $api->botId());
    }
}
