<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Bots\CurrentBot;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\GatewayWebhooks;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;
use Tests\Support\FakeRialit;

/**
 * Rialit's one webhook address an account, as the bot's management works it (PaymentMethodScreen): a Rialit method's
 * save registers the shop's address while Rialit has none — what was done said over the method's screen, its line from
 * the save's answer —, never replaces another site's — a warning pointing to the replace button, the other address
 * shown and replaced only after a question that says that site stops getting Rialit's webhooks and Rialit tells the
 * account's owner —, a change Rialit refuses (20 an hour) said with why and nothing changed, a Rialit out of reach said
 * as unknown with the screen still whole and read again at a tap, the address registered at a tap; no line, and no word
 * to Rialit, for any other method; the owner's and an agent's, an admin's tap changing nothing.
 */
final class OwnerRialitWebhookTest extends BotTestCase
{
    /** The shop's address for Rialit's webhooks. */
    private const OURS = 'https://shop.example/payments/webhooks/rialit';

    /** Another site's, set in Rialit's dashboard for the same account. */
    private const OTHER = 'https://other-shop.example/hooks/rialit';

    /** What the screen says under another site's address, but for a replace just tapped. */
    private const SWAP_HINT = 'برای جایگزین کردن، «🔁 جایگزین کردن با آدرس فروشگاه» را بزنید.';

