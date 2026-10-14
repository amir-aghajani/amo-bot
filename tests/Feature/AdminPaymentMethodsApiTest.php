<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Drivers\Descriptor;
use App\Core\Drivers\Registry;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\Drivers\Outside\OutsideGateway;
use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\BuiltinMethodException;
use App\Modules\Payments\Exceptions\MethodInUseException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentCards;
use App\Modules\Payments\Services\PaymentMethods;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Tests\HttpTestCase;

/**
 * The payment methods screen: the built-in wallet row, card methods added from the driver picker with their cards (each
 * number normalised and checked, a card paid to kept), the amounts a method is offered for, ordering, the strict switch,
 * deletion only without payments, and what the checkout offers.
 */
final class AdminPaymentMethodsApiTest extends HttpTestCase
{
    private const CARD = '6037997700001119';
    private const CARD_2 = '5892101012345670';
    private const CARD_3 = '6104337812345674';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testTheShopStartsWithTheWalletAndOffersTheDriversToAddFrom(): void
    {
        $response = $this->get('/api/admin/payment-methods');

        self::assertSame(200, $response->getStatusCode());
        $methods = $this->decode($response)['methods'];
        self::assertCount(1, $methods);
        self::assertSame([WalletGateway::key(), WalletGateway::label(), true, true, 'instant', null, null], [$methods[0]['driver'], $methods[0]['label'], $methods[0]['builtin'], $methods[0]['enabled'], $methods[0]['kind'], $methods[0]['min_amount'], $methods[0]['max_amount']]);

        $drivers = array_column($this->decode($this->get('/api/admin/payment-methods/drivers'))['drivers'], null, 'key');
        self::assertSame([WalletGateway::key(), ManualGateway::key()], array_keys($drivers), 'in the order they are registered');
        self::assertSame([WalletGateway::label(), [], ['kind' => 'instant', 'builtin' => true]], [$drivers['wallet']['label'], $drivers['wallet']['fields'], $drivers['wallet']['traits']], 'the wallet: nothing to set');
        self::assertSame(['کارت به کارت', ['kind' => 'manual', 'builtin' => false]], [$drivers['manual']['label'], $drivers['manual']['traits']]);
        self::assertSame(['instructions', 'auto_approve_after'], array_column($drivers['manual']['fields'], 'name'), "a card method's settings are its driver's form; its cards a list of their own");
    }

    public function testAddingACardMethodChecksEveryCardAndNormalisesIt(): void
    {
        $response = $this->postJson('/api/admin/payment-methods', ['driver' => 'manual', 'label' => '', 'cards' => []]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['label' => ['نامی بنویسید که مشتری هنگام پرداخت می‌بیند؛ مثلا «کارت به کارت (ملت)».'], 'cards' => ['دست کم یک کارت وارد کنید.']], $this->decode($response)['errors'], 'the label and the cards at once');

        $response = $this->postJson('/api/admin/payment-methods', ['driver' => 'manual', 'label' => 'کارت', 'cards' => [
            ['number' => '6037-9977-0000-1111', 'holder' => ''],
            ['number' => '', 'holder' => str_repeat('ن', 101), 'bank' => str_repeat('ب', 61), 'daily_cap' => 'زیاد'],
            ['number' => self::CARD, 'holder' => 'AmoBot'],
            ['number' => '6037 9977 0000 1119', 'holder' => 'AmoBot'],
        ]]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'کارت ۱: شماره کارت باید 16 رقم و معتبر باشد.',
            'کارت ۱: نام صاحب کارت را بنویسید.',
            'کارت ۲: شماره کارت را وارد کنید.',
            'کارت ۲: ' . Validation::tooLong('نام صاحب کارت', 100),
            'کارت ۲: ' . Validation::tooLong('نام بانک', 60),
            'کارت ۲: سقف روزانه را به تومان و بدون اعشار بنویسید؛ خالی یعنی بدون سقف.',
            'کارت ۴: این شماره کارت دو بار آمده است.',
        ], $this->decode($response)['errors']['cards'], 'every card refused at once, each by its place — a number that fails its check digit, a number twice');

