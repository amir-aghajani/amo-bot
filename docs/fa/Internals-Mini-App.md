[English](../Internals-Mini-App.md)

<div dir="rtl" lang="fa">

# ساختار داخلی: مینی‌اپ

مینی‌اپ تلگرام فروشگاه‌ها: بخش کاربری یک فروشگاه داخل تلگرام — خرید، پرداخت، سرویس‌ها، کیف پول، برنامه‌های فروشگاه،
پشتیبانی، حساب کاربری. صفحه سوم پروژه React پنل‌هاست (`resources/panel`)، که جداگانه بیلد می‌شود، و فقط از راه Store API
با PHP حرف می‌زند، زیر کلید فروشگاه خود مینی‌اپ ([دو در](Store-API.md#دو-در)): همان سرویس‌ها و قاعده‌های ربات و وب‌سایت،
بی‌هیچ چیزی از خودش روی سرور. این صفحه نقشه کد آن است؛ سمت سرور — در، ورود با داده راه‌اندازی، تنظیماتی که پنل‌ها ذخیره
می‌کنند — در [ساختار داخلی: API وب‌سایت](Internals-Website-API.md) است، و دکمه‌های ربات که آن را باز می‌کنند در
[ساختار داخلی: ربات تلگرام](Internals-Telegram-Bot.md#مینی‌اپ).

## درخت سورس

```text
resources/panel/
  app/index.html     the mini app's page (entry: src/apps/app/main.tsx)
  app/open.html      the bridge that hands a VPN app its "add a subscription" address (plain HTML, no React)
  src/apps/app/
    config.ts        the store key, the Store API's base and the router's basename, read off the page's address
    main.tsx         Telegram's script (bundled), the page handed to Telegram, the app drawn
    app.tsx          the routes (APP_ROUTES), the sign-in gate, the shop's look
    app.css          the design tokens, from the user's Telegram theme
    lib/             the Store API client, the query client, the session, the recent sign-in, the checkout's hand-over
    telegram/        the typed bridge to Telegram.WebApp: buttons, haptics, theme, fingerprint
    ui/              the app's own kit: Screen, Section and Row, Button, MainAction, Sheet, fields, states, the tab bar,
                     the preview's mark
    parts/           what several screens share (the checkout's ways to pay, a discount code, the price)
    screens/<area>/  a file per route, its default export the screen; its parts/ and its tests beside it
    test/            the tests' address and render helpers
```

کتابخانه‌های پنل‌ها را شریک است — `lib/api` (`apiClient`، `ApiError`)، `lib/api-types`، `lib/format`، `lib/statuses`،
`lib/jalali` — و فونت Vazirmatn را که روی خود سرور است؛ هیچ کامپوننت یا صفحه‌ای از پنل‌ها را import نمی‌کند، و پنل‌ها هم
هرگز کد آن را import نمی‌کنند.

## بیلد

دستور `vite build --mode app` — قدم دوم `pnpm build` — آن را جدا از پنل‌ها بیلد می‌کند (`vite.config.ts`، حالت `MINI_APP` آن):

- ورودی‌هایش `app/index.html` و `app/open.html` هستند؛ در <code dir="ltr">public/app/</code> می‌نویسد، با فایل‌های hashدارش در <code dir="ltr">public/app/assets/</code> (یک نسخه brotli کنار هر اسکریپت و stylesheet، و <code dir="ltr">assets/.htaccess</code> خودشان)، در دو chunk کتابخانه (`react`، `vendor`) کنار chunkهای خودش؛ پیش از نوشتن فقط `public/app` را پاک می‌کند.
- هر صفحه Content-Security-Policy بیلد را می‌گیرد، مثل صفحه یک پنل: اسکریپت‌ها و اتصال‌ها فقط به origin خودش، و اسکریپت inline آن با hashش مجاز. اسکریپت خود تلگرام، `telegram-web-app.js`، از <code dir="ltr">@twa-dev/sdk</code> همراه بیلد می‌آید (که `main.tsx` اول از همه import می‌کند): هیچ اسکریپتی از جای دیگری بار نمی‌شود، و گوشی‌ای که به telegram.org دسترسی ندارد باز هم مینی‌اپ را باز می‌کند.
- فایل <code dir="ltr">public/app/.htaccess</code> را می‌نویسد: وب‌کلاینت تلگرام (web.telegram.org) مینی‌اپ را در یک frame نشان می‌دهد، که <code dir="ltr">public/.htaccess</code> برای همه صفحه‌ها رد می‌کند (`X-Frame-Options: DENY`)، پس پوشه مینی‌اپ آن را برمی‌دارد و فقط به همان سایت و خود فروشگاه اجازه frame کردن می‌دهد (<code dir="ltr">frame-ancestors 'self' https://web.telegram.org</code>، `MINI_APP_FRAME_ANCESTORS` در `vite.config.ts`) — `'self'` برای «پیش‌نمایش» پنل‌ها، که مینی‌اپ را روی همان سایت در یک frame نشان می‌دهد.

فایل <code dir="ltr">public/.htaccess</code> فایل‌های مینی‌اپ را همان‌طور که هستند سرو می‌کند، فایلی را که زیر
پوشه <code dir="ltr">app/assets/</code> نیست با 404 جواب می‌دهد، و هر آدرس دیگری زیر <code dir="ltr">app/</code> را به
`app/index.html` می‌فرستد — و فایل `public/index.php` هم زیر `php -S` همین کار را می‌کند. zip نسخه `public/app` را همراه
دارد (`scripts/release.php` بدون آن بیلد نمی‌کند)، Git آن را نادیده می‌گیرد، و knip ورودی جداگانه `src/apps/app/main.tsx` را
می‌گیرد (`knip.jsonc`)، چون فایل `vite.config.ts` را بیلد پنل‌ها می‌خواند.

**جایی که سرو می‌شود**: <code dir="ltr">{APP_URL}/app/{store key}/…</code>، با کلید خود مینی‌اپ. اسکریپت inline صفحه
`<base>` را روی پوشه مینی‌اپ می‌گذارد، هر جا فروشگاه نصب شده باشد؛ `config.ts` کلید را بعد از <code dir="ltr">app/</code> می‌خواند (۲۴ کاراکتر
هگز با حروف کوچک)، پایه Store API را کنار پوشه (<code dir="ltr">…/api/store/v1/{key}</code>)، و basename مسیریاب را (پوشه و
کلید). آدرسی بدون کلید هیچ فروشگاهی را باز نمی‌کند.

**پل برنامه VPN** (`app/open.html`): `openLink` تلگرام فقط آدرس‌های وب را باز می‌کند، پس «اتصال سریع» روی یک سرویس
(`screens/services/parts/connect`) این صفحه را در مرورگر تلگرام باز می‌کند، با آدرس برنامه بعد از `#` —
<code dir="ltr">v2rayng://</code>، <code dir="ltr">happ://</code>، <code dir="ltr">hiddify://</code> یا
<code dir="ltr">streisand://</code>، و هر scheme دیگری رد می‌شود —، و صفحه آن را به دستگاه می‌دهد (زدن دکمه‌اش هم همین
کار را می‌کند). آدرس بعد از `#` هرگز به سرور یا لاگ آن نمی‌رسد.

## راه‌اندازی

فایل `main.tsx` اسکریپت تلگرام را import می‌کند، که داده راه‌اندازی را می‌خواند، بعد <code dir="ltr">startTelegram()</code>
(`telegram/theme`): مینی‌اپ به تلگرام می‌گوید آماده است، کل ارتفاع را می‌گیرد، کشیدنی را که می‌بندتش قفل می‌کند (Bot API 7.7)،
سرصفحه، پس‌زمینه و نوار پایین تلگرام را با رنگ‌های تم رنگ می‌کند، و با عوض شدن تم همراهش عوض می‌شود. بعد `App`
(`app.tsx`) کشیده می‌شود، داخل یک error boundary آخر (`Crash`، با راهی برای شروع دوباره، هرگز صفحه خالی):

1. **ورود** (`lib/session`): کاربر وارد می‌شود — پایین‌تر.
2. **دروازه** (`Gate`): بیرون از تلگرام مینی‌اپ می‌گوید آن را از ربات باز کنید، با دکمه‌ای به ربات وقتی <code dir="ltr">GET /</code> یکی را نام می‌برد؛ ورودی که شکست خورده می‌گوید چرا، با «تلاش دوباره» جایی که تلاش دوباره ممکن است کمک کند؛ و در این میان skeleton.
3. **ظاهر فروشگاه**، وقتی <code dir="ltr">GET /</code> جواب داد (`useShopLook`): `app.accent_color` به‌عنوان رنگ اصلی، `app.time_zone` برای همه تاریخ‌ها (`setShopTimeZone`)، `app.title` به‌عنوان عنوان صفحه، و تمام‌صفحه روی گوشی (Bot API 8.0) وقتی `app.fullscreen` بخواهد.
4. **مسیریاب**: `APP_ROUTES`، یک `BrowserRouter` زیر basename، و هر صفحه یک مسیر lazy.

## پل تلگرام

پوشه <code dir="ltr">telegram/</code> تنها جایی است که به <code dir="ltr">window.Telegram.WebApp</code> دست می‌زند، با نوع برای بخش‌هایی که
مینی‌اپ به کار می‌برد:

- فایل `webapp.ts` — <code dir="ltr">telegram()</code> (همان شیء تا وقتی صفحه با داده راه‌اندازی از تلگرام باز شده، وگرنه `null`)، <code dir="ltr">inTelegram()</code>، <code dir="ltr">supports(version)</code> و <code dir="ltr">when(version, call)</code>: هر قابلیت اول از نسخه Bot API کلاینت پرسیده می‌شود، چون کلاینت قدیمی‌تر آن را ندارد و اسکریپت روی متدی که ندارد خطا می‌دهد؛ <code dir="ltr">when()</code> خطای کلاینت را هم می‌بلعد. <code dir="ltr">openLink()</code> (مرورگر تلگرام، با مینی‌اپ باز)، <code dir="ltr">openTelegramLink()</code> (یک آدرس `t.me` داخل تلگرام)، <code dir="ltr">shareToChat()</code> و <code dir="ltr">confirmBox()</code> — هر کدام بیرون از تلگرام نسخه خود مرورگر.
- فایل `buttons.ts` — <code dir="ltr">useBackButton(onBack)</code>: دکمه بازگشت تلگرام که یک پشته نگهش می‌دارد و handler آخرین نگه‌دارنده صدا زده می‌شود، پس یک `Sheet` روی یک `Screen` پیش از برگشتن صفحه بسته می‌شود؛ <code dir="ltr">useMainButton()</code>: دکمه اصلی تلگرام، یا دکمه‌ای که صفحه در مرورگر خودش می‌کشد.
- فایل `haptics.ts` — <code dir="ltr">tap()</code>، <code dir="ltr">result()</code>، <code dir="ltr">selection()</code>.
- فایل `theme.ts` — <code dir="ltr">startTelegram()</code>، <code dir="ltr">goFullscreen()</code>، <code dir="ltr">setAccent()</code>.
- فایل `biometric.ts` — `BiometricManager` تلگرام (Bot API 7.2): <code dir="ltr">biometrics()</code> (جایی که دستگاه ندارد `null`)، <code dir="ltr">requestAccess()</code>، <code dir="ltr">keepToken()</code>، <code dir="ltr">unlock()</code>.
- فایل `active.ts` — <code dir="ltr">useAppActive()</code>: اینکه مینی‌اپ جلوی چشم کاربر است یا نه — صفحه‌اش نمایش داده می‌شود و، در Bot API 8.0، مینی‌اپ کوچک نشده (رویدادهای `activated` و `deactivated` تلگرام) —، تا صفحه‌ای که هر چند ثانیه چیزی را دوباره می‌خواند (خانه، قرعه‌کشی شبانه، پرداخت با درگاه) فقط در همان وقت این کار را بکند.

## نشست‌ها و ورود تازه

فایل `lib/session.tsx` کاربر را با داده راه‌اندازی وارد می‌کند: `POST /auth/mini-app` با `Telegram.WebApp.initData`، و کد
دعوتی که مینی‌اپ با آن باز شده (`initDataUnsafe.start_param`، آنچه بعد از <code dir="ltr">ref_</code> می‌آید)
([مینی‌اپ](Store-API-Sign-In.md#مینی‌اپ)). توکن در فضای ذخیره همین دستگاه زیر کلید فروشگاه و شناسه حساب تلگرام نگه داشته
می‌شود (<code dir="ltr">amobot-app:{key}:{telegram id}</code>): باز شدن بعدی آن را با `GET /me` می‌سنجد — که هنوز Sessionی
را باز می‌کند، و مال همین حساب تلگرام است — و با همان ادامه می‌دهد، پس هر بار باز شدن یک Session تازه باز نمی‌کند و رمز اثر
انگشت Session به کار می‌آید. توکن نگه‌داشته‌ای که تمام شده یا مال حساب دیگری است کنار گذاشته می‌شود و داده راه‌اندازی از نو
وارد می‌کند، همان‌طور که هر بار درخواستی جواب بدهد Session تمام شده (`onSessionEnded` از query client). ورودی که رد شود (`401`،
`403`) یک بار باز کردن تازه می‌خواهد؛ یک `404` می‌گوید مینی‌اپ فروشگاه خاموش است. خروجی در کار نیست: تلگرام هر بار کاربر را
وارد می‌کند.

فایل `lib/recent.ts` — <code dir="ltr">withRecentSignIn(reason, run)</code> دور هر درخواستی پیچیده می‌شود که ممکن است `403`
ورود تازه را جواب بدهد (سپردن سرویس یا پول، افزودن ایمیل، رمز عبور، روشن کردن اثر انگشت): اگر برای همین رد شود، کاربر بی‌آنکه
از مینی‌اپ بیرون برود ثابت می‌کند خودش است — اول با اثر انگشت تا وقتی `session.biometric` در پاسخ `GET /me` می‌گوید رمزی نگه
داشته شده (`POST /me/reauthenticate`، `biometric`، که `reason` متن پنجره تلگرام است)، وگرنه با داده راه‌اندازی تا وقتی کمتر از ۱۵
دقیقه از آن گذشته (`mini_app`) — و درخواست یک بار دیگر اجرا می‌شود. اگر هیچ‌کدام نشد، `RecentSignInNeeded` به او می‌گوید
مینی‌اپ را دوباره باز کند یا اثر انگشت را روشن کند ([ورود تازه](Store-API-Account.md#ورود-تازه)).

بقیه <code dir="ltr">lib/</code>:

- فایل `store.ts` — `store`، کلاینت Store API که با توصیف API نوع خورده (`StoreWrite`: بدنه اشتباه خطای compile است)، با توکن Bearer روی هر درخواست؛ <code dir="ltr">pictureUrl()</code> برای تصویری که فقط کاربر می‌تواند بخواند (کارت QR یک سرویس، تصویر یک تیکت) به‌شکل یک object URL؛ <code dir="ltr">requestKey()</code>، یک `Idempotency-Key` تازه — یکی برای هر تلاش پرداخت، که در تلاش‌های دوباره همان می‌ماند؛ <code dir="ltr">problemOf()</code>، کلمه‌های کاربر برای یک خطا (با «کد پیگیری» برای خطای سرور)؛ <code dir="ltr">signedOut()</code>، <code dir="ltr">needsRecentSignIn()</code>، <code dir="ltr">fieldProblem()</code>.
- فایل `query.ts` — query client: فقط خواندنی که جوابی نگرفته دوباره امتحان می‌شود (دو بار)، هر خواندن ۲۰ ثانیه تازه است و وقتی مینی‌اپ دوباره دیده شود دوباره خوانده می‌شود (تلگرام زیاد آن را کوچک می‌کند)، نوشتن بدون اینترنت همان لحظه شکست می‌خورد و یک toast است مگر با `meta.quiet`؛ `keys`، کلیدهای query.
- فایل `checkout.ts` — آنچه درخواست سفارش جواب داده (کارت، صفحه درگاه) برای سفارشش در فضای ذخیره تب نگه داشته و به <code dir="ltr">/pay/:orderId</code> سپرده می‌شود (<code dir="ltr">goPay()</code>)، پس برگشتن یا بارگذاری دوباره دوباره نشانش می‌دهد.

## تم و مجموعه کامپوننت‌ها

فایل `app.css` توکن‌ها را از تم تلگرام کاربر می‌سازد — ویژگی‌های <code dir="ltr">--tg-theme-\*</code> که اسکریپت تلگرام روی
صفحه نگه می‌دارد — با یک جایگزین روشن و یک تیره برای کلاینتی که کمتر می‌فرستد، یا یک مرورگر؛ حالت روشن یا تیره یک class روی
`<html>` است؛ رنگ فروشگاه، اگر گذاشته باشد، رنگ اصلی است (<code dir="ltr">--app-brand</code>)؛ <code dir="ltr">--app-top</code> و <code dir="ltr">--app-bottom</code> مینی‌اپ تمام‌صفحه را
از کنترل‌های تلگرام و لبه‌های گوشی دور نگه می‌دارند. صفحه‌ها بخش‌های گروه‌بندی‌شده روی پس‌زمینه دوم‌اند، مثل تنظیمات خود
تلگرام؛ راست به چپ، با تکه‌های لاتین (لینک‌ها، عددهایی برای کپی) که `ltr` می‌شوند؛ مبلغ به تومان و تاریخ در منطقه زمانی
فروشگاه از راه `lib/format`.

پوشه <code dir="ltr">ui/</code> مجموعه‌ای است که هر صفحه از آن ساخته می‌شود: `Screen` (عنوان، بازگشت، عمل‌ها، نوار تب یا یک عمل پایین صفحه)،
`Section`، `Row` و `Fact`، `Button`، `MainAction` (یکی در هر صفحه، هرگز داخل sheet)، `Sheet`، فیلدها، نمایه‌ها (حلقه، نوار،
نشان، قیمت با قیمت قبلی‌اش، شمارش معکوس)، حالت‌ها (`Empty`، `ErrorView` با تلاش دوباره، `LoadingBlocks`، `Skeleton`، `Notice`)،
نوار تب و toaster.

## صفحه‌ها

فهرست صفحه‌ها در `APP_ROUTES` (`app.tsx`) است: پنج تب — خانه (`/`)، خرید (<code dir="ltr">/buy</code>)، سرویس‌ها (<code dir="ltr">/services</code>)، کیف پول (<code dir="ltr">/wallet</code>) و
حساب (<code dir="ltr">/account</code>) — نوار تب را نشان می‌دهند؛ هر صفحه دیگری با دکمه بازگشت تلگرام روی آن‌ها باز می‌شود. بخش‌های زیر <code dir="ltr">screens/</code>:
خانه و اعلان‌هایش؛ خرید (کاتالوگ، یک پلن، سرویس ساخته‌شده، تست رایگان)؛ پرداخت (<code dir="ltr">/pay/:orderId</code>)؛
سرویس‌ها (فهرست، سرویسی که با لینکش اضافه می‌شود، یک سرویس، تمدیدش، خریدهای اضافه‌اش، انتقالش)؛ کیف پول (شارژ، انتقال
موجودی، تاریخچه، فاکتور در <code dir="ltr">/orders/:id</code>)؛ باشگاه (گردونه شانس، قرعه‌کشی شبانه، زیرمجموعه‌گیری)؛
پشتیبانی (تیکت‌ها، تیکت تازه، گفتگو، آموزش، نظرات)؛ حساب (امنیت، افزودن ایمیل).

آنچه همه صفحه‌ها رعایت می‌کنند:

- **تصمیم با سرور است.** قیمت همان `offer_price` یا استعلام API است؛ هر درخواست سفارش برای هر تلاش یک `Idempotency-Key` دارد؛ گردونه تا قسمتی می‌چرخد که `POST /wheel/spin` انتخاب کرده (`landed`)، و حرکتش (`screens/club/parts/spin`) فقط آن را می‌کشد.
- **برنامه‌های فروشگاه** تا وقتی `shop.programs` در پاسخ <code dir="ltr">GET /</code> می‌گوید نشان داده می‌شوند.
- **بدون HTML خام.** آموزش فروشگاه، به HTML تلگرام، در یک سند بی‌اثر parse می‌شود و فقط تگ‌های خود تلگرام دوباره به‌شکل elementهای React ساخته می‌شوند (`screens/support/parts/telegram-html`)؛ هیچ چیزی به‌شکل HTML در صفحه گذاشته نمی‌شود.
- **شماره تلفنی** که ربات فروشگاه می‌خواهد (`shop.phone_required`) با `requestContact` تلگرام (Bot API 6.9) فرستاده می‌شود، که کارت تماس کاربر را به ربات می‌فرستد ([سمت ربات](Internals-Telegram-Bot.md#مینی‌اپ))؛ حساب دوباره خوانده می‌شود تا شماره نشان داده شود. تا آن موقع سرور سفارش‌ها، کدهای هدیه و سرویس‌هایی را که با لینک گرفته می‌شوند رد می‌کند (`422` روی `status`، <code dir="ltr">CustomerGates::holdBack()</code>)، چون ربات پیش از دروازه‌هایش به چیزی جواب نمی‌دهد.
- **فاکتوری که به‌شکل تصویر نگه داشته می‌شود** روی یک canvas کشیده می‌شود (`screens/wallet/parts/invoice-image`) — مرورگر متن فارسی را شکل می‌دهد — و به‌شکل یک PNG نشان داده می‌شود تا نگه داشته و ذخیره شود: دانلود خود تلگرام فقط آدرس https می‌پذیرد.

## پیش‌نمایش

«پیش‌نمایش» پنل‌ها خود مینی‌اپ را در frameی باز می‌کند که مثل تلگرام جواب می‌دهد
([ساختار داخلی: پنل‌ها](Internals-Panels.md#تنظیمات-وب‌سایت-و-پنل)): بدون داده راه‌اندازی، و با `tgWebAppPreview` در fragment
آدرس، توکن یک Session فقط خواندنی برای یکی از کاربران فروشگاه ([سمت سرور](Internals-Website-API.md#تنظیمات-مینی‌اپ)).
مینی‌اپ آن را با <code dir="ltr">previewToken()</code> (`telegram/webapp`) از باز شدن واقعی در تلگرام جدا می‌کند: توکنی که
telegram-web-app.js از fragment خوانده (<code dir="ltr">Telegram.WebView.initParams</code>) تا وقتی داده راه‌اندازی نیست، و فقط با
نویسه‌های یک توکن Bearer — وگرنه `null`؛ <code dir="ltr">inPreview()</code> می‌گوید چنین توکنی هست یا نه. پیش‌نمایش داخل تلگرام
حساب می‌شود (<code dir="ltr">telegram()</code> همان شیء را می‌دهد و <code dir="ltr">inTelegram()</code> درست است)، چون frame آن
نقش تلگرام را بازی می‌کند.

- **ورود**: `lib/session` توکن پیش‌نمایش را همان Session می‌گیرد — `GET /me` با آن، هرگز در فضای ذخیره دستگاه نگه داشته نمی‌شود و هرگز با Session دیگری عوض نمی‌شود. پاسخ `401` آن یعنی پیش‌نمایش تمام شده: مینی‌اپ همین را می‌گوید («پیش‌نمایش تمام شده است؛ …»)، و فقط پنل پیش‌نمایش دیگری باز می‌کند.
- **هیچ کاری خودبه‌خود**: سرور هر نوشتنی را از پیش‌نمایش رد می‌کند (یک `403`، «این پیش‌نمایش است؛ …»)، و مینی‌اپ هم خودش چیزی نمی‌فرستد — هیچ اعلان یا تیکتی خوانده‌شده علامت نمی‌خورد، هیچ سرویسی هنگام باز شدن دوباره از پنلش خوانده نمی‌شود، از هیچ درگاهی نتیجه پرداخت پرسیده نمی‌شود، و هیچ ورود تازه‌ای خواسته نمی‌شود (<code dir="ltr">needsRecentSignIn()</code> نادرست است): حساب کاربر همان‌طور می‌ماند که خودش گذاشته.
- **نشان**: `ui/preview-mark` یک برچسب کوچک «پیش‌نمایش» درست زیر هدر و کنترل‌های تلگرام، در گوشه انتهایی، می‌کشد؛ لمسی نمی‌گیرد.

## تست‌ها

تست‌های مینی‌اپ در مجموعه Vitest پنل‌ها اجرا می‌شوند، کنار چیزی که تست می‌کنند، در برابر همان سرور فیک، و هر درخواست با توصیف
API سنجیده می‌شود ([تست](Testing.md#پنل‌ها)). صفحه این مجموعه پنل مالک است، پس **هر فایل تست مینی‌اپ با این شروع می‌شود**:
<code dir="ltr">vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))</code>، آدرس مینی‌اپ در تست‌هایش —
کلید فروشگاه `0123456789abcdef01234567`، و درخواست‌هایش زیر <code dir="ltr">/api/store/v1/0123456789abcdef01234567</code>.
بعد `test/app.tsx`:

- تابع <code dir="ltr">screen(element, { at, route, shop, account })</code> — یک صفحه همان‌طور که کاربر واردشده می‌بیند: providerها، یک مسیریاب در `at` زیر الگوی `route`، یک توکن Bearer روی هر درخواست، و <code dir="ltr">GET /</code> و `GET /me` که از پیش جواب گرفته‌اند (`null` هر کدام را به خود تست می‌سپارد)؛
- توابع <code dir="ltr">shop()</code> و <code dir="ltr">account()</code> — همان جواب‌ها: فروشگاهی با همه برنامه‌ها روشن، بدون رنگ یا لوگوی خودش؛ کاربر ۷ (تلگرام ۴۲)، که همین حالا با مینی‌اپ وارد شده؛
- تابع <code dir="ltr">telegram(overrides)</code> — یک تلگرام فیک روی window (کلاینت Bot API 8.0، که کاربر ۴۲ باز کرده)، با <code dir="ltr">mainButton.press()</code>، <code dir="ltr">backButton.press()</code> و spyهای لرزش.

دستور `npx vitest run src/apps/app` فقط آن‌ها را اجرا می‌کند (مسیرها از `resources/panel`، ریشه Vitest).

## از کجا شروع کنیم

- صفحه تازه: فایلی زیر <code dir="ltr">screens/&lt;area&gt;/</code> که export پیش‌فرضش صفحه است، مسیر lazy آن در `APP_ROUTES`، ساخته‌شده از <code dir="ltr">ui/</code>، با تستش کنارش.
- فراخوانی تازه Store API: عملیاتش در `resources/api/openapi.yaml`، `pnpm api:types`، بعد <code dir="ltr">store.get&lt;T&gt;()</code> یا یک نوشتن نوع‌دار؛ نوشتنی که ممکن است ورود تازه بخواهد از <code dir="ltr">withRecentSignIn()</code> می‌گذرد.

</div>
