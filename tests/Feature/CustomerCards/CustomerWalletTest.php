<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Customers\CustomerCards;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use App\Support\Persian;
use Tests\BotTestCase;

/**
 * «👛 کیف پول» on a customer's card in the report group: the balance, an agent's credit and the ledger's latest lines —
 * who of support wrote one —, and «➕ افزایش موجودی» / «➖ کاهش موجودی», each asking for the amount (a note on a second
 * line) and adjusting the wallet by the reply, keyed by the question: a reply served twice writes one line. Never the
 * admin's own account nor an agent's; only the bot's admins reply.
 */
final class CustomerWalletTest extends BotTestCase
{
    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    /** @var array<string, int> */
    private array $threads;

    private User $boss;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->threads = $this->reportGroup();
        $this->boss = $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->ali = $this->customer(['telegram_id' => 7070, 'username' => 'ali']);
    }

    public function testTheWalletShowsTheBalanceAndTheLatestFiveLinesWithWhoOfSupportWroteThem(): void
    {
        $wallet = $this->service(WalletService::class);
        $this->wallet($this->ali, '20000');
        $wallet->debit($this->ali, 5000, 'پرداخت سفارش #4 (خرید)');
        $wallet->credit($this->ali, 1000, WalletService::LINE_CREDIT_BY_SUPPORT, '@boss');
        $wallet->credit($this->ali, 2000, 'جبران <قطعی>', 'root');
        $wallet->credit($this->ali, 3000, WalletService::LINE_CREDIT_BY_SUPPORT, 'tg:5555');
        $wallet->debit($this->ali, 500, str_repeat('خیلی بلند ', 20));

        $this->send($this->press("uw:o:{$this->ali->id}"));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        $today = Persian::date(new \DateTimeImmutable());
        self::assertSame(implode("\n", [
            "⚙️ <b>مدیریت کاربر › کیف پول</b> · \u{200E}#{$this->ali->id}",
            "👤 <a href=\"tg://user?id=7070\">Ali</a> · \u{200E}@ali · <code>7070</code>",
            '👛 موجودی: ۲۰٬۵۰۰ تومان',
            '',
            '🧾 <b>آخرین تراکنش‌ها</b>',
            '➖ ۵۰۰ تومان · ' . rtrim(mb_substr(str_repeat('خیلی بلند ', 20), 0, 79)) . "… · {$today}",
            "➕ ۳٬۰۰۰ تومان · افزایش موجودی توسط پشتیبانی · 🎧 شناسه تلگرام \u{200E}5555 · {$today}",
            "➕ ۲٬۰۰۰ تومان · جبران &lt;قطعی&gt; · 🎧 پشتیبانی · {$today}",
            "➕ ۱٬۰۰۰ تومان · افزایش موجودی توسط پشتیبانی · 🎧 Boss · {$today}",
            "➖ ۵٬۰۰۰ تومان · پرداخت سفارش \u{200E}#4 (خرید) · {$today}",
        ]), $this->params(0)['text'], 'the oldest line left out; the owner\'s login read as «پشتیبانی»');
        self::assertSame([['➖ کاهش موجودی', '➕ افزایش موجودی'], ['⬅️ بازگشت']], $this->labels(0), 'the increase on the right');
        self::assertSame(["uw:d:{$this->ali->id}", "uw:c:{$this->ali->id}", "um:o:{$this->ali->id}"], $this->callbacks(0));
    }

    public function testAnEmptyWalletAnAgentsCreditAndTheLockedCards(): void
    {
        $this->send($this->press("uw:o:{$this->ali->id}"));
        self::assertStringEndsWith("👛 موجودی: ۰ تومان\n\nهنوز تراکنشی ندارد.", $this->params(0)['text']);

        $agent = $this->agent(credit: '500000', overrides: ['telegram_id' => 9292, 'username' => 'agent_reza']);
        $this->send($this->press("uw:o:{$agent->id}"));
        self::assertStringContainsString("\n👛 موجودی: ۰ تومان\n💳 اعتبار نمایندگی: ۵۰۰٬۰۰۰ تومان\n", $this->params(0)['text']);
        self::assertStringEndsWith("\n\n🔒 حساب نماینده است؛ فقط از پنل تغییر می‌کند.", $this->params(0)['text']);
        self::assertSame(["um:o:{$agent->id}"], $this->callbacks(0), 'only the way back');

        $this->send($this->press("uw:c:{$agent->id}"));
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'nothing asked');
        self::assertSame(ActorRefusedException::AGENT_ACCOUNT, $this->popup());
        self::assertSame('true', $this->params(0)['show_alert']);

        $this->send($this->press("uw:o:{$this->boss->id}"));
        self::assertStringEndsWith("\n\n🔒 حساب خودتان است؛ با مدیر دیگری یا از پنل تغییر می‌کند.", $this->params(0)['text']);
        self::assertSame(["um:o:{$this->boss->id}"], $this->callbacks(0));
        $this->send($this->press("uw:d:{$this->boss->id}"));
        self::assertSame(ActorRefusedException::OWN_ACCOUNT, $this->popup());
        self::assertSame(0, ReportMessage::query()->where('ref', 'like', 'ask:uw:%')->count());
    }

    public function testACreditIsAskedAndMadeByTheReplyWithItsNote(): void
    {
        $this->send($this->press("uw:c:{$this->ali->id}"));

        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        $question = $this->params(0);
        self::assertSame(
            "➕ چه مبلغی به کیف پول اضافه شود؟ مبلغ را به تومان در خط اول بنویسید.\nاگر خواستید، در خط دوم توضیحی بنویسید که مشتری در تاریخچه کیف پولش می‌بیند (تا ۱۹۰ حرف). \u{200E}@boss",
            $question['text'],
        );
        self::assertSame((string) self::CARD, (string) json_decode($question['reply_parameters'], true)['message_id'], 'under the card');
        self::assertTrue($this->markup(0)['force_reply']);
        self::assertSame('مبلغ به تومان', $this->markup(0)['input_field_placeholder']);
        self::assertSame(CustomerCards::WRITE_REPLY, $this->popup());
        $prompt = $this->prompt();

        $this->send($this->reply($prompt, "۱۲٬۰۰۰\n  هدیه   تولد  "));

        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], $this->calls());
        $line = WalletTransaction::query()->where('user_id', $this->ali->id)->sole();
        self::assertSame(WalletTransactionType::Credit, $line->type);
        self::assertSame('12000.00', $line->amount);
        self::assertSame('هدیه تولد', $line->description, 'the note on one line');
        self::assertSame('@boss', $line->reviewer);
        self::assertSame('report-group:' . self::REPORT_GROUP . ":{$prompt}", $line->request_key, 'keyed by the question');

        self::assertSame((string) self::CARD, $this->params(0)['message_id'], 'the card rewritten');
        self::assertStringContainsString("\n👛 موجودی: ۱۲٬۰۰۰ تومان\n", $this->params(0)['text']);
        self::assertStringContainsString('➕ ۱۲٬۰۰۰ تومان · هدیه تولد · 🎧 Boss · ' . Persian::date(new \DateTimeImmutable()), $this->params(0)['text']);
        self::assertSame(["uw:d:{$this->ali->id}", "uw:c:{$this->ali->id}", "um:o:{$this->ali->id}"], $this->callbacks(0));
        self::assertSame((string) $prompt, $this->params(1)['message_id'], 'the question deleted');
        self::assertSame('✅ ۱۲٬۰۰۰ تومان به کیف پول اضافه شد؛ موجودی: ۱۲٬۰۰۰ تومان.', $this->params(2)['text']);
    }

    public function testADebitPastTheBalanceIsAskedAgainWithTheDebtItLeavesAndMadeOnceConfirmed(): void
    {
        $this->wallet($this->ali, '5000');
        $this->send($this->press("uw:d:{$this->ali->id}"));
        self::assertStringStartsWith('➖ چه مبلغی از کیف پول کم شود؟ موجودی: ۵٬۰۰۰ تومان. مبلغ را به تومان در خط اول بنویسید.', $this->params(0)['text']);
        $prompt = $this->prompt();

        // A zero too many: nothing written yet — the card asks again, with the debt it would leave.
        $this->send($this->reply($prompt, "۱۰۰۰۰\nاصلاح <اشتباه>"));
        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], $this->calls());
        self::assertSame(['5000.00', 1], [$this->ali->balance(), WalletTransaction::query()->where('user_id', $this->ali->id)->count()], 'nothing written');
        self::assertSame((string) self::CARD, $this->params(0)['message_id'], 'the card asks');
        self::assertStringContainsString("\n👛 موجودی: ۵٬۰۰۰ تومان\n", $this->params(0)['text']);
        self::assertStringEndsWith(implode("\n", [
            '',
            '➖ <b>کاهش موجودی: ۱۰٬۰۰۰ تومان</b>',
            '📝 توضیح: اصلاح &lt;اشتباه&gt;',
            'موجودی بعد از آن: <b>۵٬۰۰۰ تومان بدهی</b>',
            '⚠️ موجودی از صفر کمتر می‌شود و مشتری بدهکار می‌شود؛ ثبت شود؟',
        ]), $this->params(0)['text']);
        self::assertSame([['⬅️ انصراف', '✅ تایید']], $this->labels(0));
        [$cancel, $confirm] = $this->callbacks(0);
        self::assertMatchesRegularExpression("/^uw:y:{$this->ali->id}:[0-9a-z]{6}$/", $confirm, 'the customer and a nonce: what it writes is the card\'s, never the data\'s');
        self::assertSame('uw:n:' . substr($confirm, 5), $cancel, 'cancelling drops the same nonce');
        self::assertSame((string) $prompt, $this->params(1)['message_id'], 'the question deleted: the card asks now');
        self::assertSame('⚠️ بعد از این کاهش، موجودی کمتر از صفر است؛ برای ثبت آن «✅ تایید» را روی کارت بزنید.', $this->params(2)['text']);

        $this->send($this->press($confirm));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame('-5000.00', $this->ali->balance());
        $line = WalletTransaction::query()->where('user_id', $this->ali->id)->latest('id')->firstOrFail();
        self::assertSame([WalletTransactionType::Debit, '10000.00', 'اصلاح <اشتباه>', '@boss'], [$line->type, $line->amount, $line->description, $line->reviewer], 'the note the reply typed');
        self::assertSame('report-group:' . self::REPORT_GROUP . ":{$prompt}", $line->request_key, 'keyed by the question, as a reply\'s');
        self::assertStringContainsString("\n👛 موجودی: ۵٬۰۰۰ تومان بدهی\n", $this->params(0)['text'], 'the card with the debt');
        self::assertSame(["uw:d:{$this->ali->id}", "uw:c:{$this->ali->id}", "um:o:{$this->ali->id}"], $this->callbacks(0));
        self::assertSame('✅ ۱۰٬۰۰۰ تومان از کیف پول کم شد؛ موجودی: ۵٬۰۰۰ تومان بدهی.', $this->popup());

        // Pressed again (twice, or two admins at once): written once.
        $this->send($this->press($confirm));
        self::assertSame(CustomerCards::DONE_ALREADY, $this->popup());
        self::assertSame(['-5000.00', 2], [$this->ali->balance(), WalletTransaction::query()->where('user_id', $this->ali->id)->count()]);
    }

    public function testADebitDownToZeroIsMadeAtOnceAndOneCancelledWritesNothing(): void
    {
        $this->wallet($this->ali, '5000');
        $this->send($this->press("uw:d:{$this->ali->id}"));
        $this->send($this->reply($this->prompt(), '5000'));
        self::assertSame('0.00', $this->ali->balance(), 'no debt: nothing to ask');
        self::assertSame('✅ ۵٬۰۰۰ تومان از کیف پول کم شد؛ موجودی: ۰ تومان.', $this->params(2)['text']);

        $this->send($this->press("uw:d:{$this->ali->id}"));
        $this->send($this->reply($this->prompt(), '1000'));
        self::assertStringEndsWith('⚠️ موجودی از صفر کمتر می‌شود و مشتری بدهکار می‌شود؛ ثبت شود؟', $this->params(0)['text']);
        [$cancel, $confirm] = $this->callbacks(0);
        $this->send($this->press($cancel));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertStringNotContainsString('ثبت شود؟', $this->params(0)['text'], 'the wallet as it stands');
        self::assertSame(['0.00', 2], [$this->ali->balance(), WalletTransaction::query()->where('user_id', $this->ali->id)->count()], 'nothing written');

        // Its «✅ تایید» pressed afterwards — a stale keyboard, another admin's client: an old button.
        $this->send($this->press($confirm));
        self::assertSame(CustomerCards::BUTTON_OLD, $this->popup());
        self::assertSame(2, WalletTransaction::query()->where('user_id', $this->ali->id)->count(), 'still nothing written');

        // Asked twice on one card: only the last ask's «✅ تایید» writes.
        $this->send($this->press("uw:d:{$this->ali->id}"));
        $this->send($this->reply($this->prompt(), '1000'));
        [, $first] = $this->callbacks(0);
        $this->send($this->press("uw:d:{$this->ali->id}"));
        $this->send($this->reply($this->prompt(), '2000'));
        [, $last] = $this->callbacks(0);
        $this->send($this->press($first));
        self::assertSame(CustomerCards::BUTTON_OLD, $this->popup());
        $this->send($this->press($last));
        self::assertSame(['-2000.00', 3], [$this->ali->balance(), WalletTransaction::query()->where('user_id', $this->ali->id)->count()]);
    }

    public function testAConfirmIsRefusedForAnAccountLockedMeanwhileOrAButtonOfNoCard(): void
    {
        $this->send($this->press("uw:d:{$this->ali->id}"));
        $prompt = $this->prompt();
        $this->send($this->reply($prompt, '1000'));
        [, $confirm] = $this->callbacks(0);

        // Made an agent meanwhile: the panel's account now.
        $this->ali->forceFill(['agency_level_id' => $this->agencyLevel()->id])->save();
        $this->send($this->press($confirm));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame(ActorRefusedException::AGENT_ACCOUNT, $this->popup());
        self::assertStringEndsWith("\n\n🔒 حساب نماینده است؛ فقط از پنل تغییر می‌کند.", $this->params(0)['text'], 'the wallet as it now stands');
        self::assertSame(0, WalletTransaction::query()->where('user_id', $this->ali->id)->count());

        // A nonce this card never drew (forged, or pruned since).
        $this->ali->forceFill(['agency_level_id' => null])->save();
        $this->send($this->press("uw:y:{$this->ali->id}:zzzzzz"));
        self::assertSame(CustomerCards::BUTTON_OLD, $this->popup());
        self::assertSame(0, WalletTransaction::query()->where('user_id', $this->ali->id)->count());

        // The card's nonce sent for another customer: an old button — it writes only what it was drawn for.
        $reza = $this->customer(['telegram_id' => 900_777, 'username' => 'reza']);
        $this->send($this->press("uw:y:{$reza->id}:" . substr($confirm, -6)));
        self::assertSame(CustomerCards::BUTTON_OLD, $this->popup());
        self::assertSame([0, 0], [WalletTransaction::query()->where('user_id', $this->ali->id)->count(), WalletTransaction::query()->where('user_id', $reza->id)->count()]);

        // Malformed: nothing done.
        $this->send($this->press("uw:y:{$this->ali->id}:1000:{$prompt}"));
        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertNull($this->popup());
    }

    public function testADebitPastTheDeepestDebtIsRefusedAndTheQuestionKept(): void
    {
        $this->wallet($this->ali, '-999995000');
        $this->send($this->press("uw:d:{$this->ali->id}"));
        self::assertStringStartsWith('➖ چه مبلغی از کیف پول کم شود؟ موجودی: ۹۹۹٬۹۹۵٬۰۰۰ تومان بدهی.', $this->params(0)['text']);
        $prompt = $this->prompt();

        $this->send($this->reply($prompt, '10000'));
        self::assertSame(['sendMessage'], $this->calls(), 'no card rewritten, no question deleted: never asked again past the deepest');
        self::assertSame('بدهی کاربر از ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان بیشتر نمی‌شود؛ موجودی فعلی: ۹۹۹٬۹۹۵٬۰۰۰ تومان بدهی.', $this->params(0)['text']);
        self::assertSame('-999995000.00', $this->ali->balance());

        // What the wallet refuses anyway is said at once too, not asked again.
        $this->send($this->reply($prompt, '0'));
        self::assertSame(['مبلغ باید عددی مثبت به تومان و بدون اعشار باشد.'], $this->said());
        $this->send($this->reply($prompt, "۳۰۰۰\n" . str_repeat('ی', 191)));
        self::assertSame(['توضیح حداکثر 190 کاراکتر است.'], $this->said());

        $this->send($this->reply($prompt, '۳۰۰۰'));
        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], $this->calls(), 'the same question answered again');
        self::assertStringEndsWith("موجودی بعد از آن: <b>۹۹۹٬۹۹۸٬۰۰۰ تومان بدهی</b>\n⚠️ بدهی مشتری بیشتر می‌شود؛ ثبت شود؟", $this->params(0)['text']);
        [, $confirm] = $this->callbacks(0);
        $this->send($this->press($confirm));
        self::assertSame('-999998000.00', $this->ali->balance());
        self::assertSame('✅ ۳٬۰۰۰ تومان از کیف پول کم شد؛ موجودی: ۹۹۹٬۹۹۸٬۰۰۰ تومان بدهی.', $this->popup());
    }

    public function testAQuestionAnsweredTwiceWritesOneLine(): void
    {
        $this->send($this->press("uw:c:{$this->ali->id}"));
        $prompt = $this->prompt();
        $this->send($this->reply($prompt, '1000'));
        self::assertSame('1000.00', $this->ali->balance());

        // The same reply again, or another amount (before the question was deleted, or two admins at once): the question
        // was taken by the first, nothing more written.
        foreach (['1000', '2000'] as $typed) {
            $this->send($this->reply($prompt, $typed));
            self::assertSame(['sendMessage'], $this->calls(), $typed);
            self::assertSame(CustomerCards::ANSWERED, $this->params(0)['text'], $typed);
        }
        self::assertSame(1, WalletTransaction::query()->where('user_id', $this->ali->id)->count());

        // Behind the question's claim, the wallet's key still holds: the claim lost (by hand here), the same reply is
        // answered by the line it wrote, another amount refused — nothing written either way.
        $question = static fn() => ReportMessage::query()->where('message_id', $prompt)->update(['lease_token' => null]);
        $question();
        $this->send($this->reply($prompt, '1000'));
        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], $this->calls());
        self::assertSame('✅ این تغییر پیش‌تر ثبت شده بود؛ موجودی: ۱٬۰۰۰ تومان.', $this->params(2)['text']);
        $question();
        $this->send($this->reply($prompt, '2000'));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame('به این پرسش پیش‌تر جواب داده شده و همان ثبت شده است؛ برای تغییر دیگری دوباره دکمه افزایش یا کاهش را بزنید.', $this->params(0)['text']);

        self::assertSame(1, WalletTransaction::query()->where('user_id', $this->ali->id)->count());
        self::assertSame('1000.00', $this->ali->balance());

        // A new question is a change of its own.
        $this->send($this->press("uw:c:{$this->ali->id}"));
        $this->send($this->reply($this->prompt(), '1000'));
        self::assertSame(2, WalletTransaction::query()->where('user_id', $this->ali->id)->count());
        self::assertSame('2000.00', $this->ali->balance());
    }

    public function testOnlyABotAdminsAmountIsTakenInAnyDigitsOrWords(): void
    {
        $this->send($this->press("uw:c:{$this->ali->id}"));
        $prompt = $this->prompt();

        $this->send($this->reply($prompt, '5000', from: self::GROUP_MEMBER));
        self::assertSame([GroupButtons::onlyAdmins(CustomerCards::MAY)], $this->said());

        foreach (['سلام', '-', "\nفقط توضیح", '12.5'] as $typed) {
            $this->send($this->reply($prompt, $typed));
            self::assertSame(['مبلغ را به تومان و با رقم در خط اول بنویسید، مثلا ۵۰٬۰۰۰؛ توضیح، اگر خواستید، در خط دوم.'], $this->said(), $typed);
        }
        self::assertSame(0, WalletTransaction::query()->where('user_id', $this->ali->id)->count());

        $this->send($this->reply($prompt, '0'));
        self::assertSame(['مبلغ باید عددی مثبت به تومان و بدون اعشار باشد.'], $this->said(), 'the wallet\'s own refusal');

        $this->send($this->reply($prompt, "۵۰ هزار تومان\n" . str_repeat('ی', 191)));
        self::assertSame(['توضیح حداکثر 190 کاراکتر است.'], $this->said(), 'a note past its length');

        $this->send($this->reply($prompt, '۵۰ هزار تومان'));
        self::assertSame('50000.00', $this->ali->balance());
    }

    public function testAnAccountLockedMeanwhileOrTheirOwnIsRefusedOnReply(): void
    {
        $this->send($this->press("uw:c:{$this->ali->id}"));
        $prompt = $this->prompt();
        $this->ali->forceFill(['agency_level_id' => $this->agencyLevel()->id])->save();

        $this->send($this->reply($prompt, '1000'));
        self::assertSame([ActorRefusedException::AGENT_ACCOUNT], $this->said());
        self::assertSame(0, WalletTransaction::query()->where('user_id', $this->ali->id)->count());

        // Another admin's wallet is theirs to change — but not by that admin's own reply.
        $sara = $this->admin(['telegram_id' => 9191, 'username' => 'sara']);
        $this->send($this->press("uw:c:{$sara->id}"));
        $prompt = $this->prompt();
        $this->send($this->reply($prompt, '1000', from: 9191));
        self::assertSame([ActorRefusedException::OWN_ACCOUNT], $this->said());
        $this->send($this->reply($prompt, '1000'));
        self::assertSame('1000.00', $sara->balance());
    }

    /** A press on the card's buttons, by the report group's bot admin. */
    private function press(string $data, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap($data, self::CARD, $this->threads['users'], $from);
    }

    /** A reply to the bot's question `$prompt`, by the report group's bot admin unless told otherwise. */
    private function reply(int $prompt, string $text, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupReply('…', $text, $this->threads['users'], $prompt, $from);
    }

    /** The message id of the section's latest question. */
    private function prompt(): int
    {
        return (int) ReportMessage::query()->where('ref', 'like', 'ask:uw:%')->latest('id')->value('message_id');
    }

    /** @return list<list<string>> The labels of the n-th call's inline buttons, row by row. */
    private function labels(int $call): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard($call));
    }
}
