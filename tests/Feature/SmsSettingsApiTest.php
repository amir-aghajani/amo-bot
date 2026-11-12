<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\ConfigFile;
use App\Core\Sms\Drivers\IpPanel;
use App\Core\Sms\IpPanelSender;
use App\Core\Sms\SmsFailedException;
use App\Modules\Settings\Exceptions\SmsNotReadyException;
use GuzzleHttp\Psr7\Response;
use Tests\HttpTestCase;

/**
 * The shop's SMS, the owner's «پیامک» group of their settings (config.php's SMS_*): the way codes go out — none, or an
 * SMS driver, described with its form and shown with its settings —, the chosen driver's settings saved by its form with
 * whether agents' websites may send codes too — the access key never sent back, and one kept never sent to another
 * address —, what is kept left as it is when SMS is switched off, every refusal at once; a test code by the settings as
 * saved, which says why none went (nothing set up, a number no phone has, or what the provider said); the account's
 * credit asked of IPPanel with the key as saved; and none of it in an agent's panel.
 */
final class SmsSettingsApiTest extends HttpTestCase
{
    private const KEY = 'OWVhN2Q5ZjEtYmM0Yy00ZjRhLTk2ZTMtNDg2Zg==';

    /** What the owner's screen sends to send by IPPanel. */
    private const IPPANEL = [
        'driver' => 'ippanel',
        'api_key' => self::KEY,
        'from' => '۳۰۰۰۵۰۵',
        'pattern' => 'jhaskdab45s6f4sfw',
        'variable' => '%code%',
        'api_url' => IpPanelSender::BASE_URL,
        'agents' => true,
    ];

