<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Captcha\Verifier;
use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\ReviewsFullException;
use App\Modules\Reviews\Exceptions\SignInToReviewException;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\Store\Exceptions\ReviewsOffException;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Enums\UserStatus;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\HttpTestCase;
use Tests\Support\AltchaWidget;
use Tests\Support\FakeTurnstile;

/**
 * The customers' reviews on the shop's website, while it shows them (its switch, off at first: a 404 both ways): the
 * approved ones anyone reads — the newest first, with how many there are and their average —, the shop's own and no
 * other's; and a review anyone writes — a guest only while the website asks a captcha, or the customer whose bearer
 * token comes with it (theirs then; a token that opens nothing a 401, never a guest's review) —, every refusal of its
 * fields at once, behind the website's captcha for the review's action (Turnstile's and ALTCHA's alike), held to what an
 * address network (an IPv6 one by its /48), a customer and every guest together may write (a refused captcha counted
 * against its network, one that could not be judged not), and to what the shop keeps waiting on support; kept waiting
 * on support, the report group told — after everything else in its queue.
 */
final class StoreReviewsTest extends HttpTestCase
{
    private const SECRET = '0x4AAAAAAA-turnstile-secret';

    /** A review that passes every rule of its fields. */
    private const REVIEW = ['name' => 'Nima', 'rating' => 5, 'body' => 'سرویس پایدار و سریع است.'];

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->website = $this->website(['reviews_enabled' => true]);
    }

    public function testTheWebsiteShowsTheApprovedOnesTheNewestFirstWithWhatTheyAddUpTo(): void
    {
        self::assertSame(['count' => 0, 'average' => null], $this->list()['meta']['summary'], 'none yet');

        $ali = $this->customer(['username' => 'ali']);
        $first = $this->review($ali, 'Ali R.', 5, 'سرعت عالی و پشتیبانی خوب.', ['status' => ReviewStatus::Approved, 'context' => 'ایرانسل · اندروید · Happ', 'avatar' => 'boy_1']);
        Carbon::setTestNow('2026-10-08 13:00:00');
        $this->review(null, 'منتظر', 1, 'هنوز بررسی نشده است.');
        $this->review(null, 'ردشده', 1, 'این نظر رد شده است و نباید دیده شود.', ['status' => ReviewStatus::Rejected]);
        $second = $this->review(null, 'Sara', 4, "خوب است\nولی گاهی کند می‌شود.", ['status' => ReviewStatus::Approved]);
        $third = $this->review(null, 'Nima', 4, 'قیمت مناسب و اتصال پایدار.', ['status' => ReviewStatus::Approved]);
        // An agent's shop's approved review is its website's, never this one's.
        $agentShop = $this->agentBot();
        $theirs = CurrentBot::run($agentShop, fn(): Review => $this->review(null, 'Other', 5, 'نظر فروشگاه دیگر است.', ['status' => ReviewStatus::Approved]));

        $list = $this->list();
        self::assertSame([$third->id, $second->id, $first->id], array_column($list['reviews'], 'id'), 'the approved ones, the newest first');
        self::assertSame(['id' => $first->id, 'name' => 'Ali R.', 'rating' => 5, 'body' => 'سرعت عالی و پشتیبانی خوب.', 'context' => 'ایرانسل · اندروید · Happ', 'avatar' => 'boy_1', 'created_at' => '2026-10-08T12:00:00+00:00'], $list['reviews'][2], "the writer's own words and choices, nothing of who wrote it nor of support's");
        self::assertSame("خوب است\nولی گاهی کند می‌شود.", $list['reviews'][1]['body'], 'its line breaks as written');
        self::assertSame([1, 25, 3, 1], [$list['meta']['page'], $list['meta']['per_page'], $list['meta']['total'], $list['meta']['last_page']]);
        self::assertSame(['count' => 3, 'average' => 4.33], $list['meta']['summary']);

        $agentWebsite = CurrentBot::run($agentShop, fn(): Website => $this->website(['reviews_enabled' => true]));
        $theirList = $this->decode($this->get($this->storeApi($agentWebsite, '/reviews')));
        self::assertSame([$theirs->id], array_column($theirList['reviews'], 'id'), "an agent's website shows its own shop's");
        self::assertSame(['count' => 1, 'average' => 5], $theirList['meta']['summary'], 'a whole average a whole number');
    }

    public function testAWebsiteThatShowsNoReviewsAnswersThemWithA404(): void
    {
        $this->review(null, 'Sara', 4, 'اتصال پایدار است و قیمت مناسب.', ['status' => ReviewStatus::Approved]);
        $this->website->forceFill(['reviews_enabled' => false])->save();
        $this->asks('turnstile');

        $read = $this->get($this->storeApi($this->website, '/reviews'));
        self::assertSame([404, ReviewsOffException::MESSAGE], [$read->getStatusCode(), $this->decode($read)['message']]);
        $written = $this->write(self::vouched(self::REVIEW));
        self::assertSame([404, ReviewsOffException::MESSAGE], [$written->getStatusCode(), $this->decode($written)['message']]);
        $this->bearer($this->customerSession($this->customer()));
        self::assertSame(404, $this->write(self::REVIEW)->getStatusCode(), "a signed-in customer's too");
        self::assertSame(1, Review::query()->count(), 'nothing written');
        self::assertSame([], $this->turnstile()->checks, 'nothing judged');
    }

    /** Without a captcha nothing stands between a bot and the shop's queue: a guest is asked to sign in, a customer writes. */
    public function testAGuestsReviewIsTakenOnlyWhileTheWebsiteAsksACaptcha(): void
    {
        $guest = $this->write(['name' => 'A', 'rating' => 6, 'body' => 'کوتاه']);
        self::assertSame([403, SignInToReviewException::MESSAGE], [$guest->getStatusCode(), $this->decode($guest)['message']], 'asked to sign in first, whatever the fields');

        $this->bearer($this->customerSession($this->customer()));
        self::assertSame(201, $this->write(self::REVIEW)->getStatusCode(), "a signed-in customer's goes through");

        $this->bearer(null);
        $this->asks('altcha');
        self::assertSame(201, $this->write(self::REVIEW + ['captcha' => $this->solved(Reviews::CAPTCHA_ACTION)])->getStatusCode(), 'behind the captcha, a guest writes');
        self::assertSame(2, Review::query()->count());
    }

    /**
     * Every guest together writes so many an hour, wherever they write from; a token the captcha refused costs that
     * count nothing — a bot's guesses would spend it for every guest — and a signed-in customer is not counted in it.
     */
    public function testEveryGuestTogetherWritesSoManyAnHour(): void
    {
        $this->asks('turnstile');
        for ($written = 0; $written < Reviews::GUEST_REVIEWS - 1; $written++) {
            self::assertSame(201, $this->from("2001:db8:{$written}::1", self::vouched(self::REVIEW))->getStatusCode(), "a guest's review {$written}, each network its own");
        }
        for ($guess = 0; $guess < 3; $guess++) {
            self::assertSame(422, $this->from("198.51.100.{$guess}", self::REVIEW + ['captcha' => 'a-guess'])->getStatusCode());
        }
        self::assertSame(201, $this->from('198.51.100.9', self::vouched(self::REVIEW))->getStatusCode(), 'the guests\' last: the refused ones cost none');

        $waits = $this->from('198.51.100.10', self::vouched(self::REVIEW));
        self::assertSame([429, (string) Reviews::GUEST_WINDOW], [$waits->getStatusCode(), $waits->getHeaderLine('Retry-After')], 'every guest together');
        $this->bearer($this->customerSession($this->customer()));
        self::assertSame(201, $this->write(self::vouched(self::REVIEW))->getStatusCode(), 'a signed-in customer is no guest');
        self::assertSame(Reviews::GUEST_REVIEWS + 1, Review::query()->count());
    }

    /** One host is often handed a whole /48: its addresses are one network's, each /64 of it no network of its own. */
    public function testAnIpv6NetworkIsCountedByItsSlash48(): void
    {
        for ($written = 0; $written < Reviews::NETWORK_REVIEWS; $written++) {
            $customer = $this->customer(['telegram_id' => 7100 + $written]);
            self::assertSame(201, $this->from("2001:db8:aa:{$written}::1", self::REVIEW, $this->customerSession($customer))->getStatusCode(), "another /64 of the /48, review {$written}");
        }

        $next = $this->customerSession($this->customer(['telegram_id' => 7199]));
        self::assertSame(429, $this->from('2001:db8:aa:ffff::1', self::REVIEW, $next)->getStatusCode(), 'the /48 wrote what it may');
        self::assertSame(201, $this->from('2001:db8:ab::1', self::REVIEW, $next)->getStatusCode(), 'another /48 is another network');
    }

    public function testAGuestWritesOneWaitingOnSupportAndTheReportGroupHearsOfItLast(): void
    {
        $this->reportGroup();
        $this->asks('turnstile');

        $response = $this->write(self::vouched(['name' => "  Ali \n R.  ", 'rating' => '۴', 'body' => "اتصال سریع است.\r\nپشتیبانی هم خوب جواب می‌دهد.", 'context' => ' ایرانسل ·  اندروید · Happ ', 'avatar' => 'girl_2']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $answer = $this->decode($response)['review'];
        self::assertSame('pending', $answer['status']);
        $review = Review::query()->sole();
        self::assertSame(
            [$answer['id'], null, 'Ali R.', 4, "اتصال سریع است.\nپشتیبانی هم خوب جواب می‌دهد.", 'ایرانسل · اندروید · Happ', 'girl_2', ReviewStatus::Pending, null, null],
            [$review->id, $review->user_id, $review->name, $review->rating, $review->body, $review->context, $review->avatar, $review->status, $review->reviewer, $review->decided_at],
            "a guest's — a name and a context one line each, the words' line breaks kept, Persian digits read",
        );
        self::assertSame([], $this->list()['reviews'], 'not shown until support approves it');
        self::assertSame([], $this->telegram()->calls(), 'nobody is told in Telegram');

        $report = ReportMessage::query()->sole();
        self::assertSame([Topic::Reviews, 2, null], [$report->topic, $report->priority, $report->keyboard], "the reviews' topic, after everything else in the queue, no buttons");
        self::assertSame(
            "⭐️ <b>نظر جدید</b> · #{$review->id}\n✍️ Ali R.\n★★★★☆ (۴ از ۵)\n📱 ایرانسل · اندروید · Happ\n👤 مهمان، بدون ورود به حساب\n📨 از وب‌سایت\n\n"
            . "اتصال سریع است.\nپشتیبانی هم خوب جواب می‌دهد.\n\n⏳ در انتظار بررسی؛ تا در صفحه «نظرات» پنل تایید نشود، در وب‌سایت دیده نمی‌شود.",
            $report->text,
        );

        $this->botSettings('reports', ['report_reviews' => false] + array_fill_keys(array_map(static fn(Topic $topic): string => 'report_' . $topic->value, Topic::cases()), true));
        self::assertSame(201, $this->write(self::vouched(['name' => 'Sara', 'rating' => 5, 'body' => 'همه چیز عالی است، ممنون.']))->getStatusCode());
        self::assertSame(1, ReportMessage::query()->count(), 'a topic switched off hears nothing');
    }

    public function testEveryRefusalOfItsFieldsAtOnce(): void
    {
        $this->asks('turnstile');
        $refused = $this->write(['name' => 'A', 'rating' => 6, 'body' => 'کوتاه', 'context' => str_repeat('ک', Reviews::CONTEXT_MAX + 1), 'avatar' => 'https://evil.example/a.png']);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame([
            'name' => ['نام حداقل 2 کاراکتر است.'],
            'rating' => ['امتیاز باید عددی بین 1 تا 5 باشد.'],
            'body' => ['متن نظر حداقل 10 کاراکتر است.'],
            'context' => ['مشخصات حداکثر 100 کاراکتر است.'],
            'avatar' => ['آواتار باید کلید یکی از آواتارهای خود وب‌سایت باشد: 1 تا 32 حرف کوچک لاتین، عدد، - یا _.'],
        ], $this->decode($refused)['errors']);

        $long = $this->decode($this->write(['name' => str_repeat('ن', Reviews::NAME_MAX + 1), 'rating' => 0, 'body' => str_repeat('م', Reviews::BODY_MAX + 1), 'avatar' => 'Boy_1']))['errors'];
        self::assertSame(['نام حداکثر 64 کاراکتر است.', 'امتیاز باید عددی بین 1 تا 5 باشد.', 'متن نظر حداکثر 600 کاراکتر است.'], [$long['name'][0], $long['rating'][0], $long['body'][0]]);
        self::assertArrayHasKey('avatar', $long, 'an avatar key is lower case');

        $blank = $this->decode($this->write(['name' => " \n ", 'rating' => 3, 'body' => '   ']))['errors'];
        self::assertSame([['نام خود را بنویسید.'], ['متن نظر را بنویسید.']], [$blank['name'], $blank['body']]);

        self::assertSame(0, Review::query()->count());
        $whole = $this->write(self::vouched(['name' => str_repeat('ن', Reviews::NAME_MAX), 'rating' => 1, 'body' => str_repeat('م', Reviews::BODY_MAX), 'context' => str_repeat('ک', Reviews::CONTEXT_MAX), 'avatar' => 'a_b-1']));
        self::assertSame(201, $whole->getStatusCode(), 'every limit taken whole');
    }

    public function testASignedInCustomersReviewIsTheirsAndATokenThatOpensNothingIsNoGuest(): void
    {
        $this->reportGroup();
        $ali = $this->customer(['username' => 'ali', 'first_name' => 'Ali']);

        $this->bearer($this->customerSession($ali));
        self::assertSame(201, $this->write(self::REVIEW)->getStatusCode());
        self::assertSame($ali->id, Review::query()->sole()->user_id, 'theirs');
        self::assertStringContainsString('👤 <a href="tg://user?id=' . self::TELEGRAM_ID . '">Ali</a> · @ali · <code>' . self::TELEGRAM_ID . '</code>', ReportMessage::query()->sole()->text, 'the report names the customer');

        $this->bearer(str_repeat('a', 64));
        $signedOut = $this->write(self::REVIEW);
        self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT, 'Bearer'], [$signedOut->getStatusCode(), $this->decode($signedOut)['message'], $signedOut->getHeaderLine('WWW-Authenticate')]);

        $this->bearer($this->customerSession($ali));
        $ali->forceFill(['status' => UserStatus::Banned])->save();
        $banned = $this->write(self::REVIEW);
        self::assertSame([403, SignInRefusedException::BANNED], [$banned->getStatusCode(), $this->decode($banned)['message']]);
        self::assertSame(1, Review::query()->count(), 'never a guest\'s review in its place');
    }

    public function testTurnstileGuardsItForTheReviewsActionARefusedTokenCountedAndOneUnjudgedNot(): void
    {
        $this->asks('turnstile');

        self::assertSame([422, ['captcha' => [Verifier::REFUSED]]], $this->refused($this->write(self::REVIEW)), 'none');
        self::assertSame([422, ['captcha' => [Verifier::REFUSED]]], $this->refused($this->write(self::REVIEW + ['captcha' => FakeTurnstile::passed('sign_in')])), 'solved for another form');
        $this->turnstile()->down();
        $unjudged = $this->write(self::REVIEW + ['captcha' => FakeTurnstile::passed(Reviews::CAPTCHA_ACTION)]);
        self::assertSame([503, CaptchaUnavailableException::MESSAGE], [$unjudged->getStatusCode(), $this->decode($unjudged)['message']], 'Cloudflare out of reach');
        $this->turnstile()->down(false);

        $token = FakeTurnstile::passed(Reviews::CAPTCHA_ACTION);
        self::assertSame(201, $this->write(self::REVIEW + ['captcha' => $token])->getStatusCode());
        self::assertSame(['secret' => self::SECRET, 'response' => $token], $this->turnstile()->checks[2], "the website's secret and the widget's token");

        // The two refused tokens were two of the network's reviews; the one Cloudflare could not judge was none.
        for ($written = 3; $written < Reviews::NETWORK_REVIEWS; $written++) {
            self::assertSame(201, $this->write(self::REVIEW + ['captcha' => FakeTurnstile::passed(Reviews::CAPTCHA_ACTION)])->getStatusCode(), "review {$written}");
        }
        self::assertSame(Reviews::NETWORK_REVIEWS - 2, Review::query()->count());
        $asked = count($this->turnstile()->checks);

        self::assertSame(429, $this->write(self::REVIEW + ['captcha' => FakeTurnstile::passed(Reviews::CAPTCHA_ACTION)])->getStatusCode(), 'the network wrote what it may');
        self::assertCount($asked, $this->turnstile()->checks, 'Cloudflare is not asked for one refused for its wait');
    }

    public function testAltchaGuardsItWithAChallengeOfTheShopsOwnForTheReviewsAction(): void
    {
        $this->asks('altcha');

        self::assertSame([422, ['captcha' => [Verifier::REFUSED]]], $this->refused($this->write(self::REVIEW)), 'none');
        self::assertSame(422, $this->write(self::REVIEW + ['captcha' => $this->solved('sign_in')])->getStatusCode(), 'a challenge asked for another form');

        $solution = $this->solved(Reviews::CAPTCHA_ACTION);
        self::assertSame(201, $this->write(self::REVIEW + ['captcha' => $solution])->getStatusCode());
        self::assertSame(422, $this->write(self::REVIEW + ['captcha' => $solution])->getStatusCode(), 'a solution passes once');
        self::assertSame([], $this->turnstile()->checks, 'no third party asked');
    }

    public function testAnAddressNetworkAndACustomerWriteSoManyInTheirWindows(): void
    {
        $this->asks('turnstile');
        for ($written = 0; $written < Reviews::NETWORK_REVIEWS; $written++) {
            self::assertSame(201, $this->write(self::vouched(self::REVIEW))->getStatusCode(), "review {$written}");
        }
        $waits = $this->write(self::vouched(self::REVIEW));
        self::assertSame([429, 'نظر زیادی ثبت شده است؛ ۶۰ دقیقه دیگر دوباره امتحان کنید.', '3600'], [$waits->getStatusCode(), $this->decode($waits)['message'], $waits->getHeaderLine('Retry-After')]);
        self::assertSame(Reviews::NETWORK_REVIEWS, Review::query()->count(), 'nothing kept');

        Carbon::setTestNow(now()->addSeconds(Reviews::NETWORK_WINDOW));
        $sara = $this->customer(['telegram_id' => 7001]);
        $this->bearer($this->customerSession($sara));
        for ($written = 0; $written < Reviews::CUSTOMER_REVIEWS; $written++) {
            self::assertSame(201, $this->write(self::vouched(self::REVIEW))->getStatusCode(), "her review {$written}");
        }
        $theirs = $this->write(self::vouched(self::REVIEW));
        self::assertSame([429, (string) Reviews::CUSTOMER_WINDOW], [$theirs->getStatusCode(), $theirs->getHeaderLine('Retry-After')], "a customer's day");
        $this->bearer(null);
        self::assertSame(201, $this->write(self::vouched(self::REVIEW))->getStatusCode(), 'a guest of the same network still writes');
    }

    public function testTheShopKeepsSoManyWaitingOnSupportThenTakesNoneUntilItDecidesSome(): void
    {
        foreach (range(1, Reviews::PENDING_MAX) as $n) {
            $this->review(null, "نفر {$n}");
        }
        $this->review(null, 'تاییدشده', overrides: ['status' => ReviewStatus::Approved]);
        $this->asks('turnstile');

        $full = $this->write(self::vouched(self::REVIEW));
        self::assertSame([503, ReviewsFullException::MESSAGE], [$full->getStatusCode(), $this->decode($full)['message']]);

        Review::query()->where('status', ReviewStatus::Pending->value)->oldest('id')->firstOrFail()->forceFill(['status' => ReviewStatus::Rejected])->save();
        self::assertSame(201, $this->write(self::vouched(self::REVIEW))->getStatusCode(), 'one decided makes room');
    }

    /** @return array<string, mixed> The website's approved reviews, as anyone reads them */
    private function list(): array
    {
        return $this->decode($this->get($this->storeApi($this->website, '/reviews')));
    }

    /** @param array<string, mixed> $body */
    private function write(array $body): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/reviews'), $body);
    }

    /**
     * A review written from `$address` — signed in with `$token` when one is given —, straight into the app: the
     * description has nothing to say of where a request came from.
     *
     * @param array<string, mixed> $body
     */
    private function from(string $address, array $body, ?string $token = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . $this->storeApi($this->website, '/reviews'), ['REMOTE_ADDR' => $address])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream((string) json_encode($body)));

        return $this->app()->http()->handle($token === null ? $request : $request->withHeader('Authorization', "Bearer {$token}"));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> The review with a token Turnstile passes for the review's action, as its widget hands it
     */
    private static function vouched(array $body): array
    {
        return $body + ['captcha' => FakeTurnstile::passed(Reviews::CAPTCHA_ACTION)];
    }

    /** The website asks the captcha of `$driver`: Turnstile under its keys, ALTCHA under none. */
    private function asks(string $driver): void
    {
        $this->turnstile();
        $this->website->forceFill(['captcha_driver' => $driver, 'captcha_config' => $driver === 'turnstile' ? ['site_key' => '0x4AAAAAAA-site-key', 'secret_key' => self::SECRET] : null])->save();
    }

    /** What ALTCHA's widget hands a form once it solved a challenge the shop gave it for `$action`. */
    private function solved(string $action): string
    {
        return AltchaWidget::solve($this->decode($this->get($this->storeApi($this->website, '/captcha/challenge?action=' . $action))));
    }

    /** @return array{int, mixed} The status, and what it said under its fields */
    private function refused(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['errors'] ?? null];
    }
}
