[English](../Drivers.md)

<div dir="rtl" lang="fa">

# درایورها

آنچه فروشگاه از راه دیگران انجام می‌دهد یک **درایور** از یک خانواده است: پنل VPN که روی آن می‌فروشد، روشی که با آن
پول می‌گیرد، دیتابیسی که روی آن اجرا می‌شود، راهی که ایمیل‌هایش فرستاده می‌شوند، کپچایی که فرم‌های یک وب‌سایت
می‌پرسند. همه خانواده‌ها روی یک هسته کوچک بنا شده‌اند، پس یک درایور تازه یعنی یک کلاس، ثبت آن و شکل درخواستش — پنل‌ها
فرمش را از روی توصیف آن رسم می‌کنند، بدون هیچ کدی از آن خودشان، جز جایی که یک خانواده در ادامه چیز دیگری بگوید.

| خانواده | interface | رجیستری (`bootstrap/container.php`) | محل | امروز | کد پنل برای درایور تازه |
|---|---|---|---|---|---|
| پنل‌های VPN | `Providers\Contracts\PanelDriver` (و کلاینتش، `ProviderInterface`) | `panel.drivers` | <code dir="ltr">app/Modules/Providers/Drivers/&lt;Name&gt;/</code> | `3x-ui`، `pasarguard` | هیچ |
| درگاه‌های پرداخت | `Payments\Contracts\GatewayDriver` (و درگاهش، `GatewayInterface`) | `payment.drivers` | <code dir="ltr">app/Modules/Payments/Drivers/&lt;Name&gt;/</code> | `wallet`، `outside` (پرداخت بیرون از فروشگاه)، `manual` (کارت به کارت)، `cubepay`، `zarinpal`، `atlaspay`، `abangateway`، `plisio`، `aqayepardakht`، `variza` | یک آیکون |
| دیتابیس‌ها | `Core\Database\Drivers\DatabaseDriver` | `database.drivers` | <code dir="ltr">app/Core/Database/Drivers/</code> | `mysql`، `sqlite` | هیچ |
| ایمیل | `Core\Mail\MailDriver` | `mail.drivers` | <code dir="ltr">app/Core/Mail/Drivers/</code> | `smtp`، `native`، `resend` | هیچ |
| کپچاها | `Core\Captcha\CaptchaDriver` | `captcha.drivers` | <code dir="ltr">app/Core/Captcha/Drivers/</code> | `turnstile`، `altcha` | هیچ |

## هسته

**فضای نام `App\Core\Drivers`** — هر درایور یک `Driver` است: <code dir="ltr">key()</code> آن (نامی که `config.php`،
یک ردیف یا یک درخواست با آن به درایور اشاره می‌کند) و <code dir="ltr">describe()</code>، که یک `Descriptor` است:
کلیدش، یک `label` و `description` فارسی، `notes` کوتاه (نسخه‌هایی که لازم دارد، محدودیت‌ها و نکته‌هایش)، `form` که از
ادمین می‌خواهد پرش کند، و `traits` — آنچه خانواده‌اش جدا از این‌ها درباره آن می‌گوید (`installable` یک دیتابیس، `sender`
یک درایور ایمیل، `kind` و `builtin` یک درگاه، `mark`، `vendor`، `docs_url` و قابلیت‌های یک کانکتور پنل). درایورهای یک
خانواده یک `Registry` هستند، بر اساس کلید، به ترتیبی که ثبت شده‌اند: دو درایور با یک کلید، وقتی کانتینر آن را می‌سازد،
یک `LogicException` است، و کلیدی که هیچ درایوری ندارد یک `UnknownDriverException` — اشتباه کد، یا اشتباه یک `config.php`
که دستی ویرایش شده، هرگز اشتباه یک درخواست.

**فضای نام `App\Core\Forms`** — فرم یک درایور یک `Form` از فیلدهای نوع‌دار است: `Text` (یک خط، یک مسیر یا چند خط)،
`Number`، `Amount`، `Numbers` (یک لیست کوتاه که به‌صورت متن تایپ می‌شود)، `Toggle`، `Choice`، `Secret`، `EmailAddress`
و `Url`. هر فیلد نامی دارد که یک صفحه آن را با آن می‌فرستد، **کلیدی که زیر آن نگه داشته می‌شود** — یک تنظیم
`config.php` (`DB_HOST`)، یک ستون یا یک کلید JSON از یک ردیف —، مقدار پیش‌فرضش، و یک `FieldSpec` که می‌گوید یک فرم
عمومی آن را چطور رسم کند: برچسبش، راهنمایش، placeholder، واحد، اینکه لاتین است یا نه (چپ به راست رسم می‌شود)، اینکه
زیر «تنظیمات پیشرفته» قرار می‌گیرد یا نه، گزینه‌های یک انتخاب، و `when` — فقط تا وقتی فیلدی پیش از آن یکی از مقدارهای
فهرست‌شده را دارد، نشان داده، خوانده و الزامی می‌شود. یک درایور می‌تواند فیلدی از آن خودش بیاورد — `CardNumber` درایور
کارت به کارت — که نوعش یکی از نوع‌های `Core\Forms\FieldType` است، که پنل‌ها رسم هر کدامشان را بلدند (`card`: در
گروه‌های چهارتایی، با صفحه‌کلید عددی گوشی). بعد:

- متد <code dir="ltr">Form::check()</code> یک ذخیره کامل را می‌خواند: هر فیلد بررسی می‌شود، و همه ردها یک‌جا، هر کدام
  زیر نام فیلد خودش و به زبان ادمین، پیش از اینکه چیزی نگه داشته شود؛ <code dir="ltr">checkSent()</code> همین کار را برای
  یک ذخیره جزئی (یک `PATCH`) می‌کند. <code dir="ltr">values()</code> چیزی است که درایور با آن کار می‌کند — مقدار هر فیلد
  که از روی آنچه نگه داشته شده خوانده می‌شود، و مقداری که فیلد ردش می‌کرد به‌صورت پیش‌فرضش — و
  <code dir="ltr">present()</code> چیزی که یک صفحه نشان می‌دهد.
- یک `Secret` هرگز دوباره نشان داده نمی‌شود: صفحه فقط می‌فهمد که آیا یکی نگه داشته شده، و راهنمایی می‌گیرد که چیز
  زیادی را لو نمی‌دهد — ۴ کاراکتر آخر رمزی که دست‌کم ۱۲ کاراکتر دارد، وگرنه فقط نقطه‌ها (`Secret::KEPT`)، و با
  <code dir="ltr">hint: Secret::hidden()</code> (یک رمز عبور، یک رمز ورود دومرحله‌ای) فقط نقطه‌ها، هر طولی که داشته باشد.
  اگر خالی بماند، همان مقدار نگه‌داشته‌شده را نگه می‌دارد، و `clear_<name>: true` آن را خالی می‌کند. با `missing`، فرمی
  که بدون آن بماند رد می‌شود. با `boundTo`، به فیلدهای دیگری وابسته است — آدرس یک سرور، راهی که درخواست تا آنجا می‌رود،
  حساب روی آن — و وقتی یکی از آن‌ها تغییر کند، فیلد خالی دیگر رمز ذخیره‌شده را نگه نمی‌دارد: ذخیره با متن `moved` رد
  می‌شود، تا رمز عبور نگه‌داشته‌شده هرگز به جایی، یا از راهی، فرستاده نشود که برای آن داده نشده بود.
