[English](../Internals-Commerce.md)

<div dir="rtl" lang="fa">

# ساختار داخلی: فروش

پلن‌ها و قیمت‌ها، تخفیف‌ها و جایزه‌ها، سفارش‌ها، پیش‌فاکتور، پرداخت‌ها و تسویه آن‌ها، کیف پول، زیرمجموعه‌گیری، نمایندگی
و خود کاربران — <code dir="ltr">app/Modules/{Catalog,Orders,Payments,Discounts,Rewards,Referrals,Agency,Users}</code>. این
صفحه می‌گوید این‌ها چطور کنار هم قرار می‌گیرند و چرا؛ آنچه مالک فروشگاه از هر کدام می‌بیند در صفحه خود آن است
([پلن‌ها](Plans.md)، [سفارش‌ها و پرداخت‌ها](Orders-And-Payments.md)، [تخفیف‌ها](Discounts.md)،
[زیرمجموعه‌گیری](Referrals.md)، …).

## ماژول‌ها

| ماژول | آنچه در اختیار دارد | اول این‌ها را باز کنید |
| --- | --- | --- |
| Catalog | پلن‌ها، دسته‌بندی‌ها، آنچه همین الان قابل فروش است | `PlanService`، `ServerSelector`، <code dir="ltr">PlanCategoryService::catalogue()</code> |
| Orders | چرخه عمر سفارش، تنها قیمت‌گذاری برنامه، کلیدهای درخواست | `OrderService`، `Pricing`، `DTO\Quote` |
| Payments | روش‌های پرداخت و درایورهای درگاه، تسویه، پیش‌فاکتور | `Checkout`، `PaymentService`، `PaymentActions` |
| Discounts | کدها، فروش‌های ویژه، تخفیف اختصاصی کاربر، سطوح وفاداری | `DiscountCodes`، `Discounts`، `Loyalty` |
| Rewards | دادن جایزه، کدهای هدیه، گردونه شانس، کش‌بک، قرعه‌کشی شبانه | <code dir="ltr">Rewards::give()</code>، `Cashback`، `LuckyWheel` |
| Referrals | کدهای دعوت، پورسانت‌ها، هدیه شروع، جایزه دعوت، هشدارهای زیرمجموعه‌گیری فیک | `ReferralService`، `ReferralGifts` |
| Agency | سطح‌های نمایندگی و درخواست‌ها، ربات‌های نماینده‌ها، حجم پیش‌خریدشده آن‌ها | `AgencyActions`، `TrafficPool`، `AgentBots` |
| Users | کاربران، دفتر تراکنش کیف پول، گروه‌ها، تصویرهای آپلودشده | `Customers`، `WalletService`، `UserActions` |

هر ردیف این ماژول‌ها مال یک فروشگاه است (`BelongsToBot`: فروشگاه ربات اصلی یا فروشگاه یک نماینده)، پس همان سرویس‌ها به
همه فروشگاه‌ها خدمت می‌کنند؛ سرورها، سطح‌های نمایندگی و دفتر تراکنش حجم نماینده‌ها مال کل نصب‌اند. اینکه کدام ماژول
می‌تواند نام کدام را بیاورد در نگاشت دقیقی در `tests/Unit/Core/LayersTest` آمده است. دو انتخاب که دانستنشان می‌ارزد:

- **پیش‌فاکتور در Payments است، نه در Orders.** پرداخت یعنی کسری کیف پول و قدم بعدی یک درگاه، روی `PaymentService`،
  که سفارش‌های پرداخت‌شده را به `OrderService` می‌سپارد. ماژول Orders هیچ سرویسی از Payments را صدا نمی‌زند.
- **وفاداری در Discounts است.** تخفیف یک سطح یکی از تخفیف‌های خودکار است؛ کش‌بک اضافه‌اش را
  `Rewards\Services\Cashback` می‌خواند. ماژول Rewards به Discounts وابسته است، هرگز برعکس.

## مسیر خرید

```text
catalogue ── PlanCategoryService::catalogue() → ServerSelector::offers()     what can be sold today
price ────── Pricing::purchase() → Quote                                    base, automatic discount, code
checkout ─── Checkout::pay()                                                one for the bot and the website
  order ──── OrderService::openPurchase() → open()                          find or make, under the user's row lock
  payment ── PaymentService::createForOrder()                               within the method's bounds
               wallet: settle() in one transaction (debit, payment → paid, order → paid), then settled()
               card:   a pending payment shown a card (PaymentCards::pick()); the receipt later → approve() → settle()
settled() ── ReferralService::reward() · OrderService::deliver() · ReferralService::purchased()
deliver() ── claim (paid → processing) → ProvisioningService → fulfilled() in the same transaction
               then OrderRewards::delivered(): cashback and loyalty, after the commit
```

ربات (`Telegram\Handlers\CheckoutScreen`) و وب‌سایت (`Store\Services\CustomerCheckout`) فقط `CheckoutResult` را نمایش
می‌دهند؛ هیچ‌کدام قاعده‌ای از خودش ندارد. تمدیدها، شارژهای کیف پول، حجم نماینده و خریدهای اضافه از همین راه می‌روند،
هر کدام با <code dir="ltr">open\*()</code> خودش.

## قیمت‌گذاری

تنها جایی که قیمت حساب می‌شود `Orders\Services\Pricing` است — ربات، وب‌سایت و تمدید خودکار همه از آن می‌پرسند:

1. **قیمت پایه** — قیمت پلن برای این کاربر (`Buyer`): قیمت خودش، قیمت خرید اولش تا وقتی کاربر چیزی نخریده
   (<code dir="ltr">Order::boughtBy()</code>، پایین‌تر)، قیمت
   نماینده‌اش برای یک نماینده یا یک گروه «همکار»، هر کدام کمتر باشد؛ قیمت یک خرید اضافه همان‌طور که پیشنهاد می‌شود
   (همراه مرحله ۲، <code dir="ltr">Pricing::extraOffer()</code>، که دکمه‌های خرید اضافه ربات هم همان را نشان می‌دهند)؛
   برای سرویسی که کاربر می‌سازد، حجم (گیگ) و روزهایش هر کدام به‌طور کامل با نرخ پله خودش قیمت می‌خورند و این دو جمع
   می‌شوند (<code dir="ltr">custom()</code>، پایین‌تر).
2. **تخفیف خودکار** — بزرگ‌ترین از میان فروش ویژه در جریان، تخفیف اختصاصی کاربر و تخفیف سطح وفاداری‌اش
   (<code dir="ltr">AutomaticDiscounts::best()</code>). هرگز با هم جمع نمی‌شوند — تصمیم مالک فروشگاه: یک تخفیف خودکار، بعد
   یک کد.
3. **کد** — روی آنچه مرحله ۲ باقی گذاشته اعمال می‌شود (<code dir="ltr">DiscountCodes::apply()</code>).

مبلغ‌ها تومان کامل‌اند و هرگز زیر صفر نمی‌روند؛ سفارشی با مبلغ صفر «رایگان» است و ردیف کیف پول فروشگاه آن را تسویه
می‌کند، هر روشی که انتخاب شده باشد. شارژ کیف پول و حجم نماینده‌ها هرگز تخفیف نمی‌گیرند (`OrderType::FOR_THEMSELVES`
نوع‌هایی را فهرست می‌کند که تخفیف، وفاداری، کش‌بک و قرعه‌کشی به آن‌ها اهمیت می‌دهند). `Quote` حاصل روی سفارش نوشته
می‌شود (`pricing`، `discounts`، `discount_amount`، `discount_code_id`)، پس سفارش قیمتی را که با آن ساخته شده نگه
می‌دارد، هر چه بعدا تغییر کند.

**سرویسی که کاربر می‌سازد** («سرویس دلخواه»، [سرویس‌های دلخواه](Custom-Services.md)) خریدی بدون پلن است. همه قاعده‌اش
`Catalog\Services\CustomServices` است: فقط در فروشگاه اصلی پیشنهاد می‌شود، آن هم وقتی روشن است، هر دو فهرست پله‌ها
قابل فروش‌اند و یکی از سرورهایش می‌تواند کلاینت بگیرد (<code dir="ltr">ServerSelector::wholeServer()</code>)؛ هر مقدار
(`Enums\CustomQuantity`: حجم، روز — یک دسته متد برای هر دو: <code dir="ltr">tiers()</code>، <code dir="ltr">bounds()</code>،
<code dir="ltr">options()</code>، <code dir="ltr">allowed()</code>، <code dir="ltr">price()</code>) در حدهای خودش — کمترین
مقدار هرگز زیر پله اول نیست، پس هر چه در حدها باشد قیمت دارد — با <code dir="ltr">DTO\PriceTiers::priceOf()</code> قیمت
می‌خورد، کل مقدار با نرخ پله خودش (<code dir="ltr">quote()</code>، که `DTO\CustomPrice` می‌دهد).
<code dir="ltr">Pricing::custom()</code> همین را قیمت پایه می‌گیرد و با آن همان‌طور رفتار می‌کند که با خرید اضافه یک
سرویس بی‌پلن: <code dir="ltr">AutomaticDiscounts::best(null, Purchase, …)</code> (فقط فروش ویژه‌ای که روی همه پلن‌هاست)
و کدی که برای <code dir="ltr">CodeTarget(Purchase, null, server)</code> سنجیده می‌شود.
<code dir="ltr">OrderService::openCustomPurchase()</code> سفارش را می‌سازد — `plan_id` خالی (null)، و
ستون‌های `server_id`، `traffic_bytes`، `days` و `client_name` در شکل آن، تا سفارش باز همان شکل پیدا شود و سفارش یک کلید باید با آن جور باشد؛
`devices` (مال سازنده سرویس دلخواه) کنار آن، که یک تنظیم است نه چیزی که کاربر خواسته، تا اگر ادمین آن را تغییر دهد نه
درخواستی که دوباره فرستاده شده به درخواست دیگری تبدیل شود و نه سفارش باز جا بماند (سفارش تعداد دستگاه فعلی را
می‌گیرد)؛ <code dir="ltr">Order::package()</code> حجم، روزها و دستگاه‌های خود سفارش را به تحویل می‌دهد، و
<code dir="ltr">ProvisioningService::provision()</code> آن را به‌طور کامل روی سرور می‌سازد
(<code dir="ltr">ServerSelector::builtOn()</code>). <code dir="ltr">Order::packageName()</code> تنها عبارتی است که می‌گوید
یک سفارش چه می‌خرد، هر جا نام پلن چاپ می‌شود (گروه گزارش‌ها، پرداخت‌ها و داشبورد پنل‌ها): نام پلن، یا
«سرویس دلخواه · ۵۰ گیگ · ۳۰ روز». روند ربات `Telegram\Handlers\CustomServiceHandler` است، و روند وب‌سایت
`Store\Api\CustomServicesController` روی <code dir="ltr">CustomerCheckout::customQuote()</code> و
<code dir="ltr">buildService()</code> — خریدی که همان‌طور نام‌گذاری می‌شود که خرید یک پلن
(<code dir="ltr">CheckoutScreen::requestedName()</code>، <code dir="ltr">CustomerCheckout::clientName()</code>).

