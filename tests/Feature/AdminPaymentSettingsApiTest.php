<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Services\PaymentSettings;
use Tests\HttpTestCase;

/**
 * «پرداخت کارت به کارت» in the bot settings (`payments`): the card's message protected and one receipt at a time until
 * switched off; card payments in support's private chat only while the bot has a support contact to send customers to.
 */
final class AdminPaymentSettingsApiTest extends HttpTestCase
{
    private const PATH = '/api/admin/bot/settings/payments';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testTheCardIsProtectedAndOneReceiptTakenAtATimeUntilSwitchedOff(): void
    {
        $settings = $this->decode($this->get('/api/admin/bot/settings'))['settings'];
        self::assertSame([true, false, true], [$settings['card_protect'], $settings['card_to_support'], $settings['receipt_single']]);

        $saved = $this->decode($this->putJson(self::PATH, ['card_protect' => false, 'card_to_support' => false, 'receipt_single' => false]))['settings'];

        self::assertSame([false, false, false], [$saved['card_protect'], $saved['card_to_support'], $saved['receipt_single']]);
        $payments = $this->service(PaymentSettings::class);
        self::assertSame([false, false], [$payments->protectsCard(), $payments->oneReceiptAtATime()]);
    }

    public function testSupportsPrivateChatNeedsASupportContact(): void
    {
        $refused = $this->putJson(self::PATH, ['card_protect' => true, 'card_to_support' => true, 'receipt_single' => true]);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['card_to_support' => [PaymentSettings::NO_CONTACT]], $this->decode($refused)['errors']);
        self::assertFalse($this->service(PaymentSettings::class)->sendsToSupport(), 'nothing saved');

        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $saved = $this->putJson(self::PATH, ['card_protect' => true, 'card_to_support' => true, 'receipt_single' => true]);

        self::assertSame(200, $saved->getStatusCode());
        self::assertTrue($this->decode($saved)['settings']['card_to_support']);
    }
}
