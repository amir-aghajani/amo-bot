<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\GatewayWebhooks;
use App\Modules\Store\Models\Website;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeRialit;

/**
 * The shop registers its webhook address with Rialit itself, through Rialit's API — one address for the merchant's whole
 * account, which another site may be using: when a Rialit method is saved (made, or its form sent again; not switched)
 * the shop reads Rialit's address and registers its own only where there is none; another address is left as it is and
 * said, until the owner replaces it (POST …/webhook with `replace`); Rialit not answering — its key refused, the server's
 * IP not allowed, its account off, too many requests, out of reach — is said too, the save done all the same. Both
 * panels have it, each in its own shop; the website's admins do not; no other gateway does. No secret is logged or
 * answered.
 */
final class RialitWebhookRegistrationTest extends HttpTestCase
{
    private const OURS = 'https://shop.example/payments/webhooks/rialit';
    private const OTHER = 'https://other-site.example/rialit/hook';

    private FakeRialit $rialit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example']);
        $this->rialit = $this->rialit();
        $this->loginAsAdmin();
    }

    public function testASavedMethodRegistersTheShopsAddressWhereRialitHasNoneAndLeavesItAfter(): void
    {
        $added = $this->add();

        self::assertSame(201, $added->getStatusCode(), (string) $added->getBody());
        $answer = $this->decode($added);
        self::assertSame(['state' => 'ours', 'url' => self::OURS, 'ours' => self::OURS, 'message' => GatewayWebhooks::REGISTERED], $answer['webhook']);
        self::assertTrue($answer['method']['registers_webhook']);
        self::assertSame(self::OURS, $this->rialit->webhookUrl);
        self::assertSame(1, $this->rialit->notices, 'Rialit tells the account\'s owner');
        self::assertSame(['GET', 'POST'], array_column($this->rialit->webhookCalls(), 'method'), 'read first');
        self::assertSame(['webhook_url' => self::OURS], $this->rialit->webhookCalls('POST')[0]['body']);
        self::assertSame(['Bearer ' . FakeRialit::KEY], array_values(array_unique(array_column($this->rialit->webhookCalls(), 'authorization'))));

        // Saved again: already the shop's — read, nothing changed, nothing to say.
        $saved = $this->putJson('/api/admin/payment-methods/' . $answer['method']['id'], ['label' => 'ریالیت تازه']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(['state' => 'ours', 'url' => self::OURS, 'ours' => self::OURS, 'message' => null], $this->decode($saved)['webhook']);
        self::assertCount(1, $this->rialit->webhookCalls('POST'));
        self::assertSame(1, $this->rialit->notices);

        // Switched off and on: no save of its form, Rialit not asked.
        $calls = count($this->rialit->calls);
        $switched = $this->decode($this->patchJson('/api/admin/payment-methods/' . $answer['method']['id'], ['enabled' => false]));
        self::assertArrayNotHasKey('webhook', $switched);
        self::assertCount($calls, $this->rialit->calls);

        // Looked at again: the same, and a POST without `replace` changes nothing either.
        self::assertSame(['webhook' => ['state' => 'ours', 'url' => self::OURS, 'ours' => self::OURS, 'message' => null]], $this->decode($this->get('/api/admin/payment-methods/' . $answer['method']['id'] . '/webhook')));
        self::assertSame('ours', $this->decode($this->postJson('/api/admin/payment-methods/' . $answer['method']['id'] . '/webhook', ['replace' => true]))['webhook']['state']);
        self::assertCount(1, $this->rialit->webhookCalls('POST'));
    }

    public function testAnotherAddressIsLeftAsItIsUntilTheOwnerReplacesIt(): void
    {
        $this->rialit->webhookUrl = self::OTHER;

        $answer = $this->decode($this->add());
        $elsewhere = ['state' => 'elsewhere', 'url' => self::OTHER, 'ours' => self::OURS, 'message' => GatewayWebhooks::TAKEN_ELSEWHERE];
        self::assertSame($elsewhere, $answer['webhook']);
        self::assertSame([self::OTHER, 0, []], [$this->rialit->webhookUrl, $this->rialit->notices, $this->rialit->webhookCalls('POST')], 'never overwritten silently');

        $webhook = '/api/admin/payment-methods/' . $answer['method']['id'] . '/webhook';
        self::assertSame(['webhook' => array_replace($elsewhere, ['message' => null])], $this->decode($this->get($webhook)), 'only read: no words beyond the state');
        self::assertSame(['webhook' => $elsewhere], $this->decode($this->postJson($webhook, ['replace' => false])));
        self::assertSame(self::OTHER, $this->rialit->webhookUrl);

        $replaced = $this->postJson($webhook, ['replace' => true]);
        self::assertSame(['webhook' => ['state' => 'ours', 'url' => self::OURS, 'ours' => self::OURS, 'message' => GatewayWebhooks::REPLACED]], $this->decode($replaced), (string) $replaced->getBody());
        self::assertSame([self::OURS, 1], [$this->rialit->webhookUrl, $this->rialit->notices]);

        // Written back another way (a closing slash, capitals): still the shop's.
        $this->rialit->webhookUrl = 'https://SHOP.example/payments/webhooks/rialit/';
        self::assertSame('ours', $this->decode($this->get($webhook))['webhook']['state']);
    }

    public function testRialitWithWebhooksOffIsRegisteredOnRequestToo(): void
    {
        $method = $this->rialitMethod();
        $webhook = '/api/admin/payment-methods/' . $method->id . '/webhook';

        self::assertSame(['webhook' => ['state' => 'none', 'url' => null, 'ours' => self::OURS, 'message' => null]], $this->decode($this->get($webhook)), 'a look changes nothing');
        self::assertSame([], $this->rialit->webhookCalls('POST'));

        self::assertSame(GatewayWebhooks::REGISTERED, $this->decode($this->postJson($webhook, ['replace' => false]))['webhook']['message']);
        self::assertSame(self::OURS, $this->rialit->webhookUrl);

        $this->assertRefusedUnder('replace', 'مقدار این گزینه باید روشن یا خاموش باشد.', $this->unchecked()->postJson($webhook, ['replace' => 'maybe']));
    }

    public function testWhenRialitCannotBeAskedTheSaveIsDoneAndSaysWhy(): void
    {
        $logs = $this->logs();
        $causes = [
            GatewayWebhooks::BAD_KEY => [fn() => null, 'rlt_live_wrong_key_0000', '(HTTP 401): Invalid or revoked API key — the API key is wrong'],
            GatewayWebhooks::IP_NOT_ALLOWED => [fn() => $this->rialit->ipAllowed = false, FakeRialit::KEY, '(HTTP 403): IP not allowed — the shop server\'s outgoing IP is not on the allowed IPs'],
            GatewayWebhooks::BLOCKED => [fn() => $this->rialit->active = false, FakeRialit::KEY, '(HTTP 403): Merchant is not active'],
            GatewayWebhooks::BUSY => [fn() => $this->rialit->limited = true, FakeRialit::KEY, '(HTTP 429): Too many requests — too many requests just now'],
            GatewayWebhooks::OUT_OF_REACH => [fn() => $this->rialit->failing = 503, FakeRialit::KEY, '(HTTP 503): Internal error — Rialit is in trouble'],
        ];
        foreach ($causes as $message => [$trouble, $key, $logged]) {
            $this->answering();
            $trouble();

            $added = $this->add(['api_key' => $key]);

            self::assertSame(201, $added->getStatusCode(), (string) $added->getBody());
            self::assertSame(['state' => 'unknown', 'url' => null, 'ours' => self::OURS, 'message' => $message], $this->decode($added)['webhook'], $message);
            self::assertSame([], $this->rialit->webhookCalls('POST'), 'nothing changed where Rialit could not be read');
            self::assertTrue($logs->hasWarningThatContains($logged), $logged);
        }

        // Out of reach: saved again all the same.
        $this->answering();
        $this->rialit->down();
        $method = PaymentMethod::query()->where('driver', 'rialit')->latest('id')->firstOrFail();
        $saved = $this->putJson('/api/admin/payment-methods/' . $method->id, ['label' => 'ریالیت']);
        self::assertSame([200, 'unknown', GatewayWebhooks::OUT_OF_REACH], [$saved->getStatusCode(), $this->decode($saved)['webhook']['state'], $this->decode($saved)['webhook']['message']]);
        self::assertTrue($logs->hasWarningThatContains('Rialit is out of reach'));
        self::assertSame('ریالیت', $method->refresh()->label);
    }

    public function testRialitsLimitOfTwentySecurityChangesAnHourIsSaid(): void
    {
        $logs = $this->logs();
        // Twenty made in its dashboard this hour.
        $this->rialit->changes = FakeRialit::CHANGES_AN_HOUR;

        $added = $this->add();

        self::assertSame(201, $added->getStatusCode());
        self::assertSame(['state' => 'none', 'url' => null, 'ours' => self::OURS, 'message' => GatewayWebhooks::TOO_MANY_CHANGES], $this->decode($added)['webhook']);
        self::assertNull($this->rialit->webhookUrl);
        self::assertTrue($logs->hasWarningThatContains('(HTTP 429): Too many security changes in the last hour — Rialit takes 20 security changes an hour at most'));

        $this->rialit->webhookUrl = self::OTHER;
        $replacing = $this->decode($this->postJson('/api/admin/payment-methods/' . $this->decode($added)['method']['id'] . '/webhook', ['replace' => true]))['webhook'];
        self::assertSame(['elsewhere', self::OTHER, GatewayWebhooks::TOO_MANY_CHANGES], [$replacing['state'], $replacing['url'], $replacing['message']]);
        self::assertSame(self::OTHER, $this->rialit->webhookUrl);
    }

    public function testAShopAddressRialitWouldNotTakeIsSaid(): void
    {
        $logs = $this->logs();
        $this->config(['app.url' => 'http://shop.example']);
        $answer = $this->decode($this->add())['webhook'];
        self::assertSame(['state' => 'none', 'url' => null, 'ours' => 'http://shop.example/payments/webhooks/rialit', 'message' => GatewayWebhooks::NOT_HTTPS], $answer);
        self::assertSame([], $this->rialit->webhookCalls('POST'), 'not sent at all');

        $this->config(['app.url' => 'https://localhost']);
        $answer = $this->decode($this->add(['label' => 'ریالیت ۲']))['webhook'];
        self::assertSame(['none', GatewayWebhooks::ADDRESS_REFUSED], [$answer['state'], $answer['message']]);
        self::assertTrue($logs->hasWarningThatContains('Rialit would not take the shop\'s address'));
        self::assertNull($this->rialit->webhookUrl);
    }

    public function testAMethodWithNoKeyAsksRialitNothing(): void
    {
        // Its key no longer decrypts (APP_KEY changed): kept empty.
        $method = $this->rialitMethod(key: '');

        $answer = $this->decode($this->get('/api/admin/payment-methods/' . $method->id . '/webhook'))['webhook'];

        self::assertSame(['unknown', GatewayWebhooks::BAD_KEY], [$answer['state'], $answer['message']]);
        self::assertSame([], $this->rialit->calls);
    }

    public function testAMethodOfAnotherGatewayHasNoWebhookHere(): void
    {
        $card = $this->cardMethod();
        $variza = $this->varizaMethod();
        $rialit = $this->rialitMethod();
        $wallet = PaymentMethod::query()->where('driver', 'wallet')->firstOrFail();

        $rows = array_column($this->decode($this->get('/api/admin/payment-methods'))['methods'], 'registers_webhook', 'id');
        self::assertSame([false, false, false, true], [$rows[$card->id], $rows[$variza->id], $rows[$wallet->id], $rows[$rialit->id]]);

        foreach ([$card, $variza, $wallet] as $method) {
            $this->assertRefusedUnder('driver', GatewayWebhooks::NOT_REGISTERING, $this->get("/api/admin/payment-methods/{$method->id}/webhook"));
            $this->assertRefusedUnder('driver', GatewayWebhooks::NOT_REGISTERING, $this->postJson("/api/admin/payment-methods/{$method->id}/webhook", ['replace' => true]));
        }
        $saved = $this->putJson("/api/admin/payment-methods/{$wallet->id}", ['label' => 'کیف پول']);
        self::assertSame([200, null], [$saved->getStatusCode(), $this->decode($saved)['webhook']]);

        self::assertSame(404, $this->get('/api/admin/payment-methods/999999/webhook')->getStatusCode());
        $outside = PaymentMethod::query()->where('driver', 'outside')->firstOrFail();
        self::assertSame(404, $this->postJson("/api/admin/payment-methods/{$outside->id}/webhook", ['replace' => false])->getStatusCode());
        self::assertSame([], $this->rialit->calls);
    }

    public function testAnAgentHasItInTheirOwnShopAndTheWebsitesAdminsDoNot(): void
    {
        $main = $this->rialitMethod();
        $bot = $this->agentBot();
        $_SESSION = [];
        $this->loginAsAgent($bot);

        $added = $this->postJson('/api/agent/payment-methods', ['driver' => 'rialit', 'label' => 'ریالیت نماینده', 'api_key' => FakeRialit::KEY, 'clear_api_key' => false]);
        self::assertSame(201, $added->getStatusCode(), (string) $added->getBody());
        $answer = $this->decode($added);
        self::assertSame(['ours', self::OURS, GatewayWebhooks::REGISTERED], [$answer['webhook']['state'], $answer['webhook']['url'], $answer['webhook']['message']], 'the one address of every shop');
        self::assertSame($bot->id, CurrentBot::everywhere(static fn(): ?int => PaymentMethod::query()->find($answer['method']['id'])?->bot_id));
        self::assertSame('ours', $this->decode($this->get('/api/agent/payment-methods/' . $answer['method']['id'] . '/webhook'))['webhook']['state']);
        self::assertSame(404, $this->get("/api/agent/payment-methods/{$main->id}/webhook")->getStatusCode(), 'the main shop\'s method is no row of theirs');

        $_SESSION = [];
        $this->bearer(null);
        $website = $this->website(['staff_grants' => []]);
        $this->loginAsStaff($website, $this->customer(['telegram_id' => 7002]));
        $staff = $this->unchecked()->json('GET', $this->storeApi($website, "/admin/payment-methods/{$main->id}/webhook"));
        self::assertSame(404, $staff->getStatusCode(), 'no route of the website\'s admins');
        self::assertSame(404, $this->unchecked()->json('POST', $this->storeApi($website, "/admin/payment-methods/{$main->id}/webhook"), ['replace' => true])->getStatusCode());
    }

    public function testNoSecretIsLoggedOrAnswered(): void
    {
        $logs = $this->logs();
        $answers = [];

        $answers[] = $this->add();
        $answers[] = $this->add(['api_key' => 'rlt_live_wrong_key_0000', 'label' => 'ریالیت ۲']);
        $this->rialit->webhookUrl = self::OTHER;
        $method = (int) $this->decode($answers[0])['method']['id'];
        $answers[] = $this->postJson("/api/admin/payment-methods/{$method}/webhook", ['replace' => true]);
        $this->rialit->down();
        $answers[] = $this->get("/api/admin/payment-methods/{$method}/webhook");

        foreach ($answers as $answer) {
            self::assertStringNotContainsString(FakeRialit::KEY, (string) $answer->getBody());
            self::assertStringNotContainsString('rlt_live_wrong_key_0000', (string) $answer->getBody());
        }
        $logged = json_encode(array_map(static fn(LogRecord $record): array => [$record->message, $record->context], $logs->getRecords()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('webhook address', $logged);
        self::assertStringNotContainsString(FakeRialit::KEY, $logged);
        self::assertStringNotContainsString('rlt_live_wrong_key_0000', $logged);
    }

    public function testRialitsAnswersUnderDataAreReadAlike(): void
    {
        $this->rialit->wrapped = true;

        self::assertSame('ours', $this->decode($this->add())['webhook']['state']);
        self::assertSame(self::OURS, $this->rialit->webhookUrl);
    }

    /**
     * A Rialit method added through the owner's panel, `$fields` over its form.
     *
     * @param array<string, mixed> $fields
     */
    private function add(array $fields = []): ResponseInterface
    {
        return $this->postJson('/api/admin/payment-methods', $fields + ['driver' => 'rialit', 'label' => 'ریالیت', 'api_key' => FakeRialit::KEY, 'clear_api_key' => false]);
    }

    /** Rialit answering again, for the next case: no refusal of its own set, no call recorded. */
    private function answering(): void
    {
        $this->rialit->ipAllowed = true;
        $this->rialit->active = true;
        $this->rialit->limited = false;
        $this->rialit->failing = null;
        $this->rialit->calls = [];
    }
}
