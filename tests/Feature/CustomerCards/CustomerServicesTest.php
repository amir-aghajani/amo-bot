<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Customers\CustomerCards;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «🛍 سرویس‌ها» on a customer's card in the report group: their services newest first, eight a page, each by its state's
 * icon; a service's view with what its state allows — read from its panel again, days and traffic added (a preset asked
 * again first, or an amount typed in reply to the bot's question — either given once, however often it is pressed or
 * answered), switched off (asked first) and on, deleted (asked first) —, every change under the presser's name, the
 * customer told. A service of anyone else is not found; the admin's own account and an agent's are only read; only the
 * report group's bot admins press.
 */
final class CustomerServicesTest extends BotTestCase
{
    /** The shop's bot, as a group addresses its commands. */
    private const BOT = 'amo_shop_bot';

    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    /** The customer's Telegram chat, where the bot tells them. */
    private const ALI = 7070;

    /** @var array<string, int> */
    private array $threads;

    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->config(['telegram.username' => self::BOT]);
        $this->threads = $this->reportGroup();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->ali = $this->customer(['telegram_id' => self::ALI, 'username' => 'ali']);
        $this->server = $this->sellingServer();
        $this->plan = $this->plan();
    }

    public function testTheListShowsEachServiceByItsStateNewestFirstEightAPage(): void
    {
        $id = $this->ali->id;
        $first = $this->owned('ali_1');
        $this->owned('ali_2', ['is_test' => true, 'plan_id' => null]);
        $this->owned('ali_3', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]);
        $this->owned('ali_4', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);
        $this->owned('ali_5', ['status' => SubscriptionStatus::Deleted]);
        foreach (range(6, 9) as $n) {
            $last = $this->owned("ali_{$n}");
        }
        $ends = Messages::expiry(now()->addHours(720), 720);

        $this->send($this->press("us:o:{$id}"));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        $text = $this->params(0)['text'];
        self::assertStringStartsWith("⚙️ <b>مدیریت کاربر › سرویس‌ها</b> · \u{200E}#{$id}\n👤 <a href=\"tg://user?id=7070\">", $text);
        self::assertStringEndsWith(implode("\n", [
            '',
            '🛍 ۹ سرویس · صفحه ۱ از ۲',
            "🟢 <code>ali_9</code> · یک‌ماهه · آلمان · {$ends}",
            "🟢 <code>ali_8</code> · یک‌ماهه · آلمان · {$ends}",
            "🟢 <code>ali_7</code> · یک‌ماهه · آلمان · {$ends}",
            "🟢 <code>ali_6</code> · یک‌ماهه · آلمان · {$ends}",
            "⚫️ <code>ali_5</code> · یک‌ماهه · آلمان · {$ends}",
            '🔴 <code>ali_4</code> · یک‌ماهه · آلمان · ' . Messages::expiry(now()->subDay(), 720),
            "⏸ <code>ali_3</code> · یک‌ماهه · آلمان · {$ends}",
            "🟢 <code>ali_2</code> · اکانت تست · آلمان · {$ends}",
        ]), $text, 'newest first; the oldest is on the next page');
        self::assertSame([
            ['🟢 ali_8', '🟢 ali_9'],
            ['🟢 ali_6', '🟢 ali_7'],
            ['🔴 ali_4', '⚫️ ali_5'],
            ['🟢 ali_2', '⏸ ali_3'],
            ['⬅️ بعدی'],
            ['⬅️ بازگشت'],
        ], $this->labels(0), 'a button a service, the first on the right');
        self::assertSame("us:v:{$id}:{$last->id}", $this->callbacks(0)[1]);
        self::assertSame(["us:o:{$id}:2", "um:o:{$id}"], array_slice($this->callbacks(0), -2));

        $this->send($this->press("us:o:{$id}:2"));

        self::assertStringEndsWith("\n🛍 ۹ سرویس · صفحه ۲ از ۲\n🟢 <code>ali_1</code> · یک‌ماهه · آلمان · {$ends}", $this->params(0)['text']);
        self::assertSame([['🟢 ali_1'], ['➡️ قبلی'], ['⬅️ بازگشت']], $this->labels(0));
        self::assertSame(["us:v:{$id}:{$first->id}", "us:o:{$id}:1", "um:o:{$id}"], $this->callbacks(0));
    }

    public function testACustomerWithNoServiceIsSaidSo(): void
    {
        $this->send($this->press("us:o:{$this->ali->id}"));

        self::assertStringEndsWith("\n\nهیچ سرویسی ندارد.", $this->params(0)['text']);
        self::assertSame([['⬅️ بازگشت']], $this->labels(0));
    }

    public function testAServicesViewSaysAllOfItAndOffersWhatItsStateAllows(): void
    {
        $id = $this->ali->id;
        $running = $this->owned('ali_1', ['label' => 'گوشی من', 'download_bytes' => 2 * Traffic::GIGABYTE, 'ip_limit' => 2]);

        $this->send($this->press("us:v:{$id}:{$running->id}"));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertStringStartsWith("⚙️ <b>مدیریت کاربر › سرویس‌ها</b> · \u{200E}#{$id}\n👤 ", $this->params(0)['text']);
        self::assertStringEndsWith(implode("\n", [
            '',
            "🔖 سرویس: <code>ali_1</code> · اشتراک \u{200E}#{$running->id}",
            '✏️ نام دلخواه مشتری: گوشی من',
            '📦 پلن: یک‌ماهه',
            '📍 لوکیشن: آلمان',
            '🚦 وضعیت: 🟢 فعال',
            '📊 مصرف: ۲ گیگابایت از ۳۰ گیگابایت · مانده: ۲۸ گیگابایت',
            '📅 پایان: ' . Messages::expiry(now()->addHours(720), 720),
            '👥 تعداد کاربر: ۲ کاربر',
            '🔗 لینک: <code>https://fake.test/sub/ali_1</code>',
        ]), $this->params(0)['text']);
        self::assertSame([['➕ زمان و حجم', '🔄 همگام‌سازی'], ['🗑 حذف', '⏸ غیرفعال'], ['⬅️ سرویس‌ها']], $this->labels(0));
        $s = $running->id;
        self::assertSame(["us:x:{$id}:{$s}", "us:s:{$id}:{$s}", "us:r:{$id}:{$s}", "us:d:{$id}:{$s}", "us:o:{$id}"], $this->callbacks(0));
        self::assertSame('danger', $this->inlineKeyboard(0)[1][0]['style']);

        $off = $this->owned('ali_2', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]);
        $this->send($this->press("us:v:{$id}:{$off->id}"));
        self::assertStringContainsString("\n🚦 وضعیت: ⏸ غیرفعال شده\n", $this->params(0)['text']);
        self::assertSame([['▶️ فعال', '🔄 همگام‌سازی'], ['🗑 حذف'], ['⬅️ سرویس‌ها']], $this->labels(0), 'switched on again, not off');
        self::assertSame('success', $this->inlineKeyboard(0)[0][0]['style']);

        $ended = $this->owned('ali_3', ['status' => SubscriptionStatus::Expired, 'download_bytes' => 30 * Traffic::GIGABYTE]);
        $this->send($this->press("us:v:{$id}:{$ended->id}"));
        self::assertStringContainsString("\n🚦 وضعیت: 🔴 حجمش تمام شده\n", $this->params(0)['text']);
        self::assertSame([['🗑 حذف', '🔄 همگام‌سازی'], ['⬅️ سرویس‌ها']], $this->labels(0), 'an ended service needs a renewal, not a switch');

        $gone = $this->owned('ali_4', ['status' => SubscriptionStatus::Deleted]);
        $this->send($this->press("us:v:{$id}:{$gone->id}"));
        self::assertStringContainsString("\n🚦 وضعیت: ⚫️ کلاینتش دیگر روی پنل نیست\n", $this->params(0)['text']);
        self::assertSame([['🗑 حذف'], ['⬅️ سرویس‌ها']], $this->labels(0), 'only deleting it is left');

        $held = $this->owned('ali_5', ['is_test' => true, 'plan_id' => null]);
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Failed, 'subscription_id' => $held->id]);
        $this->send($this->press("us:v:{$id}:{$held->id}"));
        self::assertStringContainsString("\n📦 پلن: اکانت تست\n", $this->params(0)['text']);
        self::assertStringEndsWith("\n\n⏳ سفارش \u{200E}#{$order->id} این سرویس هنوز تمام نشده است؛ تا آن موقع حذف نمی‌شود.", $this->params(0)['text']);
        self::assertSame([['➕ زمان و حجم', '🔄 همگام‌سازی'], ['⏸ غیرفعال'], ['⬅️ سرویس‌ها']], $this->labels(0), 'no delete while an order of it is open');
    }

    public function testAServiceOfAnyoneElseIsNotFound(): void
    {
        $sara = $this->customer(['telegram_id' => 8080, 'username' => 'sara']);
        $hers = $this->mirrored($this->subscription($sara, $this->plan, $this->server, 'sara_1'));
        $id = $this->ali->id;

        $this->send($this->press("us:v:{$id}:{$hers->id}"));

        self::assertSame('این سرویس پیدا نشد.', $this->popup());
        self::assertSame('true', $this->params(1)['show_alert']);
        self::assertStringEndsWith("\n\nهیچ سرویسی ندارد.", $this->params(0)['text'], 'the list, as it is: hers is none of his');

        $this->send($this->press("us:ds:{$id}:{$hers->id}"));
        $this->send($this->press("us:rs:{$id}:{$hers->id}"));
        $this->send($this->press("us:xs:{$id}:{$hers->id}:7:0:abc123"));
        self::assertSame('این سرویس پیدا نشد.', $this->popup());
        self::assertSame([[], [], []], [FakeProvider::$enabled, FakeProvider::$deleted, FakeProvider::$updated], 'no panel was touched');
        self::assertSame(SubscriptionStatus::Active, $hers->refresh()->status);
    }

    public function testSyncReadsTheServiceFromItsPanelAgain(): void
    {
        $service = $this->mirrored($this->owned('ali_1', ['download_bytes' => 5 * Traffic::GIGABYTE]));
        Subscription::query()->whereKey($service->id)->update(['download_bytes' => 0]);
        $press = "us:s:{$this->ali->id}:{$service->id}";

        $this->send($this->press($press));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame('🔄 از پنل خوانده شد.', $this->popup());
        self::assertStringContainsString("\n📊 مصرف: ۵ گیگابایت از ۳۰ گیگابایت · مانده: ۲۵ گیگابایت\n", $this->params(0)['text']);
        self::assertStringEndsWith("\n🕘 آخرین خواندن از پنل: " . Persian::date(now(), withTime: true), $this->params(0)['text']);

        FakeProvider::$unreachable = true;
        $this->send($this->press($press));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the view as it now stands');
        self::assertStringStartsWith('پنل «آلمان»: ', (string) $this->popup());
        self::assertSame('true', $this->params(1)['show_alert']);

        FakeProvider::$unreachable = false;
        FakeProvider::$clients = [];
        $this->send($this->press($press));
        self::assertSame('⚫️ کلاینتش دیگر روی پنل نیست.', $this->popup());
        self::assertSame(SubscriptionStatus::Deleted, $service->refresh()->status);
        self::assertSame([['🗑 حذف'], ['⬅️ سرویس‌ها']], $this->labels(0));
    }

    public function testAPresetIsAskedThenGivenAndTheCustomerIsTold(): void
    {
        $service = $this->mirrored($this->owned('ali_1'));
        $id = $this->ali->id;
        $s = $service->id;

        $this->send($this->press("us:x:{$id}:{$s}"));

        self::assertStringEndsWith("\n\n➕ <b>چه مقدار به آن اضافه شود؟</b> مشتری خبردار می‌شود.", $this->params(0)['text']);
        self::assertSame([['➕ ۳۰ روز', '➕ ۷ روز'], ['➕ ۵۰ گیگ', '➕ ۱۰ گیگ'], ['✍️ مقدار دلخواه'], ['⬅️ بازگشت']], $this->labels(0));
        self::assertSame(["us:xa:{$id}:{$s}:30:0", "us:xa:{$id}:{$s}:7:0", "us:xa:{$id}:{$s}:0:50", "us:xa:{$id}:{$s}:0:10", "us:xt:{$id}:{$s}", "us:v:{$id}:{$s}"], $this->callbacks(0));

        $this->send($this->press("us:xa:{$id}:{$s}:7:0"));

        self::assertStringEndsWith("\n\n❓ <b>۷ روز به سرویس <code>ali_1</code> اضافه شود؟</b> مشتری خبردار می‌شود.", $this->params(0)['text']);
        [$cancel, $yes] = $this->callbacks(0);
        self::assertSame("us:x:{$id}:{$s}", $cancel, '«انصراف», then «بله» on the right');
        self::assertMatchesRegularExpression("/^us:xs:{$id}:{$s}:7:0:[0-9a-z]{6}$/", $yes);
        self::assertSame([], FakeProvider::$updated, 'asked, not given');

        $this->send($this->press($yes));

        self::assertSame('2026-10-27 12:00:00', FakeProvider::lastUpdate('ali_1')->expiry->deadline()?->format('Y-m-d H:i:s'), 'seven days after its deadline');
        $service->refresh();
        self::assertSame([self::text(BotText::ServiceGranted, [
            'gift' => Messages::gift(7, 0),
            'client' => 'ali_1',
            'note' => '',
            'expires' => Messages::expiry($service->expires_at, $service->duration_hours),
            'remaining' => Messages::bytes(30 * Traffic::GIGABYTE),
        ])], $this->telegram()->sentTo(self::ALI));
        self::assertSame('✅ ۷ روز به سرویس ali_1 اضافه شد؛ مشتری خبردار شد.', $this->popup());
        self::assertStringContainsString("\n📅 پایان: " . Messages::expiry(now()->addDays(37), 37 * 24) . "\n", $this->said()[1]);

        $unlimited = $this->owned('ali_2', ['traffic_limit_bytes' => 0]);
        $this->send($this->press("us:x:{$id}:{$unlimited->id}"));
        self::assertSame([['➕ ۳۰ روز', '➕ ۷ روز'], ['✍️ مقدار دلخواه'], ['⬅️ بازگشت']], $this->labels(0), 'no traffic for an unlimited one');
    }

    public function testAnAmountTypedInReplyToTheBotsQuestionIsGiven(): void
    {
        $service = $this->mirrored($this->owned('ali_1', ['download_bytes' => 20 * Traffic::GIGABYTE]));
        $id = $this->ali->id;

        $this->send($this->press("us:xt:{$id}:{$service->id}"));

        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        $question = $this->params(0)['text'];
        self::assertSame("چند روز و چند گیگ به سرویس ali_1 اضافه شود؟ اول روز و بعد گیگ را با یک فاصله بنویسید؛ مثلا «30 10»، یا «30 0» فقط برای روز. \u{200E}@boss", $question);
        self::assertTrue($this->markup(0)['force_reply']);
        self::assertSame(CustomerCards::WRITE_REPLY, $this->popup());
        $prompt = ReportMessage::query()->where('ref', 'like', 'ask:us:%')->sole();
        self::assertSame("ask:us:xt:{$id}:" . self::CARD . ":{$service->id}", $prompt->ref);
        $promptId = (int) $prompt->message_id;

        $this->send($this->groupReply($question, 'سی روز', $this->threads['users'], $promptId));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame('اول تعداد روز و بعد گیگ را با یک فاصله بنویسید؛ مثلا «30 10»، یا «30 0» فقط برای روز.', $this->params(0)['text']);

        $this->send($this->groupReply($question, '0 0', $this->threads['users'], $promptId));
        self::assertSame('زمان یا حجمی برای افزودن وارد کنید.', $this->params(0)['text'], 'refused as the extension refuses it; the question kept');
        self::assertSame([], FakeProvider::$updated);

        $this->send($this->groupReply($question, '۳۰ ۱۰', $this->threads['users'], $promptId));

        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([40 * Traffic::GIGABYTE, '2026-11-19 12:00:00'], [$spec->totalBytes, $spec->expiry->deadline()?->format('Y-m-d H:i:s')]);
        self::assertSame(['sendMessage', 'editMessageText', 'deleteMessage', 'sendMessage'], $this->calls(), 'the customer told, the card rewritten, the question gone, a word under the reply');
        self::assertCount(1, $this->telegram()->sentTo(self::ALI));
        self::assertSame((string) self::CARD, $this->params(1)['message_id']);
        self::assertStringContainsString("\n📊 مصرف: ۲۰ گیگابایت از ۴۰ گیگابایت · مانده: ۲۰ گیگابایت\n", $this->params(1)['text']);
        self::assertSame((string) $promptId, $this->params(2)['message_id']);
        self::assertSame('✅ ۳۰ روز و ۱۰ گیگابایت به سرویس ali_1 اضافه شد؛ مشتری خبردار شد.', $this->params(3)['text']);
    }

    public function testAPresetIsGivenOnceHoweverOftenItsYesIsPressed(): void
    {
        $service = $this->mirrored($this->owned('ali_1'));
        $id = $this->ali->id;
        $s = $service->id;
        $this->send($this->press("us:xa:{$id}:{$s}:7:0"));
        $yes = $this->callbacks(0)[1];
        self::assertMatchesRegularExpression("/^us:xs:{$id}:{$s}:7:0:[0-9a-z]{6}$/", $yes, '«بله» carries a nonce drawn with it');

        // Refused (the panel out of reach): nothing given, so the same «بله» may try again.
        FakeProvider::$unreachable = true;
        $this->send($this->press($yes));
        self::assertStringStartsWith('پنل «آلمان»: ', (string) $this->popup());
        self::assertSame([], FakeProvider::$updated);
        FakeProvider::$unreachable = false;

        $this->send($this->press($yes));
        self::assertSame('✅ ۷ روز به سرویس ali_1 اضافه شد؛ مشتری خبردار شد.', $this->popup());
        self::assertCount(1, $this->telegram()->sentTo(self::ALI));

        // Pressed again — a double tap, or another admin on the same card —: given once, said so.
        $this->send($this->press($yes));
        self::assertCount(1, FakeProvider::$updated, 'given once');
        self::assertSame('2026-10-27 12:00:00', FakeProvider::lastUpdate('ali_1')->expiry->deadline()?->format('Y-m-d H:i:s'));
        self::assertSame([], $this->telegram()->sentTo(self::ALI), 'the customer told once');
        self::assertSame(CustomerCards::DONE_ALREADY, $this->popup());
        self::assertSame('true', $this->params(1)['show_alert']);
        self::assertStringStartsWith('⚙️ <b>مدیریت کاربر › سرویس‌ها</b>', $this->params(0)['text'], 'the view as the service now stands');

        // A nonce this card never drew (pruned since, or forged), or one drawn on another card: nothing.
        $this->send($this->press("us:xs:{$id}:{$s}:7:0:zzzzzz"));
        self::assertSame(CustomerCards::BUTTON_OLD, $this->popup());
        $this->send($this->groupTap($yes, self::CARD + 1, $this->threads['users']));
        self::assertSame(CustomerCards::BUTTON_OLD, $this->popup());
        self::assertCount(1, FakeProvider::$updated);
    }

    public function testATypedAmountIsGivenOnceHoweverOftenTheQuestionIsAnswered(): void
    {
        $service = $this->mirrored($this->owned('ali_1'));
        $this->send($this->press("us:xt:{$this->ali->id}:{$service->id}"));
        $promptId = (int) ReportMessage::query()->where('ref', 'like', 'ask:us:%')->sole()->message_id;

        $this->send($this->groupReply('…', '7 0', $this->threads['users'], $promptId));
        self::assertCount(1, FakeProvider::$updated);
        self::assertCount(1, $this->telegram()->sentTo(self::ALI));

        // Answered again — before the question was deleted, once Telegram no longer deletes it, or by another admin —:
        // nothing more.
        $this->send($this->groupReply('…', '7 0', $this->threads['users'], $promptId));
        self::assertCount(1, FakeProvider::$updated, 'given once');
        self::assertSame([], $this->telegram()->sentTo(self::ALI), 'the customer told once');
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame(CustomerCards::ANSWERED, $this->params(0)['text']);
    }

    public function testSwitchingOffIsAskedFirstAndSwitchingOnIsNot(): void
    {
        $service = $this->mirrored($this->owned('ali_1'));
        $id = $this->ali->id;
        $s = $service->id;

        $this->send($this->press("us:d:{$id}:{$s}"));

        self::assertStringEndsWith("\n\n❓ <b>سرویس <code>ali_1</code> غیرفعال شود؟</b> کلاینتش روی پنل خاموش می‌شود و مشتری خبردار می‌شود.", $this->params(0)['text']);
        self::assertSame(["us:v:{$id}:{$s}", "us:ds:{$id}:{$s}"], $this->callbacks(0));
        self::assertSame('danger', $this->inlineKeyboard(0)[0][1]['style']);
        self::assertSame([], FakeProvider::$enabled, 'asked, not made');

        $this->send($this->press("us:ds:{$id}:{$s}"));

        self::assertSame([['name' => 'ali_1', 'enabled' => false]], FakeProvider::$enabled);
        self::assertSame(SubscriptionStatus::Disabled, $service->refresh()->status);
        self::assertSame('⏸ غیرفعال شد.', $this->popup());
        self::assertCount(1, $this->telegram()->sentTo(self::ALI), 'the customer is told');
        self::assertSame([['▶️ فعال', '🔄 همگام‌سازی'], ['🗑 حذف'], ['⬅️ سرویس‌ها']], $this->labels(1));

        // A card older than the switch asks for it again: refused as the service's rules refuse it, the view as it stands.
        $this->send($this->press("us:ds:{$id}:{$s}"));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame('این سرویس غیرفعال شده است.', $this->popup());
        self::assertSame('true', $this->params(1)['show_alert']);
        $this->send($this->press("us:d:{$id}:{$s}"));
        self::assertSame('این کار برای این سرویس دیگر ممکن نیست؛ کارت به‌روز شد.', $this->popup());

        $this->send($this->press("us:e:{$id}:{$s}"));

        self::assertSame([['name' => 'ali_1', 'enabled' => false], ['name' => 'ali_1', 'enabled' => true]], FakeProvider::$enabled);
        self::assertSame(SubscriptionStatus::Active, $service->refresh()->status);
        self::assertSame('▶️ فعال شد.', $this->popup());
    }

    public function testDeletingIsAskedFirstThenTheServiceIsGoneAndTheListShown(): void
    {
        $service = $this->mirrored($this->owned('ali_1'));
        $id = $this->ali->id;
        $s = $service->id;

        $this->send($this->press("us:r:{$id}:{$s}"));

        self::assertStringEndsWith("\n\n❓ <b>سرویس <code>ali_1</code> حذف شود؟</b> کلاینتش از پنل سرور هم پاک می‌شود و این کار برگشت ندارد. مشتری خبردار می‌شود.", $this->params(0)['text']);
        self::assertSame(["us:v:{$id}:{$s}", "us:rs:{$id}:{$s}"], $this->callbacks(0));
        self::assertSame([], FakeProvider::$deleted, 'asked, not made');

        $this->send($this->press("us:rs:{$id}:{$s}"));

        self::assertFalse(Subscription::query()->whereKey($s)->exists(), 'gone for good');
        self::assertSame([['server' => $this->server->id, 'name' => 'ali_1']], FakeProvider::$deleted);
        self::assertCount(1, $this->telegram()->sentTo(self::ALI), 'the customer is told: it ran');
        self::assertSame('🗑 سرویس ali_1 حذف شد.', $this->popup());
        $edit = (int) array_search('editMessageText', $this->calls(), true);
        self::assertStringEndsWith("\n\nهیچ سرویسی ندارد.", $this->params($edit)['text'], 'back on the list');

        $this->send($this->press("us:v:{$id}:{$s}"));
        self::assertSame('این سرویس پیدا نشد.', $this->popup());
    }

    public function testTheirOwnAccountAndAnAgentsAreShownNotChanged(): void
    {
        $boss = User::query()->where('telegram_id', self::GROUP_ADMIN)->sole();
        $mine = $this->mirrored($this->subscription($boss, $this->plan, $this->server, 'boss_1'));

        $this->send($this->press("us:v:{$boss->id}:{$mine->id}"));

        self::assertStringEndsWith("\n🔗 لینک: <code>https://fake.test/sub/boss_1</code>\n\n🔒 حساب خودتان است؛ با مدیر دیگری یا از پنل تغییر می‌کند.", $this->params(0)['text']);
        self::assertSame(["us:o:{$boss->id}"], $this->callbacks(0), 'only the way back');

        foreach (["us:s:{$boss->id}:{$mine->id}", "us:ds:{$boss->id}:{$mine->id}", "us:rs:{$boss->id}:{$mine->id}", "us:xs:{$boss->id}:{$mine->id}:7:0:abc123", "us:xt:{$boss->id}:{$mine->id}"] as $press) {
            $this->send($this->press($press));
            self::assertSame(ActorRefusedException::OWN_ACCOUNT, $this->popup(), $press);
        }
        self::assertSame([[], [], []], [FakeProvider::$enabled, FakeProvider::$deleted, FakeProvider::$updated], 'no panel was touched');
        self::assertSame(0, ReportMessage::query()->where('ref', 'like', 'ask:us:%')->count(), 'nothing asked');

        $agent = $this->agent(overrides: ['telegram_id' => 9292, 'username' => 'agent_reza']);
        $theirs = $this->mirrored($this->subscription($agent, $this->plan, $this->server, 'reza_1'));
        $this->send($this->press("us:v:{$agent->id}:{$theirs->id}"));
        self::assertStringEndsWith("\n\n🔒 حساب نماینده است؛ فقط از پنل تغییر می‌کند.", $this->params(0)['text']);
        $this->send($this->press("us:ds:{$agent->id}:{$theirs->id}"));
        self::assertSame(ActorRefusedException::AGENT_ACCOUNT, $this->popup());
        self::assertSame(SubscriptionStatus::Active, $theirs->refresh()->status);
    }

    public function testOnlyTheReportGroupsBotAdminsPress(): void
    {
        $service = $this->mirrored($this->owned('ali_1'));

        $this->send($this->press("us:ds:{$this->ali->id}:{$service->id}", self::GROUP_MEMBER));

        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame(GroupButtons::onlyAdmins(CustomerCards::MAY), $this->popup());
        self::assertSame([], FakeProvider::$enabled);
        self::assertSame(SubscriptionStatus::Active, $service->refresh()->status);
    }

    /**
     * A service of the customer's on the server, on the one-month plan.
     *
     * @param array<string, mixed> $overrides
     */
    private function owned(string $name, array $overrides = []): Subscription
    {
        return $this->subscription($this->ali, $this->plan, $this->server, $name, $overrides);
    }

    /** A press on the card's buttons, by the report group's bot admin unless told otherwise. */
    private function press(string $data, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap($data, self::CARD, $this->threads['users'], $from);
    }

    /** @return list<list<string>> The labels of the n-th call's inline buttons, row by row. */
    private function labels(int $call): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard($call));
    }
}