        $response = $this->unchecked()->postJson('/api/admin/payment-methods', ['driver' => 'manual', 'label' => str_repeat('ک', 61), 'instructions' => str_repeat('ت', 1001), 'enabled' => 'maybe', 'cards' => 'none']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'label' => [Validation::tooLong('نام', 60)],
            'enabled' => [Validation::NOT_A_SWITCH],
            'instructions' => [Validation::tooLong('توضیحات', 1000)],
            'cards' => ['کارت‌های این روش را به صورت فهرست بفرستید.'],
        ], $this->decode($response)['errors'], 'words past their length, a switch that is no switch, cards that are no list');

        $response = $this->postJson('/api/admin/payment-methods', [
            'driver' => 'manual',
            'label' => ' کارت به کارت (ملت) ',
            'instructions' => 'بعد از واریز، عکس رسید را بفرستید.',
            'cards' => [
                ['number' => '۶۰۳۷-۹۹۷۷-۰۰۰۰-۱۱۱۹', 'holder' => ' سارا احمدی ', 'bank' => 'ملت', 'daily_cap' => '۵۰٬۰۰۰٬۰۰۰'],
                ['number' => self::CARD_2, 'holder' => 'AmoBot', 'enabled' => false, 'daily_cap' => ''],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $method = $this->decode($response)['method'];
        self::assertSame(['کارت به کارت (ملت)', 'manual', 'کارت به کارت', true, 0], [$method['label'], $method['driver'], $method['driver_label'], $method['enabled'], $method['config']['auto_approve_after']], 'enabled, reviewed by hand unless a window is set');
        self::assertSame((int) PaymentMethod::query()->max('sort'), $method['sort'], 'last in checkout order');
        self::assertSame(
            [[self::CARD, 'سارا احمدی', 'ملت', true, '50000000.00', '0.00', 0], [self::CARD_2, 'AmoBot', null, false, null, '0.00', 0]],
            array_map(static fn(array $card): array => [$card['number'], $card['holder'], $card['bank'], $card['enabled'], $card['daily_cap'], $card['today'], $card['payments']], $method['cards']),
            'Persian digits and dashes normalised away, in the order sent; a card left without a switch is on, a blank cap none',
        );
        self::assertSame(['instructions' => 'بعد از واریز، عکس رسید را بفرستید.', 'auto_approve_after' => 0], $method['config'], 'the cards are no setting of the method');
        self::assertNull($method['summary'], 'its cards are listed instead');
    }

    public function testTheReviewWindowIsMinutesWithinAWeek(): void
    {
        $card = ['driver' => 'manual', 'label' => 'کارت', 'cards' => [['number' => self::CARD, 'holder' => 'AmoBot']]];

        $response = $this->postJson('/api/admin/payment-methods', $card + ['auto_approve_after' => '۳۰']);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(30, $this->decode($response)['method']['config']['auto_approve_after']);

        $response = $this->postJson('/api/admin/payment-methods', $card + ['auto_approve_after' => '']);
        self::assertSame(0, $this->decode($response)['method']['config']['auto_approve_after'], 'blank: by hand');

        foreach (['-5', 'abc', '20000'] as $bad) {
            $response = $this->postJson('/api/admin/payment-methods', $card + ['auto_approve_after' => $bad]);
            self::assertSame(422, $response->getStatusCode(), $bad);
            self::assertSame(['auto_approve_after'], array_keys($this->decode($response)['errors']), $bad);
        }
    }

    public function testACardMethodKeptBeforeTheReviewWindowExistedReadsAsReviewedByHand(): void
    {
        $method = $this->cardMethod();
        // Its config as an earlier release stored it: the note — no review window yet.
        $method->forceFill(['config' => ['instructions' => '']])->save();

        // Every answer is held to the description, whose card settings are closed and complete: the window is there.
        $listed = array_column($this->decode($this->get('/api/admin/payment-methods'))['methods'], 'config', 'id');
        self::assertSame(['instructions' => '', 'auto_approve_after' => 0], $listed[$method->id]);
        self::assertSame(0, ManualGateway::reviewWindow($method->refresh()), 'what the review task reads: by hand');
    }

    public function testSeveralMethodsAreInCheckoutOrder(): void
    {
        $mellat = $this->addMethod('کارت به کارت (ملت)', self::CARD);
        $melli = $this->addMethod('کارت به کارت (ملی)', self::CARD_2);

        $labels = static fn(array $methods): array => array_column($methods, 'label');
        self::assertSame([WalletGateway::label(), 'کارت به کارت (ملت)', 'کارت به کارت (ملی)'], $labels($this->decode($this->get('/api/admin/payment-methods'))['methods']));

        $response = $this->postJson('/api/admin/payment-methods/reorder', ['ids' => [$melli, $mellat]]);
        self::assertSame(['کارت به کارت (ملی)', 'کارت به کارت (ملت)', WalletGateway::label()], $labels($this->decode($response)['methods']), 'rows left out follow the listed ones');

        $offered = fn(OrderType $type): array => array_map(static fn(PaymentMethod $m): string => $m->label, $this->service(PaymentMethods::class)->forOrder($type, null));
        self::assertSame(['کارت به کارت (ملی)', 'کارت به کارت (ملت)', WalletGateway::label()], $offered(OrderType::Purchase), 'what the bot offers, in that order');
        self::assertSame(['کارت به کارت (ملی)', 'کارت به کارت (ملت)'], $offered(OrderType::WalletTopUp), 'the wallet does not charge itself');
    }

    public function testEditingReplacesTheFormItsCardsInTheirOrderAndTheSwitchIsSeparate(): void
    {
        $id = $this->addMethod('کارت به کارت (ملت)', self::CARD);
        $first = PaymentCard::query()->where('payment_method_id', $id)->value('id');

        $response = $this->putJson("/api/admin/payment-methods/{$id}", ['label' => 'کارت ملت', 'instructions' => '', 'cards' => [
            ['number' => self::CARD_2, 'holder' => 'Reza', 'bank' => 'ملی'],
            ['id' => $first, 'number' => self::CARD, 'holder' => 'Sara', 'daily_cap' => 1_000_000, 'enabled' => false],
        ]]);
        self::assertSame(200, $response->getStatusCode());
        $method = $this->decode($response)['method'];
        self::assertSame('کارت ملت', $method['label']);
        self::assertSame([[self::CARD_2, 'Reza', true], [self::CARD, 'Sara', false]], array_map(static fn(array $card): array => [$card['number'], $card['holder'], $card['enabled']], $method['cards']), 'a new card first, the kept one after it, renamed and switched off');
        self::assertSame($first, $method['cards'][1]['id'], 'the kept card is the same row');
        self::assertSame('1000000.00', $method['cards'][1]['daily_cap']);

        foreach ([[], ['enabled' => 'maybe'], ['enabled' => null]] as $body) {
            $refused = $this->unchecked()->patchJson("/api/admin/payment-methods/{$id}", $body);
            self::assertSame(422, $refused->getStatusCode(), 'a switch is on or off — nothing else, and never read as off');
            self::assertSame(['enabled'], array_keys($this->decode($refused)['errors']));
        }
        self::assertTrue(PaymentMethod::query()->findOrFail($id)->enabled);

        $response = $this->patchJson("/api/admin/payment-methods/{$id}", ['enabled' => false]);
        $method = $this->decode($response)['method'];
        self::assertFalse($method['enabled']);
        self::assertCount(2, $method['cards'], 'the switch leaves the cards alone');
        self::assertSame([WalletGateway::label()], array_map(static fn(PaymentMethod $m): string => $m->label, $this->service(PaymentMethods::class)->forOrder(OrderType::Purchase, null)));

        $response = $this->putJson("/api/admin/payment-methods/{$id}", ['label' => 'کارت ملت', 'cards' => [['number' => '1234', 'holder' => 'AmoBot']]]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['cards'], array_keys($this->decode($response)['errors']));

        $other = $this->cardMethod('کارت دیگر', overrides: ['config' => ['card_number' => self::CARD_3]]);
        $response = $this->putJson("/api/admin/payment-methods/{$id}", ['cards' => [['id' => $other->cards()->value('id'), 'number' => self::CARD_3, 'holder' => 'AmoBot']]]);
        self::assertSame(['کارت ۱: این کارت در این روش نیست؛ صفحه را دوباره باز کنید.'], $this->decode($response)['errors']['cards'] ?? null, "another method's card is never taken over");
    }

    public function testACardPaidToKeepsItsNumberAndIsSwitchedOffNotDeleted(): void
    {
        $method = $this->cardMethod();
        $paid = $method->cards()->firstOrFail();
        $spare = $this->paymentCard($method, ['number' => self::CARD_2]);
        $this->cardPayment($this->topUpOrder($this->customer(), '120000.00'), $method, ['card_id' => $paid->id]);
        $row = static fn(PaymentCard $card, array $changes = []): array => $changes + ['id' => $card->id, 'number' => $card->number, 'holder' => $card->holder];

        $response = $this->putJson("/api/admin/payment-methods/{$method->id}", ['cards' => [$row($paid, ['number' => self::CARD_3])]]);
        self::assertSame([
            'کارت ۱: با این کارت پرداخت شده و شماره‌اش عوض نمی‌شود؛ کارت تازه‌ای اضافه و این یکی را خاموش کنید.',
        ], $this->decode($response)['errors']['cards'], 'the record of where its payment went stands — and the spare, left out, simply goes');

        $response = $this->putJson("/api/admin/payment-methods/{$method->id}", ['cards' => [$row($spare)]]);
        self::assertSame(['با کارت 6037 •••• •••• 1119 · AmoBot پرداخت شده و حذف نمی‌شود؛ به جای حذف، خاموشش کنید.'], $this->decode($response)['errors']['cards'], 'a card paid to is never left out');

        $response = $this->putJson("/api/admin/payment-methods/{$method->id}", ['cards' => [$row($paid, ['enabled' => false, 'holder' => 'Sara'])]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([[$paid->id, false, 'Sara', 1]], array_map(static fn(array $card): array => [$card['id'], $card['enabled'], $card['holder'], $card['payments']], $this->decode($response)['method']['cards']), 'switched off and renamed; the spare nobody paid to is gone');
        self::assertNull(PaymentCard::query()->find($spare->id));
    }

    public function testTheBoundsAreWholeTomanTheMostNotBelowTheLeastAndTheWalletHasThemToo(): void
    {
        $card = ['driver' => 'manual', 'label' => 'کارت', 'cards' => [['number' => self::CARD, 'holder' => 'AmoBot']]];

        $response = $this->postJson('/api/admin/payment-methods', $card + ['min_amount' => '۵۰۰٬۰۰۰', 'max_amount' => 100_000]);
        self::assertSame(['max_amount' => ['بیشترین مبلغ نباید از کمترین مبلغ کمتر باشد.']], $this->decode($response)['errors']);
        $response = $this->postJson('/api/admin/payment-methods', $card + ['min_amount' => '0', 'max_amount' => '12.5']);
        self::assertSame([
            'min_amount' => ['کمترین مبلغ را به تومان و بدون اعشار بنویسید؛ خالی یعنی بدون محدودیت.'],
            'max_amount' => ['بیشترین مبلغ را به تومان و بدون اعشار بنویسید؛ خالی یعنی بدون محدودیت.'],
        ], $this->decode($response)['errors']);

        $response = $this->postJson('/api/admin/payment-methods', $card + ['min_amount' => '۱۰٬۰۰۰', 'max_amount' => 500_000]);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->decode($response)['method']['id'];
        self::assertSame(['10000.00', '500000.00'], [$this->decode($response)['method']['min_amount'], $this->decode($response)['method']['max_amount']]);

        $response = $this->putJson("/api/admin/payment-methods/{$id}", ['label' => 'کارت ملت', 'cards' => [['number' => self::CARD, 'holder' => 'AmoBot', 'id' => PaymentCard::query()->value('id')]]]);
        self::assertSame(['10000.00', '500000.00'], [$this->decode($response)['method']['min_amount'], $this->decode($response)['method']['max_amount']], 'left out: kept');
        $response = $this->putJson("/api/admin/payment-methods/{$id}", ['min_amount' => '', 'max_amount' => null, 'cards' => [['number' => self::CARD, 'holder' => 'AmoBot', 'id' => PaymentCard::query()->value('id')]]]);
        self::assertSame([null, null], [$this->decode($response)['method']['min_amount'], $this->decode($response)['method']['max_amount']], 'blank or null: none');

        $wallet = $this->walletMethod();
        $response = $this->putJson("/api/admin/payment-methods/{$wallet->id}", ['max_amount' => '2000000']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([WalletGateway::label(), null, '2000000.00'], [$this->decode($response)['method']['label'], $this->decode($response)['method']['min_amount'], $this->decode($response)['method']['max_amount']], 'the wallet keeps its label');
    }

    public function testTheWalletCanBeSwitchedOffButNeitherDeletedNorAddedAgain(): void
    {
        $wallet = $this->walletMethod();

        $response = $this->patchJson("/api/admin/payment-methods/{$wallet->id}", ['enabled' => false]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->service(PaymentMethods::class)->forOrder(OrderType::Purchase, null));

        $response = $this->deleteJson("/api/admin/payment-methods/{$wallet->id}");
        self::assertSame(409, $response->getStatusCode());
        self::assertSame((new BuiltinMethodException($wallet))->getMessage(), $this->decode($response)['message']);

        $response = $this->unchecked()->postJson('/api/admin/payment-methods', ['driver' => 'wallet', 'label' => 'کیف پول دوم']);
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('driver', $this->decode($response)['errors']);
    }

    public function testAMethodPaymentsWereMadeWithIsSwitchedOffNotDeleted(): void
    {
        $id = $this->addMethod('کارت به کارت (ملت)', self::CARD);
        $unused = $this->addMethod('کارت به کارت (ملی)', self::CARD_2);
        $method = PaymentMethod::query()->findOrFail($id);
        $this->cardPayment($this->topUpOrder($this->customer(), '120000.00'), $method);

        $response = $this->deleteJson("/api/admin/payment-methods/{$id}");

        self::assertSame(409, $response->getStatusCode());
        self::assertSame((new MethodInUseException($method, 1))->getMessage(), $this->decode($response)['message']);
        self::assertNotNull(PaymentMethod::query()->find($id), 'its payments are read through it');
        self::assertSame(204, $this->deleteJson("/api/admin/payment-methods/{$unused}")->getStatusCode(), 'one nobody paid with goes');
        self::assertSame(0, PaymentCard::query()->where('payment_method_id', $unused)->count(), 'with its cards');
    }

    public function testUnknownDriverAndMethodAreRefused(): void
    {
        self::assertSame(422, $this->unchecked()->postJson('/api/admin/payment-methods', ['driver' => 'paypal', 'label' => 'x'])->getStatusCode());
        self::assertSame(404, $this->patchJson('/api/admin/payment-methods/999', ['enabled' => true])->getStatusCode());
    }

    public function testTheOutsideRowIsNeitherListedNorOfferedNorFoundHere(): void
    {
        $outside = PaymentMethod::query()->where('driver', OutsideGateway::key())->firstOrFail();

        self::assertNotContains($outside->id, array_column($this->decode($this->get('/api/admin/payment-methods'))['methods'], 'id'));
        self::assertNotContains(OutsideGateway::key(), array_column($this->decode($this->get('/api/admin/payment-methods/drivers'))['drivers'], 'key'), 'nor among the drivers to add a method of');
        foreach (OrderType::cases() as $type) {
            self::assertNotContains($outside->id, array_map(static fn(PaymentMethod $method): int => $method->id, $this->service(PaymentMethods::class)->forOrder($type, '100000.00')), "nor offered for {$type->value}");
        }

        self::assertSame(404, $this->putJson("/api/admin/payment-methods/{$outside->id}", ['label' => 'نقدی'])->getStatusCode(), 'never renamed');
        self::assertSame(404, $this->patchJson("/api/admin/payment-methods/{$outside->id}", ['enabled' => false])->getStatusCode(), 'never switched');
        self::assertSame(404, $this->deleteJson("/api/admin/payment-methods/{$outside->id}")->getStatusCode());
        self::assertSame([OutsideGateway::label(), true], [$outside->refresh()->label, $outside->enabled]);
    }

    public function testACardIsListedOnceInItsMethodsForm(): void
    {
        $method = $this->cardMethod();
        $card = $method->cards()->firstOrFail();

        $response = $this->putJson("/api/admin/payment-methods/{$method->id}", ['cards' => [
            ['id' => $card->id, 'number' => $card->number, 'holder' => 'AmoBot'],
            ['id' => $card->id, 'number' => self::CARD_2, 'holder' => 'Sara'],
        ]]);

        self::assertSame(['کارت ۲: این کارت دو بار در فهرست آمده است؛ صفحه را دوباره باز کنید.'], $this->decode($response)['errors']['cards'] ?? null);
        self::assertSame([self::CARD, 'AmoBot'], [$card->refresh()->number, $card->holder]);
    }

    public function testARowOfADriverNoLongerInstalledStillListsWithoutAKindAndIsOnlySwitchedOrDeleted(): void
    {
        $paypal = PaymentMethod::query()->create(['driver' => 'paypal', 'label' => 'PayPal', 'config' => ['merchant' => 'm-1'], 'enabled' => false, 'sort' => 9]);

        $row = end($this->decode($this->get('/api/admin/payment-methods'))['methods']);
        self::assertSame(['paypal', 'paypal', null, false], [$row['driver'], $row['driver_label'], $row['kind'], $row['builtin']], 'no descriptor to name it');
        self::assertSame([null, []], [$row['summary'], $row['config']], 'its settings not shown: no driver says which of them are secrets');

        $edited = $this->putJson("/api/admin/payment-methods/{$paypal->id}", ['label' => 'PayPal 2']);
        self::assertSame(422, $edited->getStatusCode());
        self::assertSame(['driver'], array_keys($this->decode($edited)['errors']), 'its form has no driver to check it');
        self::assertSame(204, $this->deleteJson("/api/admin/payment-methods/{$paypal->id}")->getStatusCode());
    }

    public function testEachCardSaysWhatItTookTodayAndEachPaymentTheCardItWasShown(): void
    {
        $method = $this->cardMethod();
        $first = $method->cards()->firstOrFail();
        $second = $this->paymentCard($method, ['bank' => 'ملی', 'daily_cap' => '1000000']);
        $customer = $this->customer();
        $this->cardPayment($this->topUpOrder($customer, '200000.00'), $method, ['card_id' => $second->id]);
        $this->cardPayment($this->topUpOrder($customer, '300000.00'), $method, ['card_id' => $second->id, 'status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $this->cardPayment($this->topUpOrder($customer, '400000.00'), $method, ['card_id' => $second->id, 'status' => PaymentStatus::Failed]);
        $this->cardPayment($this->topUpOrder($customer, '500000.00'), $method, ['card_id' => $second->id])->forceFill(['created_at' => now()->subDay()])->save();

        $row = array_column($this->decode($this->get('/api/admin/payment-methods'))['methods'], null, 'id')[$method->id];
        self::assertSame(
            [[$first->id, '0.00', null, 0], [$second->id, '500000.00', '1000000.00', 4]],
            array_map(static fn(array $card): array => [$card['id'], $card['today'], $card['daily_cap'], $card['payments']], $row['cards']),
            "today: the open and paid payments made today — not a rejected one, nor yesterday's",
        );

        $payments = array_column($this->decode($this->get('/api/admin/payments'))['payments'], 'summary');
        self::assertSame(array_fill(0, 4, '5892 •••• •••• 5670 · AmoBot · ملی'), $payments, 'the card each payment was shown, with its bank');
        self::assertNull($this->service(GatewayRegistry::class)->summary($method), "the driver's line of a card method: none, its cards are listed");
    }

    public function testAMethodsFormKeepsWhatItsRowKeepsAndNeverShowsASecret(): void
    {
        // A driver of tomorrow, as PaymentMethods meets any: a merchant and its key, and a test key shown in test mode alone.
        $driver = new class implements GatewayDriver {
            public function key(): string
            {
                return 'gateway';
            }

            public function describe(): Descriptor
            {
                return new Descriptor('gateway', 'درگاه', 'درگاه آنلاین', new Form('gateway', [
                    new Text('merchant', 'merchant', '', label: 'کد پذیرنده', max: 36, required: true, spec: new FieldSpec('کد پذیرنده')),
                    new Secret('api_key', 'api_key', '', pattern: '/^\w+$/', mismatch: 'کلید درست نیست.', boundTo: ['merchant' => null], moved: 'با کد پذیرنده تازه، کلید را هم وارد کنید.', spec: new FieldSpec('کلید API')),
                    new Toggle('test_mode', 'test_mode', false, spec: new FieldSpec('حالت تست')),
                    new Text('test_key', 'test_key', '', label: 'کلید تست', max: 36, spec: new FieldSpec('کلید تست', when: ['test_mode' => ['true']])),
                ]), traits: [GatewayDriver::KIND => 'instant', GatewayDriver::BUILTIN => false]);
            }

            public function summary(array $settings): ?string
            {
                return null;
            }

            public function gateway(array $settings): GatewayInterface
            {
                throw new \LogicException('Nothing is paid with it here.');
            }
        };
        $methods = new PaymentMethods(new GatewayRegistry(new Registry([$driver])), $this->service(PaymentCards::class), $this->service(ConnectionInterface::class));
        $form = ['label' => 'درگاه', 'merchant' => 'm-1', 'test_mode' => false];

        $method = $methods->create(['driver' => 'gateway', 'api_key' => 'secret1', 'test_mode' => true, 'test_key' => 'tk-1'] + $form);
        $methods->update($method, $form + ['api_key' => '']);
        self::assertSame(['merchant' => 'm-1', 'api_key' => 'secret1', 'test_mode' => false, 'test_key' => 'tk-1'], $method->refresh()->config, 'a secret left blank keeps the one kept, a field not shown what it holds');
        self::assertEquals(
            (object) ['merchant' => 'm-1', 'api_key' => ['set' => true, 'hint' => '••••••ret1'], 'test_mode' => false, 'test_key' => 'tk-1'],
            $methods->presentOne($method)['config'],
            'the secret shown as kept, never itself',
        );
        self::assertArrayNotHasKey('cards', $methods->presentOne($method), 'cards are a card method\'s alone');

        try {
            $methods->update($method, ['merchant' => 'm-2'] + $form);
            self::fail('a key kept for one merchant never goes with another');
        } catch (ValidationException $e) {
            self::assertSame(['api_key'], array_keys($e->errors()));
        }

        $methods->update($method, $form + ['clear_api_key' => true]);
        self::assertSame('', $method->refresh()->config['api_key']);
    }

    private function addMethod(string $label, string $card): int
    {
        $response = $this->postJson('/api/admin/payment-methods', ['driver' => 'manual', 'label' => $label, 'cards' => [['number' => $card, 'holder' => 'AmoBot']]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->decode($response)['method']['id'];
    }
}
