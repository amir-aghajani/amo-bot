<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Update\ReceivedUpdates;
use App\Modules\Users\Enums\UserRole;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «📦 پلن‌ها» in the bot's management (Telegram\Admin\Sections\Catalog): the list by category, a plan's page and what its
 * row menu does, the plan form as a wizard — a new plan asked field by field, an edit from its summary, one save of the
 * whole draft, a refused save kept —, its servers and own extras prices, the bulk price change previewed then applied,
 * and the catalogue's grant for the shop's admins.
 */
final class CatalogPlansTest extends BotTestCase
{
    private const STAFF = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnerBrowsesThePlansByCategoryAndOpensOne(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $monthly = $this->category(['name' => 'ماهانه']);
        $plan = $this->plan(['name' => 'یک‌ماهه', 'category_id' => $monthly->id, 'first_purchase_price' => '90000.00'], $server);
        $other = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00'], $server);

        $this->tapAs(Nav::to('hs'));
        self::assertContains(Nav::to('pl'), $this->callbacks(0));
        self::assertContains(Nav::to('ct'), $this->callbacks(0));

        $this->tapAs(Nav::to('pl'));
        self::assertContains('🟢 یک‌ماهه · ۱۲۰٬۰۰۰ تومان', $this->buttonLabels(0), 'a plan\'s button is its row: chip, name, price');
        self::assertContains('🟢 سه‌ماهه · ۳۰۰٬۰۰۰ تومان', $this->buttonLabels(0));
        self::assertStringContainsString('۲ پلن', $this->said()[0]);
        self::assertStringNotContainsString('یک‌ماهه', $this->said()[0], 'the text never repeats the rows');
        self::assertContains(Nav::to('pl', 'cat', $monthly->id), $this->callbacks(0), 'a chip per category');
        self::assertContains(Nav::to('ple', 'new'), $this->callbacks(0));
        self::assertContains(Nav::to('plp', 'new'), $this->callbacks(0));

        $this->tapAs(Nav::to('pl', 'cat', $monthly->id));
        self::assertContains(Nav::to('plv', 'show', $plan->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('plv', 'show', $other->id), $this->callbacks(0), 'narrowed to the category');

        $this->tapAs(Nav::to('plv', 'show', $plan->id));
        $page = $this->said()[0];
        self::assertStringContainsString('ماهانه', $page);
        self::assertStringContainsString('۹۰٬۰۰۰ تومان', $page, 'the first-purchase price');
        self::assertStringContainsString('آلمان', $page, 'its server');
        self::assertContains(Nav::to('plv', 'tog', $plan->id, 0), $this->callbacks(0), 'the switch carries what it sets');
        foreach (['dup', 'del'] as $action) {
            self::assertContains(Nav::to('plv', $action, $plan->id), $this->callbacks(0));
        }
        self::assertContains(Nav::to('ple', 'edit', $plan->id), $this->callbacks(0));
    }

    public function testThePlansAreSearchedByNameAndAnUnseenPlanIsFlagged(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $this->plan(['name' => 'یک‌ماهه'], $server);
        $quarterly = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00'], $server);
        $bare = $this->plan(['name' => 'بی‌سرور', 'price' => '50000.00']);

        $this->tapAs(Nav::to('pl'));
        self::assertContains('⚠️ بی‌سرور · ۵۰٬۰۰۰ تومان', $this->buttonLabels(0), 'an active plan the bot does not show: its chip says so');
        self::assertStringContainsString('۱ پلن فعال در ربات دیده نمی‌شود', $this->said()[0]);

        $this->tapAs(Nav::to('pl', 'search'));
        self::assertStringContainsString('چه پلنی؟', $this->said()[0]);
        $this->sayAs('سه');
        $offered = $this->offered();
        self::assertContains(Nav::to('plv', 'show', $quarterly->id), $offered);
        self::assertNotContains(Nav::to('plv', 'show', $bare->id), $offered, 'narrowed to the name searched');
        self::assertStringContainsString('۱ پلن · 🔎 «سه»', implode("\n", $this->said()));
        self::assertContains(Nav::to('pl', 'clear'), $offered);

        $this->tapAs(Nav::to('pl', 'clear'));
        self::assertContains(Nav::to('plv', 'show', $bare->id), $this->callbacks(0), 'every plan again');
    }

    public function testANewPlanIsAskedFieldByFieldAndMadeInOneSave(): void
    {
        $this->owner();
        $server = $this->sellingServer('هلند');

        $this->tapAs(Nav::to('ple', 'new'));
        self::assertStringContainsString('نام پلن', $this->said()[0]);
        $this->sayAs('یک‌ماهه ۳۰ گیگ');
        self::assertStringContainsString('قیمت', $this->said()[0], 'no category to ask: the price next');
        $this->sayAs('۱۲۰ هزار تومان');
        self::assertStringContainsString('حجم', $this->said()[0]);
        $this->tapAs(Nav::to('ple', 'v', 'traffic_gb', '30'));
        self::assertStringContainsString('مدت اعتبار', $this->said()[0]);
        $this->sayAs('۱۲ ساعت');
        self::assertStringContainsString('تعداد کاربر', $this->said()[0]);
        $this->tapAs(Nav::to('ple', 'v', 'ip_limit', '2'));

        self::assertStringContainsString('سرورهای پلن', $this->said()[0]);
        $this->tapAs(Nav::to('pls', 'add'));
        $this->tapAs(Nav::to('pls', 'pick', $server->id));
        $this->tapAs(Nav::to('pls', 'all', $server->id));
        self::assertStringContainsString('کل سرور', $this->said()[0]);
        $this->tapAs(Nav::to('ple', 'next'));

        self::assertStringContainsString('توضیحات', $this->said()[0]);
        $this->tapAs(Nav::to('ple', 'skip', 'description'));
        $this->sayAs('۹۰۰۰۰');
        $this->tapAs(Nav::to('ple', 'skip', 'agent_price'));
        self::assertStringContainsString('پلن تازه', $this->said()[0], 'the summary');
        self::assertStringContainsString('۱۲ ساعت', $this->said()[0]);
        self::assertSame(0, Plan::query()->count(), 'nothing saved before the save');

        $this->tapAs(Nav::to('ple', 'save'));
        $plan = Plan::query()->sole();
        self::assertSame(['یک‌ماهه ۳۰ گیگ', '120000.00', '90000.00', 12, 2, true], [$plan->name, $plan->price, $plan->first_purchase_price, $plan->duration_hours, $plan->ip_limit, $plan->is_active]);
        self::assertSame(30.0, (float) $plan->traffic_gb);
        self::assertSame([$server->id], $plan->servers()->pluck('server_id')->all());
        self::assertStringContainsString('پلن اضافه شد', (string) $this->popup());
        self::assertStringContainsString('یک‌ماهه ۳۰ گیگ', $this->said()[0], 'the plan\'s page');
    }

    public function testAServersManyInboundsArePickedByPages(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        foreach (range(2, 45) as $n) {
            $last = $this->inbound($server, (string) $n);
        }
        $this->tapAs(Nav::to('ple', 'new'));
        $this->sayAs('چند اینباندی');

        $this->tapAs(Nav::to('pls', 'in', $server->id));
        self::assertLessThan(100, count($this->callbacks(0)), 'within Telegram\'s keyboard');
        self::assertContains(Nav::to('pls', 'ip', 2), $this->callbacks(0));
        self::assertNotContains(Nav::to('pls', 't', $last->id), $this->callbacks(0), 'a page at a time');

        $this->tapAs(Nav::to('pls', 'ip', 2));
        self::assertContains(Nav::to('pls', 't', $last->id), $this->callbacks(0));
        $this->tapAs(Nav::to('pls', 't', $last->id));
        self::assertContains(Nav::to('pls', 't', $last->id), $this->callbacks(0), 'ticked, still on its page');
        $this->tapAs(Nav::to('pls', 'ok'));
        self::assertStringContainsString('آلمان · ۱ اینباند انتخاب‌شده', $this->said()[0], 'the inbound of the second page on the plan');
    }

    public function testAnEditChangesWhatWasEditedAndKeepsTheRest(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $inbound = $this->inbound($server, '2');
        $plan = $this->plan(['agent_price' => '100000.00', 'badge' => 'vip'], $server);
        $plan->servers()->delete();
        $this->planEntry($plan, $server, [$inbound->id]);

        $this->tapAs(Nav::to('ple', 'edit', $plan->id));
        self::assertStringContainsString('ویرایش', $this->said()[0]);
        $this->tapAs(Nav::to('ple', 'sum', 'quota'));
        self::assertContains(Nav::to('ple', 'f', 'price'), $this->callbacks(0));

        $this->tapAs(Nav::to('ple', 'f', 'price'));
        self::assertStringContainsString('۱۲۰٬۰۰۰ تومان', $this->said()[0], 'the price it has');
        $this->sayAs('150000');
        $this->tapAs(Nav::to('ple', 'v', 'badge', 'none'));
        $this->tapAs(Nav::to('ple', 'f', 'button_style'));
        $this->tapAs(Nav::to('ple', 'v', 'button_style', 'success'));
        self::assertSame('120000.00', $plan->refresh()->price, 'a draft until the save');

        $this->tapAs(Nav::to('ple', 'save'));
        $plan->refresh();
        self::assertSame(['150000.00', '100000.00', null, 'success'], [$plan->price, $plan->agent_price, $plan->badge, $plan->button_style]);
        self::assertSame([$inbound->id], $plan->servers()->sole()->inbounds()->pluck('server_inbounds.id')->all(), 'its chosen inbound kept');
    }

    public function testAQuestionSaysTheValueNowWholeToCopyBack(): void
    {
        $this->owner();
        $long = str_repeat('برای استفاده روزانه و تماشای ویدیو مناسب است. ', 4);
        $plan = $this->plan(['description' => trim($long)], $this->sellingServer());

        $this->tapAs(Nav::to('ple', 'edit', $plan->id));
        $this->tapAs(Nav::to('ple', 'f', 'description'));
        self::assertStringContainsString('مقدار فعلی: <code>' . trim($long) . '</code>', $this->said()[0], 'the whole text, not the summary\'s cut');
        self::assertStringContainsString('مثال: ', $this->said()[0]);
        self::assertContains(Nav::to('ple', 'x', 'description'), $this->callbacks(0), '«🗑 خالی کردن» for a field that may be empty');
    }

    public function testARefusedSaveKeepsTheDraftOnTheTabToFix(): void
    {
        $this->owner();
        $this->sellingServer();

        $this->tapAs(Nav::to('ple', 'new'));
        $this->sayAs('پلن بی‌قیمت');
        $this->tapAs(Nav::to('ple', 'sum'));
        $this->tapAs(Nav::to('ple', 'save'));

        self::assertSame(0, Plan::query()->count());
        self::assertStringContainsString('ذخیره نشد', (string) $this->popup());
        self::assertStringContainsString('⚠️ قیمت', $this->said()[0], 'each refusal over its field');
        self::assertContains(Nav::to('ple', 'f', 'price'), $this->offered(), 'the first tab with something to fix');
        self::assertStringContainsString('پلن بی‌قیمت', $this->said()[0], 'the draft kept');

        $this->tapAs(Nav::to('ple', 'f', 'price'));
        $this->sayAs('رایگان نیست');
        self::assertStringContainsString('مبلغ را به تومان', $this->said()[0], 'a typed answer the form cannot read is asked again');
    }

    public function testTheSwitchACopyAPlaceAndADelete(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $first = $this->plan(['name' => 'اول'], $server);
        $second = $this->plan(['name' => 'دوم'], $server);
        $sold = $this->plan(['name' => 'فروخته'], $server);
        $this->subscription($this->customer(), $sold, $server, 'ali_1');

        $this->tapAs(Nav::to('plv', 'tog', $first->id, 0));
        self::assertFalse($first->refresh()->is_active);
        self::assertSame('⏸ غیرفعال شد', $this->popup());
        self::assertContains(Nav::to('plv', 'tog', $first->id, 1), $this->offered());
        $this->tapAs(Nav::to('plv', 'tog', $first->id, 0));
        self::assertFalse($first->refresh()->is_active, 'a second tap sets it the same: never flipped back');

        $this->tapAs(Nav::to('plv', 'down', $first->id));
        self::assertSame(['دوم', 'اول', 'فروخته'], Plan::query()->oldest('sort')->pluck('name')->all());

        $this->tapAs(Nav::to('plv', 'dup', $second->id));
        $copy = Plan::query()->where('name', 'دوم (کپی)')->sole();
        self::assertFalse($copy->is_active, 'made switched off');
        self::assertStringContainsString('ویرایش «دوم (کپی)»', $this->said()[0], 'opened in its form at once');

        $this->tapAs(Nav::to('plv', 'del', $sold->id));
        self::assertStringContainsString('برای حفظ تاریخچه حذف نمی‌شود', $this->said()[0]);
        self::assertNotContains(Nav::to('plv', 'gone', $sold->id), $this->callbacks(0));

        $this->tapAs(Nav::to('plv', 'del', $second->id));
        self::assertContains(Nav::to('plv', 'gone', $second->id), $this->callbacks(0), 'asked first');
        $this->tapAs(Nav::to('plv', 'gone', $second->id));
        self::assertNull(Plan::query()->find($second->id));
    }

    public function testTheBulkPriceChangeIsPreviewedThenAppliedAsPreviewed(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $cheap = $this->plan(['name' => 'ارزان', 'price' => '50000.00', 'agent_price' => '45000.00'], $server);
        $dear = $this->plan(['name' => 'گران', 'price' => '200000.00'], $server);

        $this->tapAs(Nav::to('plp', 'new'));
        self::assertNull($this->applyOffered(), 'nothing applied before a preview');
        $this->tapAs(Nav::to('plp', 'val'));
        $this->sayAs('۱۰');
        $this->tapAs(Nav::to('plp', 'pv'));
        self::assertStringContainsString('قیمت ۲ پلن تغییر می‌کند', $this->said()[0]);
        self::assertStringContainsString('۵۵٬۰۰۰ تومان', $this->said()[0]);
        $first = $this->applyOffered();
        self::assertNotNull($first);

        $this->tapAs(Nav::to('plp', 'rnd', 1));
        self::assertNull($this->applyOffered(), 'a change drops the preview');
        $this->tapAs(Nav::to('plp', 'pv'));
        $apply = $this->applyOffered();
        self::assertNotNull($apply);
        self::assertNotSame($first, $apply, 'the apply names the preview it is drawn under');
        $this->tapAs($apply);

        self::assertSame(['55000.00', '45000.00', '220000.00'], [$cheap->refresh()->price, $cheap->agent_price, $dear->refresh()->price]);
        self::assertStringContainsString('قیمت ۲ پلن تغییر کرد', (string) $this->popup());

        $this->tapAs($apply);
        self::assertSame(['55000.00', '220000.00'], [$cheap->refresh()->price, $dear->refresh()->price], 'a second tap applies nothing again');
    }

    public function testAnApplyLeftFromAnotherPreviewMakesNothing(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $cheap = $this->plan(['name' => 'ارزان', 'price' => '50000.00'], $server);
        $dear = $this->plan(['name' => 'گران', 'price' => '200000.00'], $server);

        $this->tapAs(Nav::to('plp', 'new'));
        $this->tapAs(Nav::to('plp', 'val'));
        $this->sayAs('۱۰');
        $this->tapAs(Nav::to('plp', 'pv'));
        $old = $this->applyOffered();
        // A window kept from then (one Telegram did not let the bot take away), while the change is previewed anew.
        $this->tapAs(Nav::to('plp', 'val'));
        $this->sayAs('۲۰');
        $this->tapAs(Nav::to('plp', 'pv'));
        $new = $this->applyOffered();
        self::assertNotNull($old);
        self::assertNotNull($new);
        self::assertNotSame($old, $new);

        $this->tapAs($old);
        self::assertSame(['50000.00', '200000.00'], [$cheap->refresh()->price, $dear->refresh()->price], 'the +10% the old window showed is not the +20% previewed now: nothing made');
        self::assertStringContainsString('پیش‌نمایش عوض شده است', (string) $this->popup());
        self::assertStringContainsString('۲۴۰٬۰۰۰ تومان', implode("\n", $this->said()), 'the preview in force shown');
        self::assertSame($new, $this->applyOffered(), 'to apply from it');

        $this->tapAs(Nav::to('plp', 'ok'));
        self::assertSame(['50000.00', '200000.00'], [$cheap->refresh()->price, $dear->refresh()->price], 'an older version\'s apply, naming no preview, makes nothing');

        $this->tapAs($new);
        self::assertSame(['60000.00', '240000.00'], [$cheap->refresh()->price, $dear->refresh()->price]);
    }

    public function testEveryChoiceDropsThePreview(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $plan = $this->plan(['name' => 'ارزان', 'price' => '50000.00'], $server);
        $this->plan(['name' => 'گران', 'price' => '200000.00'], $server);

        $choices = [
            'a plan ticked off' => Nav::to('plp', 'tg', $plan->id, 1),
            'every plan ticked off' => Nav::to('plp', 'all', 1),
            'the direction' => Nav::to('plp', 'dir', 'decrease'),
            'the kind' => Nav::to('plp', 'mode', 'amount'),
            'the rounding' => Nav::to('plp', 'rnd', 100),
            'the prices moved' => Nav::to('plp', 'pr', 'agent_price'),
            'the value typed' => null,
        ];
        foreach ($choices as $what => $choice) {
            // Each round past the chat's pace of taps (ReceivedUpdates::FLOOD_UPDATES), as an owner trying them would be.
            Carbon::setTestNow(now()->addSeconds(ReceivedUpdates::FLOOD_SECONDS + 1));
            $this->tapAs(Nav::to('plp', 'new'));
            $this->tapAs(Nav::to('plp', 'val'));
            $this->sayAs('۱۰');
            $this->tapAs(Nav::to('plp', 'pv'));
            self::assertNotNull($this->applyOffered(), "previewed before {$what}");
            if ($choice === null) {
                $this->tapAs(Nav::to('plp', 'val'));
                $this->sayAs('۱۵');
            } else {
                $this->tapAs($choice);
                $this->tapAs(Nav::to('plp'));
            }
            self::assertNull($this->applyOffered(), "{$what} drops the preview");
            self::assertStringNotContainsString('<b>👁 پیش‌نمایش</b>', implode("\n", $this->said()), "{$what}: no preview shown");
        }
        self::assertSame('50000.00', $plan->refresh()->price, 'nothing applied');
    }

    public function testAChangeWhosePricesMovedSinceItsPreviewIsRefusedAndThePreviewStays(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $cheap = $this->plan(['name' => 'ارزان', 'price' => '50000.00'], $server);
        $dear = $this->plan(['name' => 'گران', 'price' => '200000.00'], $server);

        $this->tapAs(Nav::to('plp', 'new'));
        $this->tapAs(Nav::to('plp', 'val'));
        $this->sayAs('۱۰');
        $this->tapAs(Nav::to('plp', 'pv'));
        // Meanwhile the change is made once already (another tap at once, the panel): the prices are not the previewed.
        $dear->forceFill(['price' => '220000.00'])->save();

        $this->tapAs((string) $this->applyOffered());
        self::assertSame(['50000.00', '220000.00'], [$cheap->refresh()->price, $dear->refresh()->price], 'nothing moved twice, nothing moved at all');
        $window = $this->said()[0];
        self::assertStringContainsString('اعمال نشد', $window);
        self::assertStringContainsString('پلن «گران»: قیمتش بعد از پیش‌نمایش تغییر کرده است', $window, 'why, in the window');
        self::assertStringContainsString('۲۰۰٬۰۰۰ تومان ← ', $window, 'the preview kept');
        self::assertNull($this->applyOffered());

        $this->tapAs(Nav::to('plp', 'pv'));
        self::assertStringNotContainsString('اعمال نشد', $this->said()[0]);
        $this->tapAs((string) $this->applyOffered());
        self::assertSame(['55000.00', '242000.00'], [$cheap->refresh()->price, $dear->refresh()->price], 'previewed again, made on the prices as they are');
    }

    public function testALongPreviewGoesByPagesTheProblemsFirst(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        foreach (range(1, 9) as $n) {
            $this->plan(['name' => "پلن {$n}", 'price' => '100000.00'], $server);
        }
        $this->plan(['name' => 'ویژه', 'price' => '100000.00', 'agent_price' => '90000.00'], $server);

        $this->tapAs(Nav::to('plp', 'new'));
        $this->tapAs(Nav::to('plp', 'dir', 'decrease'));
        $this->tapAs(Nav::to('plp', 'val'));
        $this->sayAs('۵۰');
        $this->tapAs(Nav::to('plp', 'pv'));
        $window = $this->said()[0];
        self::assertContains('۱ / ۲', $this->buttonLabels(0), 'the page in the pager, not the text');
        self::assertStringContainsString('ویژه', $window, 'the plan that cannot take it first');
        self::assertStringContainsString('قیمت نماینده دیگر کمتر از قیمت پلن نمی‌ماند', $window);
        self::assertStringNotContainsString('پلن 8', $window, 'a page at a time');
        self::assertContains(Nav::to('plp', 'pg', 2), $this->callbacks(0));
        self::assertNull($this->applyOffered(), 'held while a plan cannot take it');

        $this->tapAs(Nav::to('plp', 'pg', 2));
        self::assertStringContainsString('پلن 8', $this->said()[0]);
        self::assertContains('۲ / ۲', $this->buttonLabels(0));
    }

    public function testThePlansOwnExtrasPricesAndAPremiumName(): void
    {
        $this->owner();
        $server = $this->sellingServer();
        $plan = $this->plan(['name' => '⭐ ویژه'], $server);

        $this->tapAs(Nav::to('ple', 'edit', $plan->id));
        $this->tapAs(Nav::to('ple', 'f', 'name'));
        $this->send($this->message('🔥 ویژه', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 1, 'custom_emoji_id' => '5368324170671202286']]], chat: self::OWNER));
        self::assertStringContainsString('✨ دارد', $this->said()[0], 'the premium emoji typed is the name\'s');

        $this->tapAs(Nav::to('plx'));
        $shop = Nav::to('bsx', 'show', 'from.plx');
        self::assertContains($shop, $this->callbacks(0), 'the shop\'s extras prices a tap away, saying where from');
        $this->tapAs($shop);
        self::assertContains(Nav::to('plx'), $this->callbacks(0), 'their «⬅️ بازگشت» is the plan\'s form');
        $this->tapAs(Nav::to('plx'));
        self::assertStringStartsWith('<b>🧩 خرید اضافه</b>', $this->said()[0], 'the draft kept: the edit is not stranded in the bot settings');
        $this->tapAs(Nav::to('plx', 'mode', 'own'));
        $this->tapAs(Nav::to('plx', 'f', 'traffic', 'price'));
        $this->sayAs('۳۰۰۰');
        $this->tapAs(Nav::to('plx', 'f', 'traffic', 'options'));
        $this->sayAs("10\n50 120000");
        self::assertStringContainsString('۳۰٬۰۰۰ تومان', $this->said()[0], 'ten at the unit price');
        self::assertStringContainsString('۱۲۰٬۰۰۰ تومان', $this->said()[0], 'fifty at its own');

        $this->tapAs(Nav::to('ple', 'save'));
        $plan->refresh();
        self::assertSame(['🔥 ویژه', '5368324170671202286'], [$plan->name, $plan->emoji_id]);
        self::assertIsArray($plan->extras);
        self::assertSame('3000.00', $plan->extras['traffic_price'] ?? null);
        self::assertCount(2, $plan->extras['traffic_options'] ?? []);
    }

    public function testAShopsAdminReadsThePlansAndChangesThemOnlyWithTheGrant(): void
    {
        $this->customer(['telegram_id' => self::STAFF, 'role' => UserRole::Admin]);
        $server = $this->sellingServer();
        $plan = $this->plan([], $server);

        $this->send($this->tap(Nav::to('pl'), chat: self::STAFF));
        self::assertContains(Nav::to('plv', 'show', $plan->id), $this->callbacks(0), 'the daily work reads the plans');
        self::assertNotContains(Nav::to('ple', 'new'), $this->callbacks(0));
        self::assertNotContains(Nav::to('plp', 'new'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('plv', 'show', $plan->id), chat: self::STAFF));
        self::assertNotContains(Nav::to('plv', 'tog', $plan->id, 0), $this->callbacks(0), 'no change offered');

        $this->send($this->tap(Nav::to('plv', 'tog', $plan->id, 0), chat: self::STAFF));
        self::assertSame(BotAdminDoor::NOT_GRANTED, $this->popup(), 'the door refuses it anyway');
        self::assertTrue($plan->refresh()->is_active);

        $this->send($this->tap(Nav::to('ple', 'new'), chat: self::STAFF));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'the form is the grant\'s');

        $this->botSettings('bot_admins', ['bot_admins_grant_catalog' => true]);
        $this->send($this->tap(Nav::to('plv', 'show', $plan->id), chat: self::STAFF));
        self::assertContains(Nav::to('plv', 'tog', $plan->id, 0), $this->callbacks(0));
        $this->send($this->tap(Nav::to('plv', 'tog', $plan->id, 0), chat: self::STAFF));
        self::assertFalse($plan->refresh()->is_active);
    }

    public function testAnAgentHasTheCatalogueOfTheirShopWithoutCustomServices(): void
    {
        $bot = $this->agentBot();

        $this->sendTo($bot, $this->tap(Nav::to('hs'), chat: self::AGENT_TELEGRAM_ID));
        self::assertContains(Nav::to('pl'), $this->callbacks(0));
        self::assertContains(Nav::to('ts'), $this->callbacks(0), 'the tests are their panel\'s');
        self::assertNotContains(Nav::to('cs'), $this->callbacks(0), 'custom services are the main shop\'s alone');

        $this->sendTo($bot, $this->tap(Nav::to('ple', 'new'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('یک‌ماهه', chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('۵۰۰۰۰', chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('از حجم نمایندگی کم می‌کند', $this->said()[0], 'an agent\'s plan sells their traffic');
        self::assertNotContains(Nav::to('ple', 'v', 'traffic_gb', '0'), $this->offered(), 'no unlimited traffic to sell');

        $this->sendTo($bot, $this->tap(Nav::to('tsr'), chat: self::AGENT_TELEGRAM_ID));
        self::assertSame([Nav::to('tsr'), Nav::to('h'), Nav::to('ts')], $this->callbacks(0), 'a server\'s terms are the owner\'s: the list only reads — «⬅️ بازگشت» on the right, «🏠 خانه» and «🔄» beside it');
    }

    public function testAnAgentsButtonNamingTheMainShopsPlanChangesNothing(): void
    {
        $plan = $this->plan([], $this->sellingServer());
        $bot = $this->agentBot();
        $plans = Plan::query()->withoutGlobalScopes()->count();

        foreach ([Nav::to('plv', 'tog', $plan->id, 0), Nav::to('plv', 'dup', $plan->id), Nav::to('plv', 'gone', $plan->id)] as $forged) {
            $this->sendTo($bot, $this->tap($forged, chat: self::AGENT_TELEGRAM_ID));
            self::assertNotNull($this->popup(), "{$forged}: reached the plan's screen and refused by the panel's route (the plan is not in the agent's shop)");
            self::assertNotContains('editMessageText', $this->calls(), "{$forged}: no screen drawn as if done");
        }

        $plan->refresh();
        self::assertTrue($plan->is_active, 'the main shop\'s plan is not the agent\'s to switch');
        self::assertSame($plans, Plan::query()->withoutGlobalScopes()->count(), 'nor to copy, nor to delete');
    }

    /** The «✔️ اعمال تغییر» the window offers now (it names its preview: `plp:ok:<digest>`); null for none. */
    private function applyOffered(): ?string
    {
        foreach ($this->offered() as $data) {
            if ($data === Nav::to('plp', 'ok') || str_starts_with($data, Nav::to('plp', 'ok') . ':')) {
                return $data;
            }
        }

        return null;
    }
}
