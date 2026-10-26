[English](../Development.md)

<div dir="rtl" lang="fa">

# توسعه

کار روی خود AmoBot: آنچه لازم دارید، اجرای آن روی سیستم خودتان، بررسی‌ها، و اینکه هر چیز کجاست. برای نقشه کلی از
[معماری](Architecture.md) شروع کنید و برای اینکه کد چطور نوشته می‌شود از [قراردادهای کد](Conventions.md)؛ قاعده‌های هر
بخش در صفحه ساختار داخلی آن و در docblock کلاس‌هایش آمده است.

## فناوری‌ها

| بخش | انتخاب |
|---|---|
| HTTP | [Slim 4](https://www.slimframework.com/) (PSR-7/15) و [PHP-DI](https://php-di.org/) |
| پنل‌ها | React 19، Vite، TypeScript، Tailwind 4 و shadcn/ui؛ پنل مدیریت و پنل نمایندگی، یک پروژه که به فایل‌های استاتیک بیلد می‌شود؛ به‌روزرسانی زنده از راه فید تغییرات (change feed) |
| API | فقط JSON (<code dir="ltr">/api/admin</code> برای مالک فروشگاه، <code dir="ltr">/api/agent</code> برای نماینده، <code dir="ltr">/api/store/v1</code> برای وب‌سایت‌های فروشگاه‌ها)، توصیف‌شده با OpenAPI 3 (`resources/api/openapi.yaml`): تست‌ها هر درخواست و پاسخ را با آن اعتبارسنجی می‌کنند، و نوع‌های TypeScript پنل‌ها از روی آن ساخته می‌شوند |
| دیتابیس | MySQL / MariaDB از راه Eloquent (`illuminate/database`)، پشت یک لایه درایور؛ SQLite برای تست‌ها |
| CLI | Symfony Console (`php bin/console`) |
| HTTP خروجی | Guzzle: پنل‌های VPN، تلگرام، سرویس‌دهنده‌های OpenID، Cloudflare |
| ایمیل | symfony/mailer، و API سرویس Resend |
| ورود | firebase/php-jwt (OpenID Connect)، و TOTP خود برنامه |
| لاگ | Monolog → <code dir="ltr">storage/logs/</code>، با حذف اطلاعات محرمانه از خط‌هایی که خود برنامه می‌نویسد (فایل `php-errors.log` خود PHP پاک‌سازی نمی‌شود) |
| کیفیت | PHPUnit 11، PHPStan (سطح ۶، و کد مرده: shipmonk/dead-code-detector)، PHP-CS-Fixer (PER-CS 2)؛ TypeScript، oxlint، knip، Prettier، Vitest |

## پیش‌نیازها

- نسخه 8.3.36، 8.4.27 یا 8.5.12 از PHP، یا بالاتر در همان شاخه، با افزونه‌هایی که [نصب](Installation.md#پیش‌نیازها)
  می‌گوید (XAMPP افزونه GD را دارد: در فایل `php.ini` آن، خط `extension=gd` را از حالت کامنت دربیاورید)، و
  `pdo_sqlite` برای مجموعه تست‌ها؛ و Composer.
- دیتابیس MySQL یا MariaDB برای یک فروشگاه محلی (XAMPP کافی است). تست‌ها به هیچ‌کدام نیاز ندارند: روی SQLite در حافظه
  اجرا می‌شوند.
- نسخه 22.12 یا جدیدتر از Node و pnpm 12 (فایل `package.json` نسخه آن را تعیین می‌کند: با روشن بودن Corepack، یعنی
  `corepack enable`، همان نسخه درست اجرا می‌شود)؛ فقط برای کار روی پنل‌ها. هر نسخه منتشرشده پنل‌ها را بیلدشده همراه
  دارد.

## اجرای محلی

```bash
composer install
pnpm install && pnpm build       # the panels -> public/admin, public/agent, public/assets
composer serve                   # the API on http://127.0.0.1:8080 (php -S); /admin/ and /agent/ are the last build
```

آدرس <code dir="ltr">http://127.0.0.1:8080/</code> را باز کنید: به پنل مدیریت می‌رود، که تا وقتی فروشگاه نصب نشده
نصب‌کننده وب را شروع می‌کند ([نصب‌کننده وب](Installation.md#نصب‌کننده-وب))، درست مثل روی هاست.

برای کار روی پنل‌ها: `pnpm dev` را کنار `composer serve` اجرا کنید و از <code dir="ltr">http://127.0.0.1:5173/admin/</code>
(پنل مدیریت) یا <code dir="ltr">http://127.0.0.1:5173/agent/</code> (پنل نمایندگی) استفاده کنید، با بارگذاری خودکار
تغییرات (hot reload)؛ درخواست‌های <code dir="ltr">/api</code> به PHP فرستاده می‌شوند. اما آدرس
<code dir="ltr">http://127.0.0.1:8080/admin/</code> و `/agent/` همیشه آخرین `pnpm build` را نشان می‌دهند. پنل
مدیریت فروشگاهی را که نشان می‌دهد در آدرسش نگه می‌دارد: <code dir="ltr">/admin/s/&lt;id&gt;/…</code> فروشگاه یک نماینده است.
نماینده فقط با لینک یک‌بارمصرفی وارد می‌شود که ربات اصلی به او می‌دهد («نمایندگی» › «🔐 ورود به پنل»)؛ کد این لینک در
بخش <code dir="ltr">#fragment</code> آدرس است، که هرگز به لاگ‌های سرور نمی‌رسد.

برای ربات: توکنی از @BotFather را در «تنظیمات پنل › ربات تلگرام» بگذارید (یا `'TELEGRAM_BOT_TOKEN'` را در `config.php`
تنظیم کنید)، بعد:

```bash
php bin/console bot:poll --watch -v # long polling for every bot; restarts on code changes; runs the scheduler too
php bin/console bot:info            # every bot's identity and webhook state
```

روش Long polling به آدرس عمومی نیاز ندارد، و برای همین سیستم توسعه‌دهنده از آن استفاده می‌کند؛ فروشگاهی که روی هاست است
با Webhook کار می‌کند ([دریافت پیام‌های ربات](Running-In-Production.md#دریافت-پیام‌های-ربات)). دستور `bot:poll` یک قفل
نگه می‌دارد، پس پولینگ دوم اجرا نمی‌شود. در Windows، متوقف کردن یک شل پس‌زمینه ممکن است پروسه فرزند `php.exe`
آن را با کد قدیمی در حال اجرا باقی بگذارد: آن را از روی خط فرمانش پیدا و متوقف کنید.

## بررسی‌ها

```bash
composer check      # code style (dry run) + PHPStan (level 6, and dead code) + the PHP tests
composer fix        # apply the code style (PER-CS 2)
composer test       # the PHP tests alone
composer analyse    # PHPStan alone
pnpm check          # the panels: API types current + typecheck + oxlint + knip + prettier --check + unit tests
pnpm format         # prettier: import order, Tailwind's class order
pnpm api:types      # regenerate the panels' API types after changing resources/api/openapi.yaml
php scripts/docs.php          # regenerate the documentation's generated pages
php scripts/docs.php --check  # the documentation current, and every link between its pages leading somewhere
```

پیش از هر Pull request آن‌ها را اجرا کنید؛ CI همه‌شان را اجرا می‌کند ([تست](Testing.md)). برای درست کردن سبک کد فقط چند
فایل، فایل پیکربندی را هم همراه آن‌ها نام ببرید:
<code dir="ltr">vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php &lt;paths&gt;</code>.

## ساختار پروژه

```text
app/
  Core/            framework glue: Application, config.php, the database (schema, upgrades, change feed, drivers), the
                   HTTP kernel and its own endpoints (/api/app, /health, /cron), drivers and forms, mail, captcha,
                   security, sessions, logging, scheduling, files and locks
  Console/         bin/console's kernel and commands
  Modules/         one folder per domain (Controllers/ or Api/, Models/, Services/, …) — which modules each may name is
                   tests/Unit/Core/LayersTest's MODULES map
    Accounts       website customers' sign-in and accounts: sessions, OpenID Connect, email, two-factor, ways in, merging
    Admin          the panels' JSON API (/api/admin, /api/agent) and the dashboard
    Agency         resellers: requests, levels (a price per GB), credit, their own bots and the traffic they sell
    Auth           the panels' sign-in: the owner's login and its recovery, an agent's one-time link, the throttle;
                   who acts — a principal, an Actor
    Bots           the bots, and whose shop the code works in
    Catalog        plans, their categories, which servers can sell them
    Discounts      discount codes, timed sales, loyalty tiers, a customer's own discount
    Installer      the web installer's API (/api/install)
    Notifications  what the shop tells a customer: kept for their website, sent to Telegram or by email
    Orders         the order's lifecycle
    Payments       gateways (wallet, card-to-card, CubePay, Zarinpal, AtlasPay, AbanGateway, Plisio, AqayePardakht,
                   Variza, and "outside": money support took for an order it made), the checkout, the settlement
    Providers      the VPN panels: the contract, 3X-UI and PasarGuard, servers and inbounds
    Referrals      invite links and commissions
    Reviews        customers' reviews of the shop: written on its website, decided by support
    Rewards        cashback, gift codes, the lucky wheel, the nightly draw
    Settings       the settings table, and the settings screens of config.php and the bot
    Store          the shops' websites' API (/api/store/v1)
    Subscriptions  services on the panels: provisioning, renewal, sync, moves, grants, reminders
    Support        support tickets
    Telegram       the Bot API client, the update dispatcher, handlers, keyboards, texts, broadcasts, the report group
    Updates        the owner's one-press update from a signed GitHub release
    Users          customers, the wallet ledger, customer groups, pictures
  Support/         helpers: input, validation, money, time (the shop's clock, Jalali months), Persian, traffic,
                   requirements
bootstrap/         app.php (boot), container.php (the DI definitions and the driver registries), schedule.php (the tasks)
config/            config.php's settings made into the app's parts
database/          schema.php (every table) and upgrades/ (a release's changes for installed shops — see Database)
docs/              this documentation
phpstan/           the project's own PHPStan extensions: Eloquent's forwarded query methods, and what the dead-code check
                   counts as used (the container's entries, the routes, the models' relations something reads)
public/            the web root: index.php, .htaccess and the built panels (admin/, agent/, assets/)
resources/         panel/ (the panels' React source), api/openapi.yaml (the API's description), assets/
routes/            web.php, api.php, webhooks.php, bot.php
scripts/           release.php and release-key.php, docs.php, api-types.mjs, timezones.php
storage/           logs, cache, sessions, uploads, installed.lock
tests/             PHPUnit: Feature/, Unit/, the fakes and the fixtures
```

پنل‌ها یک پروژه React هستند (`resources/panel`، Vite، TypeScript، Tailwind 4، shadcn/ui): اپ مالک فروشگاه و اپ نماینده
(`src/apps/admin`، `src/apps/agent`) روی صفحه‌های فروشگاه که هر دو دارند (`src/pages`)، کامپوننت‌های مشترک
(`src/components`) و کتابخانه‌ها (`src/lib`). یک `vite build` هر دو پنل و فایل‌هایی را که میانشان مشترک است می‌نویسد.

افزودن چیزی تازه: [افزودن قابلیت](Extending.md)، [درایورها](Drivers.md).

</div>
