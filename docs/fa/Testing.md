[English](../Testing.md)

<div dir="rtl" lang="fa">

# تست

اینکه مجموعه‌های تست چطور ساخته شده‌اند، هر کلاس پایه و هر helper چه چیزی در اختیارتان می‌گذارد، و چه چیزهایی جدا از
خود تست‌ها کد را درست نگه می‌دارند. پیش از هر Pull request همه را با `composer check` و `pnpm check` اجرا کنید
([توسعه](Development.md#بررسی‌ها)).

## مجموعه تست PHP

دستور `vendor/bin/phpunit` (یا `composer test`) پوشه‌های `tests/Unit` و `tests/Feature` را اجرا می‌کند، حدود ۳٬۷۰۰ تست:

- **با پیکربندی خودش**: برنامه در هر پروسه تست یک بار با `config.php` مخصوص خود مجموعه تست بالا می‌آید
  (`TestCase::CONFIG`: یک دیتابیس SQLite در حافظه، یک `APP_KEY` ثابت، UTC، بدون توکن ربات، بدون اطلاعات ورود پنل)، که
  به محض خوانده شدن پاک می‌شود؛ هرگز فایل همین سیستم. هر فایلی که برنامه می‌نویسد (`TestCase::FILES`: قفل نصب و
  کلیدها، شمارش‌های محدودیت تعداد تلاش (throttle)، آپلودها، وضعیت زمان‌بند…) به پوشه‌ای مخصوص همان اجرا می‌رود که بعد از هر
  تست خالی می‌شود.
- **بدون شبکه**: هر transport خروجی تا وقتی تستی یک فیک به آن ندهد، پاسخ «شبکه در دسترس نیست» می‌دهد، و هیچ چیز واقعا
  صبر نمی‌کند (sleep).
- **سخت‌گیر**: ترتیب تصادفی، و هر deprecation، notice یا خروجی ناخواسته باعث رد شدن تست می‌شود. PHPStan تست‌ها را هم
  بررسی می‌کند.

## کلاس‌های پایه

| کلاس | برای | چه می‌دهد |
|---|---|---|
| `Tests\TestCase` | تست واحدی که به برنامه نیاز دارد | <code dir="ltr">app()</code>، <code dir="ltr">service()</code>، <code dir="ltr">config()</code>، <code dir="ltr">swap()</code>، <code dir="ltr">logs()</code>، <code dir="ltr">configFile()</code>، <code dir="ltr">scratchDir()</code>، و فیک‌های پایین‌تر |
| `Tests\DatabaseTestCase` | هر چیزی که به دیتابیس دست می‌زند | جدول‌هایی که در هر پروسه یک بار ساخته می‌شوند، هر تست در یک تراکنش که بعد از آن rollback می‌شود، و `Fixtures` |
| `Tests\HttpTestCase` | API | <code dir="ltr">get()</code>، <code dir="ltr">postJson()</code>… (با هدر CSRF)، <code dir="ltr">send()</code> (بدون آن)، <code dir="ltr">upload()</code>، <code dir="ltr">loginAsAdmin()</code>، <code dir="ltr">loginAsAgent()</code>، <code dir="ltr">openShop()</code>، <code dir="ltr">bearer()</code>، <code dir="ltr">loginAsStaff()</code>، <code dir="ltr">storeApi()</code>، <code dir="ltr">unchecked()</code>، <code dir="ltr">decode()</code> |
| `Tests\BotTestCase` | ربات تلگرام | <code dir="ltr">send($this->message(…))</code>، <code dir="ltr">tap()</code>، <code dir="ltr">contact()</code>، برای گروه گزارش‌ها <code dir="ltr">groupTap()</code>، <code dir="ltr">groupText()</code>، <code dir="ltr">groupReply()</code>، <code dir="ltr">botMembership()</code>؛ بعد <code dir="ltr">calls()</code>، <code dir="ltr">params()</code>، <code dir="ltr">said()</code>، <code dir="ltr">popup()</code>، <code dir="ltr">inlineKeyboard()</code>، <code dir="ltr">callbacks()</code>، <code dir="ltr">buttonsUnder()</code> |

چند نکته که باید بدانید:

- **یک برگ (leaf) را عوض کنید، نه یک سرویس را.** در هر پروسه فقط یک کانتینر هست. تست جایگزین تستی (double) خودش را با
  عوض کردن یک transport یا مسیر یک فایل به برنامه می‌دهد، هرگز با عوض کردن سرویسی که بقیه نگهش داشته‌اند.
  متد <code dir="ltr">swap($id, $double, ...$rebuild)</code> برای جایگزین یک‌باره و نادر است، و در آن ورودی‌هایی از
  کانتینر را که آن را نگه می‌دارند نام می‌برید تا دوباره ساخته و بعد به حالت قبل برگردانده شوند.
- **فید تغییرات** تراکنش تست را همان دیتابیس حساب می‌کند (<code dir="ltr">ChangeFeed::countAsCommitted()</code>)، پس
  نوشتن‌ها در یک تست حوزه‌های پنل‌ها را همان‌طور جلو می‌برند که نوشتن‌های commit‌شده.
- **شنونده‌های رویداد (event listener) را همان‌طور بگذارید که پیدایشان کردید**: تستی که در پایان آن‌ها را تغییر داده
  باشد رد می‌شود. هرگز یکی را دستی با <code dir="ltr">forget()</code> برندارید، که شنونده‌های خود برنامه را هم برمی‌دارد.
  برای انجام کاری در یک لحظه دقیق، مثلا حرکت پروسه‌ای دیگر در یک race، از
  متد <code dir="ltr">whileListening($event, $listener, $work)</code> استفاده کنید که شنونده‌ها را به حالت قبل برمی‌گرداند؛
  متد <code dir="ltr">whileTheOrderClosesOnceRead()</code> همان race تسویه (settlement) است.
  متد <code dir="ltr">withTheTurnOver($work)</code> کار را طوری اجرا می‌کند که انگار در پایان یک نوبت زمان‌بند است.
- **تست‌های HTTP در هر دو جهت** با توصیف API اعتبارسنجی می‌شوند ([قرارداد API](API-Contract.md)). درخواستی که هیچ
  کلاینتی نمی‌فرستد از <code dir="ltr">unchecked()</code> می‌گذرد.
- **در تست HTTP فروشگاه نصب‌شده حساب می‌شود**؛ <code dir="ltr">installed(false)</code> نقش یک فروشگاه تازه را بازی
  می‌کند، و <code dir="ltr">configureAdmin(null)</code> فروشگاهی بدون اطلاعات ورود. <code dir="ltr">loginAsAdmin()</code>
  مستقیم Session را باز می‌کند؛ ورود از راه HTTP کار تست‌های احراز هویت است.
- **زمان** با <code dir="ltr">Carbon::setTestNow()</code> جابه‌جا می‌شود؛ throttle، کدهای TOTP و leaseها همه ساعت
  فروشگاه را می‌خوانند.

## فیکسچرها

ردیف‌ها از `Tests\Support\Fixtures` می‌آیند، هرگز از <code dir="ltr">Model::create()</code> در خود تست. هر سازنده
مقدارهای پیش‌فرضی دارد برای آنچه تست به‌ندرت برایش مهم است، و <code dir="ltr">$overrides</code>، که آخر از همه ادغام
می‌شود، برای آنچه مهم است: <code dir="ltr">customer()</code>، <code dir="ltr">webCustomer()</code>، <code dir="ltr">admin()</code>، <code dir="ltr">plan()</code>، <code dir="ltr">sellingServer()</code> (روی پنل فیک، آماده فروش)، <code dir="ltr">fakeServer()</code>، <code dir="ltr">panelServer()</code>، <code dir="ltr">cardMethod()</code>، <code dir="ltr">purchaseOrder()</code>، <code dir="ltr">cardPayment()</code>، <code dir="ltr">receipt()</code>، <code dir="ltr">subscription()</code>، <code dir="ltr">agent()</code>، <code dir="ltr">agentBot()</code>، <code dir="ltr">website()</code>، <code dir="ltr">customerSession()</code>، <code dir="ltr">ticket()</code>، <code dir="ltr">review()</code> و بیشتر.
بعضی یک سناریوی کامل می‌سازند: <code dir="ltr">buy()</code> (پرداخت از کیف پول)، <code dir="ltr">renew()</code>، <code dir="ltr">paidByCard()</code> (رسیدی فرستاده و تایید شده)، <code dir="ltr">reportGroup()</code> (وصل‌شده، با همه تاپیک‌ها ساخته‌شده)، و کلیدهای روشن/خاموش برنامه‌ها (<code dir="ltr">referralProgram()</code>، <code dir="ltr">agencyProgram()</code>، <code dir="ltr">extrasOnSale()</code>…).
موجودی‌ها دفتر تراکنش‌اند: <code dir="ltr">wallet($user, $balance)</code> و <code dir="ltr">traffic($bot, $gb)</code> همان
یک خطی را اضافه می‌کنند که موجودی را به آن مقدار می‌رساند. هر فیکسچر ردیف‌هایش را در فروشگاه فعلی می‌سازد؛ برای فروشگاه یک نماینده آن
را داخل <code dir="ltr">CurrentBot::run($bot, …)</code> بگذارید. متدهای <code dir="ltr">panelActor()</code>
و <code dir="ltr">groupAdminActor()</code> همان Actorها برای تستی هستند که مستقیم یک سرویس را صدا می‌زند.

## فیک‌ها

هر فیک جای یک طرف بیرونی را می‌گیرد و آنچه برنامه برایش فرستاده را ثبت می‌کند. فیک یک سرویس HTTP یک handler از Guzzle
است که زیر تنها کلاینت خروجی برنامه گذاشته می‌شود: <code dir="ltr">TestCase::outgoingFake($class)</code> بار اولی که
تستی آن را بخواهد آن را می‌سازد و سر جایش می‌گذارد، و بعد از تست کلاینت دوباره آفلاین است. هر کدام از این فیک‌ها helper
خودش را در جدول پایین دارد، که <code dir="ltr">outgoingFake()</code> را صدا می‌زند.

| فیک | به جای | دسترسی با |
|---|---|---|
| `Support\FakeTelegram` | Bot API و long pollهای bot:poll | <code dir="ltr">telegram()</code>: <code dir="ltr">reply()</code>، <code dir="ltr">fail()</code>، <code dir="ltr">on($method, …)</code>، <code dir="ltr">calls()</code>، <code dir="ltr">params()</code>، <code dir="ltr">sentTo()</code> |
| `Support\FakePanel` | هر HTTP خروجی دیگر: یک پنل VPN، Resend | <code dir="ltr">panelHttp()</code>: <code dir="ltr">ok()</code>، <code dir="ltr">fail()</code>، <code dir="ltr">raw()</code>، <code dir="ltr">refuse()</code>، <code dir="ltr">calls()</code>، <code dir="ltr">request()</code>، <code dir="ltr">params()</code>، <code dir="ltr">options()</code> |
| `Fakes\FakePanelDriver`، `Fakes\FakeProvider` | کانکتور یک پنل VPN، برای همه چیزهای بالای کانکتور | <code dir="ltr">fakePanel()</code>، بعد یک سرور با `driver: fake` |
| `Support\FakeCubePay`، `FakeZarinpal`، `FakeAtlasPay`، `FakeAban`، `FakePlisio`، `FakeAqayePardakht`، `FakeVariza` | API هر درگاه آنلاین | <code dir="ltr">cubePay()</code>، <code dir="ltr">zarinpal()</code>، <code dir="ltr">atlasPay()</code>، <code dir="ltr">aban()</code>، <code dir="ltr">plisio()</code>، <code dir="ltr">aqayePardakht()</code>، <code dir="ltr">variza()</code>: هر کدام یک پرداخت را همان‌طور که درگاه خودش می‌کند پرداخت، بسته یا رد می‌کند، و <code dir="ltr">down()</code> آن را از دسترس خارج می‌کند؛ <code dir="ltr">FakeVariza::webhook()</code> یک Webhook می‌نویسد و امضا می‌کند تا تست آن را بفرستد |
| `Support\FakeTelegramLogin`، `FakeGoogleLogin` | سرویس‌دهنده‌های OpenID (کلیدها امضاشده با `SigningKey`) | <code dir="ltr">telegramLogin()</code>، <code dir="ltr">googleLogin()</code> |
| `Support\FakeTurnstile`، `AltchaWidget` | siteverify سرویس Cloudflare؛ مرورگر بازدیدکننده‌ای که ALTCHA را حل می‌کند | <code dir="ltr">turnstile()</code>؛ <code dir="ltr">AltchaWidget::solve()</code> |
| `Fakes\RecordingMailTransport` | سرور ایمیل | <code dir="ltr">mail()</code>: <code dir="ltr">sent()</code>، <code dir="ltr">to()</code>، <code dir="ltr">codeIn()</code>، <code dir="ltr">failing()</code> |
| `Fakes\ProbedDriver` | دیتابیسی که تست به probe آن پاسخ می‌دهد | <code dir="ltr">probedDatabase($key)</code> |
| `Support\FakeGitHub`، `FakeRelease` | آخرین نسخه منتشرشده در GitHub و فایل‌هایش (<code dir="ltr">down()</code>، <code dir="ltr">answer()</code>، <code dir="ltr">storeOn()</code>)، و نسخه‌ای که برای تست امضا شده | کلاس `UpdateTestCase` تست‌های به‌روزرسانی: <code dir="ltr">$this->gitHub</code> (<code dir="ltr">outgoingFake(FakeGitHub::class)</code>)، <code dir="ltr">release()</code> |
| `Fakes\NullSleeper`، `RecordingSleeper` | صبر کردن | از زمان بالا آمدن برنامه سر جایش است؛ نسخه ضبط‌کننده وقتی تستی صبرها را بررسی می‌کند |

پوشه `tests/Fakes` جایگزین‌های کوچکی را هم نگه می‌دارد که یک تست به‌صورت یک کلاس نام‌دار لازم دارد (یک handler که
exception پرتاب می‌کند، یک task که اجراهایش را می‌شمارد، یک جدول lease‌شده).

## کنترل‌هایی فراتر از تست‌ها

- **سهمیه کوئری‌ها**: `tests/Feature/ListQueriesTest` هر فهرست پنل‌ها و Store API را یک بار با چند ردیف و یک بار با
  ردیف‌های زیاد می‌خواند، و اگر دومی کوئری بیشتری بزند رد می‌شود؛ `BotQueryBudgetTest` هر صفحه رایج ربات را به تعداد
  کوئری‌ها و فراخوانی‌های تلگرامش پایبند نگه می‌دارد. هر بار که سهمیه‌ای بالا برده می‌شود، باید گفته شود کوئری تازه چه
  چیزی به دست می‌دهد.
- **لایه‌ها**: `tests/Unit/Core/LayersTest` رد می‌شود وقتی Core یا Support نام یک ماژول را بیاورد، یا وقتی ماژول‌هایی که
  یک ماژول نام می‌برد با نگاشت `MODULES` آن فرق داشته باشد ([معماری](Architecture.md#ماژول‌ها)).
- **مسیرها**: `tests/Feature/RouteGuardsTest` هر مسیر (route) را به ترتیب از همه بررسی‌های امنیتی (guard) می‌گذراند، و
  با ردیفی از فروشگاه دیگر هم.
- **توصیف API**: `tests/Unit/Http/ApiDescriptionTest` فایل `resources/api/openapi.yaml` را با مسیرها هماهنگ نگه
  می‌دارد، شیءهای آن را بسته (بدون فیلد اضافه)، و قابل خواندن با ابزارهای خود یک وب‌سایت
  ([قرارداد API](API-Contract.md#چطور-طرف-php-به-آن-پایبند-می‌ماند)).
- **ارتقاها**: `tests/Unit/Database/UpgradesTest` مطمئن می‌شود هر تغییر اسکیما ارتقای خودش را دارد
  ([دیتابیس](Database.md#ارتقاها)).
- **درایورها**: `tests/Unit/Drivers/DriverFormsTest` شکل درخواست هر درایور را با فرم آن هماهنگ نگه می‌دارد
  ([درایورها](Drivers.md#هسته)).
- **کد مرده**: آشکارساز کد مرده PHPStan روی هر چیزی که هیچ چیز صدایش نمی‌زند خطا می‌دهد؛ تست صدازننده حساب نمی‌شود.

## پنل‌ها

دستور `pnpm test` مجموعه تست Vitest پنل‌ها را اجرا می‌کند (happy-dom). تست‌ها کنار چیزی که تست می‌کنند، با نام
فایل <code dir="ltr">*.test.ts[x]</code>، قرار دارند و رفتار را همان‌طور تست می‌کنند که یک صفحه از آن استفاده می‌کند. هر
تست به جای `fetch` یک سرور فیک می‌گیرد (`src/test/server`: <code dir="ltr">server().on(method, path, answer)</code>، <code dir="ltr">hold()</code>، <code dir="ltr">sent()</code>)؛
درخواستی که هیچ مسیری به آن پاسخ ندهد تست را رد می‌کند، و همین‌طور درخواستی که توصیف API آن را نپذیرد. ماژول `src/test/render` یک صفحه را با providerهای برنامه رندر
می‌کند (<code dir="ltr">providers()</code>، <code dir="ltr">signedIn()</code>)، `src/test/rows` ردیف‌های کامل API
می‌سازد، و زمان فیک است: هیچ چیز واقعا صبر نمی‌کند. صفحه [ساختار داخلی: پنل‌ها](Internals-Panels.md) جزئیات بیشتری
دارد.

## مستندات

دستور `php scripts/docs.php --check` تا وقتی یک صفحه تولیدشده کهنه باشد، یا پیوندی میان صفحه‌ها به جایی نرسد، رد
می‌شود ([مشارکت](Contributing.md#مستندات)).

## اجرا در CI

هر push به `main` و هر Pull request به آن روی GitHub Actions اجرا می‌شود (<code dir="ltr">.github/workflows/ci.yml</code>):
دستور `composer check` و بررسی مستندات روی PHP 8.2، 8.3 و 8.4 (همه نسخه‌هایی که یک فروشگاه ممکن است اجرا کند، هر کدام
با کل مجموعه تست)، و `pnpm check` پنل‌ها و یک بیلد production. تست‌های به‌روزرسانی افزونه‌های `sodium` و `zip` را لازم
دارند، که CI دارد؛ روی سیستم خودتان، `php -d extension=sodium -d extension=zip vendor/bin/phpunit` آن‌ها را اجرا می‌کند.

</div>
