<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Sms;

use App\Core\Config\ConfigValues;
use App\Core\Config\Repository as Config;
use App\Core\Drivers\Driver;
use App\Core\Drivers\Registry;
use App\Core\Sms\Drivers\IpPanel;
use App\Core\Sms\IpPanelSender;
use App\Core\Sms\Sms;
use App\Core\Sms\SmsDriver;
use App\Core\Sms\SmsSender;
use App\Core\Sms\SmsSenders;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * How config.php's SMS_* settings become the shop's SMS sender (the container's `sms.sender`): SMS_DRIVER names an SMS
 * driver, which makes its sender of its own settings as its form reads them — or none, and then the shop sends no code
 * (Sms::available()). Settings no sender can be built from send nothing and the log says why. Nothing here reaches
 * IPPanel: the outgoing client is the tests' fake.
 */
final class SmsSendersTest extends TestCase
{
    /** IPPanel set up, as config.php writes it. */
    private const IPPANEL = [
        'SMS_DRIVER' => 'ippanel',
        'SMS_AGENTS' => true,
        'SMS_IPPANEL_API_KEY' => 'OWVhN2Q5ZjEtYmM0Yy00ZjRhLTk2ZTMtNDg2Zg==',
        'SMS_IPPANEL_FROM' => '+983000505',
        'SMS_IPPANEL_PATTERN' => 'jhaskdab45s6f4sfw',
    ];

    public function testConfigPhpIsReadAsTheSmsParts(): void
    {
        $config = Config::fromDirectory($this->app()->configPath(), new ConfigValues(self::IPPANEL));

        self::assertSame(['ippanel', true], [$config->get('sms.driver'), $config->get('sms.agents')]);
        self::assertSame(self::IPPANEL['SMS_IPPANEL_PATTERN'], $config->get('sms.settings')['SMS_IPPANEL_PATTERN'] ?? null, "the driver's own settings, as written");

        $none = Config::fromDirectory($this->app()->configPath(), new ConfigValues([]));
        self::assertSame(['none', false], [$none->get('sms.driver'), $none->get('sms.agents')], 'a config.php of before SMS: none, and agents off');
    }

    public function testIPPanelIsItsSenderOnceSetUpAndTheShopSendsThroughIt(): void
    {
        $ippanel = new FakePanel();
        $ippanel->raw(new Response(200, ['Content-Type' => 'application/json'], '{"data":{"message_outbox_ids":[1]},"meta":{"status":true,"message":"انجام شد"}}'));
        $config = self::settings(self::IPPANEL);

        $sender = SmsSenders::fromConfig($config, self::drivers($ippanel), new NullLogger());
        self::assertInstanceOf(IpPanelSender::class, $sender);

        $sms = new Sms($config, $sender);
        self::assertTrue($sms->available());
        self::assertTrue($sms->forAgents());
        $sms->sendCode('+989121234567', '123456');
        self::assertSame(['POST /v1/api/send'], $ippanel->calls());
        self::assertSame(['code' => '123456'], $ippanel->params(0)['params'], 'the variable a pattern names its code by unless told otherwise');
    }

    public function testNoneIsNoSender(): void
    {
        self::assertNull(SmsSenders::fromConfig(self::settings(['SMS_DRIVER' => 'none'] + self::IPPANEL), self::drivers(), new NullLogger()));
        self::assertNull(SmsSenders::fromConfig(new Config([]), self::drivers(), new NullLogger()), 'a config.php of before SMS had none');
        self::assertFalse((new Sms(self::settings(['SMS_DRIVER' => 'none']), null))->available());
    }

    public function testSettingsThatMakeNoSenderSendNothingAndSayWhy(): void
    {
        $said = new TestHandler();
        $logger = new Logger('sms', [$said]);
        $why = static fn(string $words): bool => $said->hasWarningThatPasses(static fn(LogRecord $record): bool => str_contains((string) ($record->context['reason'] ?? ''), $words));

        self::assertNull(SmsSenders::fromConfig(self::settings(['SMS_IPPANEL_API_KEY' => ''] + self::IPPANEL), self::drivers(), $logger), 'IPPanel without its key');
        self::assertTrue($why('SMS_IPPANEL_API_KEY'));
        self::assertNull(SmsSenders::fromConfig(self::settings(['SMS_IPPANEL_PATTERN' => ' '] + self::IPPANEL), self::drivers(), $logger), 'nor its pattern');
        self::assertTrue($why('SMS_IPPANEL_PATTERN'));
        self::assertNull(SmsSenders::fromConfig(self::settings(['SMS_DRIVER' => 'kavenegar'] + self::IPPANEL), self::drivers(), $logger), 'a driver the shop does not have');
        self::assertTrue($why('kavenegar'));
        self::assertStringNotContainsString(self::IPPANEL['SMS_IPPANEL_API_KEY'], (string) json_encode(array_map(static fn(LogRecord $record): array => $record->context, $said->getRecords())));
    }

    public function testTheContainerKnowsEverySmsDriver(): void
    {
        $registry = $this->app()->container()->get('sms.drivers');

        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame(['ippanel'], array_map(static fn(Driver $driver): string => $driver->key(), $registry->all()));
        self::assertContainsOnlyInstancesOf(SmsDriver::class, $registry->all());
        self::assertInstanceOf(SmsSender::class, $this->app()->container()->get('sms.sender'), "the tests' own provider stands in for it");
    }

    /**
     * The configuration config.php's settings make, as config/sms.php reads them.
     *
     * @param array<string, string|bool> $settings
     */
    private static function settings(array $settings): Config
    {
        return new Config(['sms' => [
            'driver' => (string) ($settings['SMS_DRIVER'] ?? 'none'),
            'agents' => (bool) ($settings['SMS_AGENTS'] ?? false),
            'settings' => array_map(static fn(string|bool $value): string => (string) $value, $settings),
        ]]);
    }

    /** @return Registry<SmsDriver> */
    private static function drivers(?FakePanel $ippanel = null): Registry
    {
        return new Registry([new IpPanel(($ippanel ?? new FakePanel())->client())]);
    }
}
