<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Http\Json;
use App\Modules\Admin\Api\DashboardController;
use App\Modules\Payments\Models\Payment;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Admin\Door\ApiFile;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Screen;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Users\Enums\UserRole;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\BotTestCase;

/**
 * The management's framework (Telegram\Admin\Ui) as every screen uses it: buttons with a colour or a value to copy
 * (none for a value Telegram would not copy whole); rows written in reading order and drawn right to left; the one way
 * around last; a summary card, sections and help folded away; a long screen cut, its tags closed; the pages' middle
 * button; a list that says how many and what narrows it first, its tools beside the search; a question that says the
 * value now and offers to empty it or put it back, and a wizard step's ways on; a confirmation green or red that says
 * where it was asked; an old window's button — or data no button carries — landing on the home; the home with today
 * and what waits, the dashboard refusing too; the search across customers, orders, payments and services; and a
 * settings card that says each value once, its field buttons naming their field.
 */
final class BotAdminFoundationTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testAButtonCarriesAColourOrAValueToCopy(): void
    {
        self::assertSame(['text' => Words::CREATE, 'callback_data' => 'a:pl:new', 'style' => 'success'], Button::to(Words::CREATE, 'pl', 'new')->success()->markup());
        self::assertSame('danger', Button::to(Words::DELETE, 'pl', 'del')->danger()->markup()['style'] ?? null);
        self::assertSame('primary', Button::to(Words::EDIT, 'pl')->primary()->markup()['style'] ?? null);
        self::assertSame(['text' => '📋 کپی آدرس', 'copy_text' => ['text' => 'https://shop.example/api']], Button::copy(Words::copy('آدرس'), 'https://shop.example/api')->markup());
        self::assertSame(['text' => 'پنل', 'url' => 'https://example.com'], Button::url('پنل', 'https://example.com')->success()->markup(), 'a link takes no colour');
        self::assertSame('a:pl:del:3', Button::to('✖️ لغو', 'pl', 'del', 3)->labelled(Words::CANCEL)->markup()['callback_data'] ?? null, 'relabelled, its target kept');

        // A value Telegram would not copy whole — past 256 characters —, or none, gets no button: a key or a link cut
        // short is a wrong one, and nothing is nothing to copy. The card still shows it to copy.
        $copies = View::make('🔗 آدرس')
            ->row(Button::copy(Words::copy('آدرس'), str_repeat('a', Button::COPY_MOST)), Button::copy(Words::copy('کلید'), str_repeat('a', Button::COPY_MOST + 1)))
            ->tiles(Button::copy(Words::copy('کد'), ''), Button::to('🔑 کلید تازه', 'ws', 'key'))
            ->keyboard()['inline_keyboard'];
        self::assertSame([[Words::copy('آدرس')], ['🔑 کلید تازه']], array_map(static fn(array $row): array => array_column($row, 'text'), $copies));

        // A waiting question keeps its buttons as Telegram's shape: read back whole.
        $styled = Button::to('🎁 هدیه', 'pl', 'gift')->danger();
        $copied = Button::copy(Words::copy('کد'), 'NOWRUZ');
        self::assertSame($styled->markup(), Button::kept($styled->markup())?->markup());
        self::assertSame($copied->markup(), Button::kept($copied->markup())?->markup());
    }

    public function testRowsAreWrittenInReadingOrderAndTheWayAroundComesLast(): void
    {
        $data = static fn(View $view): array => array_map(static fn(array $row): array => array_column($row, 'callback_data'), $view->keyboard()['inline_keyboard']);
        $labels = static fn(View $view): array => array_map(static fn(array $row): array => array_column($row, 'text'), $view->keyboard()['inline_keyboard']);

        $view = View::make('📦 پلن‌ها')
            ->row(Button::to('۱', 'pl', 'a'), Button::to('۲', 'pl', 'b'), null, Button::to('۳', 'pl', 'c'))
            ->back('hs')
            ->refresh('pl')
            ->row(Button::to('۴', 'pl', 'd'));
        self::assertSame([['a:pl:c', 'a:pl:b', 'a:pl:a'], ['a:pl:d'], ['a:pl:show', 'a:h:show', 'a:hs:show']], $data($view), 'each row drawn right to left; the way around under a row added after it');
        self::assertSame([Words::REFRESH, Words::HOME, Words::BACK], $labels($view)[2]);

        self::assertSame([[Words::HOME]], $labels(View::make('🛍 فروش')->back(Screens::HOME)), 'the way back the home: the home alone');
        self::assertSame([[Words::REFRESH]], $labels(View::make('🏠 مدیریت فروشگاه')->refresh(Screens::HOME)), 'the home: no way back, only a refresh');

        $tiles = View::make('⚙️ عمومی')->tiles(Button::to('✏️ کوتاه', 'x', 'a'), Button::to('✏️ کوتاه دو', 'x', 'b'), Button::to('▶️ روشن کردن «یک نام بسیار بلندتر»', 'x', 'c'), Button::to('✏️ تنها', 'x', 'd'));
        self::assertSame([['a:x:b', 'a:x:a'], ['a:x:c'], ['a:x:d']], $data($tiles), 'two short labels a row, a long one alone');
        self::assertSame(['▶️ روشن کردن «نام رندوم»', '⏸ خاموش کردن روش «تمدید پلن فعلی»'], [Words::turn(true, 'نام رندوم'), Words::turn(false, 'روش «تمدید پلن فعلی»')], 'a switch says the state a tap sets; a label quoting itself is not quoted again');
    }

    public function testACardSectionsAndFoldedHelpEachStayOneLineOfTheMessage(): void
    {
        $view = View::make('📦 یک‌ماهه', Words::ROOT, 'فروشگاه', 'پلن‌ها')
            ->card(['قیمت' => Fmt::money('120000'), 'دسته' => null, 'توضیح' => '', 'کد' => Fmt::code('ABC'), 'وضعیت' => '<b>' . Words::ACTIVE . '</b>'])
            ->section('🖥 سرورها')
            ->line('• آلمان')
            ->help('آنچه این صفحه نشان می‌دهد.')
            ->note('کوتاه');

        self::assertSame(
            "<b>📦 یک‌ماهه</b>\n<i>مدیریت › فروشگاه › پلن‌ها</i>\n"
            . '<blockquote>قیمت: <b>' . Fmt::money('120000') . "</b>\nتوضیح: <b>—</b>\nکد: <code>ABC</code>\nوضعیت: <b>🟢 فعال</b></blockquote>\n"
            . "\n<b>🖥 سرورها</b>\n• آلمان\n"
            . "\n<blockquote expandable>❔ آنچه این صفحه نشان می‌دهد.</blockquote>\n"
            . "\n<i>کوتاه</i>",
            $view->text(),
            'a null fact left out, an empty one «—», a value to copy or already bold not bolded',
        );
        self::assertSame([Words::ROOT, 'فروشگاه', 'پلن‌ها', 'یک‌ماهه'], $view->trail(), 'where it is: its path and its title, bare');
        self::assertSame("<b>t</b>\n۲۳ پرداخت · 🧾 در انتظار بررسی", View::make('t')->total(23, 'پرداخت', '🧾 در انتظار بررسی', '')->text());
        self::assertSame(Words::ON . ' · ' . Words::OFF, Fmt::onOff(true) . ' · ' . Fmt::onOff(false), 'one chip vocabulary');
        self::assertSame(Words::ACTIVE . ' · ' . Words::INACTIVE, Fmt::active(true) . ' · ' . Fmt::active(false));
    }

    public function testALongScreenIsCutToWhatTelegramShowsItsTagsStillClosed(): void
    {
        $facts = [];
        foreach (range(1, 300) as $n) {
            $facts['کلید ' . $n] = Fmt::code('key-' . $n . '&<');
        }
        $view = View::make('📦 آزمایش', Words::ROOT)->card($facts)->help(str_repeat('راهنما <b>پررنگ</b> و ', 400));
        $text = $view->text();
        self::assertLessThanOrEqual(4000, mb_strlen($text));
        self::assertStringEndsWith("…</blockquote>\n…", $text, 'the card cut inside, its quote closed; the help after it left out');
        self::assertStringNotContainsString('راهنما', $text);
        self::assertSame([], self::unclosed($text));

        $help = View::make('t')->help(str_repeat('راهنما <b>پررنگ</b> و ', 400))->text();
        self::assertStringEndsWith("…</blockquote>\n…", $help, 'a folded help cut inside stays folded');
        self::assertSame([], self::unclosed($help));

        $caption = View::make('🧾 رسید')->card($facts)->picture(new ApiFile('x', 'image/jpeg', 'r.jpg'))->text();
        self::assertLessThanOrEqual(1000, mb_strlen($caption), 'a picture\'s caption shorter still');
        self::assertSame([], self::unclosed($caption));
    }

    public function testThePagesMiddleButtonSaysWhereItIsAndLeavesTheWindow(): void
    {
        $row = View::make('💳 پرداخت‌ها')->pager(2, 5, 'py', 'page')->keyboard()['inline_keyboard'][0];
        self::assertSame([Nav::to('py', 'page', 3), Nav::to(Screens::PAGE, Nav::SHOW, 2, 5), Nav::to('py', 'page', 1)], array_column($row, 'callback_data'));
        self::assertSame([Words::NEXT, '۲ / ۵', Words::PREVIOUS], array_column($row, 'text'), '«‹ قبلی» on the right, «بعدی ›» on the left');

        $this->owner(UserRole::Admin);
        $this->send($this->tap(Nav::to(Screens::PAGE, Nav::SHOW, 2, 5), chat: self::OWNER));
        self::assertSame('صفحه ۲ از ۵', $this->popup());
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'the window left as it is');
    }

    public function testAListSaysHowManyAndWhatNarrowsItFirstAndLaysItsToolsBesideTheSearch(): void
    {
        $list = new class extends ListScreen {
            /** What the list's meta counted: read by summary(), said by the tab (as a sales list's counting tabs). */
            private int $paid = 0;

            protected function screen(): string
            {
                return 'zzl';
            }

            protected function title(): string
            {
                return '💳 آزمایش';
            }

            protected function path(): array
            {
                return [Words::ROOT];
            }

            protected function endpoint(): string
            {
                return '/payments';
            }

            protected function key(): string
            {
                return 'payments';
            }

            protected function item(array $row): Button
            {
                return Button::to('#' . Fmt::digits(is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''), 'zzl');
            }

            protected function noun(): string
            {
                return 'پرداخت';
            }

            protected function filters(): array
            {
                return ['status' => [self::ANY => 'همه', 'paid' => '✅ موفق' . Fmt::badge($this->paid)]];
            }

            protected function summary(array $meta): array
            {
                $this->paid = 6;

                return ['💰 ۶ پرداخت موفق · جمع ' . Fmt::money('120000')];
            }

            protected function narrowedBy(): array
            {
                return ['نوع: خرید', 'تاریخ: ۷ روز اخیر'];
            }

            protected function tools(Admin $admin): array
            {
                return [Button::to('🎛 فیلتر و ترتیب', 'zzl', 'nx'), null];
            }
        };
        $this->owner(UserRole::Admin);

        $this->opening('zzl', $list, function (): void {
            $this->send($this->tap(Nav::to('zzl', 'f', 'status', 'paid'), chat: self::OWNER));
            self::assertStringStartsWith(
                "<b>💳 آزمایش</b>\n<i>مدیریت</i>\n۰ پرداخت · ✅ موفق (۶) · نوع: خرید · تاریخ: ۷ روز اخیر\n💰 ۶ پرداخت موفق · جمع " . Fmt::money('120000') . "\n",
                $this->said()[0],
                'how many and what narrows it first — the tab, then what else narrows it —, what it adds up to under that',
            );
            $rows = array_map(static fn(array $row): array => array_column($row, 'callback_data'), $this->inlineKeyboard($this->windowCall()));
            self::assertSame([Nav::to('zzl', 'f', 'status', 'paid'), Nav::to('zzl', 'f', 'status', ListScreen::ANY)], $rows[0], 'the chips');
            self::assertSame([Nav::to('zzl', 'nx'), Nav::to('zzl', 'search')], $rows[1], 'under them the search, then the tools: on its left');

            // A value the filter does not offer (an older window's chip) narrows nothing: the chip in force stays.
            $this->send($this->tap(Nav::to('zzl', 'f', 'status', 'refunded'), chat: self::OWNER));
            $kept = $this->service(SessionStore::class)->load(self::OWNER)->get(Admin::FACT);
            self::assertSame(['status' => 'paid'], is_array($kept) ? $kept['screens']['zzl']['f'] ?? null : null);

            $this->send($this->tap(Nav::to('zzl', 'search'), chat: self::OWNER));
            $this->send($this->message('ali', chat: self::OWNER));
            self::assertStringContainsString('۰ پرداخت · ✅ موفق (۶) · 🔎 «ali» · نوع: خرید · تاریخ: ۷ روز اخیر', $this->said()[0], 'the search, before what else narrows it');
            self::assertSame([Nav::to('zzl', 'nx'), Nav::to('zzl', 'clear')], array_column($this->inlineKeyboard($this->windowCall())[1], 'callback_data'), 'the search\'s way off keeps the tools beside it');
        });
    }

    public function testAQuestionSaysTheValueNowAnExampleAndOffersToEmptyIt(): void
    {
        $this->owner(UserRole::Admin);
        $this->botSettings('wallet', ['topup_presets' => [50_000, 100_000]]);

        $this->send($this->tap(Nav::to('bsw'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('bsw', 'e', 1), chat: self::OWNER));
        $asked = $this->said()[0];
        self::assertStringContainsString('<b>✍️ مبلغ‌های پیشنهادی</b>', $asked);
        self::assertStringContainsString('<i>مدیریت › تنظیمات › تنظیمات ربات › کیف پول</i>', $asked, 'asked from the card: its path');
        self::assertStringContainsString('مقدار فعلی: <code>۵۰۰۰۰، ۱۰۰۰۰۰</code>', $asked, 'in the form an answer takes, to copy');
        self::assertStringContainsString('مثال: <code>۵۰۰۰۰، ۱۰۰۰۰۰</code>', $asked);
        self::assertSame([[Words::RESET, Words::CLEAR], [Words::CANCEL]], array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard(0)), 'the amounts the panel\'s form comes with offered too');
        self::assertSame([Nav::to('bsw', 'd', 1), Nav::to('bsw', 'x', 1), Nav::to('bsw', 'v')], $this->callbacks(0));

        // A refused answer asks it again as it was asked: the value now and the way to empty it kept.
        $this->send($this->message(null, ['sticker' => ['file_id' => 'x']], chat: self::OWNER));
        $again = implode("\n", $this->said());
        self::assertStringContainsString('مقدار فعلی: <code>۵۰۰۰۰، ۱۰۰۰۰۰</code>', $again);
        self::assertContains(Nav::to('bsw', 'x', 1), $this->callbacks($this->windowCall()));

        $this->send($this->tap(Nav::to('bsw', 'x', 1), chat: self::OWNER));
        self::assertSame([], $this->service(BotSettingsScreen::class)->present()['topup_presets'], 'emptied through the panel\'s route');
    }

    public function testAQuestionOffersTheValueAFieldComesWithAndNeverShowsASecret(): void
    {
        $card = new class extends SettingsScreen {
            protected function screen(): string
            {
                return 'zz';
            }

            protected function title(): string
            {
                return '🧪 آزمایش';
            }

            protected function path(): array
            {
                return [Words::ROOT];
            }

            protected function fields(): array
            {
                return [
                    FieldDef::number('count', 'تعداد دکمه', 'دکمه')->byDefault(4)->limits('از ۰ تا ۸')->example('۴'),
                    FieldDef::secret('key', 'کلید'),
                    FieldDef::money('least', 'حداقل مبلغ'),
                ];
            }

            protected function source(): SettingsSource
            {
                throw new \LogicException('Nothing is read here.');
            }

            /** @param array<string, mixed> $values */
            public function question(int $index, array $values): Prompt
            {
                return $this->prompt($index, $this->fields()[$index], $values);
            }
        };

        $prompt = $card->question(0, ['count' => 6]);
        self::assertSame(['۶', '۴', 'از ۰ تا ۸'], [$prompt->current, $prompt->example, $prompt->limits]);
        self::assertSame(['text' => Words::RESET, 'callback_data' => 'a:zz:d:0'], $prompt->reset?->markup());
        self::assertSame(['text' => Words::CANCEL, 'callback_data' => 'a:zz:v'], $prompt->cancel?->markup());
        self::assertNull($card->question(0, ['count' => 4])->reset, 'holding it already: nothing to put back');
        self::assertSame($prompt->keep(), Prompt::kept($prompt->keep())->keep(), 'kept as asked');

        $secret = $card->question(1, ['key' => true]);
        self::assertSame('•••• (ذخیره شده)', $secret->current);
        self::assertFalse($secret->copyable, 'a secret is never offered to copy');
        self::assertSame('۵۰۰۰۰', $card->question(2, ['least' => '10000.00'])->example, 'an amount\'s example by its type');
    }

    public function testAWizardStepsWaysOnHaveARowOfTheirOwnAndOutliveARefusedAnswer(): void
    {
        self::assertSame('گام ۲ از ۶', Words::step(2, 6));

        $prompt = (new Prompt('کد', 'bsw', 'e', [0]))
            ->clearable('bsw', 'x', 0)
            ->ways(Button::to(Words::PREVIOUS_STEP, 'bsw', 'v'), null, Button::to(Words::SKIP, 'bsw', 'x', 0)->primary());
        self::assertSame([Words::PREVIOUS_STEP, Words::SKIP], array_map(static fn(Button $way): string => $way->label, $prompt->ways), 'a way not offered left out');
        self::assertSame($prompt->keep(), Prompt::kept($prompt->keep())->keep(), 'kept as asked, the ways too');
        $older = $prompt->keep();
        unset($older['ways']);
        self::assertSame([], Prompt::kept($older)->ways, 'a question an older version kept: asked without them');

        // The chat waits at the step's question, as Admin::ask() left it.
        $this->owner(UserRole::Admin);
        $this->send($this->tap(Nav::to('bsw'), chat: self::OWNER));
        $session = $this->service(SessionStore::class)->load(self::OWNER);
        $session->enter(Admin::INPUT, ['adm_prompt' => $prompt->keep()]);
        $session->save();

        // A refused answer asks it again as it was asked: the ways on in a row of their own, between the field's and the way out.
        $this->send($this->message(null, ['sticker' => ['file_id' => 'x']], chat: self::OWNER));
        $rows = $this->inlineKeyboard($this->windowCall());
        self::assertSame([[Words::CLEAR], [Words::SKIP, Words::PREVIOUS_STEP], [Words::CANCEL]], array_map(static fn(array $row): array => array_column($row, 'text'), $rows), '«⬅️ گام قبل» on the right');
        self::assertSame([Nav::to('bsw', 'x', 0), Nav::to('bsw', 'v')], array_column($rows[1], 'callback_data'));
        self::assertSame('primary', $rows[1][0]['style'] ?? null, 'its colour kept');
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'still waiting for the answer');
    }

    public function testAConfirmationIsGreenOrRedAndSaysWhereItWasAsked(): void
    {
        $this->owner(UserRole::Admin);
        $plan = $this->plan(['name' => 'شش‌ماهه'], $this->sellingServer());

        $this->send($this->tap(Nav::to('plv', 'show', $plan->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('plv', 'del', $plan->id), chat: self::OWNER));
        self::assertStringContainsString('<i>مدیریت › فروشگاه › پلن‌ها › شش‌ماهه</i>', $this->said()[0], 'the path of the card it was asked from');
        self::assertSame([[
            ['text' => Words::CANCEL, 'callback_data' => Nav::to('plv', 'show', $plan->id)],
            ['text' => '🗑 بله، حذف شود', 'callback_data' => Nav::to('plv', 'gone', $plan->id), 'style' => 'danger'],
        ]], $this->inlineKeyboard(0), 'a delete red, the yes on the right');

        $this->send($this->tap(Nav::to('bsg', 'e', 1, 1), chat: self::OWNER));
        self::assertSame('success', $this->inlineKeyboard(0)[0][1]['style'] ?? null, 'a switch turned on: green');
        $this->send($this->tap(Nav::to('bsg', 'e', 0, 0), chat: self::OWNER));
        self::assertSame('danger', $this->inlineKeyboard(0)[0][1]['style'] ?? null, 'the bot turned off: red');
        self::assertSame(Words::CANCEL, $this->inlineKeyboard(0)[0][0]['text'] ?? null);
        self::assertTrue($this->service(BotSettingsScreen::class)->present()['enabled'], 'asked, nothing changed');
    }

    public function testAnOldWindowsButtonOpensTheHomeSayingSo(): void
    {
        $this->owner(UserRole::Admin);
        foreach (['a:gone:show', 'a:h', 'a::show'] as $data) {
            $this->send($this->tap($data, chat: self::OWNER));
            $said = $this->said()[0] ?? '';
            self::assertStringStartsWith('<b>⚠️ ' . Words::OLD_BUTTON . "</b>\n\n<b>🏠 مدیریت فروشگاه</b>", $said, "«{$data}»: the home, saying so");
            self::assertContains('editMessageText', $this->calls(), "«{$data}»: in place");
        }

        // A seat that may not open the screen: the same — an agent at the owner's servers.
        $this->sendTo($this->agentBot(), $this->tap(Nav::to('sv'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringStartsWith('<b>⚠️ ' . Words::OLD_BUTTON . '</b>', $this->said()[0] ?? '');
    }

    public function testDataNoButtonCarriesOpensTheHomeAndNeverBreaksAList(): void
    {
        $this->owner(UserRole::Admin);

        // What Nav::to() never makes — a query, a path, a word — reaches no screen.
        foreach (['a:pyv:show:1?status=paid', 'a:pyv:show:../1', 'a:py:f:status:همه'] as $data) {
            $this->send($this->tap($data, chat: self::OWNER));
            self::assertStringStartsWith('<b>⚠️ ' . Words::OLD_BUTTON . "</b>\n\n<b>🏠 مدیریت فروشگاه</b>", $this->said()[0] ?? '', "«{$data}»: the home, saying so");
        }

        // A page past any list's: its last page, and the list opens as ever after it.
        $this->send($this->tap('a:py:page:' . str_repeat('9', 25), chat: self::OWNER));
        self::assertStringStartsWith('<b>💳 ', $this->said()[0] ?? '');
        $this->send($this->tap(Nav::to('py'), chat: self::OWNER));
        self::assertStringStartsWith('<b>💳 ', $this->said()[0] ?? '', 'the list again');
    }

    public function testTheHomeSaysTodayAndOpensEachQueueAsItCountedIt(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['first_name' => 'Ali']);
        $card = $this->cardMethod();
        $waiting = $this->receipt($this->cardPayment($this->topUpOrder($ali, '50000.00'), $card));
        $bought = $this->buy($ali, $this->plan([], $server = $this->sellingServer()), $server);
        $this->ticket($ali);
        $this->review($ali);

        $this->send($this->message('/admin', chat: self::OWNER));
        $home = $this->said()[0];
        self::assertStringContainsString("<b>📅 امروز</b>\n<blockquote>درآمد: <b>", $home, 'today\'s card');
        self::assertStringContainsString('سفارش‌ها: <b>۲</b>', $home, 'the top-up and the purchase, ordered today');
        self::assertStringContainsString('<b>🔔 در انتظار</b> · ۳ کار', $home);
        $callbacks = $this->callbacks(0);
        foreach (['payments_to_review', 'open_tickets', 'pending_reviews'] as $queue) {
            self::assertContains(Nav::to(Screens::HOME, 'q', $queue), $callbacks, "{$queue}: a button straight to it");
        }
        foreach (['stuck_orders', 'held_payments', 'servers_with_errors'] as $queue) {
            self::assertNotContains(Nav::to(Screens::HOME, 'q', $queue), $callbacks, "{$queue}: nothing waits, no button");
        }
        self::assertContains('🧾 رسیدها (۱)', $this->buttonLabels(0));
        self::assertContains('🎧 پشتیبانی', $this->buttonLabels(0), 'the menu without the counts the queues already say');
        self::assertContains(Nav::to(Screens::SEARCH), $callbacks);
        self::assertContains(Nav::to('shop'), $callbacks, 'the owner\'s shop switcher');
        self::assertSame([['text' => Words::REFRESH, 'callback_data' => Nav::to(Screens::HOME)]], $this->inlineKeyboard(0)[count($this->inlineKeyboard(0)) - 1]);

        $this->send($this->tap(Nav::to(Screens::HOME, 'q', 'payments_to_review'), chat: self::OWNER));
        self::assertContains(Nav::to('pyv', 'show', $waiting->id), $this->callbacks(0), 'the receipt waiting');
        self::assertNotContains(Nav::to('pyv', 'show', (int) Payment::query()->where('order_id', $bought->id)->value('id')), $this->callbacks(0), 'filtered as counted: the paid one left out');
    }

    public function testTheHomeWithoutTheDashboardStillSaysWhatWaits(): void
    {
        $this->owner(UserRole::Admin);
        $this->receipt($this->cardPayment($this->topUpOrder($this->customer(['first_name' => 'Ali']), '50000.00'), $this->cardMethod()));
        $this->swap(DashboardController::class, new class {
            public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return Json::error($response, 'داشبورد در دسترس نیست.', 503);
            }
        });

        $this->send($this->message('/admin', chat: self::OWNER));
        $home = $this->said()[0];
        self::assertStringNotContainsString('📅 امروز', $home, 'no figures, no card of them');
        self::assertStringContainsString('<b>🔔 در انتظار</b> · ۱ کار', $home, 'what waits, from the queues');
        self::assertContains(Nav::to(Screens::HOME, 'q', 'payments_to_review'), $this->callbacks(0));
    }

    public function testTheSearchFindsACustomerAndTheirOrdersPaymentsAndServicesAtOnce(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['first_name' => 'Ali', 'username' => 'ali_r', 'phone' => '+989121234567']);
        $order = $this->buy($ali, $this->plan([], $server = $this->sellingServer()), $server);
        $payment = (int) Payment::query()->where('order_id', $order->id)->value('id');
        $service = (int) Subscription::query()->where('user_id', $ali->id)->value('id');

        $this->send($this->tap(Nav::to(Screens::SEARCH), chat: self::OWNER));
        self::assertStringContainsString('<b>✍️ جستجو</b>', $this->said()[0]);
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'waiting for the value');

        $this->send($this->message('@ali_r', chat: self::OWNER));
        $found = $this->callbacks($this->windowCall());
        foreach ([Nav::to('usv', 'show', $ali->id), Nav::to('orv', 'show', $order->id), Nav::to('pyv', 'show', $payment), Nav::to('sbv', 'show', $service)] as $card) {
            self::assertContains($card, $found);
        }
        self::assertStringContainsString('🔎 «' . Fmt::ltr('@ali_r') . '» · ۱ مشتری · ۱ سفارش · ۱ پرداخت · ۱ سرویس', implode("\n", $this->said()));

        // A phone typed the Iranian way, in Persian digits.
        $this->send($this->tap(Nav::to(Screens::SEARCH), chat: self::OWNER));
        $this->send($this->message('۰۹۱۲ ۱۲۳ ۴۵۶۷', chat: self::OWNER));
        self::assertContains(Nav::to('usv', 'show', $ali->id), $this->callbacks($this->windowCall()));

        // More of a kind than shown: the whole list, searched.
        foreach (range(1, 5) as $n) {
            $this->topUpOrder($ali, (string) (10_000 * $n));
        }
        $this->send($this->tap(Nav::to(Screens::SEARCH), chat: self::OWNER));
        $this->send($this->message('ali_r', chat: self::OWNER));
        self::assertContains('📋 همه ۶ سفارش', $this->buttonLabels($this->windowCall()));
        $this->send($this->tap(Nav::to(Screens::SEARCH, 'all', 'or'), chat: self::OWNER));
        self::assertStringContainsString('🔎 «ali_r»', $this->said()[0], 'the orders list, searched');

        $this->send($this->tap(Nav::to(Screens::SEARCH), chat: self::OWNER));
        $this->send($this->message('nobody-at-all', chat: self::OWNER));
        self::assertStringContainsString(Words::NOTHING_FOUND, implode("\n", $this->said()));
    }

    public function testASettingsCardSaysEachValueOnceAndItsButtonsOnlyWhatTheyDo(): void
    {
        $this->owner(UserRole::Admin);
        $this->botSettings('wallet', ['topup_min' => 10_000]);

        $this->send($this->tap(Nav::to('bsw'), chat: self::OWNER));
        self::assertStringContainsString('<blockquote>حداقل مبلغ شارژ: <b>' . Fmt::money('10000') . '</b>', $this->said()[0], 'the value in the summary');
        self::assertContains(Words::edit('حداقل مبلغ شارژ'), $this->buttonLabels(0));
        foreach ($this->buttonLabels(0) as $label) {
            self::assertStringNotContainsString('تومان', $label, 'no value on a button');
        }
        self::assertSame([Nav::to('bsw', 'e', 1), Nav::to('bsw', 'e', 0)], array_column($this->inlineKeyboard(0)[0], 'callback_data'), 'two short ones a row, the first on the right');

        $this->send($this->tap(Nav::to('bsg'), chat: self::OWNER));
        self::assertStringContainsString('عنوان پیام پشتیبانی: <b>' . Words::ON . '</b>', $this->said()[0], 'a switch as its chip');
        self::assertContains(['text' => '⏸ خاموش کردن «عنوان پیام پشتیبانی»', 'callback_data' => Nav::to('bsg', 'e', 9, 0)], array_merge(...$this->inlineKeyboard(0)), 'a switch says the state a tap sets, and carries it');

        $this->send($this->tap(Nav::to('bsg', 'e', 9, 0), chat: self::OWNER));
        self::assertFalse($this->service(BotSettingsScreen::class)->present()['support_header'], 'saved through the panel\'s route');
        self::assertStringContainsString('ذخیره شد', (string) $this->popup());
        self::assertContains('▶️ روشن کردن «عنوان پیام پشتیبانی»', $this->buttonLabels($this->windowCall()));
    }

    public function testAFieldsButtonThatNamesNoFieldChangesNoFirstField(): void
    {
        $this->owner(UserRole::Admin);

        // The bot's own switch is field 0: data naming no field neither asks to turn it off nor opens field 0's question.
        $this->send($this->tap('a:bsg:e', chat: self::OWNER));
        self::assertStringStartsWith('<b>⚙️ عمومی</b>', $this->said()[0] ?? '', 'the card, nothing asked');
        $this->send($this->tap('a:bsw:e', chat: self::OWNER));
        self::assertStringStartsWith('<b>' . Icons::WALLET . ' ', $this->said()[0] ?? '', 'the card');
        self::assertNotSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'no question waits');
        self::assertTrue($this->service(BotSettingsScreen::class)->present()['enabled']);
    }

    public function testAHubSaysWhatIsInItAndLaysItsEntriesRightToLeft(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('hl'), chat: self::OWNER));
        self::assertStringContainsString("<b>🛍 فروش</b>\n<i>مدیریت</i>\nکارهای روزانه فروش", $this->said()[0]);
        self::assertSame([Nav::to('or'), Nav::to('py')], array_column($this->inlineKeyboard(0)[0], 'callback_data'), '«💳 پرداخت‌ها» first, on the right');
        self::assertSame([['text' => Words::HOME, 'callback_data' => Nav::to(Screens::HOME)]], $this->inlineKeyboard(0)[count($this->inlineKeyboard(0)) - 1], 'its way back is the home');
    }

    /**
     * `$work` with `$screen` opened at `$id` as the management opens any screen: among the screens made (Screens), for
     * this test alone.
     */
    private function opening(string $id, Screen $screen, \Closure $work): void
    {
        $made = new \ReflectionProperty(Screens::class, 'made');
        $screens = $this->service(Screens::class);
        $made->setValue($screens, [$id => $screen] + (array) $made->getValue($screens));
        try {
            $work();
        } finally {
            $left = (array) $made->getValue($screens);
            unset($left[$id]);
            $made->setValue($screens, $left);
        }
    }

    /**
     * The tags `$html` leaves open (or closes out of order): none for HTML Telegram takes.
     *
     * @return list<string>
     */
    private static function unclosed(string $html): array
    {
        $open = [];
        preg_match_all('~<(/?)([a-z][a-z0-9-]*)\b[^>]*>~i', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            if ($tag[1] === '') {
                $open[] = $tag[2];
            } elseif (array_pop($open) !== $tag[2]) {
                return ['</' . $tag[2] . '> out of order'];
            }
        }

        return $open;
    }
}