    private FakeRialit $rialit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);
        $this->rialit = $this->rialit();
    }

    public function testANewRialitMethodRegistersTheShopsAddressWhileRialitHasNone(): void
    {
        $this->send($this->tap(Nav::to('pmf', 'new', 'rialit'), chat: self::OWNER));
        for ($field = 0; $field < 3; $field++) {
            $this->send($this->tap(Nav::to('pmf', 's', $field), chat: self::OWNER));
        }
        $this->send($this->message(FakeRialit::KEY, chat: self::OWNER));
        self::assertStringContainsString('<code>' . self::OURS . '</code>', $this->said()[0], 'the address to paste by hand stays in its notes');

        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        $method = PaymentMethod::query()->where('driver', 'rialit')->sole();
        self::assertSame(self::OURS, $this->rialit->webhookUrl, 'registered by the save');
        self::assertSame(1, $this->rialit->notices);
        self::assertSame('✅ روش پرداخت اضافه شد', $this->popup());
        $screen = $this->said()[0];
        self::assertStringStartsWith('<b>✅ ' . GatewayWebhooks::REGISTERED . "</b>\n\n<b>🏦 ", $screen, 'what was done, over the screen');
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: 🟢 آدرس فروشگاه ثبت است', $screen);
        self::assertSame(['GET', 'POST'], array_column($this->rialit->webhookCalls(), 'method'), 'its line from the save\'s answer, not read again');
        $this->assertNoWebhookButtons($method);

        $this->send($this->tap(Nav::to('pmv', 'show', $method->id), chat: self::OWNER));
        self::assertStringStartsWith('<b>🏦 ', $this->said()[0], 'said once');
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: 🟢 آدرس فروشگاه ثبت است', $this->said()[0], 'read when the screen opens');

        $this->send($this->tap(Nav::to('pmf', 'edit', $method->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        self::assertStringStartsWith('<b>🏦 ', $this->said()[0], 'nothing said when it was the shop\'s already');
        self::assertCount(1, $this->rialit->webhookCalls('POST'), 'nothing set again');
    }

    public function testAnotherSitesAddressIsNeverReplacedSilentlyAndIsReplacedAfterAQuestion(): void
    {
        $this->rialit->webhookUrl = self::OTHER;
        $method = $this->rialitMethod();

        $this->send($this->tap(Nav::to('pmf', 'edit', $method->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        self::assertSame(self::OTHER, $this->rialit->webhookUrl, 'never replaced by a save');
        $screen = $this->said()[0];
        self::assertStringStartsWith('<b>⚠️ ' . GatewayWebhooks::TAKEN_ELSEWHERE . "</b>\n<b>" . self::SWAP_HINT . '</b>', $screen, 'over the screen, pointing to the button');
        self::assertSame(1, substr_count($screen, GatewayWebhooks::TAKEN_ELSEWHERE), 'said once');
        self::assertStringContainsString("🔗 وب‌هوک ریالیت: ⚠️ به آدرس دیگری می‌رود\n<code>" . self::OTHER . '</code>', $screen, 'the other address, copied at a tap');
        self::assertContains(Nav::to('pmv', 'swap', $method->id), $this->callbacks($this->window()));

        $this->send($this->tap(Nav::to('pmv', 'show', $method->id), chat: self::OWNER));
        self::assertStringContainsString('<code>' . self::OTHER . '</code>', $this->said()[0], 'read again: the other address');
        self::assertStringNotContainsString(GatewayWebhooks::TAKEN_ELSEWHERE, $this->said()[0], 'only read: the state says it, no words repeat it');

        $this->send($this->tap(Nav::to('pmv', 'swap', $method->id), chat: self::OWNER));
        $asked = $this->said()[0];
        self::assertStringContainsString('آدرس وب‌هوک ریالیت با آدرس فروشگاه جایگزین شود؟', $asked);
        self::assertStringContainsString('<code>' . self::OTHER . '</code>', $asked);
        self::assertStringContainsString('<code>' . self::OURS . '</code>', $asked);
        self::assertStringContainsString('آن آدرس دیگر وب‌هوک‌های ریالیت را نمی‌گیرد', $asked);
        self::assertStringContainsString('ریالیت این تغییر را در تلگرام و ایمیل به صاحب حساب خبر می‌دهد', $asked);
        self::assertSame([Nav::to('pmv', 'show', $method->id), Nav::to('pmv', 'swapped', $method->id)], $this->callbacks($this->window()), 'the yes on the right, as a Persian reader reads');
        self::assertSame(self::OTHER, $this->rialit->webhookUrl, 'asked first');

        $this->send($this->tap(Nav::to('pmv', 'show', $method->id), chat: self::OWNER));
        self::assertSame(self::OTHER, $this->rialit->webhookUrl, '«✖️ انصراف» changes nothing');
        self::assertSame(0, $this->rialit->notices);

        $this->send($this->tap(Nav::to('pmv', 'swapped', $method->id), chat: self::OWNER));
        self::assertSame(self::OURS, $this->rialit->webhookUrl);
        self::assertSame(1, $this->rialit->notices, 'Rialit told the account\'s owner');
        self::assertStringStartsWith('<b>✅ ' . GatewayWebhooks::REPLACED . '</b>', $this->said()[0]);
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: 🟢 آدرس فروشگاه ثبت است', $this->said()[0]);
        $this->assertNoWebhookButtons($method);
    }

    public function testAChangeRialitRefusesIsSaidWithWhyAndChangesNothing(): void
    {
        $method = $this->rialitMethod();
        $this->rialit->changes = FakeRialit::CHANGES_AN_HOUR;

        $this->send($this->tap(Nav::to('pmv', 'hook', $method->id), chat: self::OWNER));
        self::assertNull($this->rialit->webhookUrl);
        self::assertStringStartsWith('<b>⚠️ ' . GatewayWebhooks::TOO_MANY_CHANGES . '</b>', $this->said()[0]);
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: ⏸ ثبت نشده', $this->said()[0]);
        self::assertContains(Nav::to('pmv', 'hook', $method->id), $this->callbacks($this->window()), 'to try again later');

        $this->rialit->webhookUrl = self::OTHER;
        $this->send($this->tap(Nav::to('pmv', 'swapped', $method->id), chat: self::OWNER));
        self::assertSame(self::OTHER, $this->rialit->webhookUrl);
        self::assertSame(0, $this->rialit->notices);
        $screen = $this->said()[0];
        self::assertStringStartsWith('<b>⚠️ ' . GatewayWebhooks::TOO_MANY_CHANGES . "</b>\n\n<b>🏦 ", $screen, 'why, and no pointer to the button just tapped');
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: ⚠️ به آدرس دیگری می‌رود', $screen);
        self::assertContains(Nav::to('pmv', 'swap', $method->id), $this->callbacks($this->window()));
    }

    public function testARialitOutOfReachIsSaidAsUnknownAndTheAddressIsRegisteredAtATapOnceItAnswers(): void
    {
        $method = $this->rialitMethod();
        $this->rialit->failing = 503;

        $this->send($this->tap(Nav::to('pmv', 'show', $method->id), chat: self::OWNER));
        $screen = $this->said()[0];
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: ⚠️ ' . GatewayWebhooks::OUT_OF_REACH, $screen, 'why, on its line');
        self::assertStringContainsString('وضعیت: <b>🟢 فعال</b>', $screen, 'the screen still whole');
        self::assertContains(Nav::to('pmv', 'on', $method->id, 0), $this->callbacks($this->window()));
        self::assertContains(Nav::to('pmv', 'look', $method->id), $this->callbacks($this->window()));

        $this->send($this->tap(Nav::to('pmv', 'look', $method->id), chat: self::OWNER));
        self::assertSame('⚠️ باز هم خوانده نشد', $this->popup());

        $this->rialit->failing = null;
        $this->send($this->tap(Nav::to('pmv', 'look', $method->id), chat: self::OWNER));
        self::assertNull($this->popup());
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: ⏸ ثبت نشده', $this->said()[0]);
        self::assertContains(Nav::to('pmv', 'hook', $method->id), $this->callbacks($this->window()));
        self::assertSame([], $this->rialit->webhookCalls('POST'), 'never set by reading it');

        $this->send($this->tap(Nav::to('pmv', 'hook', $method->id), chat: self::OWNER));
        self::assertSame(self::OURS, $this->rialit->webhookUrl);
        self::assertStringStartsWith('<b>✅ ' . GatewayWebhooks::REGISTERED . '</b>', $this->said()[0]);
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: 🟢 آدرس فروشگاه ثبت است', $this->said()[0]);
    }

    public function testNoOtherMethodHasTheLineOrAsksRialit(): void
    {
        $card = $this->cardMethod();

        $this->send($this->tap(Nav::to('pmv', 'show', $card->id), chat: self::OWNER));
        self::assertStringNotContainsString('وب‌هوک', $this->said()[0]);
        $this->send($this->tap(Nav::to('pmv', 'hook', $card->id), chat: self::OWNER));
        self::assertSame([], $this->rialit->calls);
    }

    public function testAnAgentWorksTheirOwnRialitMethodsWebhookAndAnAdminNone(): void
    {
        $bot = $this->agentBot();
        $agentMethod = CurrentBot::run($bot, fn(): PaymentMethod => $this->rialitMethod('ریالیت نماینده'));
        $this->sendTo($bot, $this->tap(Nav::to('pmv', 'show', $agentMethod->id), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('🔗 وب‌هوک ریالیت: ⏸ ثبت نشده', $this->said()[0]);
        self::assertContains(Nav::to('pmv', 'hook', $agentMethod->id), $this->callbacks($this->window()));

        $this->rialit->webhookUrl = self::OTHER;
        $method = $this->rialitMethod();
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $this->send($this->tap(Nav::to('pmv', 'swapped', $method->id), chat: 7100));
        self::assertSame(self::OTHER, $this->rialit->webhookUrl, 'an admin\'s tap changes nothing');
        self::assertSame([], $this->rialit->webhookCalls('POST'));
    }

    /** The method's screen offers no webhook change: the address is the shop's. */
    private function assertNoWebhookButtons(PaymentMethod $method): void
    {
        $buttons = $this->callbacks($this->window());
        self::assertContains(Nav::to('pmf', 'edit', $method->id), $buttons, 'the method\'s own screen');
        foreach (['hook', 'swap', 'look'] as $action) {
            self::assertNotContains(Nav::to('pmv', $action, $method->id), $buttons);
        }
    }

    /** The call that drew the window last (a toast may answer the tap first). */
    private function window(): int
    {
        $index = 0;
        foreach ($this->calls() as $i => $method) {
            if (in_array($method, ['sendMessage', 'editMessageText'], true)) {
                $index = $i;
            }
        }

        return $index;
    }
}
