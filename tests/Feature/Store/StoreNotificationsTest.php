<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerNotifications;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * What the shop told a customer, on their website: every notice its bot sent them on its own — whether it reached them
 * in Telegram, by email or not at all —, theirs alone, newest first, a page at a time, in its words as plain text and as
 * safe HTML, with what it is about for a link; how many they have not read (the feed's meta, and GET /me); and marking
 * them read — those named (another's number marks nothing, and is no error), or every one.
 */
final class StoreNotificationsTest extends HttpTestCase
{
    private Website $website;

    private User $customer;

    private Subscription $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->customer = $this->customer(['username' => 'ali']);
        $server = $this->sellingServer();
        $this->service = $this->subscription($this->customer, $this->plan([], $server), $server, 'ali_1');
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheirNoticesNewestFirstAPageAtATimeInTheBotsWords(): void
    {
        foreach (range(1, 26) as $ignored) {
            $this->notifier()->serviceEnabled($this->service);
        }
        Carbon::setTestNow(now()->addMinute());
        $this->notifier()->serviceDisabled($this->service, 'استفاده <b>خارج</b> از قوانین & شرایط');
        $someoneElse = $this->customer(['telegram_id' => 7272]);
        $this->notifier()->twoFactorDisabled($someoneElse);

        $first = $this->decode($this->get($this->storeApi($this->website, '/notifications')));

        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 27, 'last_page' => 2, 'unread' => 27], $first['meta'], 'theirs alone');
        $newest = Notification::query()->where('user_id', $this->customer->id)->latest('id')->firstOrFail();
        self::assertSame([
            'id' => $newest->id,
            'type' => 'service_disabled',
            'text' => "⛔️ سرویس ali_1 توسط پشتیبانی غیرفعال شد.\nتوضیح پشتیبانی: استفاده <b>خارج</b> از قوانین & شرایط\n\nبرای پیگیری با پشتیبانی در تماس باشید.",
            'html' => '⛔️ سرویس <code>ali_1</code> توسط پشتیبانی غیرفعال شد.<br>توضیح پشتیبانی: استفاده &lt;b&gt;خارج&lt;/b&gt; از قوانین &amp; شرایط<br><br>برای پیگیری با پشتیبانی در تماس باشید.',
            'subject' => ['type' => 'subscription', 'id' => $this->service->id],
            'read' => false,
            'created_at' => '2026-10-08T12:01:00+00:00',
        ], $first['notifications'][0], "the words the bot wrote — support's note text, as it was typed —, plain and as safe HTML");
        self::assertSame($this->telegram()->sentTo(self::TELEGRAM_ID)[26] ?? null, $newest->text, 'kept as Telegram got them');

        $second = $this->decode($this->get($this->storeApi($this->website, '/notifications?page=2')));
        self::assertSame(['service_enabled', 'service_enabled'], array_column($second['notifications'], 'type'), 'the oldest last');
    }

    public function testANoticeAboutAPaymentLinksItsOrderAndOneAboutTheAccountNothing(): void
    {
        $payment = $this->cardPayment($this->topUpOrder($this->customer, '50000.00'), $this->cardMethod());
        $this->service(PaymentActions::class)->remind($payment);
        $this->notifier()->twoFactorDisabled($this->customer);

        $rows = $this->decode($this->get($this->storeApi($this->website, '/notifications')))['notifications'];

        self::assertSame([['two_factor_disabled', null], ['payment_reminder', ['type' => 'order', 'id' => $payment->order_id]]], array_map(static fn(array $row): array => [$row['type'], $row['subject']], $rows));
    }

    public function testWhatTheyHaveNotReadIsCountedOnTheirAccountAndInTheFeed(): void
    {
        $this->notifier()->serviceEnabled($this->service);
        $this->notifier()->serviceEnabled($this->service);
        $this->notifier()->serviceEnabled($this->service);
        $older = Notification::query()->where('user_id', $this->customer->id)->oldest('id')->firstOrFail();
        $this->service(CustomerNotifications::class)->markRead($this->customer, ['ids' => [$older->id]]);

        self::assertSame(2, $this->decode($this->get($this->storeApi($this->website, '/me')))['unread_notifications']);
        $unread = $this->decode($this->get($this->storeApi($this->website, '/notifications?unread=true')));
        self::assertSame([2, 2], [$unread['meta']['total'], $unread['meta']['unread']]);
        self::assertNotContains($older->id, array_column($unread['notifications'], 'id'), 'only what they have not read');
        self::assertSame(3, $this->decode($this->get($this->storeApi($this->website, '/notifications?unread=false')))['meta']['total'], 'anything but true: every one');
        self::assertSame([true, false, false], array_reverse(array_column($this->decode($this->get($this->storeApi($this->website, '/notifications')))['notifications'], 'read')));
    }

    public function testMarkingReadTouchesTheirOwnAloneAndKeepsWhenOneWasReadFirst(): void
    {
        $this->notifier()->serviceEnabled($this->service);
        $this->notifier()->serviceEnabled($this->service);
        $this->notifier()->serviceEnabled($this->service);
        $someoneElse = $this->customer(['telegram_id' => 7272]);
        $this->notifier()->twoFactorDisabled($someoneElse);
        [$first, $second] = array_map(intval(...), Notification::query()->where('user_id', $this->customer->id)->orderBy('id')->pluck('id')->all());
        $theirs = Notification::query()->where('user_id', $someoneElse->id)->sole();

        $read = $this->postJson($this->storeApi($this->website, '/notifications/read'), ['ids' => [$first, $theirs->id, 999_999]]);

        self::assertSame(['unread' => 2], $this->decode($read), "another's number marks nothing, and is no error");
        self::assertNull($theirs->refresh()->read_at);
        $firstRead = Notification::query()->findOrFail($first);
        self::assertSame('2026-10-08 12:00:00', $firstRead->read_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow(now()->addHour());
        self::assertSame(['unread' => 2], $this->decode($this->postJson($this->storeApi($this->website, '/notifications/read'), ['ids' => []])), 'none named: none marked');
        self::assertSame(['unread' => 0], $this->decode($this->postJson($this->storeApi($this->website, '/notifications/read'), [])), 'none sent: every one');
        self::assertSame('2026-10-08 12:00:00', $firstRead->refresh()->read_at?->format('Y-m-d H:i:s'), 'read before: when it was');
        self::assertSame('2026-10-08 13:00:00', Notification::query()->findOrFail($second)->read_at?->format('Y-m-d H:i:s'));
        self::assertNull($theirs->refresh()->read_at, 'every one of theirs, nobody else\'s');
        self::assertSame(0, $this->decode($this->get($this->storeApi($this->website, '/me')))['unread_notifications']);
    }

    public function testNumbersThatAreNoListOfAtMostAHundredAreRefused(): void
    {
        $this->notifier()->serviceEnabled($this->service);
        $notice = Notification::query()->sole();

        foreach ([['ids' => 'all'], ['ids' => null], ['ids' => ['x']], ['ids' => [$notice->id, -1]], ['ids' => range(1, CustomerNotifications::READ_MAX + 1)]] as $body) {
            $refused = $this->unchecked()->postJson($this->storeApi($this->website, '/notifications/read'), $body);

            self::assertSame(422, $refused->getStatusCode(), (string) json_encode($body));
            self::assertArrayHasKey('ids', $this->decode($refused)['errors']);
        }
        self::assertNull($notice->refresh()->read_at, 'nothing marked — a null is no «every one»');
        self::assertSame(['unread' => 0], $this->decode($this->postJson($this->storeApi($this->website, '/notifications/read'), ['ids' => range(1, CustomerNotifications::READ_MAX - 1) + [99 => $notice->id]])), 'a hundred at most');
    }

    public function testAnAdminsWordingIsWhatTheWebsiteShowsItsLinksToo(): void
    {
        $this->service(BotTexts::class)->save(BotText::ServiceEnabledBySupport, '✅ <b>%client%</b> روشن شد — <a href="https://shop.example/help">راهنما</a> <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>');

        $this->notifier()->serviceEnabled($this->service);

        $row = $this->decode($this->get($this->storeApi($this->website, '/notifications')))['notifications'][0];
        self::assertSame('✅ ali_1 روشن شد — راهنما 👍', $row['text'], 'a premium emoji its plain emoji');
        self::assertSame('✅ <b>ali_1</b> روشن شد — <a href="https://shop.example/help">راهنما</a> 👍', $row['html']);
    }

    public function testEveryKindOfNoticeIsOneTheApiDescriptionNames(): void
    {
        $described = self::apiDescription()->components?->schemas['StoreNoticeType']->enum ?? [];

        self::assertSame(array_column(NoticeType::cases(), 'value'), $described, 'a website reads the kinds from the description');
    }

    private function notifier(): CustomerNotifier
    {
        return $this->service(CustomerNotifier::class);
    }
}
