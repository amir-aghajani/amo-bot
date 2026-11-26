[فارسی](fa/Importing.md)

# Importing from another bot

«تنظیمات پنل › انتقال از ربات دیگر», the owner's, in the main shop: a shop moving from another Telegram bot that sells
VPN services brings it over — its customers and their wallet balances, their services (still on the same VPN panels),
its plans, discount and gift codes, required channels and bank cards. The other bot's data is read once; you see what
it holds and choose what comes; then it is written into the main shop. **Nothing changes on the VPN panels or in the
other bot, and no customer is told anything.** Today AmoBot reads **Faoxima** (https://github.com/Mmd-Amir/Faoxima),
its 1.x, and **MirzaBot** (https://github.com/mahdiMGF2/mirzabot), the bot Faoxima grew out of — any version, from its
old 5.x line to 0.9.3 and later; another bot is a driver of its own ([for developers](#for-developers)).

## Before you start

- **It is the owner's, in the main shop.** The section comes after «پشتیبان‌گیری» in «تنظیمات پنل», while the main shop
  is open — with an agent's shop open it is not in the list —, and agents' panels do not have it. Everything comes into
  the main shop.
- **Stop the other bot before the backup you import for good.** What changes there after its backup — or after its
  database is read — does not come: a code redeemed, a purchase, a balance topped up or spent. A balance is credited
  once, so a later import of a newer backup does not correct it ([running it again](#running-it-again)). An import
  while the other bot still sells is a rehearsal; the last one is taken from the bot switched off.
- **The servers.** A service comes over only from a panel the shop has a connector for — for Faoxima's and MirzaBot's,
  3X-UI, PasarGuard, Marzban and Rebecca, and Faoxima's Remnawave ([the panels](#the-panels)) —, onto one of the shop's
  servers. Add the other
  bot's panels as servers first ([Servers](Servers.md)), or let the import add them from the other bot's own connection.
- **Back the shop up first** when it sells already: an import writes many rows, and a restore is the way back
  ([Backups](Backups.md)).
- **One import at a time**: one reading, in review or importing holds a new one off until it ends or is cancelled.
- **No console**: the panel does every step. Keep the page open while it reads and writes; closed, the import waits,
  and goes on when you come back.

## Starting an import

The card «انتقال تازه» asks two things. First **«ربات قبلی»** — the bot the data is of, a card each: its mark, name,
project, what comes over and its notes (today «Faoxima» and «MirzaBot»). Pick the one the data is of: Faoxima grew out
of MirzaBot and their databases look much alike, but the import tells them apart, and names the right one when the
other was picked ([when it fails](#when-it-is-refused-or-fails)). Then where its data is read from, one of two tabs.
Both say it again: «برای انتقال نهایی، اول ربات قبلی را خاموش کنید و بعد پشتیبانش را بگیرید؛ …».

### Its backup file

«فایل پشتیبان» — the way to prefer: nothing has to reach the other bot's database, which may be gone already.

- **Faoxima's own backup**: `backup.zip`, which Faoxima sends to its report channel every 5 hours by default (its admin
  menu «🕚 تنظیمات کرون جاب» sets how often). Download the latest from that channel. It holds `backup.sql` and a
  `vpnbot/` folder of the bot's files, which is ignored.
- **MirzaBot's own backup**: `backup_<date>.sql` (`backup_2026-11-14.sql`, say), which MirzaBot sends to its report
  group every 5 hours, in the topic «🤖 بکاپ ربات», captioned «📌 خروجی دیتابیس ربات اصلی». Download the latest from
  there; the zips beside it, each captioned with a reseller bot's username, are its resellers' bots' files, not its
  database. A MirzaBot before 0.1.6 (May 2026) sent `backup_<date>.zip` instead, its `.sql` behind a password its
  caption sends you to MirzaBot's support for: open it with that password and upload the `.sql` inside — the shop never
  guesses one —, or export the database.
- **An export of the bot's database** — phpMyAdmin's *Export* in the SQL format, with the tables' structure and their
  data («ساختار و داده», *Structure and data*), `mysqldump`, Adminer or the like — as a `.sql` file, or that file zipped
  (`.zip`) or gzipped (`.gz`). In a zip, `backup.sql` is read, else its largest `.sql` file. A `.tar` or `.bz2` is not
  taken.

«انتخاب فایل», then «بارگذاری و خواندن». The file is told by its bytes, never its name. It may be as large as the host
takes in one upload — the smaller of PHP's `upload_max_filesize` and `post_max_size` ([Backups](Backups.md#limits)) —,
which the tab says under its words; a larger one is refused before it is sent («حجم این فایل … است؛ هاست فایل بزرگ‌تر
از … را نمی‌پذیرد. …»): zipped, a dump is a fraction of its size. A large file takes a while to go up («فایل در حال
بارگذاری است؛ فایل بزرگ ممکن است چند دقیقه طول بکشد.»). Unpacked, it may be 1 GB at most, and keeping and unpacking it
must leave 256 MB of the host's disk free. It is kept in `storage/imports/`, which the web never reaches, until it is
read.

### Its database

«اتصال به دیتابیس» — the other bot's MySQL database, read and never written: «هاست», «پورت» (3306), «نام دیتابیس»,
«نام کاربری» and «رمز عبور», then «اتصال و خواندن». The connection is tried at once, 5 seconds at most, and a refusal is
said under the field at fault ([when it fails](#when-it-is-refused-or-fails)).

- Faoxima and MirzaBot keep them in their `config.php`: `$dbhost`, `$dbname`, `$usernamedb` and `$passworddb` (a
  Docker install of Faoxima, in its `.env`).
- On the same host, the host is usually `localhost`. A database on another host must take connections from this shop's
  host: in that host's cPanel, *Remote MySQL*, allow this shop's server. Faoxima's Docker install publishes no port for
  its database: upload its backup instead.
- Each query may take 20 seconds; a database too slow for that breaks the reading off ([when it fails](#when-it-is-refused-or-fails)).

The connection is kept encrypted with `APP_KEY` until the reading ends.

## Reading

«در حال خواندن داده‌ها»: the file (its bytes) or the database (its rows) is read into a copy of its own inside the shop
— only the tables and columns the import uses, the panels' passwords encrypted —, a step at a time: the panel asks for
the next step, some 15 seconds each, until it is through. A zipped or gzipped file is unpacked first, a step at a time
too. Nothing is written into the shop yet.

- **Leave the page, and it waits**: back on «انتقال از ربات دیگر», it goes on where it stopped. A step another tab is
  taking meanwhile is waited for.
- **A step that got no answer** — the network, the host — is tried again by itself, after a wait that doubles each time:
  «آخرین قدم انجام نشد؛ کمی بعد خودکار دوباره تلاش می‌شود.», with «تلاش دوباره» to try at once. After a few failures in a
  row, or at once for a step the shop refused, the page stops asking and says why — «انتقال متوقف ماند» —, until you
  press «تلاش دوباره».
- **«لغو انتقال»** asks first, then stops it, and the file and what was read of it are deleted.

Read whole, the data is recognised as that bot's — or the import fails, saying which of the bot's tables it did not
find, or, for the data of another bot AmoBot reads, whose it is ([when it fails](#when-it-is-refused-or-fails)) —, and
the review opens. The uploaded file, its unpacked dump and the database's connection are deleted then: only the copy
read stays, until the import ends.

## The review

Before anything is written: what the other bot holds, where its services go, and what to bring.

**«آنچه در ربات قبلی پیدا شد»** — its customers (and how many are its resellers' bots' alone), live services (and the
free tests still on their panels), the customers' wallet balances together and how many have one, plans, discount
codes, gift codes, required channels and bank cards; the bot's version too, when it can be told (neither Faoxima nor
MirzaBot keeps one).

### The panels

«پنل‌ها» lists each panel of the other bot: its name, its kind in the other bot's word (`marzban`, `x-ui_single` …), its
address and its services. A panel's services are found on one of the shop's servers by their client names, and the
plans that sold on it are sold there. For each, «سرویس‌های «…» کجا بروند»:

- **«سرور «…»»** — one of the shop's servers of the same connector. The one at the panel's own address (its scheme,
  host and port) is marked «پیشنهادی: همان آدرس پنل» and chosen to start with.
- **«افزودن به‌عنوان سرور تازه»** — the panel becomes a new server, named as the other bot named it, from the other
  bot's own connection: its address, and the API token or the admin's username and password it signed in with; the TLS
  check on, the timeout at its default, no proxy, and — for a 3X-UI panel whose links the other bot built on another
  address — that address as «آدرس اشتراک», so customers' links stay the ones they have. Its capacity is the other bot's
  sales limit for the panel, and a panel the other bot had closed to new sales comes switched off. It is checked as a
  new server is: one that does not answer is not kept, and its services stay behind (named in [the report](#the-report)).
  Offered when the shop's connector speaks to the panel and the other bot kept a way in.
- **«منتقل نشود»** — its services stay behind; its customers and their balances still come.

A panel no connector of the shop speaks to says so in amber, and its services stay behind. Faoxima's kinds become:
`marzban` Marzban (or PasarGuard, for a Marzban panel Faoxima marks as speaking PasarGuard's API), `pasarguard`
PasarGuard, `x-ui_single` 3X-UI — v3 and later: a panel Faoxima reached by its old login may be older, and the review
warns of it —, `rebecca` Rebecca, from the `rk_…` API key Faoxima kept for it, `remnawave` Remnawave, from the API
token Faoxima kept for it; `guard` and `WGDashboard` have no connector yet ([Servers](Servers.md#the-connectors)). Its «فروش دستی» panels hold configs sold by
hand, no clients: they are not listed.

MirzaBot's become: `marzban` Marzban (or PasarGuard, for a panel MirzaBot added as PasarGuard — its «نسخه پنل» 1),
`x-ui_single` 3X-UI — from the API token MirzaBot keeps: every panel of a MirzaBot later than 0.1.9 (its tables tell),
and any it added without a username, as it adds them since. An older MirzaBot reached a 3X-UI panel by the admin's
login, the way of a panel before v3, whose services do not come: the review names such a panel —, `rebecca` Rebecca,
from the `rk_…` API key MirzaBot kept for it; `marzneshin`, `hiddify`, `WGDashboard`, `ibsng`, `mikrotik`,
`mirza_agent`, `nexora` and `wg_mate` (and the `alireza_single` and `s_ui` an old MirzaBot may still have) have no
connector yet. Its «فروش دستی» panels (`Manualsale`) are not listed either.

A Rebecca panel is added as a new server with the key the old bot kept — an admin's key, whose admin should see every
user (full access or sudo) and its usage ([Servers](Servers.md#the-connectors)); one the old bot reached another way,
an older Rebecca's login say, has no key to add it with: add it in «سرورها» with a key of yours, and choose it. Its
services are found by name, each read on its own for the device limit Rebecca's list leaves out.

A Remnawave panel of Faoxima's is added as a new server with the API token Faoxima kept — Remnawave takes no admin's
password, so one without a token is not offered as a new server ([Servers](Servers.md#the-connectors)). A panel
behind an auth portal wants its gate key too, which Faoxima does not keep: add it in «سرورها» with its token and gate
key, and choose it. Its services are found by name, as written, with their squads as they are.

### What to bring

«چه چیزهایی منتقل شود», in three parts, each switch with what it would bring counted under it:

| Switch | At first | Brings |
|---|---|---|
| «موجودی کیف پول» | on | Each customer's balance onto their wallet; a debt stays a debt ([balances](#balances)) |
| «مسدودی‌ها» | on | The customers the other bot banned, banned here too |
| «شماره موبایل‌ها» | on | The mobile number a customer gave the other bot — an Iranian mobile; anything else is left out —, or that its admin excused them from giving one ([customers](#customers)) |
| «معرف‌ها» | on | Each customer under the one who brought them there; no referral gift is given |
| «تخفیف شخصی» | on | A customer's own discount percent |
| «گروه‌ها» | on | The customer groups — the other bot's agents ([groups](#groups)) — listed with their customers; one the shop lacks is made. Shown when there are any |
| «مدیرها» | off | Those who would become the bot's admins here, listed — the other bot's full admins ([admins](#admins)). Shown when there are any |
| «مشتری‌های ربات‌های نماینده» | off | The customers of the other bot's resellers' bots alone ([customers](#customers)) — without their wallets, which those bots keep —, and the services sold through those bots. Shown when there are any |
| «سرویس‌ها» | on | Every live service, as it stands on its panel |
| «اکانت‌های تست» | on | The free tests still on their panels; held off while «سرویس‌ها» is |
| «پلن‌ها» | on | The plans with their categories, on the servers their panels map to |
| «پلن‌ها روشن بیایند» | off | The plans switched on. Off, they come switched off, for you to check their prices and servers first |
| «کدهای تخفیف» | on | The discount codes with uses and days left |
| «کدهای هدیه» | on | The wallet gift codes with a use and days left |
| «کانال‌های اجباری» | off | The channels customers had to join — this shop's bot must be an admin of each |
| «کارت‌های بانکی» | on | The bank cards, into the card-to-card method, switched off for you to check |

Every customer comes whatever the switches — the first part's say what of theirs. How each thing comes:
[below](#what-comes-over-and-how).

### What stays behind, and warnings

«آنچه منتقل نمی‌شود» counts what the other bot has that never comes ([what stays behind](#what-stays-behind)), and
«پیش از شروع بدانید:» says what to know first. For Faoxima:

- customers in debt (their wallets come below zero), and admins who were its support or sellers (no admins here);
- agents' credit limits, and agencies ending on a day still to come — that day is not carried: they stay agents here
  until you change it;
- customers with a discount of their own while codes come: Faoxima refused them codes, the shop takes them;
- the advanced agents' prices of their own (a plan keeps one agent price), and services that found no plan (their
  product renamed or deleted);
- the bot's own card payments still waiting for approval (a reseller bot's credit the wallets in its files): approve
  them in the other bot, then take a fresh backup — else that money never reaches those wallets;
- customers' links that went through the other bot, panels it reached by 3X-UI's old login, panels whose certificate it
  never checked, and cards it showed to some customers only.

For MirzaBot, the same but for the credit limits, the discounts of their own and the cards — customers in debt, its
support and sellers, agencies ending on a day to come, the advanced agents' prices, the bot's own card payments waiting,
links through the bot, 3X-UI on the old login, certificates never checked —, and:

- its advanced agents, every one of whom bought on credit, below a zero balance — without a floor unless MirzaBot was
  given one —: the shop has no such buying;
- services that found no plan — their product renamed or deleted, or a service the customer built themselves;
- products MirzaBot marked «ناموجود» (sold out): their plans come switched off, whatever «پلن‌ها روشن بیایند» says;
- customers it excused from the required channels, while the channels come: here nobody is excused;
- customers it showed no card-to-card, while the cards come: here every card switched on is shown to all;
- panels it showed to one audience alone (its customers, or one agent level), or hid from some customers: here the
  plans sold on them reach everyone.

Customers with a discount of their own bring no warning for MirzaBot: it took a code and the customer's own percent on
top of it, as the shop does.

### Starting

Above «شروع انتقال», the review says once more that a final import's backup is taken with the other bot switched off (or
its database read so). «شروع انتقال» asks first («داده‌های انتخاب‌شده در این فروشگاه نوشته می‌شود.»), then saves the
choices as the screen shows them and starts. A choice refused — a server deleted meanwhile, one of another connector —
is said beside it, and nothing starts. «لغو انتقال» drops the import: nothing was written.

## Importing

«در حال انتقال»: the stages in order — «سرورها», «گروه‌های مشتری», «مشتری‌ها», «معرفی‌ها», «پلن‌ها», «سرویس‌ها», «کدهای
تخفیف», «کدهای هدیه», «کانال‌های اجباری» and «کارت‌های بانکی», each thing after what it names —, the stage under way with
how far it got. Like the reading, it goes a step at a time while the page is open, and on from where it stopped when you
come back; a step cut short — the page closed, the host's time limit — writes nothing twice. A panel that does not
answer is asked again for up to three steps before its services are reported. «لغو انتقال» stops it: what was written
stays, and the rest comes when you run the import again ([running it again](#running-it-again)).

After its last stage the import is done, and its report opens. Should the other bot's data turn out unreadable half-way,
the import fails in plain words («داده‌های ربات قبلی در این مرحله خوانده نشد …»), keeping what it wrote.

## What comes over, and how

### Customers

By their Telegram account:

- **A customer the shop has already** — they started this bot before — keeps what they have, and gains only what they
  lack: a username, a name, a mobile number, a discount of their own (while theirs is 0); a ban, or an admin's role,
  when the other bot gave it and its switch is on.
- **Anyone else** becomes a customer quietly, as though they had always been here: no newcomer report to the group, no
  referral gift, joined on the day they joined the other bot.
- **Your own Telegram accounts** — linked from «مدیریت از ربات» — never come banned nor admins, whatever the other bot
  says: the rest of them comes, and the report lists each under «فروشگاه آن را نپذیرفت».
- **One who bought in the other bot is no newcomer here**: no plan's first-purchase price
  ([Plans](Plans.md#first-purchase-price)), no code «فقط برای خرید اول», no free test while «اکانت تست فقط برای کاربران
  جدید» is on, and no referral «هدیه شروع» for a first purchase — whatever they buy here. For Faoxima, a paid purchase it
  delivered counts; for MirzaBot, any it delivered but a free test, at any price, as MirzaBot told its own newcomers.
- **One the other bot's admin excused from giving a mobile number** — Faoxima writes `confirm number by admin` in place
  of it, MirzaBot `confrim number by admin` — comes excused from the phone number here
  ([Customers](Customers.md#excused-from-the-phone-number)), with «شماره موبایل‌ها» on; a customer the shop has already
  gains the excuse too, and never loses one.
- A customer the other bot kept without a Telegram id is left out, and named in the report.
- **A customer of one of the other bot's resellers' bots** comes only with «مشتری‌های ربات‌های نماینده», and without a
  wallet: a reseller bot keeps its customers' wallets in its own files, never in the database the import reads (the
  review counts them under what stays behind). Faoxima and MirzaBot mark a customer with the reseller bot they last
  opened, and never clear it; so one is a reseller bot's customer only when nothing ties them to the bot itself — a
  balance there, an agent's level, an admin's role of any kind, a reseller bot of their own, or a purchase in the bot
  itself. Anyone else comes as the bot's own. A service sold through a reseller bot comes only with that switch,
  whoever's it is.

### Balances

Each customer's balance comes as one wallet line, «موجودی منتقل‌شده از Faoxima» (or «… از MirzaBot»): a credit, or for
a debt a line that takes the wallet below zero. **A balance is credited once**, however many times an import is run: a
later change of it in the other bot does not come. What comes is the bot's balance itself — Faoxima's own wallet
history is incomplete, and MirzaBot keeps none.

### Groups

Faoxima's and MirzaBot's agents become two customer groups, «نماینده عادی» (their level `n`) and «نماینده پیشرفته»
(`n2`), both paying the plans' agent prices («همکار», [Customers](Customers.md#groups)); a group of that name the shop
has already is used as it is. An agency whose end date has passed comes as a customer; one ending later stays an agent
here, its end not carried. A plan keeps one agent price ([plans](#plans)), and the advanced agents' buying on credit —
below zero, down to a limit in Faoxima, and in MirzaBot without one unless it was set — is not carried.

### Admins

Only with «مدیرها», and only the other bot's full admins (Faoxima's and MirzaBot's `administrator` role) become the
bot's admins here ([Customers](Customers.md#the-bots-admins)); the review lists them alone, and warns of its sellers
and support staff, who become nothing. An admin of the main bot manages the whole shop from its «⚙️ مدیریت», with your
access ([Managing the shop from the bot](Bot-Management.md#the-shops-admins)): look at who they are before you bring
them, and take the role from anyone who should not have it.

### Referrals

Once every customer is in: who brought whom, set on a customer the shop knows no referrer of yet. What the other bot
paid for it stays there: nothing is credited, and the referral's «هدیه شروع» counts as decided — neither side gets one
for a first purchase here. A referral who bought in the other bot does not count toward their referrer's «جایزه دعوت»
here ([Referrals](Referrals.md)).

### Plans

Each plan comes **switched off** unless «پلن‌ها روشن بیایند» is on — and a plan with no server stays off even then —,
in its category by name: the shop's of that name as you keep it, or a new one, switched on only once one of its plans
comes switched on. It is sold on the servers its panels map to, on every inbound marked «فروش» there
([inbounds](Servers.md#inbounds)), with its price, traffic (GB; 0 unlimited), days (0 no end), devices and note
(«یادداشت پیش‌فاکتور»). A plan switched off still renews the services it sold ([Plans](Plans.md)).

Faoxima and MirzaBot price their agents in products of their own: one only agents saw, of the same traffic, days and
panels as a customers' product, becomes that plan's agent price — «نماینده عادی»'s over «نماینده پیشرفته»'s —, and the
services bought under it that plan's. An agents' product with no such twin comes as a plan of its own, always switched
off, and named in the report. A plan none of whose panels came over comes without a server, named in the report: give
it one before switching it on.

A MirzaBot product is sold on one panel, or on every one but those it was hidden from, and its plan on the servers
those map to. One MirzaBot marked «ناموجود» (sold out) comes switched off, even with «پلن‌ها روشن بیایند» on — and
still renews what it sold.

### Services

Each live service — for Faoxima, one not removed, no stock or hand-sold config, and no test whose time is up; for
MirzaBot, one not removed or gone from its panel, no hand-sold config, and none it marked `disabled` (a test whose time
was up, its client taken off the panel) — on a panel chosen in the review is found on its server by its client's name,
and **taken as it stands on the panel**: its subscription link, the traffic it used, its end and its switch. **Nothing
on the panel changes**, not even its comment. Then:

- with the plan it was bought from, when that plan came — found by its product's name among the products sold on its
  panel (a name may repeat, a product for each panel), or, for a product renamed or deleted since, by its traffic, days
  and panel when exactly one plan fits: renewed from it as any other;
- without one, as a [service added by its link](Service-Claims.md#renewing-one) is: its first renewal gives it a plan
  (the review counts such services). A service a MirzaBot customer built themselves («⚙️ سرویس دلخواه») always comes
  so: MirzaBot renewed it at its own prices, never a plan's;
- a free test stays a test, and the customer's own name for it comes as «نام دلخواه»;
- one that had ended by time or traffic comes ended, to renew, and its automatic removal counts from the import
  ([Services](Services.md#what-runs-by-itself)).

The link is the panel's own, or the server's «آدرس اشتراک» in front of its token. Where the other bot gave its customers
links through its own address (Faoxima's and MirzaBot's «لینک ساب اختصاصی»), those stop with it: the review warns of
it, and customers find the new link in this bot.

A service is left out, and named in the report, when its client is not on its panel, the panel gives no link for it,
its customer did not come, or the shop keeps that client already for someone else (one this customer has is linked to
it). **A panel that did not answer** for three steps leaves its services for later: run the import again once it
answers.

### Discount codes

Each code with uses and days left, its cap the uses it has left, with its limit for one customer, «فقط برای خرید
اول», its end and its note; codes are kept in capitals ([Discounts](Discounts.md#discount-codes)). A code of a name
the shop has already is left as it is, and named in the report. One the shop's codes cannot hold as it was comes
**switched off**, a note saying why: one only for agents, only for regular customers or for some of the bot's
audiences, or for one customer; one only for wallet top-ups (the shop's codes are not taken for them); one the other
bot could never take; and one whose plans or panels all stayed behind — so it never holds for more customers, or more
plans, than it did. Its limit for one customer counts afresh here: the history of its uses stays behind.

MirzaBot's codes are a percent off a purchase, a renewal or both — the two it asked for a code at —, of one product or
every one, on one panel or every one, each for everyone or for one audience (its customers, or one agent level). One
whose audience, kind of order, product or panel it does not know — or that names none, as a column an old MirzaBot
added later leaves it, which MirzaBot never matched — it could never take: it comes switched off. It held every code
to its uses and to its uses for each customer: one whose uses are spent, or that allowed none (or none for each
customer), it never took either — counted as used up, it does not come.

### Gift codes

The shop's gift codes are spent once, by whoever has them ([Gift codes](Gift-Codes-And-Lucky-Wheel.md#gift-codes)). A
wallet code with **one use left**, switched on and not past its day comes as one of them, with its amount and last day,
under the batch «منتقل‌شده از Faoxima» (or «… از MirzaBot»). One with more uses left (or no limit), or one the other
bot kept for one customer, is named in the report — with its amount and the uses it had left, or whose it was — for you
to give by hand. MirzaBot's have no last day, and always a limit: one with no use left is used up, never unlimited.
A code is kept as the shop keeps its own: in capitals, its hyphens dropped (`GIFT-WELCOME` comes as `GIFTWELCOME`) —
the shop drops them from what customers type too, so the code they have still redeems. It must then be Latin letters
and digits, 32 at most, and its amount within the wallet's bounds: any other is refused.

### Required channels

Only with «کانال‌های اجباری»: each channel is added as «تنظیمات ربات» adds one ([Bot settings](Bot-Settings.md#channels))
— by the chat the other bot checked membership in; Telegram is asked which chat it is, and the bot must be an admin
there. One Telegram refuses is named in the report in its words: add it by hand once the bot is an admin. One the shop
requires already is left as it is.

### Bank cards

Into the shop's card-to-card method — its first, or one made for them switched off, «کارت به کارت» —, **each card
switched off**, even in a method that is on: a card the other bot showed may be one nobody checks any more, or one it
showed to a few customers alone (as Faoxima could; the review names those). Check each, then switch it on
([Payment methods](Payment-Methods.md#card-to-card)). A number that is no valid card's — not 16 digits, or failing the
card-number check — is named in the report; a card the shop shows already is left as it is.

### Things deleted since

Codes, gift codes, cards and channels are known by their code, number or link; servers by the panel's address, whatever
the other bot called it. Whatever an import brought is brought once: a customer, a service, a plan, a group, a code, a
card or a channel you deleted from the shop since stays deleted — a later import counts it as «بعد از انتقال قبلی از
فروشگاه حذف شده بود» and brings nothing of it, a deleted customer's balance and services included. The one exception is a server:
choose «افزودن به‌عنوان سرور تازه» for its panel again and it is added again.

## What stays behind

- The payment history, the support tickets, the history of the wallets (Faoxima's), of the services' renewals and
  extras and of the codes' uses — a code's limit for one customer counts afresh here.
- The other bot's resellers' bots — and, unless chosen, their customers —, and those customers' wallets, which the
  resellers' bots keep in their own files, not in the database.
- Stock and hand-sold configs, and services on panels the other bot no longer has.
- Services on panels AmoBot has no connector for — Faoxima's Guard and WGDashboard panels, MirzaBot's Marzneshin,
  Hiddify, WGDashboard, IBSng, MikroTik and the rest —: their customers and balances still come.
- The panels' own prices of extra traffic and time and of built services, and the products' options no plan keeps — a
  device limit of their own, one purchase each, only for customers who never bought (MirzaBot's), traffic reset on a
  schedule.
- The bot's settings and texts.

Keep a copy of the other bot's database for any of it.

## The report

When an import ends — done, failed or cancelled — its report opens: «انتقال تمام شد», «انتقال انجام نشد» or «انتقال
لغو شد», from what and when, and who started it. A failure says why, and whether anything was written before it.

- **What each kind came to** — customers, wallet balances, customer groups, servers, categories, plans, services,
  discount codes, gift codes, channels and cards —: «تازه» (made), «از قبل بود» (found in the shop — the shop's own, or
  brought by an earlier import — and left as it is) and «نیامد» (left out).
- **«آنچه نیامد»** — the reasons with how many each, then the things themselves, 50 a page, narrowed to a reason with
  «دلیل»: which thing (the other bot's own key for it) and what finds it — a client's name and its server, a code, a
  card, the panel's or Telegram's words. A reason that is your own choice («انتخاب نشده بود», «پنلش منتقل نشد», «از
  ربات‌های نماینده؛ انتخاب نشده بود», and «بعد از انتقال قبلی از فروشگاه حذف شده بود؛ دوباره آورده نشد» for what you deleted
  since) or nothing to act on («استفاده‌هایش تمام شده بود», «مهلتش گذشته بود», «تمام شده بود») is counted, with no list.

| The report says | Why, and what to do |
|---|---|
| «روی پنلش پیدا نشد» | Its client is not on the panel: deleted there, or on another panel |
| «پنلش جواب نداد» | The panel did not answer for three steps, or refused (why, in the detail — on a non-sudo Marzban admin's server, another admin's users are refused with what to do): run the import again once it answers |
| «پنل لینک اشتراکی برایش نمی‌دهد» | The panel gave no subscription link for the client |
| «این سرویس از قبل برای مشتری دیگری در فروشگاه هست» | The shop keeps that client already, for another customer — its service's number in the detail |
| «مشتری‌اش منتقل نشده بود» | Its customer did not come (no Telegram id, say) |
| «پنلش به‌عنوان سرور اضافه نشد» | Adding the panel was refused, or it did not answer (why, in the detail); its services stayed behind. Add it in «سرورها» — a self-signed certificate with «بررسی گواهی TLS» off —, then run the import again choosing it |
| «هیچ‌کدام از پنل‌هایش منتقل نشد؛ پلن بدون سرور آمد» | The plan came without a server, switched off: give it one |
| «فقط برای نماینده‌ها بود؛ خاموش آمد» | A plan only the other bot's agents saw, switched off |
| «کدی با همین نام از قبل در فروشگاه هست» | A discount or gift code of that name is the shop's already |
| «پلن‌ها یا پنل‌هایی که برایشان بود منتقل نشد؛ خاموش آمد» | A discount code switched off: none of its plans, panels or kinds of order came |
| «کد هدیه چندبارمصرف است و کدهای هدیه اینجا یک‌بارمصرف‌اند» | Give it by hand — the uses it had left are in the detail |
| «فقط برای یک مشتری بود و کدهای هدیه اینجا مال هر کسی است که کد را دارد» | Give it to that customer by hand — whom, in the detail |
| «کانال اضافه نشد» | Telegram's words in the detail: make the bot an admin of the channel, then add it in «تنظیمات ربات» |
| «شماره کارت معتبر نیست» | Not 16 digits, or failing the card-number check: add the right number by hand |
| «شناسه تلگرام معتبر ندارد» | A customer the other bot kept without a Telegram id |
| «فروشگاه آن را نپذیرفت» | The shop's own form refused it — a plan's, a category's, a group's, a code's, a card's —: its words in the detail |
| «خوانده نشد» | The other bot's row could not be read: a service without a client name, a code without its code |
| «نوشتنش ناموفق بود؛ جزئیات در لاگ برنامه است» | Something the shop did not expect: the app's log has it ([Running in production](Running-In-Production.md#health-and-logs)) |

«حذف گزارش» deletes the report alone: what came stays, and a later import still knows it. «انتقال تازه» goes back to
the start, where «انتقال‌های قبلی» lists the imports that ended — when, from which bot, how — each with its «گزارش».

## Running it again

An import can be run again — from a newer backup, or the same — and brings only what is new: whatever an earlier import
brought is known by the other bot's own key for it, and not brought twice — nor brought back once you deleted it
([things deleted since](#things-deleted-since)). So, while the other bot still sells, import once to look the shop over,
then stop the other bot, take its last backup and import again. A balance is credited once, and a thing that came is
not updated (a customer's later balance, a plan's new price, a code's uses since); a balance that did not come the
first time, its switch off, comes once it is on, and so does what was learnt of a customer since — that they bought
there. Likewise an agent who came while «گروه‌ها» was off joins their group once it is on — once: one you take out of
it stays out. A customer who merged two accounts of theirs on the website since is the account that stayed: their
balance, services and referrals come to it. The services of a panel that did not answer, or of one added since, come
with the next run.

**Keep the other bot's last backup file** — on your computer, not only in its report channel. What stays behind today
(services on a panel AmoBot cannot reach yet, say) can come with a later version of AmoBot, but only from that file: the
import deletes its own copy of the old bot's data, and the panels' passwords in it, once it ends. Run it again from that
file then, and only what is new comes.

## After the import

1. **Mark the inbounds** «فروش» on each server the import added ([inbounds](Servers.md#inbounds)): a new server's
   inbounds come not for sale, and its plans sell on the marked ones. A server of a panel the other bot had closed to
   sales comes switched off: switch it on when it should sell.
2. **Check the plans** — prices, servers, categories — and switch them on («فروشگاه › پلن‌ها»), giving a server to the
   ones that came without.
3. **Check the bank cards** the import brought, and switch on those customers should see — and the card-to-card method
   itself, when the import made it («فروشگاه › روش‌های پرداخت»).
4. **Go through «آنچه نیامد»** in the report: the codes and channels to add by hand, the services to look for.
5. **Keep the customers' chat**: once the other bot is off, give AmoBot the same bot's token
   («تنظیمات پنل › ربات تلگرام») and press «ثبت Webhook» — customers find this shop where the other bot was, and the
   other bot gets nothing more. Another bot is a new chat: tell the customers of it.

The sync reads every service from its panel within a quarter of an hour («همگام‌سازی سرویس‌ها»,
[the task schedule](Running-In-Production.md#the-task-schedule)).

## The import's own data

The uploaded file, its unpacked dump and the database's connection are deleted once the reading ends; the copy read and
the panels' connections — the other bot's passwords among them — as the import ends, done, failed or cancelled. An
import left open and untouched for 7 days is cancelled, and deleted with them, by «پاک‌سازی انتقال‌ها», a daily task of
«زمان‌بندی کارها» ([the task schedule](Running-In-Production.md#the-task-schedule)), which also deletes the files left in
`storage/imports/` by an import a restore took away. The report stays until you delete it, and what the imports brought
is remembered for good. The whole installation's backup holds the reports and what imports brought, never the copy of
the other bot's data; an import still open comes in it as it is stored — its panels' connections and the database it
reads among it, encrypted ([Backups](Backups.md#what-a-backup-holds)).

An owner whose host has a shell may also work an import's steps from it: `php bin/console import:run <id>`
([Console commands](Console-Commands.md)) — never needed.

## When it is refused or fails

| The page says | Why |
|---|---|
| «فایل پشتیبان ربات قبلی را انتخاب کنید.» | No file came with the request: choose it again |
| «حجم این فایل … است؛ هاست فایل بزرگ‌تر از … را نمی‌پذیرد. …» | Larger than the host takes in one upload: the page did not send it. Zip it, or raise the host's PHP limits ([Backups](Backups.md#limits)) |
| «فایل بزرگ‌تر از چیزی است که هاست می‌پذیرد (…)؛ …» | The same, said by the host where PHP does not tell its limit |
| «این فایل، پشتیبان دیتابیس نیست؛ …» | No SQL dump, nor a zip or a gzip of one |
| «در این فایل zip هیچ فایل .sql پیدا نشد.» | A zip without a `.sql` in it |
| «این فایل zip رمز دارد؛ …» | Its `.sql` is behind a password — a backup of a MirzaBot before 0.1.6, say: open it with its password and upload the `.sql` inside, or export the database |
| «افزونه zip روی این هاست فعال نیست؛ …» | The host's PHP cannot open a zip: upload the `.sql` inside it, or a `.gz` of it |
| «این فایل فشرده باز که شود از … بزرگ‌تر است؛ …» | It unpacks to more than 1 GB — far more than a bot's database |
| «فضای خالی هاست برای نگه داشتن و باز کردن این فایل کافی نیست؛ …» | Keeping and unpacking it would leave less than 256 MB of the host's disk free |
| «این فایل ناقص است یا خراب شده و تا آخر خوانده نشد؛ …» | Cut short or damaged — an upload broken off, a dump that ends half-way through a statement, a row of more than 16 MB: take the backup again |
| «این فایل فقط داده‌های جدول‌ها را دارد، بدون ساختارشان (…)؛ …» | An export of the data alone: export again with the tables' structure, or upload the bot's own backup |
| «این داده‌ها مال … نیست؛ جدول‌هایی که این ربات دارد پیدا نشد (…).» | Not that bot's database, nor another's AmoBot reads, or not the whole of it: the tables looked for are named |
| «این داده‌های … است، نه …؛ …» | Another bot's data, one AmoBot reads too — Faoxima's with MirzaBot picked, say, or MirzaBot's with Faoxima: the import fails; start again with «انتقال تازه», picking the bot it names |
| «این داده‌ها مال … نیست.» | The bot's tables, with the marks of a relative of it AmoBot does not read — another fork of MirzaBot, say |
| «آدرس سرور دیتابیس ربات قبلی را وارد کنید.» · «آدرس سرور دیتابیس درست نیست؛ …» | «هاست» empty, or not a host's name or IP alone |
| «پورت باید عددی بین 1 تا 65535 باشد.» | — |
| «نام دیتابیس ربات قبلی را وارد کنید.» · «نام کاربری دیتابیس را وارد کنید.» | — |
| «افزونه pdo_mysql روی این هاست فعال نیست …» | The host's PHP cannot speak to MySQL: upload a backup instead |
| «به سرور دیتابیس وصل نشد؛ …» | The address or port is wrong, or the database's server is out of reach or refuses this host (*Remote MySQL*) |
| «نام کاربری یا رمز دیتابیس پذیرفته نشد.» | — |
| «این کاربر به این دیتابیس دسترسی ندارد.» | The user has no rights on that database |
| «دیتابیسی با این نام روی این سرور نیست.» | — |
| «این دیتابیس هیچ جدولی ندارد.» | — |
| «خواندن دیتابیس ربات قبلی نیمه‌کاره ماند (…)؛ …» | The connection broke off while it was read, or a query took more than 20 seconds: start again |
| «ربات قبلی را از فهرست انتخاب کنید.» | No bot of the list was chosen |
| «یک انتقال دیگر در جریان است؛ اول آن را تمام یا لغو کنید.» | Another import is open — another tab's, say: the page shows it |
| «این انتقال در این مرحله نیست.» | It moved on meanwhile — started or cancelled in another tab: the page reads it again |
| «آنچه این انتقال از آن خوانده می‌شد دیگر در دسترس نیست؛ …» | The uploaded file is gone before it was read: cancel the import and start again |
| «داده‌های خوانده‌شده ربات قبلی دیگر در دسترس نیست؛ انتقال را از نو شروع کنید.» | The import's copy of the other bot's data is gone — a restore of the whole installation's backup empties it |
| «داده‌های ربات قبلی در این مرحله خوانده نشد و انتقال نمی‌تواند ادامه پیدا کند؛ …» | The other bot's data is not as AmoBot expects it: the app's log has why ([Running in production](Running-In-Production.md#health-and-logs)) |
| «این نسخه دیگر انتقال از این ربات را ندارد.» | This version of AmoBot no longer reads that bot |
| «برای سرویس‌های این پنل انتخاب کنید: …» | A panel with services and no choice |
| «این پنل را نمی‌شود به‌عنوان سرور تازه اضافه کرد؛ …» | The other bot kept no way into it: choose a server, or leave it |
| «سرور انتخاب‌شده دیگر نیست؛ …» | The server chosen was deleted meanwhile |
| «این سرور با کانکتور دیگری کار می‌کند؛ …» | A server of another kind of panel |

## Watch out for

- **Customers are told nothing.** Tell them yourself — the new bot, if it is one, and that their services go on.
- **Stop the other bot first.** What customers do there after the backup you import — a top-up, a code redeemed, a new
  service — comes only with an import of a newer backup, and a balance that came never changes again.
- **A Marzban panel wants a sudo admin**: another admin sees only the users it made, and the import reports the rest
  ([Servers](Servers.md#the-connectors)).
- **A panel with a self-signed certificate** is not added by the import, which checks the certificate: add it in
  «سرورها» with «بررسی گواهی TLS» off, and choose it in the review.
- **Keep a copy of the other bot's database**: its history stays behind.

## For developers

A bot AmoBot reads is a migrator, a driver of a family of its own: it reads the other bot's tables and translates them
into the import's records, and the core writes everything ([Drivers](Drivers.md#a-migrator)). How an import is read,
reviewed and written: [Architecture](Architecture.md#imports).
