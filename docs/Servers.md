# Servers

«سرورها», the owner's alone: the VPN panels the shop sells on — 3X-UI v3 or PasarGuard 3.1+. Every shop sells on the same
servers: an agent's plans are built on them too, and a server's services are every shop's. An agent's panel has no
servers page.

## The list

Every server, in the order it was added: its name — a link to [its page](#a-servers-page) —, its connector and panel
address, and in amber why it cannot sell, when it cannot; then «وضعیت», «اینباندها» («۳ از ۵ برای فروش»), «اشتراک‌های
فعال» — every shop's, with « / 200» when it has a capacity — and «آخرین بررسی».

| «وضعیت» | Means |
|---|---|
| «غیرفعال» | You switched it off — whatever else is true of it |
| «خطا در اتصال» | The last contact with its panel failed; the ⓘ beside it says why |
| «متصل» | The last contact worked |
| «بررسی نشده» | Never contacted |

## Adding a server

«افزودن سرور» asks which software runs the panel — «3X-UI» or «PasarGuard», each with its notes and a link to its
project —, then its form:

| Field | What it takes |
|---|---|
| «نام سرور» | 100 characters at most. **Customers see it**: it is the server's «لوکیشن» in the bot, on the shop's website and its status page — name it as a customer should read it («آلمان ۱») |
| «آدرس پنل» | The panel's own address, 255 characters at most, http or https: 3X-UI's with its web base path (`https://host:2053/AbCdEf`), without the `/panel` or `/login` of its pages after it; PasarGuard's dashboard address without `/dashboard` (`https://panel.example.com:8000`), no `/api` either. No `user:pass@`, `?` or `#` |
| «احراز هویت» | «توکن API» (3X-UI) or «کلید API» (PasarGuard) — recommended —, or «نام کاربری و رمز» |
| «توکن API» / «کلید API» | 3X-UI's from *Settings → Security → API Token*, an **Admin** token (a Monitor or Node one only reads the status). PasarGuard's from *API Keys* (version 5.1 and later): the whole `pg_key_…` key, which the panel shows once, with full rights over users and reading groups and the system |
| «نام کاربری» / «رمز عبور» | The panel admin you sign in with (PasarGuard: an admin made in the dashboard — the one of its env file signs in only in debug mode) |
| «کلید TOTP» | 3X-UI only, when its two-factor login is on: the Base32 secret it showed as you turned it on. The shop makes each sign-in's code from it |

Under «تنظیمات پیشرفته»:

| Field | What it takes |
|---|---|
| «بررسی گواهی TLS» | On. Off for a panel with a self-signed certificate |
| «تایم‌اوت اتصال (ثانیه)» | 5 to 120, 30 by default: how long a call to the panel may take (connecting gives up after 10 seconds at most) |
| «آدرس اشتراک» | Blank: the subscription links are the panel's own. Set, the full prefix before the link's token, when the links must come from another address (a reverse proxy): `https://sub.example.com/sub` |
| «اتصال از طریق پراکسی SOCKS5» | Off: the shop talks to the panel directly. On, every request the shop makes to this panel goes through a SOCKS5 proxy ([below](#a-panel-behind-a-socks5-proxy)), and these appear: |
| «آدرس پراکسی» | The proxy's host name or IP address, without `socks5://` and without a port: `proxy.example.com`, `203.0.113.10` |
| «پورت پراکسی» | 1 to 65535, 1080 by default |
| «نام کاربری پراکسی» / «رمز پراکسی» | Blank for a proxy without a login. A password only with a username |
| «ظرفیت» | How many **active** services it may hold — every shop's together, free tests too. Blank is unlimited; **`0` is full** |
| «یادداشت» | Your own note, 1000 characters at most; shown nowhere but this form |
| «فعال» | Off, nothing new is sold on it or moved to it |
| «رنگ دکمه در ربات» | Its button among a plan's locations in the bot: blue, green, red, or «پیش‌فرض تلگرام». Every shop's bot shows it so |
| «جای دکمه در ربات» | A row of its own, or two or three to a row: locations of the same width that follow each other in a plan's order share rows |

**«تست اتصال»** tries the form as it is and stores nothing: whether the panel answers and accepts the credentials, its
status (the core, CPU, RAM, disk), its inbounds, and whether it serves subscription links. It needs the whole form
filled in right, the name too. A failure says what to fix in plain words — the domain, the port or firewall, a timeout,
the certificate, the token or password refused, a redirect to follow, a wrong address — and the panel's own answer under
it.

**«افزودن سرور»** saves it, then checks it at once — tested or not — and opens its page with what the check found. **It
is saved even when that check fails**: fix the connection on its page. The check lists its inbounds, **all of them not
for sale** until you mark them ([inbounds](#inbounds)).

**The secrets** — the token or key, the password, the TOTP secret, the proxy's password — are kept encrypted and never
shown again: a kept one shows as dots (a token with its last four characters). Left blank on an edit, it stays;
«پاک کردن مقدار ذخیره‌شده» removes one the connection can do without — one it needs (a panel's token or password) has
no such button: type the new one. **A secret stays only while the address keeps its origin** — the same http or
https, host and port (a path may change): moved, it is never sent to the new address, not even by «تست اتصال», and the
form asks for it again («آدرس پنل عوض شده است؛ … را دوباره وارد کنید.»). The proxy's password likewise stays only
while the proxy's address and port do. Saving another way in removes the other way's credentials.

### A panel behind a SOCKS5 proxy

Some hosts cannot reach a panel at all — a shared host abroad whose network the panel's country filters, a panel that
answers only from inside its own network. Switch on «اتصال از طریق پراکسی SOCKS5» under «تنظیمات پیشرفته» and give a
SOCKS5 proxy the shop's host can reach and that can reach the panel (one on the panel's own server, or a server in its
network, is the usual choice):

- **Only this panel goes through it.** Telegram, mail, the captcha, sign-in with Google, the update check and every
  other server talk to the internet directly, as before; each server has a proxy of its own, or none.
- **The proxy looks the panel's name up** (SOCKS5 with remote DNS), so a name only the proxy's network resolves works.
  «آدرس پنل» stays the panel's own address, as the proxy sees it.
- **«تست اتصال», «بررسی اتصال» and every sale, renewal, sync, gift, move and claimed link** go through it alike: try
  it with «تست اتصال» before saving.
- **A failure at the proxy is said as the proxy's**: «اتصال از طریق پراکسی SOCKS5 سرور برقرار نشد؛ …» — its address
  or port wrong, the proxy down, its username or password refused, or the proxy unable to reach the panel —, cURL's
  words under it, never the proxy's password. The panel is then left alone for 10 minutes like any panel out of reach
  ([Health](#health)).
- Switched off, the proxy's address, port, username and password are forgotten.

The server's page shows «پراکسی SOCKS5» — the proxy's address and username — among its connection's facts.

## Whether it can sell

A server is offered to customers — in the bot, on the website, as a move's target — only when nothing of these stands
against it; the list and its page say the first that does:

1. «کانکتور این سرور در این نصب وجود ندارد.» — its software's connector is not in this installation.
2. «سرور غیرفعال است.»
3. «سرور هنوز بررسی نشده است؛ …» — no check has learned yet whether it serves subscription links (a new server whose
   first check could not connect says this too).
4. «سرور لینک اشتراک نمی‌دهد؛ …» — every service gets one subscription link and nothing else, so a panel without its
   subscription service cannot sell. Turn it on in the panel, then «بررسی اتصال».
5. «ظرفیت سرور پر است.»

A plan sold on a server that cannot sell is simply not offered there; one with nowhere left to sell leaves the bot
([Plans](Plans.md)).

## A server's page

The header's **«بررسی اتصال»** checks the saved server and keeps what it learned: its health, whether it serves
subscription links, its inbounds. Press it after changing anything on the panel or in the server's settings. A server
whose connector this installation no longer has is not checked: «کانکتور این سرور در این نصب وجود ندارد.»

- **«وضعیت»** — when it was last checked, its status, and why it cannot sell, if it cannot. The panel's CPU, RAM, disk
  and uptime are shown only for a check made on this page now (or just after adding it): nothing of them is kept. Then
  how the shop signs in, where the links come from («از تنظیمات پنل», or your prefix), the SOCKS5 proxy when the panel
  is reached through one, the TLS check, the timeout, and
  its active services: every shop's — what its capacity counts —, and, when agents' customers have services here too,
  how many of them are the open shop's (a link to them on «اشتراک‌ها», which lists the open shop's alone).
- **«اینباندها»** — [below](#inbounds).
- **«اکانت تست»** — the free tests customers may take on it ([below](#test-accounts)).
- **«تنظیمات اتصال»** — the form again, with «تست اتصال», «بازگردانی تغییرات» and «ذخیره تغییرات» (both held until a
  field differs from what is saved). Every field but the
  connector changes here (another kind of panel is another server). **Saving does not check it again**: press «بررسی
  اتصال» after. The switch «فعال» is here, under «تنظیمات پیشرفته».
- **«افزودن زمان و حجم»** — days and traffic for its services ([below](#adding-days-and-traffic)).
- **«انتقال سرویس‌ها»** — every shop's services on it moved to another server, to empty it or to rescue them from a
  panel that is gone ([below](#moving-its-services)).
- **«حذف سرور»** — [below](#deleting-a-server).

### Inbounds

The panel's inbounds as the shop last read them — PasarGuard's groups play their part —, each with its protocol, port,
clients and the «فروش» switch. A plan sold on the whole server uses every inbound marked «فروش» and enabled on the
panel, at the moment of each purchase; a plan that pins its inbounds uses those whatever the switch says
([Plans](Plans.md)); a service moved here, on a plan without this server, gets the inbounds marked. Every service is
one client on the panel, attached to its inbounds.

The shop reads them only on «به‌روزرسانی از پنل», «بررسی اتصال» and the check of a new server — never by itself. New
ones come not for sale. One the panel no longer lists is switched off and kept with your choice, for when it comes back;
it cannot be switched on meanwhile, nor can an inbound disabled on the panel.

### Test accounts

The card «اکانت تست» sets what a free test on this server gets, in every shop — the main bot's and each agent's —, and
is saved on its own:

- «اکانت تست روی این سرور» — off at first; on, customers may take a test here while their shop gives tests (its hint
  links to «اکانت تست › تنظیمات», where a shop switches tests on);
- «حجم (مگابایت)» — 1 to 102400, 500 by default;
- «مدت (ساعت)» — 1 to 720, 24 by default;
- «شروع از اولین اتصال» — on at first: the hours count from the customer's first connection; off, from the moment the
  test is made.

A test is one client — on a panel with inbounds, on every inbound marked «فروش» —, made only while the server can sell.
It takes room in the server's «ظرفیت» as any active service. A change holds for the tests made from then on. Agents
see these terms on their tests page and cannot change them. The shop's own rules — the switch, the quota, the
clean-up — are on «فروشگاه › اکانت تست» ([Test accounts](Test-Accounts.md)).

### Adding days and traffic

«افزودن زمان و حجم» › «افزودن به سرویس‌ها» gives the services on this server days and traffic — an outage made good, a
gift:

- «تعداد روز» (0 to 365) — onto each service's end, or a longer term for one not started yet; «حجم (گیگابایت)» (0 to
  10000) — on top of its quota. At least one above zero.
- «دلیل» — optional, 300 characters at most: the customers read it under the gift («توضیح پشتیبانی: …»).
- «سرویس‌های در انتظار اولین اتصال هم شامل شوند» — off: an outage cost a service whose clock has not started nothing.
- «پیام به مشتری‌ها» — on: each customer is told by their shop's bot, or by email when Telegram cannot reach them, and
  the notice is kept on their website.

**Who gets it**: the services of every shop that were on this server when you gave it — none bought afterwards, and no
free test — and running. Running is the panel's word, asked at each service's turn: one that ended by time or traffic,
or was switched off — by support or on the panel — is passed by; days go only to a service that ends some day, traffic
only to one with a quota. The dialog counts whom it reaches from the shop's copy now; the card's progress says how many
were checked, given, passed by and failed.

**How it runs**: one service at a time, by the card while the page is open, else by the scheduler every minute. One
gift runs on a server at a time — a [mass gift](Broadcasts.md#the-mass-gift)'s part on it too. A panel out of reach
holds the gift at that service, with an amber note saying why, and it goes on once the panel answers; a service the
panel refused, or whose update timed out or got an error page from a proxy in front of the panel, is counted failed
and not tried again (the panel may have taken it).
**«لغو»** stops it there («لغوشده») — what was given stays given. The card lists the latest ten.

### Moving its services

«انتقال سرویس‌ها» › «انتقال سرویس‌ها» moves the services on this server — every shop's, the agents' customers' too — to
another server in one go: to empty a server you are retiring, or to rescue your customers when its panel is gone. One
service at a time, so it is the batch of [moving services](Services.md#moving-services) without the tab kept open.

- «سرور مقصد» — any other server that can sell now; one that cannot shows why (switched off, full, no subscription links,
  never checked).
- «روش»:
  - **«مهاجرت»** (migrate) — this server's panel answers. Each client is read there, made on the target with what it has
    left, then deleted here.
  - **«نجات»** (rescue) — the panel is gone. Each service is made on the target from what the shop last read of it (the
    15-minute sync), and this panel is never asked. Its old clients stay on it — they would work again if it came back —,
    so the shop keeps a list of them and removes them once the panel answers ([below](#the-clients-a-rescue-left)). The
    dialog starts on it when the server's last contact failed.
- «سرویس‌های غیرفعال‌شده هم منتقل شوند» — off: the services support switched off stay here. On, they move switched off,
  and stay so until support switches them on.
- «پیام به مشتری‌ها» — on: each customer is sent the new link.
- «فقط سرویس‌های این پلن‌ها» — shown when the services here are of more than one plan; none ticked moves them all. A
  service with no plan (a free test, one added by its link) moves only when none is ticked.

The dialog counts, from the shop's copy, how many services it would move and of which shops, and warns when the target's
«ظرفیت» has room for fewer.

**What moves**: the services that were on this server when you pressed «شروع انتقال» — active ones, running or waiting for
their first connection, free tests too —, still here when their turn comes. Ended services stay (nothing is left to carry;
the shop's [automatic removal](Services.md#what-runs-by-itself) takes them). One found ended at its turn — its panel says
its time or traffic ran out — is passed by («رد شد»). One bought here after you pressed it stays: to empty the server,
switch «فعال» off first, so nothing new is sold on it. An agent's customers' services go to the target even when you keep
that server from the agent's shop: that list keeps the shop's sales, free tests and single moves off it, not this.

**What each service keeps, and what changes**: it keeps its customer, plan, end date (or its term still waiting for the
first connection), device limit, a renewal queued behind its period, its orders and «تمدید خودکار». **Its link changes** —
it is a new client on another panel —, and its traffic starts again from zero on a quota of what was left: 30 GB with 12
used becomes 18 GB with none used. In a rescue, what was left is what the shop last read, so traffic used since the last
sync is not counted.

**What customers see**: «🔄 سرویس شما به سرور دیگری منتقل شد» with the new link (and its QR card, when the bot sends one),
in their own shop's bot — an agent's customer from the agent's bot —, as a single move tells it ([Bot texts
reference](Bot-Texts-Reference.md)). Without «پیام به مشتری‌ها» nobody is told; the service's screen shows the new link
either way. The main bot's report group hears in «خطاها» when a move starts and when it ends, with its numbers.

**How it runs**: as [adding days and traffic](#adding-days-and-traffic) does — by the card while the page is open, else by
the scheduler every minute. The card shows how far it got: services checked, moved, passed by and failed, and the
clients it left on a panel that are still there («کلاینت روی پنل مانده است»; [below](#the-clients-a-rescue-left)).

- **A panel out of reach** — the target's, or this one's in a migration — holds the move at that service with an amber
  note saying why, and it goes on once the panel answers. A migration waiting for this server's panel can go on as a
  rescue: «ادامه با حالت نجات».
- **A panel that stops answering half-way through a service** is asked again before anything is undone: an old client
  it deleted without saying so leaves the service moved, and a client the target made without saying so is kept — a
  customer is never left without a service, nor given two. When the panel cannot be asked either, the client it may
  still have is listed with [the clients left behind](#the-clients-a-rescue-left) and removed once it answers.
- **A service the target refused** is counted failed and the move goes on; «سرویس‌های ناموفق» lists each with why. Move
  those one at a time from «اشتراک‌ها» once the cause is fixed.
- **A target that can take no more** — full, switched off, no subscription links — ends the move as «ناتمام», saying
  why; the rest stay here. Raise its capacity or pick another target, and start a new move for them.
- **A service another change is working on** (a renewal, a support action) is come back to a moment later.
- «توقف موقت» and «ادامه» pause and resume it; «لغو» ends it — what moved stays moved, the rest stay here. A service
  under way when you pause it, or switch to «ادامه با حالت نجات», finishes or is come back to: none is passed by.

One move from a server at a time, and a server that is receiving one cannot send its own meanwhile. The card lists the
latest ten.

#### The clients a rescue left

The card says how many clients moves left on this server's panel — a rescue's old ones, those of services moved one at a
time with «انتقال بدون حذف از سرور قبلی» ([Services](Services.md#moving-services)), and any a panel did not say it
deleted or made, or would not take back. The scheduler removes them by itself once the panel answers again — tried each
time the [ten minutes](#health) of leaving it alone are over —, so a customer never has two working services.
«حذف همین حالا» tries at once, even while the panel is left alone; «فراموش کردن» drops the list, for a panel that is
gone for good — if it ever came back, those clients would work. A client by a name a service of any shop has on this server again (moved back here,
say) is not touched, and one whose service is being changed at that moment waits for the next try.

### Deleting a server

«حذف سرور» is refused while **any** service of any shop is on it, in whatever state — ended, switched off, gone from
the panel: move them or delete them from «اشتراک‌ها» first ([Services](Services.md)) — an agent's customers' from that
agent's shop —, or switch the server off instead (or [move them all](#moving-its-services)). It is refused too while a
move of services from or to it is under way or paused («سرویس‌های این سرور در حال انتقال به سرور «…» است؛ …»): let it
finish, or cancel it. Deleting removes its inbounds, its place on every plan, its gifts' history and its moves' — with the
list of clients left on its panel; those its moves left on another server's panel stay listed there —; orders keep their
record. The panel itself is not touched.

## Health

«آخرین بررسی» moves with every check, and with every refresh of the inbounds and 15-minute sync of the services that
worked. Everything else that talks to the panel — a sale, a renewal, a gift, a customer's screen — changes the server's
status only when the panel stops answering (it cannot be reached, refuses the credentials, or is not the API) and when
it answers again.

A panel whose last contact failed is left alone for **10 minutes**: the sync, gifts, renewal periods and automatic
renewals wait for it (nothing is charged meanwhile), and a customer's own service screen shows the last numbers — the
bot says they may be old; the website's refresh says the panel did not answer. Then it is tried again. Your own
buttons, sales and deliveries do not wait.

The main shop's dashboard counts the servers whose last contact failed («سرورهای دارای خطا»), and the main bot's
report group hears it in «خطاها»: «🔴 پنل سرور … جواب نمی‌دهد» when a panel that answered stops, «🟢 … دوباره جواب
می‌دهد» when it answers again ([Report group](Telegram-Group.md)).

## The two connectors

**3X-UI** (v3 and later, MHSanaei's):

- The **subscription service must be on** in the panel's settings: the shop builds each link the way the panel does
  (its sub domain, port and path), or from «آدرس اشتراک».
- A service's term starts at its first connection; the plan's devices are its IP limit; the comment names the
  customer's Telegram id.
- «تغییر لینک» gives the client new credentials and a new subscription id.

**PasarGuard** (3.1 and later):

- **Its groups are the inbounds**: a plan sells groups, and a group disabled on the panel cannot be sold.
- Every answer carries a freshly signed link, so a service's kept link changes with each sync — every one keeps working.
- It has no IP limit: **a plan's device count is not applied** here.
- Its status shows CPU, RAM, disk and uptime of the panel's host; the cores run on its nodes, so their state reads
  «نامشخص».
- «تغییر لینک» revokes the link: every earlier one stops working.

Another panel is a driver ([Drivers](Drivers.md#a-vpn-panel-connector)).

## From a shell

`php bin/console panel:probe 3x-ui <address> -t <token>` (or `-u`/`-p`, `--totp` for 3X-UI's two-factor login, `-k` for
a self-signed certificate, `--proxy socks5h://[user:password@]host[:port]` through a SOCKS5 proxy; `pasarguard` for the
other) tries an address and its credentials as «تست اتصال» does, stores nothing, and prints the status, the inbounds
and the subscription server ([Console commands](Console-Commands.md)).

## Watch out for

- **A server whose panel is down still sells**: its purchases are paid, then wait under «سفارش‌ها» › «نیازمند رسیدگی»
  for a retry. For a long outage, switch the server off («تنظیمات اتصال» › «تنظیمات پیشرفته» › «فعال»).
- **After changing the panel, press «بررسی اتصال»**: the subscription service turned off on 3X-UI is noticed only by a
  check — until then sales are offered and fail («… لینک اشتراک نساخت …») —, and inbounds added or deleted there are
  unknown until a check or «به‌روزرسانی از پنل».
- **Two buttons**: «تست اتصال» tries what the form holds and keeps nothing; «بررسی اتصال» checks the saved server and
  keeps what it learns.
- **Secrets are trimmed**: a panel password that starts or ends with a space will not sign in.
