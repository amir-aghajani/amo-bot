<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Sms;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Sms\Drivers\IpPanel;
use App\Core\Sms\IpPanelSender;
use App\Core\Sms\SmsCredit;
use App\Core\Sms\SmsFailedException;
use App\Core\Sms\SmsSettingsException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * IPPanel, the first SMS driver: its card and form — the access key bound to the API's address, one of the account's
 * lines kept as IPPanel takes it (E.164), the approved pattern and its variable —, and its sender against IPPanel Edge's
 * API through the shop's one outgoing client (here the tests' fake): a pattern send signed with the key as it is (no
 * Bearer), never following a redirect, given up on in time; IPPanel's refusals — the key, the request, anything else it
 * said no to — in its own words, the key in none; and the account's credit in Rial.
 */
final class IpPanelTest extends TestCase
{
    private const KEY = 'OWVhN2Q5ZjEtYmM0Yy00ZjRhLTk2ZTMtNDg2Zg==';

    /** The form's values a sender is built from: one of the account's lines, the pattern and its variable. */
    private const VALUES = ['api_key' => self::KEY, 'from' => '+983000505', 'pattern' => 'jhaskdab45s6f4sfw', 'variable' => 'code', 'api_url' => IpPanelSender::BASE_URL];

    public function testItsCardSaysWhatIPPanelNeeds(): void
    {
        $card = self::driver()->describe()->toArray();

        self::assertSame(['ippanel', 'IPPanel (ای‌پی پنل)'], [$card['key'], $card['label']]);
        self::assertCount(3, $card['notes'], 'the pattern approved with one variable, the line the account\'s, the cost');
        self::assertSame(['api_key', 'from', 'pattern', 'variable', 'api_url'], array_column($card['fields'], 'name'));
        [$key, $from, , $variable, $url] = $card['fields'];
        self::assertSame([true, true, ['api_url'], IpPanel::KEY_MOVED], [$key['secret'], $key['required'], $key['bound_to'], $key['moved']], 'the key goes with the address it was given for');
        self::assertSame([true, '+983000505'], [$from['ltr'], $from['placeholder']]);
        self::assertSame(IpPanel::VARIABLE, $variable['default']);
        self::assertSame([true, IpPanelSender::BASE_URL], [$url['advanced'], $url['default']], 'another address only under «تنظیمات پیشرفته»');
    }

    public function testItsFormRefusesEveryBadFieldAtOnce(): void
    {
        try {
            self::driver()->describe()->form->check(['from' => 'خط من', 'pattern' => 'الگو', 'variable' => '1code', 'api_url' => 'edge.ippanel.com'], static fn(Field $field): mixed => null);
            self::fail('taken all the same');
        } catch (ValidationException $e) {
            self::assertSame(['api_key', 'from', 'pattern', 'variable', 'api_url'], array_keys($e->errors()));
            self::assertSame([IpPanel::KEY_MISSING], $e->errors()['api_key'], 'no code could go without it');
        }

        $this->expectException(ValidationException::class);
        self::driver()->describe()->form->check(['api_key' => 'Bearer ' . self::KEY] + self::VALUES, static fn(Field $field): mixed => null);
    }

    /** @return array<string, array{string, string}> what is typed, what IPPanel is sent */
    public static function lines(): array
    {
        return [
            'a line as the panel shows it' => ['3000505', '+983000505'],
            'in Persian digits, spaced' => ['۳۰۰۰ ۵۰۵', '+983000505'],
            'in E.164' => ['+98 3000-505', '+983000505'],
            'with 00' => ['00983000505', '+983000505'],
            'with 98 alone' => ['983000505', '+983000505'],
            'a landline' => ['02188776655', '+982188776655'],
        ];
    }

    #[DataProvider('lines')]
    public function testTheLineIsKeptAsIPPanelTakesIt(string $typed, string $kept): void
    {
        $saved = self::driver()->describe()->form->check(['from' => $typed, 'variable' => '%code%'] + self::VALUES, static fn(Field $field): mixed => null);

        self::assertSame([$kept, 'code'], [$saved['SMS_IPPANEL_FROM'], $saved['SMS_IPPANEL_VARIABLE']], 'the variable without its %');
    }

    public function testAKeptKeyGoesToNoOtherAddress(): void
    {
        $form = self::driver()->describe()->form;
        $kept = ['SMS_IPPANEL_API_KEY' => self::KEY, 'SMS_IPPANEL_URL' => IpPanelSender::BASE_URL];
        $stored = static fn(Field $field): mixed => $kept[$field->key] ?? null;

        try {
            $form->check(['api_key' => '', 'api_url' => 'https://sms.elsewhere.example/v1'] + self::VALUES, $stored);
            self::fail('the kept key went elsewhere');
        } catch (ValidationException $e) {
            self::assertSame(['api_key' => [IpPanel::KEY_MOVED]], $e->errors());
        }

        $same = $form->check(['api_key' => '', 'api_url' => 'https://edge.ippanel.com/v2'] + self::VALUES, $stored);
        self::assertSame(self::KEY, $same['SMS_IPPANEL_API_KEY'], 'another path on the same server keeps it');
    }

