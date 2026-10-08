<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Exceptions\ShopRefusedException;
use App\Modules\Auth\NamedShop;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\CustomerGroup;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * The shop a request of the owner's panel works in is the one it names (Auth\NamedShop): its `X-Shop` header — or, a
 * read, its `shop` query parameter, what a picture's address carries —, the main bot's when it names none. None of it is
 * the session's: one session's requests naming two shops are each worked in their own, whatever came between (the tabs
 * of one browser), and a change lands in the shop it named and nowhere else. A shop that is not there is a 404 —
 * never the main shop in its place —, a name that is no shop's a 422; signed out, a request is told that first.
 */
final class PanelShopTest extends HttpTestCase
{
    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->bot = $this->agentBot();
        $this->customer(['telegram_id' => 501, 'username' => 'main_customer']);
        CurrentBot::run($this->bot, fn() => $this->customer(['telegram_id' => 502, 'username' => 'agents_customer']));
        $this->loginAsAdmin();
    }

    public function testOneSessionsRequestsNamingTwoShopsAreEachWorkedInTheirOwn(): void
    {
        $agents = [NamedShop::HEADER => (string) $this->bot->id];
        $users = fn(array $headers): array => array_column($this->decode($this->get('/api/admin/users', $headers))['users'], 'username');
        $shop = fn(array $headers): int => $this->decode($this->get('/api/admin/auth/me', $headers))['session']['shop']['id'];

        self::assertSame(['agents_customer'], $users($agents));
        self::assertSame(['main_customer', 'agent'], $users([]), 'none named: the main shop');
        self::assertSame(['agents_customer'], $users($agents), 'nothing of the last request stays');
        self::assertSame(['main_customer', 'agent'], $users([NamedShop::HEADER => (string) Bot::MAIN]));
        self::assertSame([$this->bot->id, Bot::MAIN, $this->bot->id], [$shop($agents), $shop([]), $shop($agents)], 'who is signed in, in the shop each names');
    }

    public function testAChangeLandsInTheShopItNamedAndNowhereElse(): void
    {
        $inTheAgents = ['X-Requested-With' => 'XMLHttpRequest', NamedShop::HEADER => (string) $this->bot->id];

        $created = $this->send('POST', '/api/admin/customer-groups', ['name' => 'VIP'], $inTheAgents);
        $switched = $this->send('PUT', '/api/admin/bot/settings/general', ['enabled' => false, 'phone_required' => false], $inTheAgents);

        self::assertSame([201, 200], [$created->getStatusCode(), $switched->getStatusCode()]);
        $group = CustomerGroup::query()->withoutGlobalScope(CurrentBot::SCOPE)->findOrFail($this->decode($created)['group']['id']);
        self::assertSame($this->bot->id, $group->bot_id);
        self::assertSame([], $this->decode($this->get('/api/admin/customer-groups'))['groups'], "the main shop's groups untouched");
        self::assertSame([false, true], [
            $this->decode($this->get('/api/admin/bot/settings', [NamedShop::HEADER => (string) $this->bot->id]))['settings']['enabled'],
            $this->decode($this->get('/api/admin/bot/settings'))['settings']['enabled'],
        ], "the agent's bot switched off, the main bot's as it was");
    }

    public function testAShopThatIsNotThereIsA404NeverTheMainShop(): void
    {
        $missing = $this->get('/api/admin/users', [NamedShop::HEADER => '999']);

        self::assertSame([404, ShopRefusedException::NOT_FOUND, ['shop' => [ShopRefusedException::NOT_FOUND]]], [$missing->getStatusCode(), $this->decode($missing)['message'], $this->decode($missing)['errors']]);
        self::assertSame(404, $this->get('/api/admin/auth/me', [NamedShop::HEADER => '999'])->getStatusCode(), 'the session too: the panel says the address is wrong');
        self::assertSame(404, $this->send('POST', '/api/admin/customer-groups', ['name' => 'VIP'], ['X-Requested-With' => 'XMLHttpRequest', NamedShop::HEADER => '999'])->getStatusCode());
        self::assertSame(0, CustomerGroup::query()->withoutGlobalScope(CurrentBot::SCOPE)->count(), 'written nowhere');
    }

    public function testANameThatIsNoShopsIsRefused(): void
    {
        $refused = fn(ResponseInterface $response): array => [$response->getStatusCode(), $this->decode($response)['errors'] ?? null];

        self::assertSame([422, ['shop' => [NamedShop::MALFORMED]]], $refused($this->unchecked()->get('/api/admin/users', [NamedShop::HEADER => 'agent'])));
        self::assertSame([422, ['shop' => [NamedShop::MALFORMED]]], $refused($this->unchecked()->get('/api/admin/users', [NamedShop::HEADER => '0'])));
        self::assertSame([422, ['shop' => [NamedShop::MALFORMED]]], $refused($this->get('/api/admin/bot/qr-background?shop=' . Bot::MAIN, [NamedShop::HEADER => (string) $this->bot->id])), 'two shops named at once');
        self::assertSame([422, ['shop' => [NamedShop::QUERY_ON_A_CHANGE]]], $refused($this->postJson('/api/admin/customer-groups?shop=' . $this->bot->id, ['name' => 'VIP'])), 'a change names its shop in the header alone');
        self::assertSame(0, CustomerGroup::query()->withoutGlobalScope(CurrentBot::SCOPE)->count());
    }

    public function testSignedOutARequestIsToldThatWhateverShopItNames(): void
    {
        $this->postJson('/api/admin/auth/logout');

        self::assertSame(401, $this->get('/api/admin/users', [NamedShop::HEADER => '999'])->getStatusCode(), 'nothing said of which shops there are');
        self::assertSame(401, $this->unchecked()->get('/api/admin/users', [NamedShop::HEADER => 'agent'])->getStatusCode());
    }

    public function testASignInIsInTheShopItNamesAndOneNamingNoShopOpensNothing(): void
    {
        $this->postJson('/api/admin/auth/logout');
        $signIn = fn(string $shop): ResponseInterface => $this->send('POST', '/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD], ['X-Requested-With' => 'XMLHttpRequest', NamedShop::HEADER => $shop]);

        $nowhere = $signIn('999');
        self::assertSame([404, ['shop' => [ShopRefusedException::NOT_FOUND]]], [$nowhere->getStatusCode(), $this->decode($nowhere)['errors']]);
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode(), 'nothing opened');

        $signedIn = $signIn((string) $this->bot->id);
        self::assertSame(['root', $this->bot->id], [$this->decode($signedIn)['session']['name'], $this->decode($signedIn)['session']['shop']['id']], "back where the tab was: the agent's shop");
        self::assertSame(Bot::MAIN, $this->decode($this->get('/api/admin/auth/me'))['session']['shop']['id'], 'the session itself in none');
    }

    public function testAPicturesAddressNamesItsShopInItsQuery(): void
    {
        $payment = CurrentBot::run($this->bot, function () {
            $customer = $this->customer(['telegram_id' => 503]);

            return $this->receipt($this->cardPayment($this->topUpOrder($customer, '50000.00'), $this->cardMethod()));
        });
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\0", 32);
        $this->telegram()->reply(['file_id' => 'receipt-1', 'file_path' => 'photos/file_1.jpg']);
        $this->telegram()->raw(new Response(200, ['Content-Type' => 'image/jpeg'], $jpeg));

        self::assertSame(404, $this->get("/api/admin/payments/{$payment->id}/receipt")->getStatusCode(), "the main shop's has no such payment");
        $receipt = $this->get("/api/admin/payments/{$payment->id}/receipt?shop={$this->bot->id}");

        self::assertSame([200, $jpeg], [$receipt->getStatusCode(), (string) $receipt->getBody()]);
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0), "fetched by the agent's bot: worked in their shop");
    }
}