سرویس بدون پلن (<code dir="ltr">CustomerRenewal::picksPlan()</code>: ساخته‌شده، یا افزوده‌شده با لینکش) روی پلنی که
کاربرش انتخاب می‌کند تمدید می‌شود یا، تا وقتی <code dir="ltr">CustomerRenewal::buildable()</code>، با بسته‌ای که ساخته
می‌شود. <code dir="ltr">Pricing::renewal()</code> هر کدام از چیزهایی را که سرویس با آن تمدید می‌شود می‌گیرد — یک `Plan`
(قیمت تمدیدش: قیمت نماینده، هرگز قیمت خرید اول) یا یک `CustomPrice` — و بسته را همان‌طور قیمت می‌گذارد که
<code dir="ltr">custom()</code>، ولی به‌عنوان یک `Renewal`: <code dir="ltr">best(null, Renewal, …)</code> و
<code dir="ltr">CodeTarget(Renewal, null, the service's server)</code> (مسیر مشترک هر دو
<code dir="ltr">Pricing::built()</code> است). <code dir="ltr">OrderService::openCustomRenewal()</code> همزاد
<code dir="ltr">openRenewal()</code> است — `plan_id` خالی، و `traffic_bytes`، `days` و `renewal_method` در
شکلش، و `devices` کنار آن —، و هر دو از <code dir="ltr">openRenewalOf()</code> می‌گذرند: ردیف سرویس قفل می‌شود و
<code dir="ltr">renewalUnderWay()</code> در تراکنش سفارش پرسیده می‌شود (فقط تمدیدهای مالک فعلی سرویس: تمدیدی که مالک
قبلی جا گذاشته هرگز روی آن تحویل نمی‌شود، پس جلوی چیزی را نمی‌گیرد — <code dir="ltr">AutoRenewal::claim()</code> هم
همین‌طور می‌شمارد). پیش‌فاکتور همان چیزی را سفارش می‌دهد که پیش‌نمایشش نشان می‌دهد، با
<code dir="ltr">openPreviewed()</code>، که از روی آنچه `DTO\RenewalPreview` با آن تمدید می‌کند یکی از دو همزاد را انتخاب
می‌کند. <code dir="ltr">ProvisioningService::renew()</code> مقدار <code dir="ltr">Order::package()</code> را اضافه می‌کند و
هیچ پلنی روی سرویس نمی‌نویسد، پس سرویس در تمدید بعدی هم پلن انتخاب می‌کند؛ `AutoRenewal` هرگز آن را پیشنهاد نمی‌دهد.

**«خرید کرده» سه معنا دارد، هر کدام در یک جا روی `Order`.**

- متدهای <code dir="ltr">bought()</code> و <code dir="ltr">boughtBy()</code> — خریدی که پرداخت شده و پس داده نشده
  (`KEPT`)، پلن رایگان هم حساب است، ولی هرگز سرویسی که رایگان داده شده — قیمت‌های خرید اول و کدهای خرید اول را تعیین
  می‌کنند. بازپرداخت خرید را برمی‌گرداند، پس کاربری که تنها خریدش بازپرداخت شده دوباره تازه‌وارد است.
- متد <code dir="ltr">keptPurchases()</code> — همان، با قیمتی بالای صفر — چیزی است که جایزه‌های پول‌مانند می‌خوانند:
  اولین خرید یک زیرمجموعه برای هدیه شروع، خریداران جایزه دعوت، هشدار زیرمجموعه‌های زیادی که هیچ‌کدام خرید نکرده‌اند، و
  قاعده «فقط خریداران» گردونه. نه پلن رایگان و نه خریدی که از آن به بعد بازپرداخت شده قفل این‌ها را باز نمی‌کند.
- متد <code dir="ltr">paidPurchases()</code> — هر خرید با قیمتی که زمانی پرداخت شده، چه بعد بازپرداخت شده باشد چه نه —
  کاربر را برای اکانت‌های تست رایگان دیگر تازه‌وارد حساب نمی‌کند: قاعده «فقط تازه‌واردها» و «خرید کرد» در فهرست تست‌ها.