- متد <code dir="ltr">describe()</code> هر فیلد را برای پنل‌ها بیرون می‌نویسد (`DriverDescription.fields` در API:
  نوعش، متن‌هایش، اینکه الزامی است، محرمانه است، به کدام فیلدها وابسته است، پیشرفته است، گزینه‌هایش، `when`، حدها و
  پیش‌فرض).

**پنل‌ها** هر درایوری را فقط از روی همین توصیف رسم می‌کنند: `resources/panel/src/components/driver-form` —
`DriverFields` (هر فیلد بر اساس نوعش، قاعده‌های `when`، بخش تاشوی پیشرفته، و برای رمزی که برای آدرسی نگه داشته شده
که تغییر کرده، متن خود سرور که می‌گوید دوباره تایپش کنید، پیش از اینکه چیزی فرستاده شود)، `DriverPicker` (درایورهای
یک خانواده با نامشان، و توصیف درایور انتخاب‌شده زیر آن) و `draft.ts` (پیش‌نویسی که فرم از آن شروع می‌کند، و آنچه
می‌فرستد).

**در API**، درخواست هر خانواده به‌صورت یک `oneOf` توصیف می‌شود، با یک شکل بسته برای هر درایور، در
`resources/api/openapi.yaml` — تا درخواست دقیقا با فیلدهای درایور خودش سنجیده شود — و
`tests/Unit/Drivers/DriverFormsTest` هر شکل را با فرم درایورش تطبیق می‌دهد، در هر دو جهت: همان فیلدها، همان فیلدهای
الزامی، یک شکل برای هر درایور ثبت‌شده و برای هیچ چیز دیگر. این تست درخواست‌های دیتابیس، ایمیل، کانکتور پنل، روش پرداخت
و کپچا را پوشش می‌دهد، و فهرست نوع‌های فیلد و کپچاهای توصیف را با فهرست‌های کد تطبیق می‌دهد. پس از ویرایش توصیف،
`pnpm api:types` نوع‌های پنل‌ها را دوباره می‌سازد.

