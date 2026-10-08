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
use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use App\Modules\Payments\Exceptions\BuiltinMethodException;
use App\Modules\Payments\Exceptions\MethodInUseException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Support\Validation;
use Tests\HttpTestCase;

/**
 * The payment-methods screen: the wallet row the shop starts with, plus rows the admin adds from the driver picker —
 * several cards, each its own method, the card number normalised and checked —, put in checkout order, switched by a
 * strict switch, and deleted only while no payment was made with them. What the checkout offers comes from here: every
 * method switched on, but the wallet for a top-up.
 */
final class AdminPaymentMethodsApiTest extends HttpTestCase
{
    private const CARD = '6037997700001119';
    private const CARD_2 = '5892101012345670';

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
        self::assertSame([WalletGateway::key(), WalletGateway::label(), true, true, 'instant'], [$methods[0]['driver'], $methods[0]['label'], $methods[0]['builtin'], $methods[0]['enabled'], $methods[0]['kind']]);

        $drivers = array_column($this->decode($this->get('/api/admin/payment-methods/drivers'))['drivers'], null, 'key');
        self::assertSame([WalletGateway::key(), ManualGateway::key()], array_keys($drivers), 'in the order they are registered');
        self::assertSame([WalletGateway::label(), [], ['kind' => 'instant', 'builtin' => true]], [$drivers['wallet']['label'], $drivers['wallet']['fields'], $drivers['wallet']['traits']], 'the wallet: nothing to set');
        self::assertSame(['کارت به کارت', ['kind' => 'manual', 'builtin' => false]], [$drivers['manual']['label'], $drivers['manual']['traits']]);
        self::assertSame(['card_number', 'card_holder', 'instructions', 'auto_approve_after'], array_column($drivers['manual']['fields'], 'name'), "a card's settings are its driver's form");
    }

    public function testAddingACardValidatesAndNormalisesIt(): void
    {
        $response = $this->postJson('/api/admin/payment-methods', ['driver' => 'manual', 'label' => '', 'card_number' => '6037-9977-0000-1111', 'card_holder' => '']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['label', 'card_number', 'card_holder'], array_keys($this->decode($response)['errors']), 'every field at once — a card that fails its check digit too');

        $response = $this->unchecked()->postJson('/api/admin/payment-methods', ['driver' => 'manual', 'label' => str_repeat('ک', 61), 'card_holder' => str_repeat('ن', 101), 'instructions' => str_repeat('ت', 1001), 'enabled' => 'maybe']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'label' => [Validation::tooLong('نام', 60)],
            'enabled' => [Validation::NOT_A_SWITCH],
            'card_number' => ['شماره کارت را وارد کنید.'],
            'card_holder' => [Validation::tooLong('نام صاحب کارت', 100)],
            'instructions' => [Validation::tooLong('توضیحات', 1000)],
        ], $this->decode($response)['errors'], 'a missing card, words past their length, a switch that is no switch');

        $response = $this->postJson('/api/admin/payment-methods', [
            'driver' => 'manual',
            'label' => ' کارت به کارت (ملت) ',
            'card_number' => '۶۰۳۷-۹۹۷۷-۰۰۰۰-۱۱۱۹',
            'card_holder' => 'سارا احمدی',
            'instructions' => 'بعد از واریز، عکس رسید را بفرستید.',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $method = $this->decode($response)['method'];
        self::assertSame('کارت به کارت (ملت)', $method['label']);
        self::assertSame('manual', $method['driver']);
        self::assertSame('کارت به کارت', $method['driver_label']);
        self::assertSame(self::CARD, $method['config']['card_number'], 'Persian digits and dashes are normalised away');
        self::assertTrue($method['enabled'], 'new methods start enabled');
        self::assertSame(2, $method['sort'], 'after the wallet');
        self::assertSame(0, $method['config']['auto_approve_after'], 'reviewed by hand unless a window is set');
    }

    public function testTheReviewWindowIsMinutesWithinAWeek(): void
    {
        $card = ['driver' => 'manual', 'label' => 'کارت', 'card_number' => self::CARD, 'card_holder' => 'AmoBot'];

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

    public function testACardKeptBeforeTheReviewWindowExistedReadsAsReviewedByHand(): void
    {
        $method = $this->cardMethod();
        // Its config as an earlier release stored it: the card, its holder, the note — no review window yet.
        $method->forceFill(['config' => ['card_number' => self::CARD, 'card_holder' => 'AmoBot', 'instructions' => '']])->save();

        // Every answer is held to the description, whose card settings are closed and complete: the window is there.
        $listed = array_column($this->decode($this->get('/api/admin/payment-methods'))['methods'], 'config', 'id');
        self::assertSame(['card_number' => self::CARD, 'card_holder' => 'AmoBot', 'instructions' => '', 'auto_approve_after' => 0], $listed[$method->id]);
        self::assertSame(0, ManualGateway::reviewWindow($method->refresh()), 'what the review task reads: by hand');
    }

    public function testSeveralCardsAreSeveralMethodsInCheckoutOrder(): void
    {
        $mellat = $this->addCard('کارت به کارت (ملت)', self::CARD);
        $melli = $this->addCard('کارت به کارت (ملی)', self::CARD_2);

        $labels = static fn(array $methods): array => array_column($methods, 'label');
        self::assertSame([WalletGateway::label(), 'کارت به کارت (ملت)', 'کارت به کارت (ملی)'], $labels($this->decode($this->get('/api/admin/payment-methods'))['methods']));

        $response = $this->postJson('/api/admin/payment-methods/reorder', ['ids' => [$melli, $mellat]]);
        self::assertSame(['کارت به کارت (ملی)', 'کارت به کارت (ملت)', WalletGateway::label()], $labels($this->decode($response)['methods']), 'rows left out follow the listed ones');

        $offered = fn(OrderType $type): array => array_map(static fn(PaymentMethod $m): string => $m->label, $this->service(PaymentMethods::class)->forOrder($type));
        self::assertSame(['کارت به کارت (ملی)', 'کارت به کارت (ملت)', WalletGateway::label()], $offered(OrderType::Purchase), 'what the bot offers, in that order');
        self::assertSame(['کارت به کارت (ملی)', 'کارت به کارت (ملت)'], $offered(OrderType::WalletTopUp), 'the wallet does not charge itself');
    }

    public function testEditingReplacesTheFormAndTheSwitchIsSeparate(): void
    {
        $id = $this->addCard('کارت به کارت (ملت)', self::CARD);

        $response = $this->putJson("/api/admin/payment-methods/{$id}", ['label' => 'کارت ملت', 'card_number' => self::CARD_2, 'card_holder' => 'AmoBot', 'instructions' => '']);
        self::assertSame(200, $response->getStatusCode());
        $method = $this->decode($response)['method'];
        self::assertSame('کارت ملت', $method['label']);
        self::assertSame(self::CARD_2, $method['config']['card_number']);
        self::assertSame('AmoBot', $method['config']['card_holder']);

        foreach ([[], ['enabled' => 'maybe'], ['enabled' => null]] as $body) {
            $refused = $this->unchecked()->patchJson("/api/admin/payment-methods/{$id}", $body);
            self::assertSame(422, $refused->getStatusCode(), 'a switch is on or off — nothing else, and never read as off');
            self::assertSame(['enabled'], array_keys($this->decode($refused)['errors']));
        }
        self::assertTrue(PaymentMethod::query()->findOrFail($id)->enabled);

        $response = $this->patchJson("/api/admin/payment-methods/{$id}", ['enabled' => false]);
        $method = $this->decode($response)['method'];
        self::assertFalse($method['enabled']);
        self::assertSame(self::CARD_2, $method['config']['card_number'], 'the switch leaves the details alone');
        self::assertSame([WalletGateway::label()], array_map(static fn(PaymentMethod $m): string => $m->label, $this->service(PaymentMethods::class)->forOrder(OrderType::Purchase)));

        $response = $this->putJson("/api/admin/payment-methods/{$id}", ['label' => 'کارت ملت', 'card_number' => '1234', 'card_holder' => 'AmoBot']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['card_number'], array_keys($this->decode($response)['errors']));
    }

    public function testTheWalletCanBeSwitchedOffButNeitherDeletedNorAddedAgain(): void
    {
        $wallet = $this->walletMethod();

        $response = $this->patchJson("/api/admin/payment-methods/{$wallet->id}", ['enabled' => false]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->service(PaymentMethods::class)->forOrder(OrderType::Purchase));

        $response = $this->deleteJson("/api/admin/payment-methods/{$wallet->id}");
        self::assertSame(409, $response->getStatusCode());
        self::assertSame((new BuiltinMethodException($wallet))->getMessage(), $this->decode($response)['message']);

        $response = $this->unchecked()->postJson('/api/admin/payment-methods', ['driver' => 'wallet', 'label' => 'کیف پول دوم']);
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('driver', $this->decode($response)['errors']);
    }

    public function testACardPaymentsWereMadeWithIsSwitchedOffNotDeleted(): void
    {
        $id = $this->addCard('کارت به کارت (ملت)', self::CARD);
        $unused = $this->addCard('کارت به کارت (ملی)', self::CARD_2);
        $method = PaymentMethod::query()->findOrFail($id);
        $this->cardPayment($this->topUpOrder($this->customer(), '120000.00'), $method);

        $response = $this->deleteJson("/api/admin/payment-methods/{$id}");

        self::assertSame(409, $response->getStatusCode());
        self::assertSame((new MethodInUseException($method, 1))->getMessage(), $this->decode($response)['message']);
        self::assertNotNull(PaymentMethod::query()->find($id), 'its payments are read through it');
        self::assertSame(204, $this->deleteJson("/api/admin/payment-methods/{$unused}")->getStatusCode(), 'one nobody paid with goes');
    }

    public function testUnknownDriverAndMethodAreRefused(): void
    {
        self::assertSame(422, $this->unchecked()->postJson('/api/admin/payment-methods', ['driver' => 'paypal', 'label' => 'x'])->getStatusCode());
        self::assertSame(404, $this->patchJson('/api/admin/payment-methods/999', ['enabled' => true])->getStatusCode());
    }

    public function testARowOfADriverNoLongerInstalledStillListsWithoutAKindAndIsOnlySwitchedOrDeleted(): void
    {
        $paypal = $this->cardMethod('PayPal', overrides: ['driver' => 'paypal', 'enabled' => false]);

        $row = end($this->decode($this->get('/api/admin/payment-methods'))['methods']);
        self::assertSame(['paypal', 'paypal', null, false], [$row['driver'], $row['driver_label'], $row['kind'], $row['builtin']], 'no descriptor to name it');
        self::assertSame([null, []], [$row['summary'], $row['config']], 'its settings not shown: no driver says which of them are secrets');

        $edited = $this->putJson("/api/admin/payment-methods/{$paypal->id}", ['label' => 'PayPal 2']);
        self::assertSame(422, $edited->getStatusCode());
        self::assertSame(['driver'], array_keys($this->decode($edited)['errors']), 'its form has no driver to check it');
        self::assertSame(204, $this->deleteJson("/api/admin/payment-methods/{$paypal->id}")->getStatusCode());
    }

    public function testTheCardsSummaryIsItsMaskedNumberAndHolderOnItsRowAsOnItsPayments(): void
    {
        $id = $this->addCard('کارت به کارت (ملت)', self::CARD);
        $method = PaymentMethod::query()->findOrFail($id);

        self::assertSame('6037 •••• •••• 1119 · AmoBot', $this->service(GatewayRegistry::class)->summary($method));
        self::assertNull($this->service(GatewayRegistry::class)->summary($this->walletMethod()));
        self::assertSame(
            [WalletGateway::key() => null, ManualGateway::key() => '6037 •••• •••• 1119 · AmoBot'],
            array_column($this->decode($this->get('/api/admin/payment-methods'))['methods'], 'summary', 'driver'),
            'the list says it in the driver’s words, as the payments screen does',
        );
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
        $methods = new PaymentMethods(new GatewayRegistry(new Registry([$driver])));
        $form = ['label' => 'درگاه', 'merchant' => 'm-1', 'test_mode' => false];

        $method = $methods->create(['driver' => 'gateway', 'api_key' => 'secret1', 'test_mode' => true, 'test_key' => 'tk-1'] + $form);
        $methods->update($method, $form + ['api_key' => '']);
        self::assertSame(['merchant' => 'm-1', 'api_key' => 'secret1', 'test_mode' => false, 'test_key' => 'tk-1'], $method->refresh()->config, 'a secret left blank keeps the one kept, a field not shown what it holds');
        self::assertEquals(
            (object) ['merchant' => 'm-1', 'api_key' => ['set' => true, 'hint' => '••••••ret1'], 'test_mode' => false, 'test_key' => 'tk-1'],
            $methods->present($method->loadCount('payments'))['config'],
            'the secret shown as kept, never itself',
        );

        try {
            $methods->update($method, ['merchant' => 'm-2'] + $form);
            self::fail('a key kept for one merchant never goes with another');
        } catch (ValidationException $e) {
            self::assertSame(['api_key'], array_keys($e->errors()));
        }

        $methods->update($method, $form + ['clear_api_key' => true]);
        self::assertSame('', $method->refresh()->config['api_key']);
    }

    private function addCard(string $label, string $card): int
    {
        $response = $this->postJson('/api/admin/payment-methods', ['driver' => 'manual', 'label' => $label, 'card_number' => $card, 'card_holder' => 'AmoBot']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->decode($response)['method']['id'];
    }
}