خرید اول در لحظه تحویل دوباره سنجیده می‌شود: <code dir="ltr">OrderService::carryOut()</code> پیش از تحویل هر چیزی،
<code dir="ltr">assertFirstPurchase()</code> را برای هر سفارشی می‌پرسد. سفارشی که با قیمت خرید اول، یا با کدی فقط برای
خرید اول (`DiscountLine::$firstOnly` در خط تخفیف آن)، ثبت شده، وقتی خرید دیگری از همین کاربر که هنوز
پس داده نشده پیش از آن پرداخت شده باشد ناموفق می‌شود — از دو سفارشی که پیش از پرداخت هر کدام این‌طور ثبت شده‌اند،
آنکه دوم پرداخت شود ناموفق می‌شود تا پشتیبانی بازپرداختش کند، و یادداشتش همین را می‌گوید
(`FirstPurchaseUsedException`). کاربرش این را با متنی مخصوص خودش می‌شنود
([چرا سفارش پرداخت‌شده ناموفق شد](#چرا-سفارش-پرداخت‌شده-ناموفق-شد)).

**استفاده‌های کد تخفیف.** هر استفاده یعنی سفارشی که با کد ساخته شده و نه لغو شده و نه بازپرداخت، پس سفارش پرداخت‌نشده
وقتی منقضی شود استفاده‌اش را پس می‌دهد — برای سفارش‌های کددار زودتر (<code dir="ltr">DiscountSettings::holdMinutes()</code>،
`ExpireOrdersTask`) — و سفارش پرداخت‌شده وقتی بازپرداخت شود. استعلام قیمت، استفاده‌ها را بدون سفارش‌های در انتظار خود
کاربر می‌شمارد (ممکن است پیش‌فاکتورش در حال باز کردن دوباره یکی از آن‌ها باشد)؛ سفارشی که استفاده را می‌گیرد دوباره زیر
قفل ردیف کد می‌شمارد (<code dir="ltr">DiscountCodes::reserve()</code>، داخل <code dir="ltr">OrderService::open()</code>)،
پس آخرین استفاده به یک کاربر می‌رسد. وقتی تنها استفاده باقی‌مانده برای کاربر را سفارش پرداخت‌نشده دیگری از خود او
نگه داشته، متن رد آن سفارش را نام می‌برد (`DiscountCodes::HELD_BY_YOU`): پرداخت آن سفارش کد را همان‌جا به کار می‌برد، و
تمام شدن مهلت پرداختش کد را آزاد می‌کند. کد را تا وقتی می‌شود حذف کرد که جز سفارش لغوشده هیچ سفارشی با آن ساخته نشده
باشد؛ کدی که همه سفارش‌هایش بازپرداخت شده‌اند برای سابقه آن‌ها نگه داشته می‌شود، با متن رد خودش
(<code dir="ltr">DiscountInUseException::refunded()</code>). کدهای اشتباه برای هر کاربر محدودیت تعداد تلاش دارند
(<code dir="ltr">DiscountCodes::tried()</code>). کدها و فروش‌های ویژه `Models\Concerns\DiscountScope` (نوع‌ها، پلن‌ها،
بازه زمانی) و `Services\DiscountForm` (فیلدهای مشترک فرم‌هایشان) را با هم شریک‌اند.

## سفارش‌ها و پیش‌فاکتور

متد <code dir="ltr">OrderService::open()</code> سفارش قابل پرداخت کاربر را با همان شکل، همان قیمت و همان کد پیدا
می‌کند، یا یکی می‌سازد، زیر قفلی روی ردیف کاربر — دو بار زدن دکمه فقط یک سفارش می‌سازد. شکل یعنی آنچه سفارش می‌خرد: پلن و سرورش
یا سرویسش، یک مبلغ، یک بسته ساخته‌شده (حجم و روزهایش)، یک نام کلاینت، روش یک تمدید (`renewal_method`: تمدید با روش دیگر
سفارش دیگری است؛ روش تمدید خودکار همان پیش‌فرضی است که ادمین تعیین کرده، <code dir="ltr">OrderService::createRenewal()</code>). شکل یک خرید
اضافه، سرویسش و چیزی است که اضافه می‌کند؛ پلن سرویس کنار آن می‌آید (به‌عنوان پلن سفارش نشان داده می‌شود)، پس درخواستی
که بعد از پلن‌دار شدن سرویس دوباره فرستاده شود باز همان درخواست است. یک تمدید یا خرید اضافه زیر قفل ردیف سرویسش باز
می‌شود، تا وقتی سرویس هنوز مال همان کاربر است (<code dir="ltr">OrderService::holding()</code>: با
`ServiceTransfers::MOVED_ON` رد می‌شود، یا وقتی ردیفش از بین رفته با `SERVICE_GONE`)، پس انتقالی که همان لحظه انجام شود
سفارش را می‌بیند (بررسی سفارش باز آن زیر همان قفل اجرا می‌شود) و صفحه‌ای که پیش از یک انتقال کشیده شده چیزی سفارش
نمی‌دهد. انتقالی که پشتیبانی انجام می‌دهد سفارش‌های پرداخت‌نشده دهنده را لغو می‌کند
(<code dir="ltr">ServiceTransfers::cancelLeftOrders()</code>، از راه <code dir="ltr">PaymentService::expire()</code>)؛ سفارشی
که باز گذاشته — رسیدش در حال بررسی — نه پرداخت می‌شود (<code dir="ltr">PaymentService::createForOrder()</code>) و نه تحویل
(<code dir="ltr">OrderService::carryOut()</code>، و دوباره زیر نگه‌داشتن سرویس برای تحویلی که در لحظه دست به دست شدن در
جریان است، <code dir="ltr">ProvisioningService::renew()</code>/<code dir="ltr">addExtra()</code>؛
`ServiceChangedHandsException`: ناموفق می‌شود تا پشتیبانی بازپرداختش کند، همان‌طور که خرید اولی که دوم پرداخت شده، و
به کاربرش گفته می‌شود که سرویس دیگر مال او نیست) — <code dir="ltr">Order::serviceChangedHands()</code>. وب‌سایت با هر درخواست سفارش یک `Idempotency-Key` می‌فرستد: سفارشی که
یک کلید به آن رسیده به همان درخواست، وقتی دوباره فرستاده شود، پاسخ می‌دهد، هر اتفاقی از آن به بعد برایش افتاده باشد
(پرداخت، تحویل، لغو، قیمت تازه)، و کلید روش پرداختش را به خاطر می‌سپارد تا تکرار هرگز از راه دیگری پرداخت نشود. دو
درخواست هم‌زمان با یک کلید را ردیف یکتای `request_keys` و compare-and-swap سفارش حل می‌کنند؛ بازنده نتیجه برنده را
توصیف می‌کند (<code dir="ltr">Checkout::standing()</code>). درخواست کلیددار تا وقتی کاربر `OrderService::UNPAID_MAX` سفارش
پرداخت‌نشده دارد سفارش تازه‌ای نمی‌سازد (هر کدام ممکن است یک تصویر رسید روی هاست نگه دارد).

وضعیت‌ها (`OrderStatus`): pending، بعد paid، بعد processing، بعد fulfilled یا failed؛ یا cancelled بدون پرداخت، یا
refunded. فهرست‌های خواندن روی `Order` هستند: `SOLD` (یک فروش)، `KEPT` (پرداخت‌شده و پس داده نشده — یک فروش یا یک تحویل
ناموفق)، و scopeهای <code dir="ltr">bought()</code>، <code dir="ltr">spending()</code> (آنچه وفاداری و قرعه‌کشی
می‌شمارند)، <code dir="ltr">payable()</code>، <code dir="ltr">expirable()</code>، <code dir="ltr">stale()</code>،
<code dir="ltr">stuck()</code> (صف پشتیبانی).

**هر جابه‌جایی یک compare-and-swap است** (`Core\Database\Transitions`): یک پرداخت یک سفارش را پرداخت می‌کند؛ فقط
پروسه‌ای که سفارش را تصاحب می‌کند (از paid به processing) آن را تحویل می‌دهد، پس تایید پشتیبانی که با تایمر تایید خودکار
مسابقه می‌دهد، یا دو تلاش دوباره، هرگز دو بار تحویل نمی‌دهند. سفارش در همان تراکنشی fulfilled علامت می‌خورد که آنچه
تحویل داده را می‌نویسد، و نتیجه‌ها در همان تراکنش به گروه گزارش داده می‌شوند.

**تحویلی که یک پروسه مرده جا گذاشته** از روی `updated_at` سفارش پیدا می‌شود
(<code dir="ltr">OrderService::isStale()</code>، <code dir="ltr">Order::stale()</code>)، و `ResumeDeliveriesTask` آن را
همان‌طور به پایان می‌رساند که یک تلاش دوباره — هرگز تحویل ناموفق را، که منتظر پشتیبانی می‌ماند:

- سفارش پرداخت‌شده‌ای که به مدت `STALE_PAID_MINUTES` (۱۰ دقیقه) کسی تصاحبش نکرده: پروسه‌اش پیش از شروع مرده است؛
- تصاحب processing که به مدت `STALE_PROCESSING_MINUTES` (۱۸ دقیقه) دست نخورده: پروسه‌اش وسط راه مرده است. تحویلی که در
  حال کار است با **ضربانش** (heartbeat) نشان می‌دهد زنده است: پیش از هر فراخوانی پنل، وقتی سفارش ۶۰ ثانیه دست‌نخورده
  مانده، <code dir="ltr">ProvisioningService::beat()</code> آن را لمس می‌کند — بی‌صدا
  (<code dir="ltr">ChangeFeed::quietly()</code>)، و فقط تا وقتی هنوز processing است و با لحظه‌ای که همین تحویل آخرین بار
  نوشته. طولانی‌ترین زمانی که یک تحویل زنده سفارشش را دست‌نخورده می‌گذارد همان یک دقیقه است به‌اضافه یک فراخوانی روی
  کندترین پنلی که یک سرور ممکن است داشته باشد (`ProvisioningService::LONGEST_SILENCE`، حدود ۱۷ و نیم دقیقه)؛
  `STALE_PROCESSING_MINUTES` همین است به دقیقه کامل، به‌اضافه یک دقیقه. تحویلی که بیش از این ساکت مانده و کار از دستش
  گرفته شده می‌بیند ضربانش رد می‌شود: `DeliveryTakenOverException` آن را پیش از فراخوانی بعدی پنل متوقف می‌کند،
  <code dir="ltr">carryOut()</code> سفارش را به پروسه‌ای می‌سپارد که آن را در دست دارد، و حجم نماینده‌ای که برداشته
  برداشته می‌ماند — مال همان تحویل است (<code dir="ltr">OrderService::withTraffic()</code>؛
  <code dir="ltr">TrafficPool::draw()</code> هرگز برای یک سفارش دو بار برنمی‌دارد).

سفارش‌های پرداخت‌نشده بعد از `ExpireOrdersTask::EXPIRE_HOURS` منقضی می‌شوند (<code dir="ltr">Order::expirable()</code>)،
هرگز تا وقتی ممکن است پولشان دست پشتیبانی باشد (<code dir="ltr">Payment::isWithSupport()</code>): رسیدی در حال بررسی، یا
انتقال کارتی که در پیوی پشتیبانی انجام می‌شود — که هیچ کارتی به آن نشان داده نشده و تایید یا لغوش با پشتیبانی می‌ماند،
هر چه تنظیم از آن به بعد بگوید.

تحویلی که یک پنل یا یک قاعده ردش کند سفارش را با دو یادداشت ناموفق می‌کند: `notes`، که برای هر کسی که فروشگاه را
می‌خواند بی‌خطر است (یک نماینده، مدیران سایت)، و `diagnosis`، جزئیات پنل برای مالک فروشگاه (آدرسش، پاسخش).
<code dir="ltr">OrderDirectory::notes()</code> از روی اینکه چه کسی می‌خواند یکی را انتخاب می‌کند.

### چرا سفارش پرداخت‌شده ناموفق شد

کاربر هیچ‌کدام از این دو یادداشت را نمی‌خواند. آنچه به او گفته می‌شود از
<code dir="ltr">Orders\Enums\OrderFailure::of()</code> می‌آید، که از روی خود سفارش خوانده می‌شود:

- مقدار `FirstPurchaseUsed` (`first_purchase_used`) — یادداشت‌هایش متن `FirstPurchaseUsedException` است، به خاطر قیمت یا
  با متن یک کد (<code dir="ltr">FirstPurchaseUsedException::failedSo()</code>)؛
- مقدار `ServiceChangedHands` (`service_changed_hands`) — یادداشت‌هایش `ServiceChangedHandsException::MESSAGE` است
  (<code dir="ltr">ServiceChangedHandsException::failedSo()</code>)؛
- مقدار `Delivery` (`delivery`) — هر شکست دیگری؛
- مقدار `null` برای سفارشی که ناموفق نشده.

دو مورد اول هیچ‌وقت تحویل نمی‌شوند: پشتیبانی پرداختشان را با کاربر تسویه می‌کند. `Delivery` منتظر تلاش دوباره پشتیبانی
می‌ماند. متد <code dir="ltr">Telegram\Notifications\ServiceCard::failedText()</code> متن کاربر را از روی آن انتخاب
می‌کند — `BotText::PayFirstPurchaseUsed`، `BotText::PayServiceChangedHands`، وگرنه متن شکست خود نوع سفارش
(`PayProvisionFailed`، `RenewFailed`، `ExtraFailed`، `TopupFailed`، `AgencyTrafficFailed`) —، و همان یک متن به ربات،
اعلان‌های وب‌سایت و ایمیل یکسان می‌رود (<code dir="ltr">CustomerNotifier::paymentSettled()</code>،
<code dir="ltr">renewalFailed()</code>؛ پیش‌فاکتور ربات، `CheckoutScreen`). Store API این مقدار را
به‌صورت `StoreOrder.failure` می‌فرستد (`Store\Presenters\OrderPresenter`). دلیل تازه‌ای که یک تحویل ممکن است برای
همیشه به خاطرش رد شود با یک استثنا می‌آید که <code dir="ltr">failedSo()</code> خودش را دارد، با یک case در اینجا، و با
یک متن ربات.

### سفارش‌هایی که پشتیبانی ثبت می‌کند

متد <code dir="ltr">Orders\Services\ManualOrders::make()</code> همان «افزودن سفارش» است (`POST /users/{id}/orders`، در هر
دو پنل و — با مجوز `manual_orders` — برای مدیران سایت): خریدی برای یک کاربر، از یک پلن یا با عددهای خود پشتیبانی که مثل
عددهای یک سرویس ساخته‌شده روی سفارش می‌آیند (`traffic_bytes`، `days`، `devices`، هر کدام ۰ یعنی بدون محدودیت —
<code dir="ltr">Order::isBuilt()</code>، <code dir="ltr">Order::package()</code>)، روی سروری که از پیش بررسی شده
(<code dir="ltr">ServerSelector::resolve()</code> / <code dir="ltr">builtOn()</code>)، در فروشگاه یک نماینده با سنجش
موجودی حجم او (<code dir="ltr">Bot::trafficRefusal()</code>: نه سقف حجم نامحدود، نه بیش از آنچه مانده) و هرگز روی سروری
یا از پلنی که مالک فروشگاه از آن فروشگاه پنهان کرده (<code dir="ltr">Bot::hidesServer()</code>، `Plan::$hidden_by_owner`)،
با نامی که <code dir="ltr">ClientNaming::chosen()</code> می‌دهد (قاعده‌های نامی که خریدار تایپ می‌کند، هر راهی که فروشگاه
پیشنهاد کند)؛ و رد می‌شود: برای حساب خود انجام‌دهنده (<code dir="ltr">ActorRefusedException::ownOrder()</code>)، برای
فروشگاه نماینده‌ای که فروشش متوقف است (<code dir="ltr">Bot::sells()</code>، روی `status`، هر کس که بپرسد — مالک فروشگاه
هم، که اول باید فروش را دوباره باز کند: `SellingSuspendedException::OWNER`) و برای کیف پولی که کمتر از مبلغ دارد
(<code dir="ltr">Checkout::shortfall()</code>). <code dir="ltr">OrderService::openManual()</code> آن را از نو می‌سازد — هرگز
سفارش باز هم‌شکلی پیدا نمی‌شود — با `created_by` (نام انجام‌دهنده) و `support_note`، و `Idempotency-Key` آن مثل کلید
وب‌سایت در `request_keys` نگه داشته می‌شود؛ کلیدی که به سفارشی رسیده پیش از اینکه هر چیز وابسته به وضعیت پرسیده شود با
همان سفارش پاسخ می‌دهد (توقف فروشی که بعدا آمده، کیف پولی که خودش خالی کرده، نامی که گرفته). سپس
<code dir="ltr">PaymentService::payBySupport()</code> آن را بلافاصله به نام انجام‌دهنده تسویه می‌کند، همان‌طور که
پیش‌فاکتور کیف پول — با ردیف کیف پول (که از کاربر کم می‌کند — و وقتی کیف پول خاموش است از همان اول رد می‌شود —؛ و برای
سفارش رایگان چیزی برنمی‌دارد) یا ردیف داخلی **خارج از فروشگاه** (`Drivers\Outside`، پولی که بیرون از فروشگاه گرفته
شده) — و بعد تحویلش می‌دهد؛ کیف پولی که در همان لحظه خرج شده باشد سفارش را لغو می‌کند و رد می‌شود. وقتی سفارش fulfilled شد
به کاربر خبر داده می‌شود (<code dir="ltr">CustomerNotifier::paymentSettled()</code>)؛
<code dir="ltr">ShopReports::purchaseText()</code> اضافه می‌کند چه کسی آن را ثبت کرده — نام کاربری ورود مالک فروشگاه به
شکل «پشتیبانی» (<code dir="ltr">Reviewers::shared()</code>) — و چطور پرداخت شده است.

نحوه شمرده شدنش از همان قاعده‌های موجود درمی‌آید، از روی `pricing` و روش پرداخت: سفارش رایگان `pricing` از نوع gift و
بدون قیمت است — خرید حساب نمی‌شود (<code dir="ltr">Order::bought()</code>) و چیزی به دست نمی‌آورد؛ سفارش کیف پولی از نوع
`regular` با مبلغی است که پشتیبانی تعیین کرده — یک خرید است و خرج حساب می‌شود (کش‌بک، وفاداری، قرعه‌کشی، هدیه خرید اول
یک زیرمجموعه)، ولی پورسانتی ندارد و <code dir="ltr">moneyIn()</code> نیست (پرداختی از کیف پول است)؛ سفارش خارج از
فروشگاه همان خرید است و <code dir="ltr">moneyIn()</code> هم هست، و پورسانت معرف را به دست می‌آورد.
<code dir="ltr">Order::manualPayment()</code> می‌خواند کدام بوده (رایگان از روی قیمتش، وگرنه از روی روش پرداخت)
برای `manual` در `OrderDirectory` و برای گزارش. ردیف خارج از فروشگاه هرگز در پیش‌فاکتور پیشنهاد نمی‌شود
(<code dir="ltr">PaymentMethods::offers()</code>) و میان روش‌ها یا درایورها فهرست نمی‌شود
(<code dir="ltr">PaymentMethods::all()</code>، <code dir="ltr">drivers()</code>)؛ هر فروشگاه یکی دارد (ردیف‌های
database/schema.php، <code dir="ltr">createBuiltins()</code>، ارتقای 0.5.0).

## پرداخت‌ها

هر روش پرداخت یک ردیف است: یک درایور درگاه (<code dir="ltr">Payments\Drivers\\\*</code>، ثبت‌شده در `payment.drivers`) با
تنظیماتش و یک عنوان. ده درایور همراه برنامه می‌آیند: کیف پول (داخلی، یک ردیف برای هر فروشگاه، بلافاصله تسویه می‌کند)،
ردیف خارج از فروشگاه (آن هم داخلی، فقط مال پشتیبانی، بالا)، کارت به کارت (یک درگاه دستی: کارتی نشان می‌دهد و منتظر رسید
می‌ماند)، و CubePay، Zarinpal، AtlasPay، AbanGateway، Plisio، AqayePardakht و Variza (درگاه‌های آنلاین: کاربر در صفحه
آن‌ها می‌پردازد، و از آن‌ها پرسیده می‌شود نتیجه چه شد — Variza، که به هیچ پرسشی جواب نمی‌دهد، نتیجه را با یک Webhook
امضاشده خبر می‌دهد — [درایورها](Drivers.md#درگاه-آنلاین)). افزودن یک درگاه یعنی یک کلاس درایور، ثبت آن و شکل
درخواست‌هایش — [درایورها](Drivers.md#درگاه-پرداخت) را ببینید.

هر روش حدهایی دارد (`min_amount`، `max_amount`): <code dir="ltr">PaymentMethods::forOrder($type, $amount, $customer)</code>
و <code dir="ltr">payable()</code> فقط روشی را پیشنهاد می‌کنند که آن مبلغ را بپذیرد (<code dir="ltr">takes()</code>: در
محدوده <code dir="ltr">within()</code>، و برای روش کارتی، کارتی که جا دارد — <code dir="ltr">PaymentCards::offers()</code>،
یا کاربر روی یک سفارش باز از همان نوع و همان مبلغ پرداختی در انتظار از آن روش دارد، که پرسیدن دوباره همان را دوباره به
کار می‌برد، جای خالی کارتش یا روشن و خاموش بودنش هر چه باشد)، و مبلغ null یعنی هر مبلغی (فهرست وب‌سایت بدون `amount`،
و بررسی نوع سفارش پیش از اینکه قیمتش معلوم باشد)؛ <code dir="ltr">allow()</code> رد را پرتاب می‌کند
(`MethodUnavailableException`، 422 روی `method_id`، با عبارت خود روش). این پرسش در یک جا پرسیده می‌شود:
<code dir="ltr">Checkout::pay($user, $method, $type, …)</code>، تنها پیش‌فاکتور ربات و وب‌سایت، پیش از اینکه چیزی سفارش
داده شود (کنار <code dir="ltr">ReceiptsInReview::allowCard()</code>)، با قیمت — یا مبلغ خود سفارش کلیددار؛
<code dir="ltr">PaymentService::createForOrder()</code> برای فراخواننده‌های دیگرش هر پرداختی را دوباره به این حدها پایبند
می‌کند — سفارش رایگان در هر حدی جا می‌گیرد. پرداختی که آخرش هیچ کارتی پیدا نکند (آخرین جای خالی در این فاصله گرفته شده،
یا پرداخت بازی که <code dir="ltr">offers()</code> شمرده بود مال سفارش دیگری از همان نوع و مبلغ بوده) چیزی برای پرداخت
باقی نمی‌گذارد: <code dir="ltr">Checkout::withdraw()</code> سفارشی را که درخواست ساخته لغو می‌کند
(<code dir="ltr">OrderService::cancel()</code>، با رد به‌عنوان یادداشتش) و کلیدی را که نگه داشته فراموش می‌کند.
<code dir="ltr">CheckoutScreen::show()</code> در ربات مبلغ را از هر فراخواننده می‌گیرد، و <code dir="ltr">buy()</code> با
رد، پیش‌فاکتور را با راه‌هایی که مانده دوباره می‌کشد؛ فراخواننده‌ها اول <code dir="ltr">payable()</code> را بدون مبلغ
می‌پرسند (خاموش، یا هیچ روشی برای این نوع سفارش)، همان‌طور که `CustomerCheckout` نوع روش را پیش از اینکه استعلام قیمت
معلوم باشد بررسی می‌کند. `AutoRenewal` فقط قیمتی را از کیف پول تمدید می‌کند که در حدهای آن باشد، و
<code dir="ltr">AutoRenewal::renews()</code> همین را درباره قیمت تمدید کاربر می‌پرسد
(<code dir="ltr">Pricing::renewalOffers()</code>، که برای هر صفحه قیمت هر کاربر را یک بار می‌خواند): به سرویسی که نپذیرد
کلید تمدید خودکار پیشنهاد نمی‌شود، و `ServiceReminders` پایانش را یادآوری می‌کند.
<code dir="ltr">PaymentService::payBySupport()</code> هیچ حدی نمی‌پرسد: سفارشی که پشتیبانی با دست ثبت می‌کند عمدی است.

کارت‌های یک روش کارتی ردیف‌های جداگانه خودشان هستند (`Models\PaymentCard`، `payment_cards`)، که فرم روش آن‌ها را به شکل
یک فهرست نگه می‌دارد (`cards`: <code dir="ltr">PaymentCards::read()</code> آن را یکجا بررسی می‌کند،
<code dir="ltr">keep()</code> آن را در تراکنش روش ذخیره می‌کند)؛ فرم درایور فقط یادداشت و مهلت بررسی را دارد.
<code dir="ltr">PaymentService::openPayment()</code> پرداخت باز سفارش از آن روش را دوباره به کار می‌برد — با
کارتش، `payments.card_id`، تا یک پرداخت همیشه همان کارت را نشان دهد — یا یکی می‌سازد با کارتی که
<code dir="ltr">PaymentCards::pick()</code> به‌طور تصادفی از میان کارت‌های فعالی بیرون می‌کشد که `daily_cap` آن‌ها جا دارد
(برای شارژ کیف پول، جا برای بیشترین مبلغی که <code dir="ltr">amountFor()</code> ممکن است بخواهد)، زیر قفلی روی کارت‌های
روش و با خواندن آنچه گرفته‌اند با یک خواندن قفل‌دار — از دو پرداخت هم‌زمان دومی منتظر می‌ماند و اولی را می‌بیند؛ هیچ
کارتی: <code dir="ltr">MethodUnavailableException::noCard()</code>. آنچه امروز به یک کارت رسیده
(<code dir="ltr">takenToday()</code>) پرداخت‌های آن است که از نیمه‌شب فروشگاه (<code dir="ltr">LocalTime::midnight()</code>)
ساخته شده‌اند و در انتظار، در حال بررسی، پرداخت‌شده یا بازپرداخت‌شده هستند — جمع زده، همان‌طور که پرداخت‌های یک کارت
شمرده می‌شوند، فقط از <code dir="ltr">payments(card_id, created_at, status, amount)</code>، بدون فیلتر `bot_id` فروشگاه
(پرداخت‌های یک کارت مال فروشگاه همان کارت‌اند). <code dir="ltr">PaymentMethods::update()</code> پیش از اینکه
<code dir="ltr">read()</code> فرم را بسنجد کارت‌های روش را قفل می‌کند، پس کارتی که همان لحظه به پرداختی نشان داده می‌شود
شماره‌اش را نگه می‌دارد و می‌ماند. درگاه کارت پرداخت را به آن نشان می‌دهد:
<code dir="ltr">GatewayInterface::initiate(Payment)</code> — `ManualGateway` شیء `CardTransfer` را از
<code dir="ltr">$payment->card</code> و یادداشت روش می‌سازد، و <code dir="ltr">Checkout::awaiting()</code> (درخواستی که
دوباره فرستاده شده) هم همین‌طور. کارتی که به پرداختی نشان داده شده شماره‌اش را نگه می‌دارد و هرگز حذف نمی‌شود
(`payments.card_id` جلویش را می‌گیرد). ارتقا به 0.5.0 کارت تنظیمات هر روش را اولین ردیفش کرد و همان کارت را به همه
پرداخت‌های آن روش داد؛ شماره‌ای که فرم روش آن را خالی حساب می‌کرد ردیفی نساخت، و آن روش تا وقتی کارتی اضافه نشود به هیچ‌کس
پیشنهاد نمی‌شود (پرداخت‌هایش کارتی نگه نمی‌دارند).

شارژ کیف پول کارت به کارت بیشتر از سفارشش درخواست می‌کند (<code dir="ltr">PaymentCards::amountFor()</code>): ۱ تا
<code dir="ltr">WalletSettings::uniqueMax()</code> تومان، مبلغی که هیچ پرداخت باز دیگری به آن کارت نمی‌خواهد — در
محدوده `max_amount` روش نگه داشته می‌شود (<code dir="ltr">extra()</code>: برای مبلغی که در بیشترین مقدار خود است چیزی اضافه
نمی‌شود)، و جای خالی کارت روی همان مبلغ نگه‌داشته سنجیده می‌شود. سفارش مبلغ درخواستی را نگه می‌دارد
(<code dir="ltr">OrderService::open()</code> سفارش را از روی مبلغش پیدا می‌کند)؛ `amount` پرداخت همان است که پرداخت،
واریز (<code dir="ltr">OrderService::carryOut()</code> مبلغ پرداخت پرداخت‌شده را واریز می‌کند)، شمرده
(<code dir="ltr">Payment::moneyIn()</code>، پورسانت‌های زیرمجموعه‌گیری) و بازپرداخت می‌شود.

سرویس `PaymentService` پرداخت‌ها را با compare-and-swap جابه‌جا می‌کند و هر جابه‌جایی را در همان تراکنش گزارش می‌دهد.
تسویه (<code dir="ltr">settle()</code>) سهم درگاه (برداشت از کیف پول)، جابه‌جایی پرداخت و تصاحب سفارش است در یک تراکنش —
باختن هر کدام از دو compare-and-swap استثنا پرتاب می‌کند و برداشت را برمی‌گرداند. تحویل بعد از commit اجرا می‌شود، چون
با یک پنل حرف می‌زند. رسیدی که در ربات فرستاده شده ممکن است با مهلت بررسی روشش پذیرفته شود (`AutoApproveReceiptsTask`)؛
رسیدی که از وب‌سایت آپلود شده همیشه منتظر پشتیبانی می‌ماند، چون حسابش ممکن است یک ایمیل یک‌بارمصرف باشد. بازپرداخت‌ها
از روی آنچه سفارش خریده پیش می‌روند: اول آنچه به یک سرویس داده پس گرفته می‌شود، بعد پولش به کیف پول برمی‌گردد (منهای
هر کش‌بک؛ در فروشگاه نماینده حجمی که برداشته به موجودی حجم برمی‌گردد،
<code dir="ltr">TrafficPool::giveBack(refunded: true)</code>)، حجم نماینده از موجودی حجمش بیرون می‌رود، و شارژ کیف پول از
کیف پول بیرون می‌رود — وقتی خرج یا فروخته شده باشد رد می‌شود. پول شارژ کیف پول از فروشگاه بیرون می‌رود (پشتیبانی آن را
بیرون از فروشگاه برمی‌گرداند)، پس بازپرداختش پورسانت زیرمجموعه‌ای را هم که آورده پس می‌گیرد
(<code dir="ltr">ReferralService::refundTakesBack()</code>، <code dir="ltr">takeBack()</code>: از کیف پول معرف، به‌صورت یک
خط جداگانه، در تراکنش بازپرداخت)، و اگر معرف آن را خرج کرده باشد رد می‌شود
(<code dir="ltr">RefundRefusedException::commissionSpent()</code>)؛ بعد به معرف خبر داده می‌شود
(<code dir="ltr">CustomerNotifier::commissionTakenBack()</code>). پول هر بازپرداخت دیگری به‌صورت اعتبار کیف پول در فروشگاه
می‌ماند، و پورسانتش سر جایش می‌ماند. پس گرفتن آنچه یک سفارش به سرویس داده کار طرف سرویس‌هاست: Payments صاحب
`Contracts\RefundedOrders` است و `RefundedDeliveries` در Subscriptions آن را پیاده می‌کند. سرویس یک خرید با همان حذفی
پاک می‌شود که صفحه سرویس‌ها دارد (<code dir="ltr">SubscriptionActions::delete()</code>، بدون پیامی از خودش)، و تا وقتی سفارش دیگری از
سرویس پرداخت‌نشده یا نگه‌داشته باشد رد می‌شود (`Order::KEPT`: پرداخت‌شده، در حال تحویل، تحویل‌شده یا ناموفق —
<code dir="ltr">SubscriptionActions::orderHolding()</code>): <code dir="ltr">RefundRefusedException::serviceInUse()</code> از
روی وضعیت آن سفارش به پشتیبانی می‌گوید اول چه کند — لغوش کند، منتظر تحویلش بماند، یا بازپرداختش کند. هدیه یک تمدید یا
یک خرید اضافه با <code dir="ltr">ProvisioningService::undo()</code> (<code dir="ltr">ServiceTerms::takeBack()</code>)
برگردانده می‌شود: مدت و حجم یک تمدید همان‌طور که `given_hours` و `given_bytes` در لحظه تحویل نگهشان داشتند، و محدودیت
دستگاهش که برگردانده می‌شود (`given_ip_limit`، `ip_limit_was`، `ip_limit_queued`، `ip_limit_reset_was`:
<code dir="ltr">ServiceTerms::limitPutBack()</code>)، و `days`، `traffic_bytes` یا `devices` یک خرید اضافه — تا جایی که
سرویس هنوز آن‌ها را دارد: آنچه یک تمدید بعدی از نو شروع کرده، و آنچه فقط آخرین تمدید باقی‌مانده برمی‌گرداند، به آن
سپرده می‌شود ([پس گرفتن](Internals-Services.md#تمدید-خرید-اضافه-و-یادآوری‌ها))؛ یک بار — `taken_back_at` همراه ردیف
نوشته و زیر نگه‌داشتن سرویس بررسی می‌شود —، و کلاینتش همان‌طور که پنل داشت روشن یا خاموش می‌شود. فقط از سفارش
تحویل‌شده پس گرفته می‌شود، و هرگز از سرویسی که حالا مال کاربر دیگری است
(<code dir="ltr">RefundRefusedException::serviceChangedHands()</code>، اول و دوباره زیر نگه‌داشتن پرسیده می‌شود: انتقالی
که از آن به بعد انجام شده؛ ادغام، سفارش‌ها را همراه سرویس‌ها جابه‌جا می‌کند). یک رد، یا پنلی که شکست بخورد، پرداخت را
بازپرداخت‌نشده باقی می‌گذارد: <code dir="ltr">PaymentActions::refund()</code> پیش از
<code dir="ltr">PaymentService::refund()</code> آن را می‌پرسد، و وضعیتی از سفارش را که با آن سنجیده به آن می‌دهد
(<code dir="ltr">$seen</code>) — سفارشی که در این فاصله تحویل شده زیر قفل سفارش رد می‌شود، تا دوباره بازپرداخت شود (و
پس گرفته شود).

کلاس‌های `PaymentActions` و `OrderActions` تصمیم‌های پشتیبانی‌اند، هر جا گرفته شوند (هر دو پنل، API مدیران وب‌سایت،
دکمه‌های گروه گزارش‌ها، تایمر). هر کدام قاعده‌اش را بررسی می‌کند (<code dir="ltr">allowed()</code>) و اینکه چه کسی تصمیم
می‌گیرد (یک `Auth\Actor`: هیچ‌کس پرداخت خودش را تایید یا بازپرداخت نمی‌کند؛ مدیران سایت فقط رسیدی را که در حال بررسی است
تایید می‌کنند)، و فقط وقتی به کاربر خبر می‌دهد که همین فراخوانی تصمیم گرفته باشد — بازنده دو تصمیم هم‌زمان با
`DECIDED_MEANWHILE` رد می‌شود. تایید پرداختی که به‌عنوان رسید در حال بررسی سنجیده شده (در وب‌سایت و با تایمر مهلت بررسی
همیشه همین‌طور است) آن را فقط تا وقتی جابه‌جا می‌کند که هنوز در حال بررسی باشد
(<code dir="ltr">PaymentService::approve(receiptOnly: true)</code>): ردی یا لغوی که همان لحظه انجام شده سر جایش می‌ماند و
هرگز تایید رویش نوشته نمی‌شود. پرداخت آنلاین هرگز تایید یا رد نمی‌شود: <code dir="ltr">check()</code> از درگاهش می‌پرسد
(<code dir="ltr">OnlinePayments::check()</code>، فقط حرف درگاه آن را جابه‌جا می‌کند)، و پرداختی که بعد از بسته شدن
سفارشش انجام شده (<code dir="ltr">Payment::wentToWallet()</code>: پولش در کیف پول است) نه دوباره امتحان می‌شود و نه
بازپرداخت — هیچ بخشی از سفارش را نپرداخته است. پرداخت آنلاین وقتی برای پشتیبانی نگه داشته می‌شود
(<code dir="ltr">OnlinePayments::isHeld()</code>) که درگاهش پاسخی بدهد که با آن جور نیست، پولی را که برایش رسیده تا ۴۸
ساعت پس از بسته شدن صفحه‌اش تسویه نکند، یا آن را پس از اینکه گفته بود پولی برایش رسیده ببندد — این آخری همان لحظه —،
و هر کدام یادداشت خودش را دارد (`PaymentService::HELD_NOTES`). پرداخت نگه‌داشته‌شده دیگر پرسیده نمی‌شود — فقط
<code dir="ltr">cancel()</code> مجاز است —، و تا وقتی هنوز منتظر است (در انتظار، یا ناموفق با صفحه‌اش) سفارشش نه منقضی
می‌شود (<code dir="ltr">PaymentService::expire()</code> نگهش می‌دارد) و نه از راه دیگری پرداخت می‌شود
(<code dir="ltr">Checkout::pay()</code> استثنای `PaymentHeldException` پرتاب می‌کند)؛ وقتی لغو شود جلوی چیزی را نمی‌گیرد.
صفحه پرداخت‌ها پرداخت‌های نگه‌داشته‌شده را در تب جداگانه خودشان فهرست می‌کند (`PaymentDirectory::HELD`،
<code dir="ltr">Payment::whereHeld()</code>)، و داشبورد آن‌ها را میان صف‌های ادمین می‌شمارد.

کلاس `ReceiptDismissals` رسیدها را گروهی رد می‌کند، با چرخیدن روی <code dir="ltr">PaymentActions::reject()</code>، بعد
هر آپلود را حذف می‌کند (<code dir="ltr">Receipts::discard()</code>) و برای هر درخواست یک خط گزارش می‌دهد
(<code dir="ltr">ShopReports::receiptsDismissed()</code>). با `all` (همان «رد همه رسیدهای در انتظار» پنل) در هر درخواست
قدیمی‌ترین ۱۰۰ رسید فروشگاه را برمی‌دارد، و هر دور به `receipt_at` تا لحظه دور اول محدود است (`until`، که پنل آن را پس
می‌فرستد)، پس رسیدی که در حین اجرای آن فرستاده شود هرگز ندیده رد نمی‌شود.

اینکه پرداخت‌های کارت به کارت چطور گرفته می‌شوند `PaymentSettings` است (گروه `payments` در تنظیمات ربات). قاعده «یک
رسید در هر زمان» `ReceiptsInReview` است: <code dir="ltr">Checkout::pay()</code> پیش از اینکه چیزی سفارش داده شود
<code dir="ltr">allowCard()</code> را می‌پرسد (از هر دو ورودی؛ درخواست وب‌سایتی که با کلیدش دوباره فرستاده شده و رسید
خودش در حال بررسی است رد نمی‌شود)، `ReceiptHandler` ربات و `CustomerReceipts` وب‌سایت پیش از نگاه به تصویر
<code dir="ltr">allowReceipt()</code> را می‌پرسند، و آن را از راه <code dir="ltr">submit()</code> می‌گیرند — که دوباره زیر
قفلی روی ردیف کاربر و با یک خواندن قفل‌دار پرسیده می‌شود، پس از دو رسیدی که هم‌زمان برای دو پرداخت فرستاده شوند دومی
اولی را در حال بررسی می‌بیند. هر رد یک `ReceiptInReviewException` است (422 روی `status`، همان «رسید قبلی در حال بررسی»
ربات). <code dir="ltr">allowCard()</code> قفلی نمی‌گیرد: آنچه مسابقه با یک رسید می‌تواند باقی بگذارد — پرداخت کارتی که
منتظر انتقالش است در حالی که رسیدی در حال بررسی است — جایی است که کاربر بدون آن هم به آن می‌رسد، با شروع دو پرداخت
کارتی پیش از رسید اول؛ خود قاعده کار <code dir="ltr">submit()</code> است.

پرداخت‌های کارتی در پیوی پشتیبانی (<code dir="ltr">sendsToSupport()</code>: کلید روشن و راه تماس پشتیبانی ربات تنظیم‌شده،
که موتور پرداخت آن را به شکل <code dir="ltr">PaymentCards::toSupport()</code> می‌خواند) پرداخت‌هایی هستند که **هیچ کارتی**
به آن‌ها نشان داده نمی‌شود: <code dir="ltr">PaymentService::openPayment()</code> هیچ کارتی انتخاب نمی‌کند
(`payments.card_id` خالی)، پس سقف روزانه هیچ کارتی گرفته نمی‌شود، و <code dir="ltr">PaymentCards::offers()</code> روش را
پیشنهاد می‌کند، کارت‌هایش هر چه باشد (`to_support` در صفحه روش‌ها). شارژ کیف پول همچنان چند تومان بیشترش را درخواست
می‌کند (<code dir="ltr">PaymentCards::amountFor()</code>)، یکتا میان پرداخت‌های باز آن روش که کارتی به آن‌ها نشان داده
نشده — اول ردیف روش قفل می‌شود، همان‌طور که <code dir="ltr">pick()</code> کارت‌ها را قفل می‌کند. پرداخت باز آنچه را به آن
نشان داده شده نگه می‌دارد: کارتی از پیش از روشن شدن کلید، یا هیچ — که وقتی فروشگاه دوباره کارت نشان دهد
<code dir="ltr">openPayment()</code> به آن کارت می‌دهد، همان‌طور که به پرداختی از پیش از کارت‌ها. پرداختی که این‌طور کارت
می‌گیرد مبلغی را که خواسته بود نگه می‌دارد، مگر اینکه پرداخت باز دیگری به همان کارت همان مبلغ را بخواهد، که آن وقت مبلغ
تازه‌ای می‌گیرد (<code dir="ltr">amountFor(…, keeping:)</code>). نمایش از پرداخت پیروی می‌کند: <code dir="ltr">ManualGateway::initiate()</code> برای پرداختی که هیچ کارتی ندیده
<code dir="ltr">CardTransfer::toSupport()</code> را پاسخ می‌دهد (بدون کارت، با یادداشت روش)،
<code dir="ltr">CheckoutScreen::transfer()</code> بعد سفارش، راه رسیدن به پشتیبانی و یادداشت را می‌کشد و منتظر رسیدی
نمی‌ماند، <code dir="ltr">CustomerCheckout::answer()</code> به‌جای `card` مقدار `contact` را می‌دهد، و `PaymentDirectory` و
<code dir="ltr">ShopReports::receiptSubmitted()</code> جایی که کارت می‌بود `Payment::TO_SUPPORT` را می‌گویند.
<code dir="ltr">CustomerNotifier::paymentReminder()</code> هم از پرداخت پیروی می‌کند: پرداختی که کارتی ندیده به‌جای
«ارسال رسید» راه رسیدن به آنجا را می‌گیرد (<code dir="ltr">Buttons::supportChat()</code>، همان‌طور که `CheckoutScreen`
می‌کشد)، هر چه کلید حالا بگوید. پیام محافظت‌شده کارت همیشه از نو فرستاده می‌شود، هرگز با ویرایش پیام قبلی
(<code dir="ltr">Context::resend()</code> با `protect_content`: پیش‌فاکتور حذف می‌شود یا، بعد از ۴۸ ساعت تلگرام، دکمه‌هایش
برداشته می‌شود).

## دفترهای تراکنش

کیف پول (`WalletService`) و حجم نماینده (`TrafficPool`) دفترهای تراکنش فقط‌افزودنی‌اند که از راه `Core\Database\Ledger`
نوشته می‌شوند: ردیف صاحب دفتر قفل می‌شود، آخرین خط زیر قفل خوانده می‌شود، و هر خط موجودی بعد از خودش را دارد — آخرین
خط *خود* موجودی است (<code dir="ltr">User::balance()</code>، <code dir="ltr">Bot::trafficBalance()</code>)، بی‌آنکه نسخه
دیگری باشد که از آن فاصله بگیرد. برداشت فقط تا جایی می‌تواند زیر صفر برود که فراخواننده اجازه دهد (اعتبار یک نماینده).
فهرست‌ها موجودی‌ها را همراه ردیف‌هایشان می‌خوانند (<code dir="ltr">User::balanceColumn()</code>)، هرگز با یک کوئری برای هر
ردیف.

تغییرهای دستی پشتیبانی ممکن است با یک `Idempotency-Key` بیایند (پنل‌ها آن را می‌فرستند): افزایش یا کاهش کیف پول
(<code dir="ltr">WalletService::credit()</code>/<code dir="ltr">debit()</code> با <code dir="ltr">$key</code>) و تغییر حجم یک نماینده
(<code dir="ltr">TrafficPool::adjust()</code>) آن را روی خطی که می‌نویسند نگه می‌دارند (`request_key`، یکتا برای هر کاربر، و
برای حجم برای هر فروشگاه). همان کلید اگر دوباره بیاید — تلاش دوباره‌ای که پاسخش گم شده، یا دو درخواست هم‌زمان — با همان
خط پاسخ می‌گیرد، که زیر قفل دفتر خوانده می‌شود و چیزی نوشته نمی‌شود (`wasRecentlyCreated` برابر false؛ API کیف پول به
جای 201 با 200 پاسخ می‌دهد)؛ همان کلید با نوع، مبلغ یا متن دیگری `KeyReusedException` است. ادغام حساب‌ها کلیدی را که هر دو
دفتر دارند روی خط حساب باقی‌مانده نگه می‌دارد (<code dir="ltr">WalletService::takeOver()</code>).

خواندن قفل‌دار آخرین خط، شکاف ایندکس بعد از آن را هم قفل می‌کند، پس خط‌های دو صاحب دفتر در یک لحظه — شارژ کیف پول دو
تازه‌وارد، انتقال‌های دو کاربر — روی MySQL می‌توانند به بن‌بست (deadlock) برسند، و MySQL یک تراکنش را به‌طور کامل لغو
می‌کند: هیچ پولی دو بار جابه‌جا نمی‌شود، ولی درخواست شکست می‌خورد. برای همین هر نوشتن پولی، `Ledger::ATTEMPTS` بار، با
قاعده‌ای که در [قراردادهای کد](Conventions.md#فقط-یک-بار) آمده دوباره امتحان می‌شود: خود
<code dir="ltr">Ledger::append()</code>، یک انتقال موجودی، تحویل شارژ کیف پول و حجم نماینده، سفارشی که یک سفارش دستی
می‌سازد (<code dir="ltr">OrderService::openManual()</code>)، تسویه یک پرداخت (پیش‌فاکتور کیف پول،
<code dir="ltr">payBySupport()</code>، <code dir="ltr">approve()</code>)، یک بازپرداخت، کش‌بکی که به دست می‌آید، پورسانت
یک معرف و هدیه‌های شروع، تومان یک کد هدیه و یک چرخش گردونه. دوباره امتحان نمی‌شوند: آنچه پولی جابه‌جا نمی‌کند (حکم یک
رسید، یک لغو، یک انقضا، که تصویرهایی را که باید حذف شوند داخل خودش جمع می‌کند) و ادغام حساب. Laravel تمام شدن مهلت
انتظار برای قفل (lock wait timeout) را هم خطای هم‌زمانی می‌شمارد، پس نوشتنی که تا `innodb_lock_wait_timeout` برای یک قفل
منتظر مانده دوباره منتظر می‌ماند.

## انتقال موجودی

«انتقال موجودی» همان `Users\Services\WalletTransfers` است، یک قاعده برای هر دو ورودی — ربات
(`Telegram\Handlers\WalletTransferHandler`، <code dir="ltr">wsend:</code> و وضعیت گفتگو
<code dir="ltr">wsend[.{recipient}[.{amount}]]</code>) و وب‌سایت (`Store\Api\WalletController`: `GET /wallet/transfer` برای
گیرنده، `POST` برای انتقال؛ `Store\Services\CustomerWalletTransfers` برای آنچه `GET /wallet` درباره آن می‌گوید).
تنظیماتش `WalletTransferSettings` است (`wallet_transfers`، به‌طور پیش‌فرض خاموش).

- **گیرنده** — <code dir="ltr">recipient()</code> اول <code dir="ltr">check()</code> را می‌پرسد (هر دو ورودی: کاربری که
  چیزی برای فرستادن ندارد نام هیچ کاربر دیگری را نمی‌فهمد)، بعد او را از راه <code dir="ltr">CustomerLookup::find()</code>
  پیدا می‌کند، سرویس جست‌وجویی که انتقال‌ها با هم شریک‌اند: شناسه همان‌طور که تایپ شده، کاربری از فروشگاه فرستنده، و
  جست‌وجوهای یک کاربر با هم شمرده می‌شوند (`LOOKUPS` در هر `LOOKUP_WINDOW`)؛ بعد نه خود فرستنده، نه مسدودشده. ردها زیر
  `telegram_id` هستند.
- **مبلغ** — تومان کامل (<code dir="ltr">Input::amountOf()</code>) در حدهای فروشگاه، حداکثر به اندازه موجودی خود فرستنده:
  برداشت از راه <code dir="ltr">WalletService::debit()</code> و بدون اعتبار انجام می‌شود، پس اعتبار نماینده هرگز جابه‌جا
  نمی‌شود؛ و همراه آنچه فرستنده از نیمه‌شب به ساعت فروشگاه (<code dir="ltr">LocalTime::midnight()</code>) فرستاده، در
  محدوده سقف روزانه، وقتی سقفی تعیین شده باشد. <code dir="ltr">most()</code> کمترین مقدار از این سه است، همان که ربات
  می‌پرسد و `transfer.most` در `GET /wallet`؛ <code dir="ltr">check()</code> کسی را که فقط کمتر از کمترین انتقال می‌تواند
  بفرستد در قدم اول ربات، و پیش از هر جست‌وجویی، رد می‌کند. ردها زیر `amount`؛ فروشگاهی که هیچ انتقالی پیشنهاد نمی‌کند
  زیر `status`، مثل چیزهای دیگری که یک فروشگاه می‌تواند خاموش کند (`ServiceTransfers`، `CustomServices`). برای هر کاربر
  `TRANSFERS` انتقال در هر `TRANSFER_WINDOW`. سقف روزانه ۰ (بدون سقف) یا دست‌کم برابر کمترین انتقال است
  (`WalletTransferSettings`): سقفی کمتر از آن هیچ انتقالی برای انجام باقی نمی‌گذاشت.
- **جابه‌جایی** — <code dir="ltr">transfer()</code>: یک تراکنش که ردیف هر دو کاربر را به ترتیب شناسه قفل می‌کند (همان‌طور
  که <code dir="ltr">Ledger::merge()</code>، تا دو کاربری که هم‌زمان به هم پول می‌فرستند به نوبت منتظر بمانند)، انتقال‌های
  روز را با یک خواندن قفل‌دار جمع می‌زند، از فرستنده برمی‌دارد و به گیرنده واریز می‌کند (`InsufficientBalanceException` با
  عبارت `SHORT`)، و هر خط نام طرف دیگر را می‌برد (`LINE_SENT`، `LINE_RECEIVED`، <code dir="ltr">User::shownAs()</code>)،
  ردیف `wallet_transfers` را می‌نویسد — شناسه دو خط، چیزی که سقف جمع می‌زند و پنل‌ها کاربر طرف دیگر یک خط را از روی آن
  نشان می‌دهند (<code dir="ltr">UserDirectory::ledger()</code>، `WalletTransactionRow.counterpart`) — و
  <code dir="ltr">ShopReports::walletTransferred()</code> («شارژ کیف پول») را. بعد از commit،
  <code dir="ltr">CustomerNotifier::walletReceived()</code> به گیرنده خبر می‌دهد، و <code dir="ltr">walletSent()</code> اعلان
  فرستنده را نگه می‌دارد — که فقط وقتی انتقال از وب‌سایت باشد به چت او فرستاده می‌شود، چون ربات همان‌جا جواب داده است.
- **کلید وب‌سایت** — <code dir="ltr">transferById()</code> به `Idempotency-Key` که دیده (`wallet_transfers.request_key`، یکتا
  برای هر فرستنده) با انتقالی که انجام داده پاسخ می‌دهد، که برای دو درخواست هم‌زمان دوباره زیر قفل‌ها خوانده می‌شود؛ همان
  کلید با گیرنده یا مبلغ دیگر `KEY_REUSED` است. ادغام دو حساب همه ردیف‌ها را می‌برد (`AccountMerger::REFERENCES`، `unkey`):
  آنچه هر دو فرستاده‌اند به حساب یک سقف روزانه شمرده می‌شود، و کلیدی که هر دو به کار برده‌اند مال حساب باقی‌مانده است —
  انتقال دیگر نگه داشته می‌شود، بی‌کلید.

## جایزه‌ها

متد <code dir="ltr">Rewards::give(user, Reward, reason)</code> راهی است که هر جایزه یا هدیه‌ای به کاربر می‌رسد — تومان به
شکل یک خط کیف پول، حجم یا روز روی تازه‌ترین سرویس در حال کارش که آن را بپذیرد (در فروشگاه نماینده از موجودی حجم
نماینده)، یا یک پلن به شکل یک سرویس رایگان: یک سفارش خرید بدون قیمت، با `pricing` از نوع gift (پس هرگز خرید اولش
نیست)، که ردیف کیف پول تسویه‌اش می‌کند. `Eligibility` پیش از اینکه چیزی خرج شود (یک خانه گردونه، یک کد هدیه) جواب
می‌دهد «آیا این همین الان به او می‌رسد؟». هر دو فروشگاه را از نو می‌خوانند (<code dir="ltr">Bot::sellsNow()</code>): در
فروشگاه نماینده‌ای که فروشش متوقف است، فقط تومان داده می‌شود.

**سرویس رایگان یک پلن همین که سفارشش پرداخت شود داده‌شده حساب می‌شود**، تحویلش هر چه شود. تحویلی که یک پنل ردش کرده،
یا روی یک باگ شکسته (`OrderService::DELIVERY_BROKEN`)، سفارش را برای تلاش دوباره پشتیبانی باقی می‌گذارد
(«نیازمند رسیدگی»)، همان‌طور که شکست هر سفارش پرداخت‌شده‌ای: <code dir="ltr">Rewards::plan()</code> آن را لاگ می‌کند و
هرگز استثنا پرتاب نمی‌کند، پس هیچ فراخواننده‌ای جایزه را دوباره نمی‌دهد — هیچ چرخشی پس داده نمی‌شود، هیچ مرحله‌ای آزاد
نمی‌شود، هیچ برنده بعدی‌ای قرعه نمی‌خورد. فقط `RewardUnavailableException` یعنی چیزی داده نشده: هیچ سرویس در حال کاری
حجم یا روز را نمی‌پذیرد (روز را فقط سرویسی که پایانش با آن روزها در مرز دیتابیس بماند:
<code dir="ltr">ServiceTerms::daysLeft()</code>)، هیچ سروری پلن را تحویل نمی‌دهد، مدت پلن پایان سرویس را از آن مرز
می‌گذراند (با متن `TermTooLongException`)، یا فروش فروشگاه متوقف است.
<code dir="ltr">Rewards::failureLevel()</code> یک رد (رد یک پنل یا یک قاعده) را به‌عنوان هشدار لاگ می‌کند، و هر چیز دیگری را
به‌عنوان خطا.

- **کدهای هدیه** — هر تلاش پیش از سنجیده شدن شمرده می‌شود و وقتی کد وجود داشته باشد پس داده می‌شود، پس فقط کدهای
  اشتباه سهمیه را خرج می‌کنند؛ هر کد با یک به‌روزرسانی شرطی گرفته می‌شود، و کد یک پلن وقتی چیزی داده نشود پس داده
  می‌شود.
- **گردونه شانس** — فاصله میان چرخش‌ها (cooldown)، خانه‌های مجاز و هزینه در یک تراکنش زیر قفل ردیف کاربر بررسی و پرداخت
  می‌شوند؛ جایزه بعد از commit داده می‌شود. وقتی چیزی نتواند داده شود، هزینه برمی‌گردد و چرخش دلیلش را نگه می‌دارد و
  برای فاصله میان چرخش‌ها حساب نمی‌شود — هر دو در یک تراکنش.
- **کش‌بک** — برای هر سفارش یک بار به دست می‌آید (`cashbacks.order_id` یکتا)، بعد از commit تحویل
  (<code dir="ltr">OrderRewards::delivered()</code>)، پس قفل کیف پول هرگز جلوی یک تحویل را نمی‌گیرد و شکستی در آنجا هرگز
  تحویل را برنمی‌گرداند. بازپرداخت آن را با کمتر دادن به همان اندازه پس می‌گیرد.
- **قرعه‌کشی شبانه** — ردیف روز اول درج می‌شود (یکتا برای هر فروشگاه و روز)، پس دو اجرا هرگز دو بار قرعه نمی‌کشند.
  شانس‌ها سفارش‌هایی‌اند که از قرعه‌کشی قبلی به بعد پرداخت شده‌اند (<code dir="ltr">Lottery::window()</code>): از یک
  دقیقه پیش از آن قرعه‌کشی تا یک دقیقه پیش از این یکی، پس پرداختی که همان لحظه تسویه می‌شود در یک قرعه‌کشی شرکت می‌کند،
  و اجرای دیرهنگام یا ساعتی که جابه‌جا شده هیچ سفارشی را دو بار وارد نمی‌کند و هیچ‌کدام را جا نمی‌اندازد. بازه حداکثر ۴۸
  ساعت عقب می‌رود؛ اولین قرعه‌کشی یک فروشگاه ۲۴ ساعت پیش از خودش را می‌گیرد.

## زیرمجموعه‌گیری

فقط کاربری که فروشگاه هرگز ندیده زیرمجموعه می‌شود (<code dir="ltr">ReferralService::attribute()</code>، که
<code dir="ltr">Customers::register()</code> برای تازه‌وارد صدا می‌زند) — کاربر شناخته‌شده‌ای که لینکی را باز کند چیزی را
تغییر نمی‌دهد. پورسانت فقط روی پولی که وارد شده به دست می‌آید (<code dir="ltr">Payment::moneyIn()</code>): برای هر پرداخت
یک بار با یک ردیف یکتا، قاعده `first_only` زیر قفلی روی کاربر سنجیده می‌شود و خود پرداخت زیر قفل خودش دوباره خوانده
می‌شود، پس بازپرداختی که همان لحظه انجام شود یا اول می‌آید (چیزی به دست نمی‌آید) یا پورسانت را پیدا می‌کند. بازپرداخت
شارژ کیف پول آن را پس می‌گیرد (پولش از فروشگاه بیرون می‌رود: [پرداخت‌ها](#پرداخت‌ها))؛ هر بازپرداخت دیگری آن را سر
جایش باقی می‌گذارد. `referrer_id` همان‌طور که به دست آمده نگه داشته می‌شود، پس ادغام حساب هرگز درآمدهای گذشته را جابه‌جا نمی‌کند. معرف حساب
ادغام‌شده معرف حساب باقی‌مانده است، وگرنه معرف حساب دیگر — هرگز کسی که خود حساب باقی‌مانده، با هر چند واسطه، آورده است،
که یک حلقه می‌ساخت (`AccountMerger`).

کلاس `ReferralGifts` هدیه شروع (هنگام عضویت یا روی اولین خرید پولی) و جایزه دعوت را می‌دهد، هر دو از راه `Rewards`؛
«خرید کرده» یعنی <code dir="ltr">keptPurchases()</code>، پس خریدی که از آن به بعد بازپرداخت شده، خرید بعدی را اولین خرید
زیرمجموعه باقی می‌گذارد. جایزه دعوت مرحله‌های زیرمجموعه‌هایی را که خرید کرده‌اند می‌شمارد: اولین خرید هر زیرمجموعه
مرحله بعدی‌ای را که شمارش به آن رسیده تصاحب می‌کند — کوچک‌ترین مضرب عدد ادمین بالای همه مرحله‌هایی که تا حالا تصاحب
شده —، زیر قفلی روی معرف، با یک ردیف جداگانه پیش از اینکه چیزی داده شود. پس دو اولین خریدی که هم‌زمان از دو مرحله
می‌گذرند هر دو را می‌دهند، پایین آوردن عدد هرگز چند جایزه را یک‌جا نمی‌پردازد، و مرحله‌ای که پلنش نتوانست داده شود دوباره آزاد می‌شود
تا اولین خرید بعدی آن را تصاحب کند. تا وقتی یک هشدار زیرمجموعه‌گیری فیک پابرجاست
(`ReferralAlerts`: موجی از عضویت‌ها، یا زیرمجموعه‌های زیادی که هیچ‌کدام خرید نکرده‌اند) هر دو نگه داشته می‌شوند و
تصمیم‌گرفته ثبت می‌شوند، پس پاک کردن هشدار هرگز بعدا آن‌ها را نمی‌پردازد. `ReferralService` هر وقت لازم باشد
`ReferralGifts` را از کانتینر می‌گیرد: وگرنه `ReferralGifts → Rewards → PaymentService → ReferralService` یک حلقه در
سازنده‌ها (constructor) می‌شد.

## نمایندگی

نماینده کاربری از فروشگاه اصلی است با یک سطح (`users.agency_level_id`) و ربات خودش — یک فروشگاه کامل از آن خودش.
درخواست با compare-and-swap تایید یا رد می‌شود؛ تایید فروشگاه را باز می‌کند (ردیف ربات و کیف پول)، پایان نمایندگی ربات را
خاموش می‌کند و فروشگاهش را برای بازگشت نگه می‌دارد. نماینده حجم را با قیمت هر گیگ سطح خودش می‌خرد؛ هر تحویل رباتش اول
از همین موجودی برمی‌دارد، برای هر سفارش یک بار، و اگر تحویل شکست بخورد پسش می‌دهد (<code dir="ltr">withTraffic()</code>
در `OrderService`). اینکه موجودی حجم هزینه یک فروش را می‌پردازد یا نه یک سنجش واحد است،
<code dir="ltr">Bot::trafficRefusal()</code> — سقف حجم نامحدود، یا مانده خیلی کم، با عبارت‌های `TrafficShortException` —:
تحویل از روی آن برمی‌دارد، پلنی که ردش کند پیشنهاد نمی‌شود (<code dir="ltr">ServerSelector::covers()</code>)، و
`ManualOrders` پیش از ساختن هر چیزی آن را می‌پرسد. تنظیمات برنامه نمایندگی در ردیف‌های ربات اصلی است، هر فروشگاهی که
آن‌ها را بخواند.

**آنچه مالک فروشگاه از فروشگاه یک نماینده پنهان نگه می‌دارد** روی مدل `Bot` است، که هر ماژولی می‌تواند نامش را بیاورد:
`bot_hidden_servers` (<code dir="ltr">Bot::hiddenServerIds()</code>، <code dir="ltr">hidesServer()</code>، برای هر ردیف یک
بار خوانده می‌شود) و `plans.hidden_by_owner`. هیچ global scopeی روی `Server` نیست — سرویس‌های موجود، همگام‌سازی، تحویل‌ها
و هدیه‌های زمان و حجم به همه سرورها نیاز دارند. فیلترها جایی هستند که چیز تازه‌ای فروخته می‌شود:
<code dir="ltr">ServerSelector::choicesAmong()</code> از ردیف سروری که روی سرور پنهان است می‌گذرد (پلنی که بی‌ردیف سرور
بماند `NO_CHOICE` است، و آن ردیف `HIDDEN_SERVER`) — ولی نه برای تحویل سفارش پرداخت‌شده
(<code dir="ltr">resolve(paid: true)</code>، <code dir="ltr">ProvisioningService::provision()</code>) —؛
<code dir="ltr">SubscriptionActions::move()</code> (برای همه جز مالک فروشگاه)، `ManualOrders` و
<code dir="ltr">PlanService::validateServers()</code> (سروری که پلن از قبل رویش نبوده) آن را رد می‌کنند، همه
با عبارت `HIDDEN_SERVER` — <code dir="ltr">moveTarget()</code> رد نمی‌کند، پس `ServerMigrations` مالک فروشگاه و انتقال‌های تکی او
سرویس‌های یک نماینده را هم می‌برند —؛ و فهرست‌های انتخاب <code dir="ltr">Server::shown()</code> را می‌خوانند، یعنی سرورهای
فروشگاه فعلی جز پنهان‌هایش: <code dir="ltr">TestServers::choices()</code>،
<code dir="ltr">TestAccountDirectory::servers()</code>، <code dir="ltr">StoreCatalog::status()</code>،
<code dir="ltr">PlanService::options()</code> و سرورهای `DiscountCodes`. <code dir="ltr">Plan::active()</code> (و
<code dir="ltr">Plan::onSale()</code>) پلن‌های پنهان را کنار می‌گذارند، و `Rewards` هیچ جایزه‌ای از آن‌ها نمی‌دهد؛ هیچ چیز
در `PlanService` این ستون را نمی‌نویسد — فقط `Catalog\Services\AgentRestrictions` (`PUT /agency/agents/{id}/hidden` مالک
فروشگاه) — و <code dir="ltr">duplicate()</code> آن را کپی می‌کند. تمدیدها و خریدهای اضافه سرویسی که از قبل روی سرور پنهان
است، یا از یک پلن پنهان، ادامه دارند: چیز تازه‌ای نمی‌سازند.

**شرایط فروش یک نماینده** (`Agency\Services\AgencyStanding`): `bots.agency_expires_at` و `monthly_minimum`، با
`selling_suspended_at` و `suspension_reason` (`Bots\Enums\SellingSuspension`: expired، minimum، manual) و نشانگرهایی که
هر هشدار را به یک بار محدود می‌کنند (`expiry_warned_at`، `minimum_warned_month`) و هر ماه را یک بار می‌سنجند
(`minimum_checked_month`، یک `YYYY-MM` شمسی که مثل ماهش مقایسه می‌شود — `Support\JalaliMonth`، به ساعت فروشگاه). هر دلیل
یک بار عبارت‌بندی شده است، <code dir="ltr">SellingSuspension::words()</code>: اعلان نماینده و خط حسابش در ربات اصلی، گزارش
گروه اصلی (بعد <code dir="ltr">remedy()</code>، راه برگشت مالک فروشگاه) و، کلمه به کلمه کپی‌شده، `SELLING_SUSPENSION`
پنل‌ها. کف خرید برنامه و کف خرید خود یک نماینده یک فیلدند (<code dir="ltr">AgencySettings::minimumOf()</code>): تومان کامل،
۰ برای هیچ — `monthly_minimum` خالی (null). <code dir="ltr">Bot::sells()</code> تنها پرسشی است که همه ورودی‌ها می‌پرسند
— برای ربات اصلی true؛ پایانی که گذشته بلافاصله حساب می‌شود، پیش از اینکه `Agency\Tasks\AgencyStandingTask` ساعتی آن را
ثبت کند. هرگز <code dir="ltr">Bot::status()</code> نیست، که ربات، Webhookش، پنلش، وب‌سایتش و کارهایش را متوقف می‌کرد. جایی
که پاسخ باید همان باشد که دیتابیس همین الان دارد — پروسه پولینگ یک ربات ردیف فروشگاهش را چند ثانیه نگه می‌دارد، و توقف
از همان لحظه که نوشته شود برقرار است — ورودی <code dir="ltr">Bot::sellsNow()</code> را می‌پرسد، که ردیف نماینده را دوباره
می‌خواند (بدون قفل: اکانت تست رایگان ردیف فروشگاه را پیش از ردیف کاربرش قفل می‌کند): <code dir="ltr">OrderService::open()</code>
آن را بار دوم داخل تراکنشش، زیر قفل ردیف کاربر، می‌پرسد، و <code dir="ltr">Rewards::give()</code>،
<code dir="ltr">Eligibility::givable()</code> و <code dir="ltr">GiftCodes::redeem()</code> هم آن را می‌پرسند. ورودی‌ها:
<code dir="ltr">OrderService::open()</code> (هر خرید، خرید سرویس ساخته‌شده، تمدید، خرید اضافه و شارژ — هرگز
سفارش `OrderType::Traffic`، که در فروشگاه اصلی خریده می‌شود)، <code dir="ltr">openGift()</code>،
<code dir="ltr">createRenewal()</code> و <code dir="ltr">AutoRenewal::due()</code>، `RenewalRefusal::SellingSuspended` در
کلاس `CustomerRenewal`، <code dir="ltr">CustomerExtras::offersFor()</code>/<code dir="ltr">quote()</code> (نه
<code dir="ltr">refusal()</code>، که تحویل خرید اضافه‌ای که قبلا پرداخت شده می‌پرسد)، `TestRefusal::Suspended` در
کلاس `TestAccounts`، <code dir="ltr">ServerSelector::covered()</code> و <code dir="ltr">weigh()</code> (هیچ پلنی پیشنهاد
نمی‌شود)، <code dir="ltr">Rewards::give()</code> (تومان همچنان داده می‌شود — <code dir="ltr">RewardKind::isSold()</code>) و
<code dir="ltr">Rewards\Services\Eligibility::givable()</code> پیش از آن (خانه گردونه فقط از میان تومان و «پوچ» قرعه
می‌خورد، و کد هدیه‌ای که ارزشش یک پلن است با عبارت `RewardUnavailableException::SUSPENDED` رد می‌شود)،
<code dir="ltr">SubscriptionActions::extend()</code> (مالک فروشگاه همچنان می‌تواند) — کارکنان فروشگاه یک عبارت را
می‌خوانند، `SellingSuspendedException::STAFF` —، <code dir="ltr">CustomerCheckout::ordering()</code> در وب‌سایت (503،
`taking_orders` برابر false — رسیدها همچنان گرفته می‌شوند؛ آنچه فقط قیمت یک سفارش را می‌دهد، یعنی پیش‌نمایش تمدید و
استعلام قیمت خرید اضافه، آن را به شکل 422 روی `status` می‌گوید:
<code dir="ltr">SellingSuspendedException::onStatus()</code>) و <code dir="ltr">CheckoutScreen::buy()</code> در
ربات (`SellingSuspendedException`). ربات به‌جای صفحه `BotText::SellingPaused` را می‌گوید — متن یک پیام، هرگز یک پاپ‌آپ، تا HTML
نماینده همان‌طور که نوشته شده نشان داده شود — (<code dir="ltr">CheckoutScreen::paused()</code>): در یک تمدید، یک خرید
اضافه و هر قدم شارژ کیف پول (`TopUpHandler`، که دکمه‌اش کاربر را از «کیف پول» بیرون می‌برد)، همان‌طور که در پلن‌ها و
یک اکانت تست رایگان.
تحویل چیزی نمی‌پرسد: آنچه پرداخت شده تحویل داده می‌شود. وقتی تمدید خودکار منتظر است، `ServiceReminders` پایان سرویسی را
که «تمدید خودکار» آن روشن است هم یادآوری می‌کند. هر توقف و هر برداشتن آن یک UPDATE شرطی است، پس دو اجرا، یا یک اجرا و
مالک فروشگاه، فقط یک بار به نماینده (<code dir="ltr">CustomerNotifier::agencySuspended()</code>،
<code dir="ltr">agencyResumed()</code>) و گروه اصلی (<code dir="ltr">ShopReports::agencySuspended()</code>) خبر می‌دهند.
توقفی که به خاطر کف خرید است با رسیدن ماه جاری به کف خرید برداشته می‌شود — در تحویل سفارش حجم
(<code dir="ltr">AgencyStanding::bought()</code>، بعد از commit، بی‌آنکه هرگز تحویل را ناموفق کند) یا در اجرای بعدی —، و
برای ماهی که کم آمده اصلا توقفی پیش نمی‌آید اگر ماه جاری پیش از اینکه اجرا آن را بسنجد به کف رسیده باشد. در هر زمان یک
دلیل برقرار است (manual بر expired، و expired بر minimum مقدم است): برداشتن دلیل قوی‌تر همه را برمی‌دارد، از جمله دلیل
ماهی که کم آمده — جز برداشتن پایان نمایندگی (<code dir="ltr">AgencyStanding::liftEnd()</code>): کف خرید ماه گذشته، که
تا وقتی پایان نمایندگی فروشگاه را نگه داشته بود سنجیده نشده، آن وقت سنجیده می‌شود، و اگر هم ماه گذشته و هم ماه جاری از
آن کمتر بوده باشند، فروشگاه با یک نوشتن شرطی متوقف می‌ماند، این بار به خاطر کف خرید (و به نماینده و گروه همین گفته
می‌شود). پایان نمایندگی‌ای که مدت برنامه هنگام تایید حساب می‌کند به مرز دیتابیس محدود است
(<code dir="ltr">AgencyActions::approve()</code>، [دیتابیس](Database.md#زمان-و-مرز-۲۰۳۸)).

## کاربران

کلاس `Customers` هر تازه‌واردی را به یک شکل ثبت می‌کند — ربات، یا ورود با تلگرام، ایمیل یا گوگل در وب‌سایت — در یک
تراکنش همراه معرفش و گزارش؛ گزارشی که دیتابیس ردش کند تازه‌وارد را هم برمی‌گرداند. پروفایل تلگرام همان‌طور نگه داشته
می‌شود که <code dir="ltr">Customers::profile()</code> آن را می‌خواند: نام یا نام خانوادگی‌ای که هیچ چیز نشان نمی‌دهد
(<code dir="ltr">Support\Text::blank()</code>: فقط فاصله از هر نوع، کاراکترهای بدون عرض یا پرکننده) نام حساب نمی‌شود. یک
بازدید تنها حداکثر یک بار در دقیقه و بی‌صدا نوشته می‌شود (<code dir="ltr">ChangeFeed::quietly()</code>)، تا حضور کاربر
باعث نشود هر پنل بازی دوباره بارگذاری شود. `UserActions` تصمیم‌های پشتیبانی درباره یک حساب را در خود دارد (مدیران سایت
هرگز به حساب یک مدیر دیگر یا یک نماینده دست نمی‌زنند). `CustomerPictures` تنها قاعده تصویرهای آپلودشده است (رسیدها، تصویرهای تیکت): از روی بایت‌هایشان سنجیده
می‌شوند، دوباره encode می‌شوند، برای هر کاربر یک سهمیه دارند، و وقتی دیسک هاست تقریبا پر است با 503 رد می‌شوند.

## تست‌ها

تست‌های feature به نام آنچه پوشش می‌دهند نام‌گذاری شده‌اند (`PaymentSettlementTest`، `DiscountsTest`، `CashbackTest`،
`ReferralGiftsTest`، و `Admin*ApiTest` برای هر صفحه). ردیف‌ها را با helperهای `Tests\Support\Fixtures` بسازید —
<code dir="ltr">plan()</code>، <code dir="ltr">sellingServer()</code>، <code dir="ltr">buy()</code> (پرداخت‌شده از کیف
پول)، <code dir="ltr">paidByCard()</code>، <code dir="ltr">wallet()</code>، <code dir="ltr">agent()</code>،
<code dir="ltr">agentBot()</code>، <code dir="ltr">traffic()</code>، <code dir="ltr">discountCode()</code>،
<code dir="ltr">sale()</code>، <code dir="ltr">loyaltyTier()</code>، <code dir="ltr">cashbackOn()</code>،
<code dir="ltr">giftCode()</code>، <code dir="ltr">wheelOn()</code>، <code dir="ltr">lotteryOn()</code>،
<code dir="ltr">referralProgram()</code>، <code dir="ltr">customServicesOn()</code> — و برای فیک‌ها (یک پنل فیک، یک
تلگرام فیک) [تست](Testing.md) را ببینید.

</div>