**متن‌ها** — برچسب، توصیف، یادداشت‌ها، راهنماها و ردهای یک درایور — فارسی‌اند، به همان شیوه‌ای که در بقیه کد نوشته
می‌شود ([مشارکت](Contributing.md#زبان)): پنل‌ها آن‌ها را همان‌طور که هستند نشان می‌دهند.

## کانکتور پنل VPN

پنلی که فروشگاه روی آن می‌فروشد. این قرارداد عمدا به هیچ پنلی وابسته نیست: کانکتور تازه به هیچ ویرایشی بیرون از پوشه
خودش نیاز ندارد، جز ثبتش و شکل درخواستش.

1. **درایور** — `app/Modules/Providers/Drivers/<Name>/<Name>Driver.php`، که `PanelDriver` را پیاده‌سازی می‌کند، یک
   تعریف بدون وضعیت (stateless):
   - متد <code dir="ltr">key()</code> — آنچه `servers.driver` نگه می‌دارد (`3x-ui`، `pasarguard`)؛
   - متد <code dir="ltr">describe()</code> — کارت انتخاب‌گر افزودن سرور: برچسب، یک توصیف، `notes` (نسخه‌هایی که پشتیبانی
     می‌کند)، و `traits`: `PanelDriver::MARK` (دو حرفی که کارت با آن‌ها رسم می‌شود)، `VENDOR`، `DOCS`، به‌علاوه
     <code dir="ltr">$this-&gt;capabilities()-&gt;traits()</code>. فرمش اتصال سرور است، که از فهرست‌هایی ساخته می‌شود
     که همه کانکتورها در آن شریک‌اند:
     <code dir="ltr">Support\PanelConnection::address(hint, placeholder, pages, pagesRefusal)</code> (آدرس پنل، که
     صفحه‌های خود پنل را که کانکتور شما نام می‌برد رد می‌کند — <code dir="ltr">/panel</code> در 3X-UI،
     <code dir="ltr">/dashboard</code> در PasarGuard)، بعد
     <code dir="ltr">...Support\PanelCredentials::fields(token, tokenHint, tokenPattern, tokenMismatch, loginHint)</code>
     (یک توکن API، یا نام کاربری و رمز عبور یک ادمین)، بعد فیلدهای خودش — فیلدی که فقط برای روش رمز عبور است
     `when: PanelCredentials::WITH_PASSWORD` می‌گیرد، و یک رمز <code dir="ltr">boundTo: PanelConnection::bound()</code>
     (آدرس پنل با origin آن، هاست و پورت پراکسی، و بررسی TLS؛ خاموش کردن پراکسی چیزی را عوض نمی‌کند) با `moved`ی که با
     `PanelConnection::MOVED` شروع می‌شود —، بعد <code dir="ltr">...PanelConnection::options(subscriptionHint, subscriptionPlaceholder)</code> (TLS، timeout،
     آدرسی دیگر برای لینک‌های اشتراک، و پراکسی SOCKS5 که ممکن است پنل از راه آن در دسترس باشد، زیر «تنظیمات
     پیشرفته»). هر فیلد زیر کلیدش نگه داشته می‌شود: یک ستون از `servers` (`api_token`، `username`، `password`،
     `totp_secret`، <code dir="ltr">proxy_\*</code> …)، یا <code dir="ltr">meta.&lt;name&gt;</code> برای فیلدی که ستونی
     ندارد — کانکتور به هیچ ستونی از آن خودش نیاز ندارد. فیلدهای خود سرور (نام، ظرفیت، یادداشت‌ها و کلید روشن/خاموش آن)
     مال `ServerService` هستند، دور فیلدهای شما؛
   - متد <code dir="ltr">capabilities()</code> — یک `DTO\Capabilities`: `inbounds` (کلاینت‌ها روی اینباندهایی قرار
     می‌گیرند که مالک فروشگاه انتخاب می‌کند؛ بدون آن، سرور یکجا فروخته می‌شود)، `linkRotation` (اطلاعات ورود یک
     کلاینت، و در نتیجه لینکش، می‌تواند عوض شود — وگرنه «تغییر لینک» در کار نیست) و `ipLimit` (پنل کلاینت را به
     `ClientSpec::$ipLimit` محدود می‌کند — وگرنه «کاربر اضافه» روی آن فروخته نمی‌شود)، و `names` (یک
     `DTO\ClientNameRule`: طول، کاراکترها و جداکننده‌هایی که پنل در نام یک کلاینت می‌پذیرد — به‌طور پیش‌فرض هر چیزی؛
     فروشگاه نام‌های خودش را با آن جور می‌کند و نامی را که تایپ شده و پنل نمی‌پذیرد رد می‌کند). آنچه یک کانکتور ادعا
     نکند، فروشگاه ارائه نمی‌دهد؛
   - متد <code dir="ltr">connect(Server, PanelHttp)</code> — کلاینت پنل یک سرور، که روی
     <code dir="ltr">PanelConnection::of($server)</code> و <code dir="ltr">PanelCredentials::of($server)</code> ساخته
     می‌شود. <code dir="ltr">ProviderRegistry::forServer()</code> آن را تا وقتی اتصال سرور همان بماند پنج دقیقه نگه
     می‌دارد، تا Session یا توکنی که برایش وارد شده به فراخوانی‌های بعدی هم برسد.
2. **کلاینت** — <code dir="ltr">&lt;Name&gt;Provider.php</code>، که `Providers\Contracts\ProviderInterface` را
   پیاده‌سازی می‌کند: <code dir="ltr">testConnection()</code>، <code dir="ltr">status()</code>،
   <code dir="ltr">listInbounds()</code>، <code dir="ltr">servesSubscriptions()</code>، <code dir="ltr">createClient()</code>،
   <code dir="ltr">updateClient()</code>، <code dir="ltr">rotateClientCredentials()</code>،
   <code dir="ltr">setClientEnabled()</code>، <code dir="ltr">deleteClient()</code>، <code dir="ltr">findClient()</code>،
   <code dir="ltr">listClients()</code>، <code dir="ltr">findClientByLink()</code>،
   <code dir="ltr">resetClientTraffic()</code>. کلاینت‌ها به‌صورت یک `ClientSpec` وارد می‌شوند (نام، سقف حجم،
   یک `Expiry` — شمرده‌شده از اولین اتصال، ثابت، یا هرگز —، یک محدودیت IP، شناسه تلگرام یا هیچ، یک کامنت، و `enabled`: هم
   <code dir="ltr">createClient()</code> و هم <code dir="ltr">updateClient()</code> کلاینت را همان‌طور که این فیلد
   می‌گوید روشن یا خاموش می‌گذارند — سرویسی که در حالت خاموش منتقل شده روی سرور تازه‌اش خاموش ساخته می‌شود؛ پنلی که
   کاربر را فقط روشن می‌سازد، همان‌طور که PasarGuard می‌سازد، کاربر تازه را فورا خاموش می‌کند، و اگر این کار رد شود
   کاربر را پس می‌گیرد) و به‌صورت یک `ClientInfo` بیرون می‌آیند (نام، روشن بودن، شمارنده‌ها، سقف حجم، انقضا، لینک
   اشتراک، حضور، محدودیت IP که پنل نگه می‌دارد یا هیچ — همگام‌سازی آن را روی سرویس کپی می‌کند)؛
   <code dir="ltr">findClientByLink()</code> می‌گوید یک `DTO\ClientLink` — توکن‌های یک لینک اشتراک، یا اطلاعات ورود یک
   کانفیگ — مال کیست، برای کاربری که کلاینتی را که دستی ساخته شده مال خودش اعلام می‌کند
   ([افزودن سرویس با لینک](Service-Claims.md)): هر چه لینک‌های پنل شما با خود دارند را تطبیق دهید، و هرگز به توکنی که
   خودتان نمی‌توانید تایید کنید اعتماد نکنید — PasarGuard توکن‌هایش را امضا می‌کند، پس کانکتور آن به جای خواندن توکن،
   از مسیر اشتراک خود پنل (<code dir="ltr">…/{token}/info</code>) می‌پرسد توکن مال کدام کاربر است؛ اینباندها
   `InboundInfo` هستند با یک `key` مبهم (opaque) که کانکتور شما می‌فهمد. آنچه یک پنل نمی‌تواند پاسخ دهد یک `null` روی
   DTO است، یا یک `UnsupportedOperationException` (<code dir="ltr">status()</code>، <code dir="ltr">listClients()</code>
   — آن‌وقت همگام‌سازی کلاینت به کلاینت می‌پرسد —، <code dir="ltr">rotateClientCredentials()</code>). با پنل از راه
   `Support\PanelHttp` حرف بزنید، تنها کلاینت خروجی فروشگاه، با قاعده TLS اتصال، timeoutها و پراکسی SOCKS5، بدون دنبال
   کردن ریدایرکت‌ها — هرگز کلاینت دیگری، که پراکسی سرور را دور می‌زند؛ این کلاینت پاسخی را هم که API نیست می‌خواند
   (<code dir="ltr">PanelHttp::redirected()</code>، <code dir="ltr">notTheApi()</code>)، و `Support\Raw` JSON پنل را که
   نوع‌هایش دقیق نیست می‌خواند.
3. **خطاهایش** exceptionهای نوع‌دار `Providers\Exceptions` هستند: `ConnectionException` (ساخته‌شده با
   <code dir="ltr">fromTransport()</code>: DNS، رد شدن اتصال، timeout، TLS، پراکسی — هرگز با تکرار آدرس پنل یا اطلاعات
   ورود پراکسی)، `AuthenticationException` (سازنده‌های نام‌دارش: توکن جاافتاده یا ردشده، scope جاافتاده، ورود ردشده، کد
   ورود دومرحله‌ای، Session، توکن CSRF)، `UnexpectedResponseException` (مسیر پایه اشتباه، ریدایرکت، HTML به‌جای API)،
   `NotFoundException` (کلاینتی که پنل ندارد — هرگز مسیری که پنل ندارد)، `UnsupportedOperationException`، و یک زیرکلاس
   `PanelApiException` از آن خودتان برای «پنل نه گفت، چون …». به آن‌هایی که یک تنظیم قابل اصلاح را توصیف می‌کنند یک
   `hint` به زبان خود پنلتان (منوهایش، صفحه‌هایش) بدهید: `ProviderErrorPresenter` هر خطا را برای مالک فروشگاه، که
   سرورها را اداره می‌کند، شرح می‌دهد، و برای هر کس دیگری با یک کلمه ساده. نوشتنی که کلاینت را در پاسخ برمی‌گرداند
   (<code dir="ltr">createClient()</code>، <code dir="ltr">updateClient()</code>،
   <code dir="ltr">rotateClientCredentials()</code>) و *بعد از* اینکه پنل آن را پذیرفت شکست می‌خورد — خواندن کلاینت که
   پس از آن می‌آید شکست می‌خورد — خطایش را با علامت <code dir="ltr">-&gt;afterWrite()</code> پرتاب می‌کند
   (<code dir="ltr">ProviderException::afterWrite()</code>): آن‌وقت <code dir="ltr">mayHaveBeenDone()</code> آن true
   است، پس فروشگاه از پنل می‌پرسد کلاینت در چه وضعی است، به‌جای اینکه نوشتن را انجام‌نشده بگیرد و دوباره انجامش دهد.
4. **ثبت آن** در `bootstrap/container.php`: <code dir="ltr">$c-&gt;get(&lt;Name&gt;Driver::class)</code> را به
   `panel.drivers` اضافه کنید.
5. **توصیف درخواست‌هایش** در `resources/api/openapi.yaml`: یک `Server<Name>Request` در `oneOf` مربوط به `ServerRequest`
   — `driver` با کلید شما به‌عنوان تنها مقدارش، <code dir="ltr">&lt;&lt;: \*serverFormProperties</code>، و فیلدهای
   خودتان، هر رمز با <code dir="ltr">clear_&lt;name&gt;</code> خودش — و یک `Server<Name>TestRequest` (همان، همراه `id`)
   در `oneOf` مربوط به `ServerTestRequest`. بعد `pnpm api:types`.
6. **پنل‌ها** به هیچ چیز نیاز ندارند: کارت انتخاب‌گر از توصیف و traitهای شما می‌آید، و فرم از `DriverFields`.
7. **تست‌ها**:
   - فایل `tests/Unit/Providers/<Name>/<Name>DriverTest.php` — کارتش، همه فیلدهای ردشده که یک‌جا گزارش می‌شوند، قاعده
     آدرس، رمز ذخیره‌شده‌ای که تا وقتی آدرس روی همان هاست بماند نگه داشته می‌شود و وقتی آدرس به هاست دیگری برود، پراکسی
     روشن شود یا بررسی TLS تغییر کند رد می‌شود (مثل `PasarGuardDriverTest`)؛
   - تست‌های client و provider در برابر پنل فیک — <code dir="ltr">$this-&gt;panelHttp()</code>
     (`tests/Support/FakePanel`: پاسخ‌ها را با <code dir="ltr">ok()</code>، <code dir="ltr">fail()</code>،
     <code dir="ltr">raw()</code>، <code dir="ltr">refuse()</code> در صف بگذارید؛ آنچه فرستاده شده را با
     <code dir="ltr">calls()</code>، <code dir="ltr">request(i)</code>، <code dir="ltr">params(i)</code>،
     <code dir="ltr">options(i)</code> بخوانید) —، و وقتی پنل توصیفی از API خود منتشر می‌کند، یک تست قرارداد که هر
     درخواستی را که کلاینت شما به آن می‌فرستد با آن توصیف تطبیق می‌دهد (همان‌طور که `ThreeXuiContractTest` با
     `tests/Fixtures/3x-ui-openapi.json` می‌کند)؛
   - یک تست feature از راه API سرورها و یک فروش (مثل `PasarGuardConnectorTest`)؛
   - و بعد `DriverFormsTest` خودش شکل‌های درخواست شما را با فرمتان تطبیق می‌دهد.
8. **امتحانش کنید** روی یک پنل واقعی از یک شل:
   <code dir="ltr">php bin/console panel:probe &lt;key&gt; &lt;url&gt; -t &lt;token&gt;</code>
   ([دستورهای کنسول](Console-Commands.md)).

دو کانکتوری که وجود دارند:

- کانکتور **3X-UI** (`Drivers/ThreeXui`) نسخه v3 از API پنل را هدف می‌گیرد: `ThreeXuiClient` (یک توکن API به‌صورت
  Bearer، یا ورود با کوکی — توکن CSRF دوباره فرستاده می‌شود، و وقتی رمزی نگه داشته شده یک کد TOTP هم —، که پس از یک رد
  یک بار دوباره وارد می‌شود)، `ThreeXuiApi` (یک متد برای هر اندپوینتی که فروشگاه صدا می‌زند)، `ThreeXuiProvider`.
  مدتی که از اولین اتصال شمرده شود همان «expire on first use» خود پنل است.
- کانکتور **PasarGuard** (`Drivers/PasarGuard`، 3.1+): `PasarGuardClient` (یک کلید API، 5.1+، یا نام کاربری و رمز عبور
  یک ادمین که با توکنی معاوضه می‌شود که کلاینت نگه می‌دارد) و `PasarGuardProvider`. **گروه‌هایش** چیزی است که یک سرور
  می‌فروشد (کاربر فقط از راه گروه‌ها به اینباندها می‌رسد)، مدتی که از اولین اتصال شمرده شود همان *on hold* آن است، و
  محدودیت IP برای هر کاربر ندارد، پس تعداد دستگاه یک پلن آنجا اعمال نمی‌شود. نام کاربری ۳ تا ۱۲۸ حرف، رقم و
  <code dir="ltr">_ - . @</code> است، و هرگز دو تا از این نشانه‌ها کنار هم نمی‌آیند؛ کاربری که در یک ویرایش خاموش نگه داشته
  می‌شود `disabled` است با مدتش (کاربری که هنوز on hold است مدتش را برای روشن شدن دوباره نگه می‌دارد).

## درگاه پرداخت

روشی که فروشگاه با آن پول می‌گیرد. هر روش پرداخت یک فروشگاه («روش‌های پرداخت») یک ردیف از `payment_methods` است: یک
درایور درگاه، برچسبی که دکمه پیش‌فاکتور نشان می‌دهد، و تنظیمات درایور در `config` آن ردیف.

1. **درایور** — `app/Modules/Payments/Drivers/<Name>/<Name>Driver.php`، که `GatewayDriver` را پیاده‌سازی می‌کند:
   - متد <code dir="ltr">key()</code> — آنچه `payment_methods.driver` نگه می‌دارد؛
   - متد <code dir="ltr">describe()</code> — برچسب و توصیفی که انتخاب‌گر «افزودن روش پرداخت» نشان می‌دهد، یادداشت‌ها، و
     فرم تنظیمات یک روش (هر فیلد در `config` ردیف زیر کلیدش نگه داشته می‌شود؛ رمز یک `Secret` است، که فیلد خالی آن را
     نگه می‌دارد و ردیف آن را رمزنگاری‌شده با `APP_KEY` نگه می‌دارد — `Payments\MethodSettings` —، و خوانا به درایور
     داده می‌شود). traitهایش: `GatewayDriver::KIND` — یک `Enums\GatewayKind`: `instant` (همان لحظه‌ای که کاربر تایید
     می‌کند تسویه می‌شود، مثل کیف پول)، `manual` (کاربر بیرون از فروشگاه می‌پردازد و یک رسید پذیرفته می‌شود، مثل کارت به
     کارت) یا `online` (کاربر در صفحه درگاه می‌پردازد و درگاه آن را اعلام می‌کند، مثل CubePay و بقیه — پایین‌تر) — و
     `GatewayDriver::BUILTIN` (`true` فقط برای درایوری که بخشی از هر فروشگاه است: هرگز اضافه نمی‌شود، هرگز حذف نمی‌شود)؛
   - متد <code dir="ltr">summary(settings)</code> — تنها خطی که لیست روش‌ها از روی تنظیماتش درباره آن نشان می‌دهد، یا
     `null` (کیف پول؛ کارت به کارت، که کارت‌هایش ردیف‌هایی جداگانه‌اند — لیست آن‌ها را نشان می‌دهد، و هر پرداخت کارتی را
     که به کاربر نشان داده شده). روشی که روی سرویس آزمایشی درگاهش تنظیم شده، جایی که هیچ پولی جابه‌جا نمی‌شود،
     `GatewayDriver::TEST_MODE` را می‌گوید («حالت آزمایشی: پولی جابه‌جا نمی‌شود»)؛
   - متد <code dir="ltr">gateway(settings)</code> — درگاه یک ردیف روش، که `GatewayInterface` را پیاده‌سازی می‌کند:
     <code dir="ltr">initiate($payment)</code> می‌گوید کاربر برای پرداخت آن پرداخت قدم بعد چه کند
     (<code dir="ltr">PaymentInitiation::instant()</code>، یا <code dir="ltr">transfer()</code> با کارتی که به او نشان
     داده شد)، و <code dir="ltr">settle()</code> سهم آن در تسویه یک پرداخت است، داخل تراکنش تسویه — فقط کار دیتابیسی:
     وقتی MySQL آن تراکنش را به خاطر deadlock لغو کند دوباره امتحان می‌شود، پس <code dir="ltr">settle()</code> ممکن است
     برای یک پرداخت بیش از یک بار اجرا شود. حدهای یک روش (`min_amount`، `max_amount`) مال فروشگاه‌اند، نه درایور: هر
     روشی آن‌ها را دارد.
2. **ثبت آن** در `bootstrap/container.php`: <code dir="ltr">$c-&gt;get(&lt;Name&gt;Driver::class)</code> را به
   `payment.drivers` اضافه کنید.
3. **توصیف آن** در `resources/api/openapi.yaml`: یک <code dir="ltr">&lt;Name&gt;MethodRow</code> (همراه
   <code dir="ltr">&lt;Name&gt;GatewayConfig</code> آن) در `oneOf` مربوط به `PaymentMethodRow`، یک
   <code dir="ltr">&lt;Name&gt;MethodRequest</code> در `oneOf` مربوط به `PaymentMethodUpdateRequest`، و — مگر اینکه
   درایور built-in باشد — یک <code dir="ltr">&lt;Name&gt;MethodCreateRequest</code> در `oneOf` مربوط به
   `PaymentMethodCreateRequest`. فرمی که همه فیلدهایش در ویرایش اختیاری‌اند (رمزهایی که وقتی خالی بمانند نگه داشته
   می‌شوند، مثل کلید AtlasPay) با به‌روزرسانی کیف پول هم جور درمی‌آید: <code dir="ltr">&lt;Name&gt;MethodRequest</code>
   آن <code dir="ltr">not: { $ref: '#/components/schemas/WalletMethodRequest' }</code> را می‌گوید، تا `oneOf` همچنان فقط
   با یکی جور شود — فرمی با فیلدی که هر ذخیره می‌فرستد (`fee_fixed` در CubePay) به آن نیازی ندارد. بعد
   `pnpm api:types`.
4. **پنل‌ها**: یک ورودی در `GATEWAYS` (`resources/panel/src/components/payments/gateways/index.tsx`) به آن بدهید —
   آیکونش و، اگر لیست باید کنار خلاصه‌اش چیزی بگوید، یک `Note`؛ وقتی نوع ردیف وجود داشته باشد، type checker آن را
   می‌خواهد. انتخاب‌گر و فرم روش (`gateways/method-form.tsx`) از روی توصیف رسم می‌شوند — رمز الزامی دکمه «پاک کردن مقدار
   ذخیره‌شده» را ارائه نمی‌دهد.
5. **تست‌ها**: یک تست unit مثل `tests/Unit/Payments/ManualDriverTest.php` — توصیف و traitهایش، ردهای فرمش به‌صورت
   یک‌جا، تنظیم نگه‌داشته‌شده‌ای که فرم ردش می‌کند و به‌صورت پیش‌فرضش خوانده می‌شود، خلاصه‌اش، و
   <code dir="ltr">initiate()</code> و <code dir="ltr">settle()</code> درگاهش؛ `DriverFormsTest` خودش درخواست‌های ساخت و
   به‌روزرسانی و ردیف را با فرمش تطبیق می‌دهد؛ یک case در `tests/Feature/AdminPaymentMethodsApiTest.php` برای افزودن و
   ویرایش آن از راه API پنل‌ها.

درایوری از یکی از این سه نوع همان‌طور که هست در پیش‌فاکتور جا می‌شود: ربات و وب‌سایت «تسویه شد»، «به این کارت
بپردازید» و «در این صفحه بپردازید» را به یک شکل نمایش می‌دهند.

### درگاه آنلاین

کاربر در صفحه درگاه می‌پردازد — صفحه CubePay (`Drivers\CubePay`)، Zarinpal (`Drivers\Zarinpal`)، مینی‌اپ AtlasPay در
تلگرام (`Drivers\AtlasPay`)، AbanGateway (`Drivers\Aban`)، Plisio (`Drivers\Plisio`)، AqayePardakht
(`Drivers\AqayePardakht`)، Variza (`Drivers\Variza`)، هفت درگاهی که همراه برنامه می‌آیند — و درگاه می‌گوید نتیجه چه شد.
درگاهش `Contracts\OnlineGateway` را پیاده‌سازی می‌کند (نوع `online`):

- متد <code dir="ltr">open($payment, $request)</code> پرداخت را روی درگاه باز می‌کند — شناسه پرداخت آنجا
  (<code dir="ltr">$request-&gt;orderId</code>، که برای یک پرداخت همیشه یکی است، و هرگز مال نصب دیگری نیست)، مبلغ به
  تومان کامل، آدرسی که درگاه به آن Callback می‌زند، خطی که می‌گوید بابت چه پرداخت می‌شود، شناسه تلگرام و یوزرنیم کاربر —
  و یک `OnlineInvoice` پاسخ می‌دهد: صفحه، تا کی پول می‌گیرد (<code dir="ltr">OnlineInvoice::until()</code> پایانی را
  که درگاه گفته می‌خواند، وگرنه زمانی از خود درایور؛ `null` برای هیچ: فروشگاه صفحه را یک ساعت نگه می‌دارد)، شناسه درگاه
  برای آن، و جزئیاتی که بعدا دوباره می‌خواند (روی پرداخت به‌صورت `invoice` نگه داشته می‌شود). فروشگاه هر پرداخت را یک
  بار باز می‌کند (`Services\OnlineInvoices`)، بیرون از هر تراکنشی، و **برای هر پرداخت یک صفحه** نگه می‌دارد: آنچه
  <code dir="ltr">open()</code> پاسخ داده فقط تا وقتی پرداخت هنوز صفحه‌ای ندارد نگه داشته می‌شود، پس اگر دو صفحه در یک
  لحظه باز شوند هر دو همان یکی را که نگه داشته شده نشان می‌دهند — فاکتور دیگر در درگاه بی‌استفاده می‌ماند، و
  <code dir="ltr">open()</code> هرگز لازم نیست یک صفحه را دو بار پاسخ دهد. اگر دوباره زده شود در حالی که صفحه‌اش هنوز چند
  دقیقه وقت دارد، همان صفحه نشان داده می‌شود، وگرنه یک پرداخت تازه باز می‌شود؛
- متد <code dir="ltr">check($payment)</code> از درگاه می‌پرسد نتیجه چه شد — <code dir="ltr">OnlineVerdict::paid()</code>
  (با شماره مرجعش)، <code dir="ltr">waiting()</code> یا <code dir="ltr">closed()</code> — و هرگز حرف یک Callback را
  باور نمی‌کند. <code dir="ltr">waiting(moneyIn: true)</code> می‌گوید درگاه پولی بابت آن گرفته که هنوز تسویه نکرده است
  (رسیدی در حال بررسی، مبلغی که بخشی از آن پرداخت شده): فروشگاه پرداخت را علامت می‌زند (`invoice.money_in`) و بعد از دو
  ساعت معمول هم درباره‌اش می‌پرسد، تا ۴۸ ساعت پس از بسته شدن صفحه‌اش — بعد آن را برای پشتیبانی نگه می‌دارد. پرداختی
  که این علامت را دارد و درگاه بعد درباره‌اش <code dir="ltr">closed()</code> پاسخ می‌دهد، همان لحظه برای پشتیبانی نگه
  داشته می‌شود؛
- متدهای <code dir="ltr">initiate($payment)</code> و <code dir="ltr">settle()</code> برای همه درگاه‌های آنلاین یکسان‌اند،
  پس از یک trait می‌آیند، `Drivers\OnlinePage`: <code dir="ltr">initiate()</code> آنچه را <code dir="ltr">open()</code>
  نگه داشته می‌خواند و هیچ چیزی را صدا نمی‌زند — <code dir="ltr">PaymentInitiation::link()</code> با یک `PayLink`:
  صفحه، تا کی، مبلغ دقیقی که می‌خواهد (درگاه ممکن است چند تومان از طرف خودش اضافه کند، از راه
  <code dir="ltr">page()</code> در trait) و، اگر درگاه کارتی برای نمایش در ربات داده باشد، یک کارت —؛
  <code dir="ltr">settle()</code> با شماره مرجع پرداخت پاسخ مثبت می‌دهد (درگاه پیش از اجرای آن گفته که پرداخت شده).

متدهای <code dir="ltr">open()</code> و <code dir="ltr">check()</code> از راه
<code dir="ltr">Drivers\GatewayHttp::call()</code> با درگاه حرف می‌زنند — بدون دنبال کردن ریدایرکت، با درخواست JSON، و
با برگرداندن وضعیت و شیء JSON آن — و وقتی درگاه در دسترس نیست یا رد می‌کند، `GatewayUnavailableException` را با آنچه
گفته به‌عنوان جزئیات پرتاب می‌کنند (جزئیات مال لاگ است؛ کاربر متن خود exception را می‌خواند) — متن خود درگاه هم به
همان شکل با <code dir="ltr">GatewayHttp::said()</code> کوتاه می‌شوند —، و وقتی پاسخش با پرداخت جور نیست (سفارش یا
مبلغی غیر از آنچه خواسته شد) با `held: true`. آن‌وقت فروشگاه **پرداخت را برای پشتیبانی نگه می‌دارد**
(<code dir="ltr">OnlinePayments::isHeld()</code>): دیگر درباره‌اش پرسیده نمی‌شود، به «خطاها»ی گروه گزارش‌ها یک بار خبر
داده می‌شود، و — تا وقتی هنوز منتظر است، در انتظار یا شکست‌خورده با صفحه‌اش — سفارشش نه منقضی می‌شود و نه از راه دیگری
پرداخت می‌شود (<code dir="ltr">Checkout::pay()</code> یک `PaymentHeldException` پرتاب می‌کند). بقیه چیزها کار فروشگاه
است (`Services\OnlinePayments`، <code dir="ltr">PaymentService::settleOnline()</code>): پرسیدن‌ها درباره یک پرداخت به
نوبت انجام می‌شوند — یکی از ۳۲ فایل قفل، بر اساس شناسه پرداخت؛ و پرداخت وقتی نوبتش شد از نو خوانده می‌شود، پس
<code dir="ltr">check()</code> و کاری که فروشگاه با پاسخش می‌کند یک قدم‌اند و دو پرسش هم‌زمان هرگز چیزی را که دیگری در
دست دارد تسویه نمی‌کنند —، آدرس Callback، <code dir="ltr">/payments/callback/{payment}/{secret}</code> — با رمزی مال خود
پرداخت؛ فراخوانی آنجا فقط باعث می‌شود فروشگاه از درگاه بپرسد، حداکثر چند بار در دقیقه برای یک پرداخت، و به فراخوانی
بیش از آن از روی وضعیت فعلی پرداخت پاسخ داده می‌شود —، زمان‌بند که هر از گاهی درباره هر پرداختی که هنوز پرداخت نشده
می‌پرسد (`Tasks\OnlinePaymentsTask`) تا دو ساعت پس از بسته شدن صفحه‌اش — درگاه ممکن است دیر پول بگیرد —، «بررسی» ربات
و وب‌سایت برای کاربر و بررسی پنل‌ها برای پشتیبانی؛ پرداخت پرداخت‌شده سفارشش را یک بار تسویه می‌کند و یک بار به کاربر خبر
می‌دهد، و پولی که درگاه پس از بسته شدن سفارشش گرفته به کیف پول کاربر می‌رود. تست، نسخه‌ای فیک از API درگاه را زیر
کلاینت خروجی فروشگاه می‌گذارد، همان‌طور که `Tests\Support\FakeCubePay`، `FakeZarinpal`، `FakeAtlasPay`، `FakeAban`،
`FakePlisio`، `FakeAqayePardakht` و `FakeVariza` می‌کنند. درگاهی که مرورگر کاربر را به آن آدرس برمی‌گرداند (Zarinpal،
AbanGateway، Plisio، AqayePardakht، Variza) برای مرورگر صفحه‌ای از نتیجه می‌گیرد (`Controllers\ReturnPage`، تنها صفحه‌ای
که PHP پاسخ می‌دهد، با سیاستی که هیچ اسکریپتی اجرا نمی‌کند) — به فراخوانی‌های سرور آن درگاه با JSON پاسخ داده می‌شود.
درگاهی که کلیدش را در query آدرس می‌گیرد (Plisio) کلید را به <code dir="ltr">GatewayHttp::call()</code> می‌دهد تا از
پیام‌هایش بیرون نگه داشته شود. درگاهی که صفحه‌اش فقط برای بازدیدکننده‌ای از دامنه فروشگاه باز می‌شود (AqayePardakht:
شاپرک Referrer را بررسی می‌کند) <code dir="ltr">$request-&gt;viaShop()</code> را به‌عنوان صفحه‌اش پاسخ می‌دهد و صفحه
خودش را در جزئیات فاکتور به‌صورت `page` نگه می‌دارد: آدرس Callback با <code dir="ltr">?go=1</code>، تا وقتی درگاه پول
می‌گیرد، صفحه‌ای به کاربر نشان می‌دهد که او را به آن صفحه می‌برد (<code dir="ltr">ReturnPage::go()</code>، که دکمه‌اش
Origin فروشگاه را به‌عنوان Referrer می‌فرستد)، و بعد از آن، نتیجه را.

درگاهی که به هیچ پرسشی درباره پرداخت پاسخ نمی‌دهد و به‌جایش حرفش را امضا می‌کند (Variza) `Contracts\SignedWebhook` را
هم پیاده‌سازی می‌کند: <code dir="ltr">hear($body, $headers)</code> وقتی امضا مال روش خودش باشد یک `WebhookWord` پاسخ
می‌دهد — شماره مرجع درگاه برای پرداخت (`null` برای رویدادی که هیچ پرداختی را نام نمی‌برد) و آنچه گفته —، و وقتی نباشد
`null`. Webhook آن به یک آدرس برای درایورش می‌آید، <code dir="ltr">/payments/webhooks/{driver}</code>
(`Controllers\GatewayWebhookController`؛ <code dir="ltr">Urls::paymentWebhook()</code> برای یادداشت فرم): از هر روش
آن درایور، در هر فروشگاه، خواسته می‌شود آن را بشنود؛ پرداخت روشی که آن را امضا کرده، که با شماره مرجعش پیدا می‌شود، این
حرف را به‌صورت `heard` فاکتورش نگه می‌دارد — یک بار، تا وقتی پرداخت نشده است
(<code dir="ltr">OnlinePayments::hear()</code>) — و همان لحظه بررسی می‌شود، و <code dir="ltr">check()</code> درگاهش
آن حرف را به‌عنوان پاسخ می‌خواند. پاسخ‌ها:

- پاسخ **404** («هیچ روش پرداختی وب‌هوک این آدرس را نمی‌گیرد.») — درایور نصب نشده، یا هیچ روشی از آن Webhook امضاشده
  نمی‌گیرد (چنین روشی در هیچ فروشگاهی نیست، یا درگاهی است که به‌جایش از آن پرسیده می‌شود، مثل همه درگاه‌های آنلاین
  دیگر)؛
- پاسخ **400** («امضای این درخواست با هیچ روش پرداختی جور نیست.») — هیچ روشی آن را امضا نکرده: چیزی تغییر نمی‌کند، و
  لاگ این را می‌گوید؛
- پاسخ **200** — امضاشده، نتیجه هر چه باشد (پرداختی که نام می‌برد پیدا نشد، یا برای پشتیبانی نگه داشته شده است)، تا
  درگاه از فرستادن دوباره‌اش دست بکشد.

متد <code dir="ltr">FakeVariza::webhook()</code> برای یک تست Webhookی می‌نویسد و امضا می‌کند.

## درایور دیتابیس

دیتابیسی که فروشگاه روی آن اجرا می‌شود: MySQL / MariaDB برای فروشگاه، SQLite برای تست‌ها و دستگاه یک توسعه‌دهنده.

1. **درایور** — `app/Core/Database/Drivers/<Name>Driver.php`، که `DatabaseDriver` را پیاده‌سازی می‌کند:
   - متد <code dir="ltr">key()</code> — آنچه `DB_CONNECTION` نگه می‌دارد؛
   - متد <code dir="ltr">describe()</code> — برچسب، توصیف و یادداشت‌هایش (نسخه‌هایی که لازم دارد)، فرمش (تنظیمات
     اتصالش، هر کدام زیر تنظیم `config.php` خودش — `DB_HOST`، `DB_PORT` …؛ `DB_DATABASE` مشترک است: نام دیتابیس در
     MySQL و فایل در SQLite —، و رمز عبور یک `Secret` وابسته به فیلدهایی که می‌گویند دیتابیس کجاست)، و trait
     `DatabaseDriver::INSTALLABLE`: اینکه نصب‌کننده وب آن را ارائه می‌کند یا نه؛
   - متد <code dir="ltr">extensions()</code> — افزونه‌های PHP که لازم دارد: فهرست پیش‌نیازها آن‌ها را نشان می‌دهد، و
     probe بدون یکی از آن‌ها رد می‌شود؛
   - متد <code dir="ltr">connection(config)</code> — اتصال illuminate/database برای آن تنظیمات: charset، یک Session با
     UTC، strict mode، گزینه‌های PDO آن — تنها توصیف آن، هم برای فروشگاه و هم برای probe؛
   - متد <code dir="ltr">probe(config)</code> — اتصالی کوتاه و گفتن اینکه چه چیزی پاسخ داد
     (<code dir="ltr">ProbeResult::probe($connection, $minimum)</code>: نسخه، که زیر کف شما رد می‌شود، و جدول‌های اسکیمای
     فعلی)، یا اینکه چرا فروشگاه نمی‌تواند روی آن اجرا شود، به زبان ادمین (`ProbeFailedException`) — تا وقتی این متد بله
     نگوید چیزی در `config.php` نوشته نمی‌شود؛
   - متد <code dir="ltr">localDate(column, offset)</code> — SQL روز یک ستون UTC در منطقه زمانی فروشگاه، برای داشبورد؛
   - متد <code dir="ltr">releaseNames(schema, table)</code> — آنچه `db:rebuild` پیش از اینکه جدولی کنار برود حذف
     می‌کند: نام‌هایی که مال دیتابیس‌اند نه جدول (کلیدهای خارجی MySQL، ایندکس‌های SQLite).
2. **ثبت آن** در `bootstrap/container.php`: آن را به `database.drivers` اضافه کنید. افزونه PDO آن را به `suggest` در
   `composer.json` اضافه کنید.
3. **توصیف درخواستش**: یک `Database<Name>Request` در `oneOf` مربوط به `DatabaseRequest` (`driver` با کلید شما، و
   فیلدهایتان، هر رمز با <code dir="ltr">clear_&lt;name&gt;</code> خودش)، بعد `pnpm api:types`. مرحله دیتابیس
   نصب‌کننده و «تنظیمات پنل › دیتابیس» آن را از روی توصیفش رسم می‌کنند.
4. **تست‌ها**: یک تست unit مثل `tests/Unit/Database/Drivers/MySqlDriverTest.php` — توصیف و فیلدهایش به ترتیب، هر فیلد
   که به فارسی زیر نام خودش رد می‌شود، رمزی که نگه داشته، جایگزین یا پاک می‌شود، اتصالی که توصیف می‌کند، روز داشبورد،
   نام‌های یک بازسازی، ردی که از روی خطای سرور نوشته می‌شود. `DriverFormsTest` خودش درخواستش را با فرمش تطبیق می‌دهد؛
   `tests/Unit/Database/SchemaTest.php` فایل `database/schema.php` را در محدوده آنچه هر دیتابیس می‌پذیرد نگه می‌دارد؛
   `Tests\Fakes\ProbedDriver` (<code dir="ltr">probedDatabase()</code>) نقش درایوری را بازی می‌کند که یک تست به probe آن
   پاسخ می‌دهد.
5. **ساختن دوباره مرجع**: `php scripts/docs.php` تنظیمات آن را به
   [مرجع config.php (انگلیسی)](../Config-Reference.md#database) اضافه می‌کند.

دیتابیس PostgreSQL به چیزهایی هم نیاز خواهد داشت که قرارداد هنوز پوشش نمی‌دهد: تنظیم sequenceهایش پس از کپی یک
بازسازی (شناسه‌های کپی‌شده آن‌ها را عقب جا می‌گذارند)، و جستجویی که به بزرگی و کوچکی حروف حساس نباشد
(<code dir="ltr">Page::whereContains()</code> به collation در MySQL تکیه دارد).

## درایور ایمیل

راهی که ایمیل‌های فروشگاه فرستاده می‌شوند — کدهای ثبت‌نام و بازنشانی رمز عبور یک وب‌سایت، و اطلاع‌رسانی‌هایی برای
کاربری که تلگرام به او نمی‌رسد.

1. **درایور** — `app/Core/Mail/Drivers/<Name>.php`، که `MailDriver` را پیاده‌سازی می‌کند:
   - متد <code dir="ltr">key()</code> — آنچه `MAIL_TRANSPORT` نگه می‌دارد (`none` درایور نیست: یعنی بدون ایمیل)؛
   - متد <code dir="ltr">describe()</code> — برچسب و توصیفش، فرمش (تنظیمات <code dir="ltr">MAIL_\*</code> خودش، هر کدام
     زیر تنظیم `config.php` خودش نگه داشته می‌شود؛ رمز عبور یا کلید یک `Secret` است — وابسته به فیلدهای حسابی که مال
     آن است، وقتی چنین فیلدهایی باشند —، با `missing` وقتی بدون آن کار نمی‌کند)، و trait `MailDriver::SENDER`: ایمیل‌هایش
     از کدام آدرس می‌توانند فرستاده شوند، با متنی برای مالک فروشگاه؛
   - متد <code dir="ltr">transport(values)</code> — transport کتابخانه symfony/mailer که آن تنظیمات می‌سازند، یا یک
     `MailSettingsException` وقتی هیچ transportی نمی‌سازند (لاگ دلیلش را می‌گوید، و هیچ ایمیلی فرستاده نمی‌شود). یک API
     ایمیل، transportی از خود برنامه است روی تنها کلاینت خروجی فروشگاه، `GuzzleHttp\ClientInterface` (همان‌طور که
     `Core\Mail\ResendTransport` هست)، تا تست‌ها مثل هر فراخوانی دیگری به آن پاسخ دهند.
2. **ثبت آن** در `bootstrap/container.php`: آن را به `mail.drivers` اضافه کنید. `Core\Mail\Mailer` رمزهای همه
   درایورهای ایمیل را از هر چه یک سرور ایمیل خودش بگوید بیرون می‌کشد.
3. **توصیف درخواستش**: کلیدش در enum مربوط به `MailTransport`، و یک `Mail<Name>Request` در `oneOf` مربوط به
   `ConfigMailRequest` (`transport`، فیلدهایش، `from_address` و `from_name`)، بعد `pnpm api:types`. «تنظیمات پنل ›
   ایمیل» آن را از روی توصیفش رسم می‌کند.
4. **تست‌ها**: transport آن در `tests/Unit/Core/MailTransportTest.php` (و یک تست جداگانه برای transportی از خود برنامه،
   مثل `ResendTransportTest`)؛ یک ذخیره در `tests/Feature/MailSettingsApiTest.php`. `DriverFormsTest` خودش درخواستش را با
   فرمش تطبیق می‌دهد.
5. **ساختن دوباره مرجع**: `php scripts/docs.php` تنظیمات آن را به
   [مرجع config.php (انگلیسی)](../Config-Reference.md#mail) اضافه می‌کند.

## درایور کپچا

کپچایی که وب‌سایت یک فروشگاه می‌تواند در فرم‌هایش بخواهد — ثبت‌نام، ورود با رمز عبور، بازنشانی رمز عبور، ثبت نظر، و
هر فرمی از خود سایت که backend آن توکنش را برای سنجش به فروشگاه می‌دهد ([کپچا](Store-API-Sign-In.md#کپچا)).

1. **درایور** — `app/Core/Captcha/Drivers/<Name>.php`، که `CaptchaDriver` را پیاده‌سازی می‌کند:
   - متد <code dir="ltr">key()</code> — آنچه وب‌سایت نگه می‌دارد (`websites.captcha_driver`) و `captcha.driver` در پاسخ
     <code dir="ltr">GET /</code> می‌گوید؛
   - متد <code dir="ltr">describe()</code> — برچسب، توصیف و یادداشت‌هایش، و فرمش: فیلدهایش در `captcha_config`
     رمزنگاری‌شده وب‌سایت زیر کلیدهایشان نگه داشته می‌شوند — و رمز، وابسته به کلید عمومی‌ای که همراهش است؛
   - متد <code dir="ltr">siteKey(values)</code> — کلید عمومی که صفحه ویجت را با آن رسم می‌کند، و `null` برای کپچایی که
     کلید عمومی ندارد؛
   - متد <code dir="ltr">verify(values, CaptchaAttempt)</code> — سنجش یک توکن: یک `CaptchaVerdict` (اینکه قبول شد یا نه،
     action و هاستی که برایشان حل شده، و دلیل رد، با کدهای دلیل [کپچا](Store-API-Sign-In.md#کپچا))؛ `Verifier` آن را با
     action فرم و هاست‌های وب‌سایت تطبیق می‌دهد. شیء attempt توکن، action، هاست‌های وب‌سایت و آدرس بازدیدکننده را، وقتی
     معلوم باشد، با خود دارد (`visitor`: فرستنده یک فرم ورود، یا `remoteip` که backend یک سایت نام می‌برد) — Turnstile
     آن را به Cloudflare می‌دهد. یک `CaptchaUnavailableException` وقتی نشد توکن را سنجید — سرویس‌دهنده‌اش در دسترس نیست
     — یک `503` است، هرگز یک رد.
   - کپچایی که ویجتش چالشش را از فروشگاه می‌گیرد `IssuesChallenges` را هم پیاده‌سازی می‌کند:
     <code dir="ltr">challenge(values, action)</code>، که در `GET /captcha/challenge` سرو می‌شود.
2. **ثبت آن** در `bootstrap/container.php`: آن را به `captcha.drivers` اضافه کنید.
3. **توصیف آن**: کلیدش در enum مربوط به `CaptchaDriver` و در enum مربوط به `captcha.driver` وب‌سایت، و یک
   `Website<Name>Request` در `oneOf` مربوط به `WebsiteCaptchaRequest`، بعد `pnpm api:types`. «تنظیمات وب‌سایت» فرمش را
   از روی توصیفش رسم می‌کند.
4. **تست‌ها**: یک تست unit مثل `tests/Unit/Core/Captcha/TurnstileTest.php` یا `AltchaTest.php` — توکنی که روی هاست
   سایت برای action فرم حل شده قبول می‌شود، توکنی که جای دیگری یا برای action دیگری حل شده نه، ردهای سرویس‌دهنده،
   سرویس‌دهنده‌ای که در دسترس نیست؛ نسخه‌ای فیک از سرویس‌دهنده‌اش در `tests/Support` وقتی یکی را صدا می‌زند (مثل
   `FakeTurnstile`)؛ فرم‌های وب‌سایت با آن در `tests/Feature/Store/CaptchaTest.php`؛ و `DriverFormsTest`، که شکل
   درخواستش را با فرمش، و فهرست کپچاهای توصیف را با کپچاهای ثبت‌شده، تطبیق می‌دهد.
5. **به توسعه‌دهندگان وب‌سایت‌ها بگویید**: یک صفحه چطور آن را رسم می‌کند، در [کپچا](Store-API-Sign-In.md#کپچا).

</div>