    public function testACodeGoesAsTheApprovedPatternSignedWithTheKeyAsItIs(): void
    {
        $ippanel = new FakePanel();
        $ippanel->raw(self::answer(['message_outbox_ids' => [1123594208]]));

        self::sender($ippanel)->sendCode('+989121234567', '048213');

        $request = $ippanel->request(0);
        self::assertSame('POST https://edge.ippanel.com/v1/api/send', $request->getMethod() . ' ' . $request->getUri());
        self::assertSame(self::KEY, $request->getHeaderLine('Authorization'), 'the key as it is, no Bearer');
        self::assertSame([
            'sending_type' => 'pattern',
            'from_number' => '+983000505',
            'code' => 'jhaskdab45s6f4sfw',
            'recipients' => ['+989121234567'],
            'params' => ['code' => '048213'],
        ], $ippanel->params(0), 'one recipient, the code as text — its leading zero kept');
        $options = $ippanel->options(0);
        self::assertFalse($options['allow_redirects'] ?? null, 'the key goes to IPPanel and nowhere else');
        self::assertSame([15, 5], [$options['timeout'] ?? null, $options['connect_timeout'] ?? null], 'a customer waits for their code: not the client\'s whole minute');
    }

    public function testTheSettingsMakeTheSenderAsTheyAreKept(): void
    {
        $ippanel = new FakePanel();
        $ippanel->raw(self::answer(['message_outbox_ids' => [1]]));

        // A line and a variable as a hand-edited config.php may hold them; another address IPPanel gave.
        $sender = (new IpPanel($ippanel->client()))->sender(['from' => '3000505', 'variable' => '%otp%', 'api_url' => 'https://edge2.example.com/v1/'] + self::VALUES);
        $sender->sendCode('+989121234567', '123456');

        self::assertSame('https://edge2.example.com/v1/api/send', (string) $ippanel->request(0)->getUri());
        self::assertSame(['+983000505', ['otp' => '123456']], [$ippanel->params(0)['from_number'], $ippanel->params(0)['params']]);
    }

    public function testSettingsThatMakeNoSenderSayWhatIsMissing(): void
    {
        foreach (['api_key' => 'SMS_IPPANEL_API_KEY', 'from' => 'SMS_IPPANEL_FROM', 'pattern' => 'SMS_IPPANEL_PATTERN'] as $field => $setting) {
            try {
                self::driver()->sender([$field => ' '] + self::VALUES);
                self::fail("built without {$setting}");
            } catch (SmsSettingsException $e) {
                self::assertStringContainsString($setting, $e->getMessage());
            }
        }
    }

    public function testARefusedKeyIsSaidInIPPanelsWordsWithoutTheKey(): void
    {
        $ippanel = new FakePanel();
        $ippanel->raw(self::refusal(401, 'اطلاعات وارد شده صحیح نمی باشد', code: '400-1'));

        $e = self::failure(static fn() => self::sender($ippanel)->sendCode('+989121234567', '123456'));

        self::assertSame(SmsFailedException::MESSAGE, $e->getMessage(), "a customer's words say only that it did not go");
        self::assertSame('IPPanel کلید دسترسی را نپذیرفت (اطلاعات وارد شده صحیح نمی باشد)؛ کلید را در پنل IPPanel، «توسعه‌دهندگان ← کلیدهای دسترسی»، ببینید.', $e->reason);
    }

    public function testARefusedRequestSaysEachFieldsWords(): void
    {
        $ippanel = new FakePanel();
        $ippanel->raw(self::refusal(422, 'تکمیل گزینه code الزامی است (و 1 خطای دیگر)', ['code' => ['تکمیل گزینه code الزامی است'], 'params.code' => ['مقدار متغیر code معتبر نیست']], '400-2'));

        $e = self::failure(static fn() => self::sender($ippanel)->sendCode('+989121234567', '123456'));

        self::assertSame('IPPanel درخواست را نپذیرفت: تکمیل گزینه code الزامی است؛ مقدار متغیر code معتبر نیست', $e->reason);
    }

