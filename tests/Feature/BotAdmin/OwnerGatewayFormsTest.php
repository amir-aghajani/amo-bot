<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Drivers\Bluepal\BluepalDriver;
use App\Modules\Payments\Drivers\Rialit\RialitDriver;
use App\Modules\Payments\Drivers\Rikhtam\RikhtamDriver;
use App\Modules\Payments\MethodSettings;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Telegram\Admin\Ui\Nav;
use Tests\BotTestCase;
use Tests\Support\FakeBluepal;
use Tests\Support\FakeRialit;
use Tests\Support\FakeRikhtam;

/**
 * Today's three online gateways as the bot's generic payment-method form (PaymentMethodFormScreen) draws them from their
 * drivers' descriptions: Rikhtam's crypto method — its key typed at a question that says it is taken away, a coin among
 * five choices laid one a row so no label is cut, changed on an edit while the key is kept —, Bluepal's merchant id and
 * key — a key the API refuses said under its field, the draft kept —, and Rialit's key with the address to give Rialit
 * copyable among its notes. Every save through the panel's own route; no key ever shown.
 */
final class OwnerGatewayFormsTest extends BotTestCase
{
    private const OWNER = 7001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);
    }

    public function testRikhtamsCryptoMethodIsAddedByItsKeyAndCoinAndItsCoinChangedWhileTheKeyIsKept(): void
    {
        $this->walkTo('rikhtam', 3);
        $asked = $this->said()[0];
        self::assertStringContainsString('کلید امنیتی درگاه', $asked);
        self::assertStringContainsString('پیامتان بعد از خواندن پاک می‌شود', $asked, 'a secret says it is taken away');
        self::assertNotContains(Nav::to('pmf', 's', 3), $this->callbacks(0), 'required: no way past it');

        $this->send($this->message(FakeRikhtam::KEY, chat: self::OWNER));
        self::assertContains('deleteMessage', $this->calls(), 'the typed key taken away');
        $rows = $this->inlineKeyboard($this->window());
        self::assertSame('● انتخاب مشتری در صفحه ریختم', $rows[0][0]['text'], 'the customer\'s pick by default, marked');
        foreach (array_slice($rows, 0, 5) as $row) {
            self::assertCount(1, $row, 'a coin a row: «تتر روی شبکه ترون (TRC20)» is too long for half a phone\'s row');
        }
        self::assertSame([['text' => '✖️ انصراف', 'callback_data' => Nav::to('pmf', 'quit')]], end($rows), 'the walk\'s way out is named for what it does');

        $this->send($this->tap(Nav::to('pmf', 'c', 4, 3), chat: self::OWNER));
        $card = $this->said()[0];
        self::assertStringContainsString('ارز پرداخت: تتر روی شبکه ترون (TRC20)', $card, 'the walk ends on the card');
        self::assertStringContainsString('کلید امنیتی درگاه: •••• (تازه نوشته شده)', $card);
        self::assertStringContainsString('ℹ️ پول مستقیم به کیف پول خود شما می‌رود', $card, 'its notes over the form');
        self::assertStringNotContainsString(FakeRikhtam::KEY, $card);

        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        $method = PaymentMethod::query()->where('driver', 'rikhtam')->sole();
        self::assertSame(['secret' => FakeRikhtam::KEY, 'coin' => 'usdt_trc20'], $this->settings(RikhtamDriver::class, $method));
        self::assertStringNotContainsString(FakeRikhtam::KEY, (string) json_encode($method->config), 'kept encrypted');
        self::assertStringContainsString('ارز: تتر روی شبکه ترون (TRC20)', $this->said()[0], 'its summary on its page');

        $this->send($this->tap(Nav::to('pmf', 'edit', $method->id), chat: self::OWNER));
        self::assertStringContainsString('کلید امنیتی درگاه: ذخیره شده', $this->said()[0]);
        $this->send($this->tap(Nav::to('pmf', 'e', 4), chat: self::OWNER));
        self::assertStringContainsString('الان: تتر روی شبکه ترون (TRC20)', $this->said()[0]);
        self::assertContains(Nav::to('pmf', 'show'), $this->callbacks(0), 'back to the card, the draft kept');
        $this->send($this->tap(Nav::to('pmf', 'c', 4, 0), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        self::assertSame(['secret' => FakeRikhtam::KEY, 'coin' => RikhtamDriver::ANY_COIN], $this->settings(RikhtamDriver::class, $method->refresh()), 'the key kept, «any» for the customer\'s pick');
        self::assertStringContainsString('ارز: انتخاب مشتری در صفحه ریختم', $this->said()[0]);
    }

    public function testBluepalsMerchantAndKeyAreSavedAndAKeyTheApiRefusesIsSaidUnderItsField(): void
    {
        $this->walkTo('bluepal', 3);
        self::assertStringContainsString('مرچنت آیدی', $this->said()[0]);
        $this->send($this->message('MRC-۵۸۲۱۹', chat: self::OWNER));
        self::assertStringContainsString('کلید API', $this->said()[0]);
        self::assertStringContainsString('پیامتان بعد از خواندن پاک می‌شود', $this->said()[0]);
        $this->send($this->message('sk live with spaces', chat: self::OWNER));
        $card = $this->said()[0];
        self::assertStringContainsString("مرچنت آیدی (merchant_id): \u{2068}" . FakeBluepal::MERCHANT . "\u{2069}", $card, 'typed in Persian digits, kept in Latin');
        self::assertStringContainsString('ℹ️ اگر در داشبورد بلو پال «دامنه‌های مجاز» را پر کرده‌اید', $card);

        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        self::assertSame(0, PaymentMethod::query()->where('driver', 'bluepal')->count());
        self::assertStringContainsString('فاصله و حروف فارسی ندارد', (string) $this->popup(), 'the API\'s words');
        self::assertStringContainsString('⚠️ کلید API را همان‌طور که داشبورد بلو پال نشان می‌دهد کپی کنید', $this->said()[0], 'under its field, the draft kept');

        $this->send($this->tap(Nav::to('pmf', 'e', 4), chat: self::OWNER));
        $this->send($this->message(FakeBluepal::KEY, chat: self::OWNER));
        self::assertStringNotContainsString('⚠️', $this->said()[0], 'its refusal gone with it');
        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        $method = PaymentMethod::query()->where('driver', 'bluepal')->sole();
        self::assertSame(['merchant_id' => FakeBluepal::MERCHANT, 'api_key' => FakeBluepal::KEY], $this->settings(BluepalDriver::class, $method));
        self::assertStringNotContainsString(FakeBluepal::KEY, implode("\n", $this->said()));
    }

    public function testRialitsKeyIsSavedAndTheAddressToGiveRialitIsCopyableAmongItsNotes(): void
    {
        $this->walkTo('rialit', 3);
        $this->send($this->message(FakeRialit::KEY, chat: self::OWNER));
        $card = $this->said()[0];
        self::assertStringContainsString('<code>https://shop.example/payments/webhooks/rialit</code>', $card, 'the address in its note, copied at a tap');
        self::assertStringContainsString('کلید API: •••• (تازه نوشته شده)', $card);
        self::assertStringNotContainsString(FakeRialit::KEY, $card);

        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        $method = PaymentMethod::query()->where('driver', 'rialit')->sole();
        self::assertSame(['api_key' => FakeRialit::KEY], $this->settings(RialitDriver::class, $method));

        $this->send($this->tap(Nav::to('pmf', 'edit', $method->id), chat: self::OWNER));
        self::assertStringContainsString('<code>https://shop.example/payments/webhooks/rialit</code>', $this->said()[0], 'on an edit too');
    }

    /** A new method of `$driver` walked to its field `$index`: the label, the least and the most amounts passed by. */
    private function walkTo(string $driver, int $index): void
    {
        $this->send($this->tap(Nav::to('pmf', 'new', $driver), chat: self::OWNER));
        for ($field = 0; $field < $index; $field++) {
            $this->send($this->tap(Nav::to('pmf', 's', $field), chat: self::OWNER));
        }
    }

    /**
     * The method's settings as its driver reads them, its secrets readable.
     *
     * @param class-string<GatewayDriver> $driver
     * @return array<string, mixed>
     */
    private function settings(string $driver, PaymentMethod $method): array
    {
        return $this->service(MethodSettings::class)->read($this->service($driver), (array) $method->config);
    }

    /** The call that drew the window last. */
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
