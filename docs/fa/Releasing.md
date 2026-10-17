[English](../Releasing.md)

<div dir="rtl" lang="fa">

# انتشار نسخه

هر نسخه یک فایل zip است که مالک فروشگاه روی هاستش آپلود می‌کند: خود برنامه، پکیج‌های PHP آن بدون پکیج‌های مخصوص توسعه،
پنل‌های بیلدشده، کلید عمومی نسخه‌ها و یک پوشه <code dir="ltr">storage/</code> خالی، و هیچ چیزی که فروشگاه در حال اجرا
نمی‌خواند (نه تست‌ها، نه سورس پنل‌ها، نه `node_modules`، نه `config.php`، نه لاگ‌ها، نه <code dir="ltr">docs/</code>)؛ و
کنار آن، `release.json`، یعنی مانیفست آن، امضاشده: به‌روزرسان فروشگاه‌ها نسخه‌ای را فقط وقتی نصب می‌کند که مانیفستش با
کلیدی امضا شده باشد که درون خود فروشگاه گنجانده شده است ([به‌روزرسانی](Upgrading.md#به‌روزرسانی-از-پنل)).

## شماره نسخه

ثابت `App\Core\Application::VERSION` شماره نسخه است. نصب‌کننده آن را در `storage/installed.lock` ثبت می‌کند و هر
به‌روزرسانی آن را جلو می‌برد: ارتقاهای دیتابیس از همان‌جا شروع می‌شوند. نسخه‌ای که `database/schema.php` را تغییر
می‌دهد، همان تغییر را به‌صورت `database/upgrades/<its version>.php` هم همراه دارد ([دیتابیس](Database.md)).

## کلید امضا

نسخه‌ها با یک کلید Ed25519 امضا می‌شوند. نیمه عمومی آن `resources/release-key.pub` است، که هر نسخه همراه دارد و هر
فروشگاه نسخه بعدی را با آن بررسی می‌کند؛ نیمه محرمانه‌اش Secret مخزن با نام `RELEASE_SIGNING_KEY` است که Workflow
انتشار از آن استفاده می‌کند، و یک نسخه آفلاین نزد خودتان. این جفت کلید را یک بار بسازید:

```bash
php -d extension=sodium scripts/release-key.php ~/amobot-release.key
```

این دستور کلید عمومی را در `resources/release-key.pub` می‌نویسد (آن را commit کنید) و کلید محرمانه را، به‌صورت
base64، در فایلی که نام برده‌اید؛ بیرون از مخزن، و هرگز چاپ نمی‌شود. بعد:

1. محتوای فایل را Secret مخزن با نام `RELEASE_SIGNING_KEY` کنید (GitHub › Settings › Secrets and variables › Actions،
   یا `gh secret set RELEASE_SIGNING_KEY < ~/amobot-release.key`)؛
2. یک نسخه از آن را آفلاین نگه دارید (در یک برنامه مدیریت رمز عبور، یک درایو رمزگذاری‌شده) و فایل را از اینجا پاک کنید.

هر فروشگاه به کلید نسخه‌ای اعتماد دارد که اجرا می‌کند. کلید تازه (<code dir="ltr">--rotate</code>، بعد از گم شدن یک
کلید یا به‌عمد) فقط از راه نسخه‌ای به فروشگاه‌ها می‌رسد که با کلید مورد اعتماد آن‌ها امضا شده و کلید تازه را همراه
دارد: آن نسخه را با کلید قدیمی امضا کنید (Workflow امضا را با کلید آخرین نسخه بررسی می‌کند) و برای نسخه‌های بعد از آن،
Secret را به کلید تازه تغییر دهید. اگر کلید گم شود، کلید تازه فقط با یک به‌روزرسانی دستی به دست هر فروشگاه می‌رسد.

## ساختن فایل zip

```bash
php scripts/release.php              # pnpm build first, then build/amobot-<version>.zip and build/release.json
php scripts/release.php --no-build   # use the panels already built in public/
php scripts/release.php --composer="php /path/to/composer.phar"
```

این اسکریپت پنل‌ها را بیلد می‌کند، آنچه اجرا می‌شود را کپی می‌کند (`app`، `bin`، `bootstrap`، `config`، `database`،
پوشه `public` با پنل‌های بیلدشده، `routes`، `resources/assets` و `resources/release-key.pub`، فایل
<code dir="ltr">.htaccess</code> ریشه، `composer.json` و `composer.lock`، `LICENSE` و `README.md`)، یک
پوشه <code dir="ltr">storage/</code> خالی می‌سازد (با پوشه‌های `cache`، `logs`، `sessions`، `updates` و `uploads` و یک
فایل <code dir="ltr">.htaccess</code> که همه دسترسی‌ها را می‌بندد)، دستور
`composer install --no-dev --optimize-autoloader --classmap-authoritative` را اجرا می‌کند، به
پوشه‌های <code dir="ltr">vendor/</code> و <code dir="ltr">resources/</code> هم یک <code dir="ltr">.htaccess</code> جدا
می‌دهد که همه دسترسی‌ها را می‌بندد (برای هاستی که پروژه در ریشه وب آن است)، و `build/amobot-<version>.zip` را می‌نویسد و
مقدار sha256 آن را چاپ می‌کند. بعد `build/release.json` را: شماره نسخه، نام، اندازه و sha256 فایل zip، قدیمی‌ترین نسخه
PHP و افزونه‌هایی که `composer.json` لازم دارد، و مسیرهای سطح بالای برنامه که این نسخه جایگزین می‌کند. اگر
مقدار `RELEASE_SIGNING_KEY` در محیط اجرایش باشد، آن را امضا می‌کند و امضا را در `build/release.json.sig` می‌نویسد، البته
فقط وقتی امضا با کلیدی که فروشگاه‌ها به آن اعتماد دارند (`resources/release-key.pub`،
یا <code dir="ltr">--trusted-key=&lt;file&gt;</code>) تایید شود؛ بدون کلید، نسخه بدون امضاست و هیچ فروشگاهی خودش را به
آن به‌روز نمی‌کند.

این اسکریپت Composer لازم دارد (یک `composer.phar` در PATH با همین PHP اجرا می‌شود) و pnpm، و یکی از این‌ها: افزونه zip
در PHP، یک دستور `tar` که zip بنویسد (Windows 10 به بعد، macOS) یا `zip` (Linux)؛ و برای امضا، افزونه sodium در PHP.

## انتشار

1. در فایل `docs/Releases.md`، یعنی نسخه انگلیسی [نسخه‌ها](Releases.md) که متن اصلی یادداشت‌های انتشار است،
   عنوان <code dir="ltr">## Unreleased</code> را به نام نسخه تغییر دهید (<code dir="ltr">## Version 1.2.3</code>) و
   بالای آن یک <code dir="ltr">## Unreleased</code> تازه و خالی شروع کنید. بعد ترجمه فارسی آن، `docs/fa/Releases.md`،
   را به‌روز کنید: آنچه از آخرین به‌روزرسانی صفحه فارسی به بخش این نسخه اضافه شده ترجمه کنید، عنوان
   `## در دست ساخت` آن را به `## نسخه 1.2.3` تغییر دهید، و بالای آن یک `## در دست ساخت` تازه و خالی شروع کنید.
   Workflow انتشار فقط صفحه انگلیسی را می‌خواند.
2. مقدار `Application::VERSION` را تنظیم کنید، commit کنید و `main` را push کنید.
3. صبر کنید تا CI روی همان commit، روی همه نسخه‌های PHP، موفق شود؛ فقط بعد از آن
   تگ <code dir="ltr">v&lt;version&gt;</code> را (`v1.2.3` برای `1.2.3`) روی آن push کنید. انتشار نسخه با همین تگ انجام
   می‌شود.

فایل <code dir="ltr">.github/workflows/release.yml</code> بررسی می‌کند که تگ همان `Application::VERSION` را نام ببرد،
فایل `docs/Releases.md` بخش آن نسخه را داشته باشد و Secret امضا تنظیم شده باشد (هیچ نسخه بدون امضایی هرگز منتشر
نمی‌شود)، دوباره `composer check` (روی PHP 8.2) و `pnpm check` را اجرا می‌کند، نسخه را با `scripts/release.php` بیلد و
امضا می‌کند (کلیدی که فروشگاه‌ها الان به آن اعتماد دارند از تگ آخرین نسخه گرفته می‌شود)، و نسخه را در GitHub منتشر
می‌کند: فایل zip، `release.json` و `release.json.sig`، با همان بخش [نسخه‌ها](Releases.md) به عنوان یادداشت‌هایش. بررسی
روزانه فروشگاه‌ها ظرف یک روز آن را می‌بیند؛ «بررسی دوباره» همان لحظه.

هر push به `main` و هر Pull request بررسی‌ها را روی PHP 8.2 تا 8.4 اجرا می‌کند و پنل‌ها را بیلد می‌کند
(<code dir="ltr">.github/workflows/ci.yml</code>، [تست](Testing.md#اجرا-در-ci)).

</div>