    public function testAnyOtherRefusalIsIPPanelsOwnMessageWithoutTheKey(): void
    {
        $ippanel = new FakePanel();
        $ippanel->raw(self::refusal(200, 'اعتبار شما کافی نیست'));
        self::assertSame('IPPanel نپذیرفت: اعتبار شما کافی نیست', self::failure(static fn() => self::sender($ippanel)->sendCode('+989121234567', '123456'))->reason, 'meta.status false is a refusal whatever the status');

        $ippanel->raw(self::refusal(400, 'کلید ' . self::KEY . ' به این خط دسترسی ندارد'));
        $e = self::failure(static fn() => self::sender($ippanel)->sendCode('+989121234567', '123456'));
        self::assertSame('IPPanel نپذیرفت: کلید •••• به این خط دسترسی ندارد', $e->reason);
        self::assertStringNotContainsString(self::KEY, $e->explained()->getMessage());

        $ippanel->raw(new Response(502, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>'));
        self::assertSame('IPPanel پاسخ نامعلومی داد (HTTP 502).', self::failure(static fn() => self::sender($ippanel)->sendCode('+989121234567', '123456'))->reason);

        $ippanel->raw(new Response(200, ['Content-Type' => 'application/json'], '{"data":null}'));
        self::assertSame('IPPanel پاسخ نامعلومی داد (HTTP 200).', self::failure(static fn() => self::sender($ippanel)->sendCode('+989121234567', '123456'))->reason, 'no word that it went is no send');
    }

    public function testIPPanelOutOfReachOrTooSlowIsAFailureThatCarriesNoRequest(): void
    {
        $ippanel = new FakePanel();
        $ippanel->refuse(new ConnectException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received', new Request('POST', IpPanelSender::BASE_URL . '/api/send', ['Authorization' => self::KEY])));
        $slow = self::failure(static fn() => self::sender($ippanel)->sendCode('+989121234567', '123456'));
        self::assertSame('IPPanel به‌موقع جواب نداد.', $slow->reason);
        self::assertNull($slow->getPrevious(), "the client's exception holds the request, and so the key");

        $ippanel->refuse(new ConnectException('cURL error 6: Could not resolve host: edge.ippanel.com', new Request('POST', IpPanelSender::BASE_URL . '/api/send')));
        self::assertSame('به IPPanel وصل نشد: cURL error 6: Could not resolve host: edge.ippanel.com', self::failure(static fn() => self::sender($ippanel)->sendCode('+989121234567', '123456'))->reason);
    }

    public function testTheCreditIsTheAccountsInRial(): void
    {
        $ippanel = new FakePanel();
        $ippanel->raw(self::answer(['credit' => 6084722932.705052, 'yesterday_credit' => 195251925510.55, 'gift' => 0, 'gift_expires_at' => null, 'updated_at' => '2025-06-30 18:07:06']));

        $credit = self::sender($ippanel)->credit();

        self::assertEquals(new SmsCredit(6084722932, 'ریال'), $credit, 'whole Rials');
        $request = $ippanel->request(0);
        self::assertSame('GET https://edge.ippanel.com/v1/api/payment/credit/mine', $request->getMethod() . ' ' . $request->getUri());
        self::assertSame(self::KEY, $request->getHeaderLine('Authorization'));

        $ippanel->raw(self::refusal(401, 'اطلاعات وارد شده صحیح نمی باشد'));
        self::assertStringStartsWith('IPPanel کلید دسترسی را نپذیرفت', self::failure(static fn() => self::sender($ippanel)->credit())->reason);
        $ippanel->raw(self::answer(['gift' => 0]));
        self::assertSame('IPPanel اعتبار حساب را نگفت.', self::failure(static fn() => self::sender($ippanel)->credit())->reason);
    }

    private static function driver(): IpPanel
    {
        return new IpPanel((new FakePanel())->client());
    }

    private static function sender(FakePanel $ippanel): IpPanelSender
    {
        $sender = (new IpPanel($ippanel->client()))->sender(self::VALUES);
        self::assertInstanceOf(IpPanelSender::class, $sender);

        return $sender;
    }

    /**
     * IPPanel's answer to a call that went through, carrying `$data`.
     *
     * @param array<string, mixed> $data
     */
    private static function answer(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => $data, 'meta' => ['status' => true, 'message' => 'انجام شد', 'message_parameters' => [], 'message_code' => '200-1']]));
    }

    /**
     * IPPanel's refusal: `meta.status` false with its message, and each field's words for a refused request.
     *
     * @param array<string, list<string>> $errors
     */
    private static function refusal(int $status, string $message, array $errors = [], string $code = '400-3'): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode([
            'data' => null,
            'meta' => ['status' => false, 'message' => $message, 'message_parameters' => [], 'message_code' => $code, 'errors' => (object) $errors],
        ]));
    }

    /** @param \Closure(): mixed $call */
    private static function failure(\Closure $call): SmsFailedException
    {
        try {
            $call();
        } catch (SmsFailedException $e) {
            self::assertStringNotContainsString(self::KEY, $e->reason, 'the key is in no failure');

            return $e;
        }

        self::fail('IPPanel took it');
    }
}