    private ConfigFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = $this->configFile(['APP_NAME' => 'AmoBot', 'APP_KEY' => 'base64:x']);
        $this->loginAsAdmin();
    }

    public function testTheGroupShowsEveryDriverWithWhatTheShopRunsWithUntilItIsSet(): void
    {
        $sms = $this->decode($this->get('/api/admin/settings/config'))['groups']['sms'];

        self::assertSame(['none', false], [$sms['driver'], $sms['agents']]);
        self::assertSame(['ippanel'], array_column($sms['drivers'], 'key'));
        self::assertSame(['api_key', 'from', 'pattern', 'variable', 'api_url'], array_column($sms['drivers'][0]['fields'], 'name'));
        self::assertSame(['api_url'], $sms['drivers'][0]['fields'][0]['bound_to'], 'a kept key stays with its address');
        self::assertSame(
            ['ippanel' => ['api_key' => ['set' => false, 'hint' => ''], 'from' => '', 'pattern' => '', 'variable' => 'code', 'api_url' => IpPanelSender::BASE_URL]],
            $sms['values'],
        );
    }

    public function testIPPanelIsSavedWithItsKeyWhichNeverComesBack(): void
    {
        $missing = $this->putJson('/api/admin/settings/config/sms', ['api_key' => ''] + self::IPPANEL);
        self::assertSame([422, ['api_key' => [IpPanel::KEY_MISSING]]], [$missing->getStatusCode(), $this->decode($missing)['errors']], 'IPPanel needs its key');
        self::assertNull($this->file->get('SMS_DRIVER'), 'nothing written');

        $response = $this->putJson('/api/admin/settings/config/sms', self::IPPANEL);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            ['ippanel', self::KEY, '+983000505', 'jhaskdab45s6f4sfw', 'code', IpPanelSender::BASE_URL, true],
            array_map(fn(string $key): mixed => $this->file->get($key), ['SMS_DRIVER', 'SMS_IPPANEL_API_KEY', 'SMS_IPPANEL_FROM', 'SMS_IPPANEL_PATTERN', 'SMS_IPPANEL_VARIABLE', 'SMS_IPPANEL_URL', 'SMS_AGENTS']),
            'the line as IPPanel takes it, the variable without its %',
        );
        $sms = $this->decode($response)['groups']['sms'];
        self::assertSame(['set' => true, 'hint' => '••••••Zg=='], $sms['values']['ippanel']['api_key'], 'its last characters, never the key');
        self::assertSame(['ippanel', true, '+983000505'], [$sms['driver'], $sms['agents'], $sms['values']['ippanel']['from']]);
        self::assertStringNotContainsString('OWVhN2Q5', (string) $response->getBody());

        self::assertSame(200, $this->putJson('/api/admin/settings/config/sms', ['api_key' => '', 'agents' => false] + self::IPPANEL)->getStatusCode());
        self::assertSame([self::KEY, false], [$this->file->get('SMS_IPPANEL_API_KEY'), $this->file->get('SMS_AGENTS')], 'left blank, the one kept stays');
        $cleared = $this->putJson('/api/admin/settings/config/sms', ['api_key' => '', 'clear_api_key' => true] + self::IPPANEL);
        self::assertSame([IpPanel::KEY_MISSING], $this->decode($cleared)['errors']['api_key'] ?? null, 'cleared while IPPanel is the way out: no code could go');

        $off = $this->putJson('/api/admin/settings/config/sms', ['driver' => 'none']);
        self::assertSame(200, $off->getStatusCode(), 'no SMS needs nothing else');
        self::assertSame(['none', self::KEY, false], [$this->file->get('SMS_DRIVER'), $this->file->get('SMS_IPPANEL_API_KEY'), $this->file->get('SMS_AGENTS')], 'what is kept stays, for a way back');
        self::assertSame('jhaskdab45s6f4sfw', $this->decode($off)['groups']['sms']['values']['ippanel']['pattern'], 'and shows');
    }

    public function testAKeptKeyGoesToNoOtherAddress(): void
    {
        $this->putJson('/api/admin/settings/config/sms', self::IPPANEL);

        $moved = $this->putJson('/api/admin/settings/config/sms', ['api_key' => '', 'api_url' => 'https://sms.elsewhere.example/v1'] + self::IPPANEL);
        self::assertSame([422, ['api_key' => [IpPanel::KEY_MOVED]]], [$moved->getStatusCode(), $this->decode($moved)['errors']]);
        self::assertSame(IpPanelSender::BASE_URL, $this->file->get('SMS_IPPANEL_URL'), 'nothing written');

        self::assertSame(200, $this->putJson('/api/admin/settings/config/sms', ['api_key' => 'YW5vdGhlci1rZXktZm9yLWVsc2V3aGVyZQ==', 'api_url' => 'https://sms.elsewhere.example/v1'] + self::IPPANEL)->getStatusCode(), 'typed again, it goes with the address it is typed for');
        self::assertSame('https://sms.elsewhere.example/v1', $this->file->get('SMS_IPPANEL_URL'));
    }

    public function testEveryRefusalIsSaidAtOnceAndNothingIsKept(): void
    {
        $response = $this->putJson('/api/admin/settings/config/sms', ['from' => 'خط من', 'pattern' => 'الگو', 'variable' => '1code', 'api_url' => 'edge.ippanel.com'] + self::IPPANEL);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['from', 'pattern', 'variable', 'api_url'], array_keys($this->decode($response)['errors']));
        self::assertNull($this->file->get('SMS_DRIVER'), 'nothing written');

        $missing = $this->unchecked()->putJson('/api/admin/settings/config/sms', ['driver' => 'ippanel', 'api_key' => self::KEY]);
        self::assertSame(['from', 'pattern', 'variable', 'api_url', 'agents'], array_keys($this->decode($missing)['errors']), 'the line, the pattern, its variable, the address and the agents\' switch');

        $unknown = $this->unchecked()->putJson('/api/admin/settings/config/sms', ['driver' => 'kavenegar', 'from' => 'x']);
        self::assertSame(['driver'], array_keys($this->decode($unknown)['errors']), 'no driver of that name: nothing else to check');
    }

    public function testATestCodeGoesByTheSettingsAsSaved(): void
    {
        $provider = $this->sms();

        $response = $this->postJson('/api/admin/settings/config/sms/test', ['to' => ' ۰۹۱۲ ۱۲۳ ۴۵۶۷ ']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $answer = $this->decode($response);
        self::assertTrue($answer['sent']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $answer['code']);
        self::assertSame([['to' => '+989121234567', 'code' => $answer['code']]], $provider->sent, 'to the number in E.164, the code the owner is shown');
    }

    public function testThePanelSaysWhyNoTestCodeWent(): void
    {
        $notReady = $this->postJson('/api/admin/settings/config/sms/test', ['to' => '09121234567']);
        self::assertSame([422, SmsNotReadyException::MESSAGE], [$notReady->getStatusCode(), $this->decode($notReady)['message']], 'no driver saved');

        $provider = $this->sms();
        $nowhere = $this->postJson('/api/admin/settings/config/sms/test', ['to' => '0912']);
        self::assertSame(['to' => ['شماره موبایل گیرنده درست نیست؛ آن را مثل 09121234567 یا +989121234567 بنویسید.']], $this->decode($nowhere)['errors']);
        $blank = $this->postJson('/api/admin/settings/config/sms/test', ['to' => ' ']);
        self::assertSame(['to' => ['شماره موبایل گیرنده را وارد کنید.']], $this->decode($blank)['errors']);

        $provider->refuse('IPPanel نپذیرفت: اعتبار شما کافی نیست');
        $refused = $this->postJson('/api/admin/settings/config/sms/test', ['to' => '+989121234567']);
        self::assertSame(502, $refused->getStatusCode());
        self::assertSame('پیامک فرستاده نشد: IPPanel نپذیرفت: اعتبار شما کافی نیست', $this->decode($refused)['message'], "the owner's diagnosis");
        self::assertNotSame(SmsFailedException::MESSAGE, $this->decode($refused)['message'], "a customer's words say less");
        self::assertSame([], $provider->sent);
    }

    public function testTheCreditIsAskedOfIPPanelWithTheKeyAsSaved(): void
    {
        $notReady = $this->get('/api/admin/settings/config/sms/credit');
        self::assertSame([422, SmsNotReadyException::MESSAGE], [$notReady->getStatusCode(), $this->decode($notReady)['message']], 'no driver saved');

        $this->putJson('/api/admin/settings/config/sms', self::IPPANEL);
        $ippanel = $this->panelHttp();
        $ippanel->raw(new Response(200, ['Content-Type' => 'application/json'], '{"data":{"credit":6084722932.705052,"gift":0},"meta":{"status":true,"message":"انجام شد","message_code":"200-1"}}'));

        $response = $this->get('/api/admin/settings/config/sms/credit');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['credit' => ['amount' => 6084722932, 'unit' => 'ریال']], $this->decode($response));
        self::assertSame(['GET /v1/api/payment/credit/mine'], $ippanel->calls());
        self::assertSame(self::KEY, $ippanel->request(0)->getHeaderLine('Authorization'), 'the key as saved, as it is');

        $ippanel->raw(new Response(401, ['Content-Type' => 'application/json'], '{"data":null,"meta":{"status":false,"message":"اطلاعات وارد شده صحیح نمی باشد","message_code":"400-1","errors":{}}}'));
        $refused = $this->get('/api/admin/settings/config/sms/credit');
        self::assertSame(502, $refused->getStatusCode());
        self::assertStringStartsWith('اعتبار حساب پیامک خوانده نشد: IPPanel کلید دسترسی را نپذیرفت (اطلاعات وارد شده صحیح نمی باشد)', $this->decode($refused)['message']);
        self::assertStringNotContainsString(self::KEY, (string) $refused->getBody());

        $this->file->set('SMS_IPPANEL_PATTERN', '');
        self::assertSame(422, $this->get('/api/admin/settings/config/sms/credit')->getStatusCode(), 'settings no sender is built from');
    }

    public function testAnAgentsPanelHasNoneOfIt(): void
    {
        $this->loginAsAgent($this->agentBot());

        self::assertSame(404, $this->get('/api/agent/settings/config')->getStatusCode());
        self::assertSame(404, $this->putJson('/api/agent/settings/config/sms', self::IPPANEL)->getStatusCode());
        self::assertSame(404, $this->postJson('/api/agent/settings/config/sms/test', ['to' => '09121234567'])->getStatusCode());
        self::assertSame(404, $this->get('/api/agent/settings/config/sms/credit')->getStatusCode());
        self::assertNull($this->file->get('SMS_DRIVER'));
    }
}
