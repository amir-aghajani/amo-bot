<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Store\Models\Website;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;

/**
 * Every email a website's sign-ins send — a code, or the email that goes in its place — is held to budgets before it
 * goes, whoever asks and from wherever: twenty an hour asked from one address network, ten a day to one address from
 * every shop together, five a day a signed-in customer adding an email asks for — each a 429 —, and sixty an hour from
 * one shop, a hundred and fifty an hour from every shop together — past those, no code goes at all: a 503 in the
 * customer's words, the log told once. An address with no account is told so once a day, every later ask that day
 * counted as the same email. An email that did not go, or was refused on the way, gives back what it counted.
 */
final class EmailBudgetsTest extends HttpTestCase
{
    private Website $website;

    private RecordingMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->mail = $this->mail();
        $this->website = $this->website(['email_signup' => true]);
    }

    public function testOneAddressNetworkAsksForTwentyEmailsAnHour(): void
    {
        for ($i = 1; $i <= SignInThrottle::EMAIL_NETWORK_HOURLY; $i++) {
            self::assertSame(202, $this->forgot("ali{$i}@example.com")->getStatusCode(), "email {$i}");
        }

        $refused = $this->forgot('one-more@example.com');

        self::assertSame(429, $refused->getStatusCode());
        self::assertEqualsWithDelta(3600, (int) $refused->getHeaderLine('Retry-After'), 5, 'until its hour is over');
        self::assertSame(202, $this->forgot('one-more@example.com', '198.51.100.9')->getStatusCode(), 'another network is not held up');
        self::assertCount(SignInThrottle::EMAIL_NETWORK_HOURLY + 1, $this->mail->sent());

        Carbon::setTestNow(now()->addHour());
        self::assertSame(202, $this->forgot('later@example.com')->getStatusCode(), 'an hour on');
    }

    public function testAnAddressGetsTenEmailsADayFromEveryShopTogether(): void
    {
        $agentsSite = CurrentBot::run($this->agentBot(), fn(): Website => $this->website(['email_signup' => true]));
        for ($i = 1; $i <= SignInThrottle::EMAIL_RECIPIENT_DAILY; $i++) {
            $website = $i % 2 === 0 ? $agentsSite : $this->website;
            self::assertSame(202, $this->forgot('victim@example.com', "198.51.100.{$i}", $website)->getStatusCode(), "email {$i}");
            Carbon::setTestNow(now()->addSeconds(SignInThrottle::CODE_EVERY_SECONDS));
        }

        foreach ([$this->website, $agentsSite] as $website) {
            self::assertSame(429, $this->forgot('victim@example.com', '203.0.113.50', $website)->getStatusCode(), 'from any network, and any shop');
        }

        Carbon::setTestNow(now()->addDay());
        self::assertSame(202, $this->forgot('victim@example.com')->getStatusCode(), 'the next day');
    }

    public function testAnAddressWithoutAnAccountIsToldSoOnceADay(): void
    {
        self::assertSame(202, $this->forgot('nobody@example.com')->getStatusCode());
        self::assertCount(1, $this->mail->to('nobody@example.com'));

        Carbon::setTestNow(now()->addSeconds(SignInThrottle::CODE_EVERY_SECONDS));
        self::assertSame(202, $this->forgot('nobody@example.com')->getStatusCode(), 'the same answer');
        self::assertCount(1, $this->mail->to('nobody@example.com'), 'no second email that day');

        $this->webCustomer();
        self::assertSame(202, $this->forgot(self::WEB_EMAIL)->getStatusCode());
        Carbon::setTestNow(now()->addSeconds(SignInThrottle::CODE_EVERY_SECONDS));
        self::assertSame(202, $this->forgot(self::WEB_EMAIL)->getStatusCode());
        self::assertCount(2, $this->mail->to(self::WEB_EMAIL), 'an account gets its code each time');

        Carbon::setTestNow(now()->addDay());
        self::assertSame(202, $this->forgot('nobody@example.com')->getStatusCode());
        self::assertCount(2, $this->mail->to('nobody@example.com'), 'the next day');
    }

    public function testACustomerAsksForFiveCodesADayAddingAnEmail(): void
    {
        $this->bearer($this->customerSession($this->customer()));
        for ($i = 1; $i <= SignInThrottle::EMAIL_LINK_DAILY; $i++) {
            self::assertSame(202, $this->addEmail("mine{$i}@example.com")->getStatusCode(), "code {$i}");
        }

        self::assertSame(429, $this->addEmail('one-more@example.com')->getStatusCode());
        self::assertCount(SignInThrottle::EMAIL_LINK_DAILY, $this->mail->sent());

        $this->bearer($this->customerSession($this->customer(['telegram_id' => 7070])));
        self::assertSame(202, $this->addEmail('one-more@example.com')->getStatusCode(), 'another customer is not held up');
    }

    public function testPastTheShopsBudgetNoCodeGoesOutAndTheLogIsToldOnce(): void
    {
        $logs = $this->logs();
        $throttle = $this->service(SignInThrottle::class);
        for ($i = 1; $i <= SignInThrottle::EMAIL_SHOP_HOURLY; $i++) {
            $throttle->emailing($this->from("198.51.100.{$i}"), "ali{$i}@example.com");
        }

        $refused = $this->forgot('sara@example.com', '203.0.113.9');
        self::assertSame([503, SignInRefusedException::MAIL_OFF], [$refused->getStatusCode(), $this->decode($refused)['message']]);
        self::assertSame(503, $this->forgot('ali@example.com', '203.0.113.10')->getStatusCode());
        self::assertSame([], $this->mail->sent());
        self::assertCount(1, array_filter($logs->getRecords(), static fn(LogRecord $record): bool => str_contains($record->message, 'reached a budget')), 'the log told once');

        $agentsSite = CurrentBot::run($this->agentBot(), fn(): Website => $this->website(['email_signup' => true]));
        self::assertSame(202, $this->forgot('sara@example.com', '203.0.113.11', $agentsSite)->getStatusCode(), "another shop's budget is its own");

        Carbon::setTestNow(now()->addHour());
        self::assertSame(202, $this->forgot('sara@example.com', '203.0.113.9')->getStatusCode(), 'an hour on — and the refused ones spent no turn');
    }

    public function testPastTheInstallationsBudgetNoShopSendsACode(): void
    {
        $throttle = $this->service(SignInThrottle::class);
        $shops = [$this->shopOfAnAgent(9001), $this->shopOfAnAgent(9002), $this->shopOfAnAgent(9003)];
        for ($i = 1; $i <= SignInThrottle::EMAIL_INSTALLATION_HOURLY; $i++) {
            CurrentBot::run($shops[$i % 3], fn() => $throttle->emailing($this->from("198.51.{$i}.1"), "ali{$i}@example.com"));
        }

        $refused = $this->forgot('sara@example.com', '203.0.113.9');

        self::assertSame([503, SignInRefusedException::MAIL_OFF], [$refused->getStatusCode(), $this->decode($refused)['message']], 'the main shop sent none of them: every shop together');
    }

    public function testAnEmailThatDidNotGoGivesItsCountsBack(): void
    {
        $this->mail->failing('550 the mail server is down');
        for ($i = 1; $i <= SignInThrottle::EMAIL_RECIPIENT_DAILY + 1; $i++) {
            self::assertSame(502, $this->forgot('sara@example.com')->getStatusCode(), "try {$i}");
        }

        $this->mail->failing(null);
        self::assertSame(202, $this->forgot('sara@example.com')->getStatusCode(), 'nothing went, nothing spent');
        self::assertCount(1, $this->mail->sent());
    }

    public function testARefusedEmailGivesBackWhatItCountedBeforeIt(): void
    {
        for ($i = 1; $i <= SignInThrottle::EMAIL_NETWORK_HOURLY; $i++) {
            self::assertSame(202, $this->forgot("ali{$i}@example.com")->getStatusCode());
        }
        self::assertSame(429, $this->forgot('sara@example.com')->getStatusCode(), "the network's hour");

        self::assertSame(202, $this->forgot('sara@example.com', '198.51.100.9')->getStatusCode(), "the address's turn was given back: another network may ask at once");
    }

    /**
     * A forgotten password asked for `$address` on `$website` (the fixtures' own) from `$network` — straight into the
     * app: the description has nothing to say of where a request came from.
     */
    private function forgot(string $address, string $network = '203.0.113.7', ?Website $website = null): ResponseInterface
    {
        $path = $this->storeApi($website ?? $this->website, '/auth/password/forgot');
        $request = (new ServerRequestFactory())->createServerRequest('POST', "http://localhost{$path}", ['REMOTE_ADDR' => $network])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream((string) json_encode(['email' => $address])));

        return $this->app()->http()->handle($request);
    }

    private function addEmail(string $address): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => $address, 'password' => 'new-secret-1']);
    }

    /** A request from `$network`, as the throttle reads where one came from. */
    private function from(string $network): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/', ['REMOTE_ADDR' => $network]);
    }

    /** The shop of an agent — the main bot's customer `$telegramId` —: a shop of its own, whatever its bot. */
    private function shopOfAnAgent(int $telegramId): Bot
    {
        return $this->agent(overrides: ['telegram_id' => $telegramId])->ownBot ?? self::fail('The agent has no shop.');
    }
}
