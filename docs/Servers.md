[فارسی](fa/Servers.md)

# Servers

«سرورها», the owner's alone: the VPN panels the shop sells on — 3X-UI v3, PasarGuard 3.1+, HMPanel 2.5+, Marzban 0.8+,
Rebecca 1.0+ or Remnawave 2.1+.
Every shop sells on the same servers: an agent's plans are built on them too, and a server's services are every shop's.
An agent's panel has no servers page.

## The list

Every server, in the order it was added: its name — a link to [its page](#a-servers-page) —, its connector and panel
address, and in amber why it cannot sell, when it cannot; then «وضعیت», «اینباندها» («۳ از ۵ برای فروش»), «اشتراک‌های
فعال» — every shop's, with « / 200» when it has a capacity — and «آخرین بررسی».

| «وضعیت» | Means |
|---|---|
| «غیرفعال» | You switched it off — whatever else is true of it |
| «خطا در اتصال» | The last contact with its panel failed; the ⓘ beside it says why: what to fix, and under it, on a line of its own, the technical detail (the panel's own words, or cURL's) |
| «متصل» | The last contact worked |
| «بررسی نشده» | Never contacted |

## Adding a server

«افزودن سرور» asks which software runs the panel — «3X-UI», «PasarGuard», «HMPanel (HMray)», «Marzban», «Rebecca» or
«Remnawave», each with its notes and a link to its project —, then its form:

| Field | What it takes |
|---|---|
| «نام سرور» | 100 characters at most. **Customers see it**: it is the server's «لوکیشن» in the bot, on the shop's website and its status page — name it as a customer should read it («آلمان ۱») |
| «ایموجی پرمیوم نام» | Optional: a premium emoji from the bot's list ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md#premium-emoji)). Picked, its plain emoji becomes the name's first, and the bot draws that one premium: the location's button gets it as its icon — in place of the «🌍» its wording starts with —, and the texts that name the location (`%server%`) show it in its place. The website, the status page and the panels show the plain one. While it is chosen the name must start with an emoji |
| «آدرس پنل» | The panel's own address, 255 characters at most, http or https: 3X-UI's with its web base path (`https://host:2053/AbCdEf`), without the `/panel` or `/login` of its pages after it; PasarGuard's dashboard address without `/dashboard` (`https://panel.example.com:8000`), no `/api` either; HMPanel's the address its sign-in page opens at (`https://panel.example.com`), without `/api`, `/dashboard`, `/clients` or any page of it; Marzban's dashboard address without `/dashboard` (`https://panel.example.com:8000`), no `/api` or `/docs` either; Rebecca's likewise; Remnawave's the https address of its reverse proxy, the domain its dashboard opens at (`https://panel.example.com`), without `/dashboard`, `/auth` or `/api` — its own port over plain http only on the shop's own host or its private network ([Remnawave](#the-connectors)). No `user:pass@`, `?` or `#` |
| «احراز هویت» | «توکن API» (3X-UI, Remnawave) or «کلید API» (PasarGuard, HMPanel, Rebecca) — recommended —, or «نام کاربری و رمز». HMPanel, Rebecca and Remnawave offer their key or token alone: their APIs take no admin's password. Marzban offers «نام کاربری و رمز» alone: its API has no keys |
| «توکن API» / «کلید API» | 3X-UI's from *Settings → Security → API Token*, an **Admin** token (a Monitor or Node one only reads the status). PasarGuard's from *API Keys* (version 5.1 and later): the whole `pg_key_…` key, which the panel shows once, made with *Inherit admin permissions* — or with the users' permissions (create, view, edit, delete, reset usage, revoke) and viewing groups (their simple list is enough) and the system —, best without an expiry or a request cap. HMPanel's from «تنظیمات ← API» (*Settings → API*): the whole `hmp_…` key, which the panel shows once, made with the scope `*` — or with `clients.read`, `clients.write`, `clients.renew` and `inbounds.read`. Rebecca's from «حساب من ← کلیدهای API» (*My Account → API keys*): the whole `rk_…` key, which the panel shows once — a full access or sudo admin's ([Rebecca](#the-connectors)). Remnawave's from *Remnawave Settings → API Tokens*: the whole token — `eyJ…`, three parts joined by dots —, which the panel shows once, made with the scopes `users:*`, `internal-squads:read` and `system:read` (`*` works too, but opens the whole panel) |
| «نام کاربری» / «رمز عبور» | The panel admin you sign in with (PasarGuard: an admin made in the dashboard who sees every user — the one of its env file signs in only in debug mode ([PasarGuard](#the-connectors)); Marzban: a sudo admin, who sees every user — another sees only the users it made) |
| «کلید TOTP» | 3X-UI only, when its two-factor login is on: the Base32 secret it showed as you turned it on. The shop makes each sign-in's code from it |

Under «تنظیمات پیشرفته»:

| Field | What it takes |
|---|---|
| «کلید دروازه» | Remnawave only, for a panel behind an auth portal (Caddy's or TinyAuth): the portal's API key — TinyAuth's whole `Basic …` value —, sent with every call. Blank for a panel without one ([Remnawave](#the-connectors)) |
| «بررسی گواهی TLS» | On. Off for a panel with a self-signed certificate |
| «تایم‌اوت اتصال (ثانیه)» | 5 to 120, 30 by default: how long a call to the panel may take (connecting gives up after 10 seconds at most) |
| «آدرس اشتراک» | Blank: the subscription links are the panel's own. Set, the full prefix before the link's token, when the links must come from another address (a reverse proxy): `https://sub.example.com/sub` (HMPanel's links: `https://sub.example.com/s`; Remnawave's, the prefix before the user's short id: `https://sub.example.com`) |
| «اتصال از طریق پراکسی SOCKS5» | Off: the shop talks to the panel directly. On, every request the shop makes to this panel goes through a SOCKS5 proxy ([below](#a-panel-behind-a-socks5-proxy)), and these appear: |
| «آدرس پراکسی» | The proxy's host name or IP address, without `socks5://` and without a port: `proxy.example.com`, `203.0.113.10` (an IP typed in Persian digits is read as the same IP) |
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

**The secrets** — the token or key, the password, the TOTP secret, Remnawave's gate key, the proxy's password — are kept
encrypted and never shown again: a kept one shows as dots — a token of 12 characters or more with its last four; a
password, the TOTP secret, the gate key and the proxy's password with none. Left blank on an edit, it stays; «پاک کردن مقدار ذخیره‌شده» removes one the
connection can do without — one it needs (a panel's token or password) has no such button: type the new one. **A secret
stays only while the way to the panel stays**: the address's origin — the same http or https, host and port (a path
may change) —, the proxy's address and port, and «بررسی گواهی TLS». Change one, and the kept token, password or gate key
is never sent the new way, not even by «تست اتصال»: the form asks for it again («آدرس پنل، پراکسی یا بررسی گواهی TLS عوض شده
است؛ … را دوباره وارد کنید.»). Switching the proxy off keeps it — it then goes straight to the panel it was given for.
The proxy's password likewise stays only while the proxy's address and port do. Saving another way in removes the
other way's credentials.

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

- **«وضعیت»** — when it was last checked, its status, why its last contact failed, if it did — what to fix, and under
  it, on a line of its own, the technical detail (the panel's own words, or cURL's) —, and why it cannot sell, if it
  cannot. A check made on this page shows its own result in the failure's place. The panel's CPU, RAM, disk
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
  اتصال» after. The switch «فعال» is here, under «تنظیمات پیشرفته». When the server is saved elsewhere meanwhile
  (another tab), what you typed stays and the form says so; «دیدن نسخه تازه» shows the saved one instead.
- **«افزودن زمان و حجم»** — days and traffic for its services ([below](#adding-days-and-traffic)).
- **«انتقال سرویس‌ها»** — every shop's services on it moved to another server, to empty it or to rescue them from a
  panel that is gone ([below](#moving-its-services)).
- **«حذف سرور»** — [below](#deleting-a-server).

### Inbounds

The panel's inbounds as the shop last read them — PasarGuard's groups play their part; HMPanel's are the inbounds of
the 3X-UI panels beneath it, each named after its panel («Germany · DE»); Marzban's are those of its Xray config, each
by its tag; Rebecca's are its services, a plan picking one; Remnawave's are its internal squads, a client in several at
once —, each with its protocol, port, clients and the «فروش» switch. A plan sold on the whole server uses every inbound marked «فروش» and enabled on the
panel, at the moment of each purchase; a plan that pins its inbounds uses those whatever the switch says
([Plans](Plans.md)); a service moved here, on a plan without this server, gets the inbounds marked. Every service is
one client on the panel, attached to its inbounds.

The shop reads them only on «به‌روزرسانی از پنل», «بررسی اتصال» and the check of a new server — never by itself. New
ones come not for sale. One the panel no longer lists is switched off and kept with your choice, for when it comes back;
it cannot be switched on meanwhile, nor can an inbound disabled on the panel. A very long remark or tag is kept cut
short (255 characters of a remark, 128 of a tag).

### Test accounts

The card «اکانت تست» sets what a free test on this server gets, in every shop — the main bot's and each agent's —, and
is saved on its own: whether the server gives tests at all («اکانت تست روی این سرور», off at first), a test's traffic
and hours, and whether the hours count from the customer's first connection. Each field is described on
[Test accounts](Test-Accounts.md#what-a-test-gets-each-servers-terms).

A test is one client — on a panel with inbounds, on every inbound marked «فروش» —, made only while the server can sell.
It takes room in the server's «ظرفیت» as any active service. A change holds for the tests made from then on. Agents
see these terms on their tests page and cannot change them. The shop's own rules — the switch, the quota, the
clean-up — are on «فروشگاه › اکانت تست» ([Test accounts](Test-Accounts.md#the-shops-rules)).

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
only to one with a quota. A service the days would end after [the last day](Services.md#watch-out-for) the shop can keep
is passed by whole, its traffic too. The dialog counts whom it reaches from the shop's copy now; the card's progress says how many
were checked, given, passed by and failed.

**How it runs**: one service at a time, by the card while the page is open, else by the scheduler every minute by default. One
gift runs on a server at a time — a [mass gift](Broadcasts.md#the-mass-gift)'s part on it too.

- **A panel out of reach** holds the gift at that service, with an amber note saying why, and it goes on once the panel
  answers.
- **An update that got no clear answer** — it timed out, the connection broke, or a proxy in front of the panel sent an
  error page — has the panel asked what it now holds. When the panel has the gift, the service counts as given (and its
  customer is told). Otherwise, or when the panel cannot be asked, it is counted failed and not tried again: the panel
  may still have taken it, and a service is never given twice.
- **A service the panel refused** is counted failed.
- **«لغو»** stops it there («لغوشده») — what was given stays given.

The card lists the latest ten.

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
- «سرویس‌های غیرفعال‌شده هم منتقل شوند» — off: the services support switched off stay here. On, they move too: each is
  made switched off on the target, and stays so until support switches it on.
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
the scheduler every minute by default. The card shows how far it got: services checked, moved, passed by and failed, and the
clients it left on a panel that are still there («کلاینت روی پنل مانده است»; [below](#the-clients-a-rescue-left)).

- **A panel out of reach** — the target's, or this one's in a migration — holds the move at that service with an amber
  note saying why, and it goes on once the panel answers. When it is this server's panel that does not answer, the card
  offers «ادامه با حالت نجات»: the migration goes on as a rescue. A target out of reach holds a rescue too, so the
  button is not offered then.
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

The main shop's dashboard counts the switched-on servers whose last contact failed («سرورهای دارای خطا»; one you
switched off is not counted), and the main bot's
report group hears it in «خطاها»: «🔴 پنل سرور «…» جواب نمی‌دهد», with the reason, when a panel that answered stops,
and «🟢 پنل سرور «…» دوباره جواب می‌دهد» when it answers again ([Report group](Telegram-Group.md)).

## The connectors

**3X-UI** (v3 and later, MHSanaei's):

- The **subscription service must be on** in the panel's settings: the shop builds each link the way the panel does
  (its sub domain, port and path — a path of just `/` too), or from «آدرس اشتراک».
- A service's term starts at its first connection; the plan's devices are its IP limit; the comment names the
  customer's Telegram id.
- «تغییر لینک» gives the client new credentials and a new subscription id.
- Signed in with «نام کاربری و رمز», the shop signs in again by itself when the panel's session has expired — whether
  it was reading or changing something.

**PasarGuard** (3.1 and later):

- **Its groups are the inbounds**: a plan sells groups, and a group disabled on the panel cannot be sold.
- Every answer carries a freshly signed link, so a service's kept link changes with each sync — every one keeps working.
- It has no IP limit: **a plan's device count is not applied** here.
- Its status shows CPU, RAM, disk and uptime of the panel's host; the cores run on its nodes, so their state reads
  «نامشخص».
- «تغییر لینک» revokes the link: every earlier one stops working.
- **Give a key or an admin that sees every user.** An admin limited to its own users — before 5.0 one that is not sudo;
  from 5.0 one whose role reads only its own users, as the built-in *operator* does — sees only the users it made:
  another admin's user is refused (before 5.0, with a hint to sign in as a sudo admin) or not found (5.0 and later), a
  service moved on the panel to another admin is taken for deleted at the next sync, and an
  [import](Importing.md) finds no other admin's services. A name another admin's user holds is taken all the same: a
  sale gives way to the next name — also when PasarGuard 5 refuses a name as taken but will not show its user. The
  card's notes say so.
- **A role's rules are named**: a refusal for a permission the admin's role lacks says what it needs — the users'
  permissions (create, view, edit, delete, reset usage, revoke), viewing groups and the system, as the *administrator*
  role has —, and so do the refusals of an admin out of traffic, a role that makes users from templates only (*Require
  template*), a role's limits on traffic, term or user count, a role that demands an on-hold timeout or a HWID limit of
  every user (the shop sends neither), and a group the panel or the role does not have. Such a refusal fails the sale
  at once — a permission the admin lacks is no taken name. An admin out of traffic whose role blocks all its access
  («Admin is limited — all access blocked») leaves the whole server out of use until it is given traffic: recorded on
  the server, reported as not answering, and left alone a while like a panel out of reach ([Health](#health)); one
  whose role blocks only its writes is refused call by call.
- **An admin whose role may read only the groups' simple list** — the built-in *operator* from 5.0 — gets them by id
  and name alone: a group switched off on the panel cannot be seen there and shows switched on. Remove a group you
  switch off on the panel from this server's plans too, or a service on it is made without configs. The card's notes
  say so.
- **An API key** (5.1 and later) acts as its admin. One refused — unknown, expired, revoked, out of its *Max Requests*,
  or of an admin switched off — says so, with what to do: give a key without an expiry or a request cap. One without
  the right says to make it with *Inherit admin permissions*, or with the permissions above (the groups' simple list is
  enough). Signed in with a username and password instead, the panel reports every sign-in of the shop's to its
  Telegram or Discord admins (when its admin-login notice is on), a failed one with the password that was sent; a
  key's calls are not reported.
- **The panel's own clean-up of ended users** — 5.6's *Cleanup → Automatic retention → Expired accounts* in its
  settings, `USERS_AUTODELETE_DAYS` before — would delete the shop's ended services before the shop's renewal window
  and its own removal of ended services. The users the shop makes are exempt from it. Those made by an earlier AmoBot
  cannot be changed so — PasarGuard sets a user's own days only as it is made —: on a panel with that clean-up on,
  turn its rule for expired accounts off (*Keep forever*), or before 5.6 unset `USERS_AUTODELETE_DAYS` or make it
  negative, to keep them.
- **The quota is once a term**: every update sets the user's traffic reset to none, so a user taken over from the
  panel with a reset of its own (each day, week or month) no longer gets its quota back every period.
- **A term from the first connection cannot be cleared** from a service through PasarGuard's API, and PasarGuard 5.1
  and later puts any switched-off service still carrying one on hold when it is switched on. When the shop switches on or updates
  a service it knows is not waiting — one with a fixed end, or with none —, it puts it back active, so it never starts
  its old term over its end at its next connection; a switched-off service with no end that still carries a duration is read as waiting, as the
  panel reads it.
- Before 5.1 the panel's own traffic reset makes a service waiting for its first connection active; the update the
  shop sends right after a reset puts its waiting term back. A *next plan* set on the panel survives an update from
  PasarGuard 5.5; earlier versions drop it.
- The comment, naming the customer's Telegram id, is the user's note, cut to PasarGuard's 500 characters. A link a
  customer claims is checked by asking the panel's own subscription route whose it is — a route of several folders too
  ([Services added by their link](Service-Claims.md)).

**HMPanel** («HMPanel (HMray)» in «افزودن سرور», 2.5 and later) — a panel that runs several 3X-UI panels beneath it:

- **Its key is the one way in**: made in its «تنظیمات ← API» (*Settings → API*, or `/api-keys`), shown once, with the
  scope `*` — or `clients.read`, `clients.write`, `clients.renew` and `inbounds.read`. The key acts as the HMPanel admin
  it was made for: a reseller's reaches only that reseller's clients, and a disabled or expired admin takes its keys
  with it. «تست اتصال» reads the key and names a scope it lacks.
- **Only the inbounds of its 3X-UI panels are offered**, each named after its panel; a PasarGuard or Eylan panel
  beneath it goes another way inside HMPanel. A client lives on **one** panel: a plan on this server — or the inbounds
  marked «فروش», for a plan sold on the whole server — must keep to one panel's inbounds, or its sales are refused.
  To sell two of its panels as two locations, add the HMPanel twice, each server selling one panel's inbounds.
- A service's term starts at its first connection and the plan's devices are its IP limit — 3X-UI's, as HMPanel passes
  them on —; the comment names the customer's Telegram id. The numbers are HMPanel's copy of 3X-UI's, as fresh as its
  own sync.
- The link is HMPanel's own (`https://panel.example.com/s/…`), or «آدرس اشتراک» in front of the client's token
  (`https://sub.example.com/s`).
- There is no «تغییر لینک» (its key API renews no credentials), and no status card of CPU, RAM and disk: the key API
  reports none.
- **A key answers 60 calls a minute** unless it was made with more; past that HMPanel refuses every call until the
  minute is over, and the shop leaves the server alone a few minutes («خطا در اتصال», with what to do). The shop keeps
  its calls few — the sync lists 200 clients a call —, but a busy shop wants a key with a higher limit.
- **An error of HMPanel's own** (an answer of 500 or more) to a change counts as no clear answer: HMPanel may have made
  the change before it failed, so the shop asks the panel before doing it again — a service is never made twice, under
  another name.

**Marzban** (0.8 and later, Gozargah's):

- **An admin's username and password, the one way in**: Marzban's API has no keys. The shop trades them for a token —
  a day long by default — and keeps it across requests, encrypted, for this server, its address, its way there and
  that login, until shortly before it ends (10 minutes, or a tenth of its life when that is shorter): it signs in again
  only then, or when the panel refuses the token (a password changed, say). Marzban tells its Telegram admins of every
  sign-in: to hear none of the shop's, put the shop's host's IP in `LOGIN_NOTIFY_WHITE_LIST`, in Marzban's settings, as
  the card's note says. A failed sign-in is reported all the same, with the password that was sent; `NOTIFY_LOGIN=false`
  silences every report.
- **Give a sudo admin**, one made in the panel's database rather than the `SUDO_USERNAME` of its `.env` file: the users
  that one makes belong to no admin, which Marzban 0.8.2 to 0.8.4 fails to delete cleanly (below).
  Another admin sees only the users it made: its server is never read whole — the sync, and an [import](Importing.md),
  ask for its services one at a time —, and another admin's user is refused with a hint to sign in as a sudo admin. A
  name another admin's user holds is taken all the same: a sale gives way to the next name.
- **Its inbounds** are those of the panel's Xray config («Core Settings» in its dashboard), each by its tag, with its
  protocol, port, transport and security; none of them is switched off on the panel. A service is made on its plan's
  inbounds, with a proxy of each protocol they speak (the panel makes their ids and passwords). Marzban keeps a user's
  inbounds as the inbounds of its protocols it is left out of: an inbound added to the panel later, of a protocol a
  service has, reaches that service too. An inbound the panel no longer has is refused before anything is sent, saying
  to read the inbounds again; one the panel refuses — an inbound gone, a protocol disabled on it — says to look at
  «Core Settings».
- A service's term starts at its first connection (Marzban's *on hold*) or ends on its day; one made switched off is
  made, then switched off — taken back when the panel refuses that. The comment, naming the customer's Telegram id, is
  the user's note (500 characters at most). Every change reads the user first and keeps a *next plan* set on the panel.
  A service switched back on is judged by the panel at once — limited or expired when its traffic or time is over —,
  never given a few seconds' access first.
- **A term replaced leaves no waiting behind**: Marzban's API cannot clear a service's on-hold duration (0 means "no
  change"), and the panel reads a switched-off service with no end that still carries one as waiting. So an update
  that replaces a waiting term overwrites the duration with one Marzban reads as none — a service once waiting, later
  given unlimited time, then switched off and on, no longer comes back on hold with its old term.
- **The quota is once a term**: every update sets the user's traffic reset to none, so a user adopted from the panel
  with a reset of its own (each day, week or month) no longer gets its quota back every period.
- **The panel's own clean-up of ended users** (`USERS_AUTODELETE_DAYS`) would delete the shop's ended services before
  the shop's renewal window and its own removal of ended services. The users the shop makes are exempt from it. Those
  made by an earlier AmoBot cannot be changed so — Marzban's API sets that only as a user is made —: on a panel with
  `USERS_AUTODELETE_DAYS` set, unset it or make it negative to keep them.
- **Deleting a service whose user belongs to no admin** — made by the `.env` file's `SUDO_USERNAME`, or whose admin was
  removed — on Marzban 0.8.2 to 0.8.4: Marzban deletes it from its database, then fails (an error 500, its issue
  #1634) before taking it off Xray, where the customer's config would go on working until the core restarts. The shop
  switches such a user off first, which takes it off Xray, and counts a failure after which the user is gone as the
  delete done — a refund, a move or a removal goes through.
- A traffic reset leaves a switched-off service off and one waiting for its first connection on hold — Marzban's own
  reset would switch both on: in 0.8.x it misreads a status and makes a switched-off user active (fixed in its
  unreleased development line), and in every version it makes one on hold active with no end.
- **It has no IP limit**: a plan's device count is not applied, and no «کاربر اضافه» is sold on it.
- A client's name is 3 to 32 English letters, digits and `_` ([Bot settings](Bot-Settings.md#service-usernames)).
- «تغییر لینک» revokes the link (Marzban's *revoke_sub*): new proxy credentials, and every earlier link stops working.
- The link is the user's subscription link — on the panel's own address when Marzban gives only its path —, or «آدرس
  اشتراک» in front of its token. Every answer carries a freshly signed link, so a service's kept link changes with each
  sync — every one keeps working until the link is revoked. A link a customer claims is checked by asking the panel's
  own subscription route whose it is — a route of several folders too ([Services added by their link](Service-Claims.md)).
- Its status shows the panel host's CPU and RAM and Xray's state and version — no disk or uptime. A user counts as
  online when Marzban saw it in the last minute.
- The sync reads a sudo admin's users 500 at a time; a panel of more than 50,000 is asked service by service instead.

**Rebecca** (1.0 and later, rebeccapanel's — Marzban's descendant, rewritten in Go):

- **Its API key is the one way in**: an admin's personal key, made in its dashboard under «حساب من ← کلیدهای API» (*My
  Account → API keys*, a tab an admin allowed «مدیریت کلیدهای API خود» has; one who manages admins makes another's
  under «کلیدهای API» on the admins page), the whole `rk_…` key, which the panel shows once. Rebecca asks bots in so:
  no admin's password is typed here. The key acts as its admin, with that admin's role, permissions and services.
- **Give a full access or sudo admin's key.** Those see every user of the panel. A standard admin — or a reseller —
  sees only the users it made: its server is never read whole — the sync, and an [import](Importing.md), ask for its
  services one at a time —, and another admin's user is refused with a hint to give a sudo or full access admin's key.
  A name another admin's user holds is taken all the same: a sale gives way to the next name. An admin who is not full
  access needs these permissions for its users: «ساخت کاربر», «حذف کاربر», «ریست مصرف», «لغو دسترسی» (a link changed), «ساخت کاربر در حالت تعلیق» (a term from the first connection), «اجازه حجم نامحدود»,
  «اجازه تاریخ انقضا نامحدود» and «مدیریت محدودیت IP کاربران»; a refusal for want of one says so, with what to give.
- **Its services are the inbounds**: a Rebecca user belongs to exactly one service and takes that service's hosts, so
  «اینباندها» lists the services the key's admin may put users in, each by its name. **A plan on this server picks one
  service** — and a plan sold on the whole server, a free test and a service a customer builds take every service
  marked «فروش», so the shop lets one be marked at a time: marking a second asks you to take the mark off the first —:
  a sale naming two is refused before anything is sent. A service without an active host takes no user: it shows switched off and cannot be sold. To sell
  two services as two locations, add the panel twice, each server selling one.
- A service's term starts at its first connection (Rebecca's *on hold*) or ends on its day; one made switched off is
  made, then switched off — taken back when the panel refuses that. Every change reads the user first: it keeps the
  user's service, link and the *next plans* set on the panel, a term replaced is replaced whole — a waiting one is
  cleared with a deadline —, and the traffic reset is set to none, so the quota is once a term. The comment, naming
  the customer's Telegram id, is the user's note (500 characters at most).
- **The plan's devices are its IP limit**, and «کاربر اضافه» is sold on it.
- «تغییر لینک» revokes the link (Rebecca's *revoke_sub*): a new key for the user, so new configs and a new link, and
  every earlier one stops working. A traffic reset puts a user Rebecca had limited for its traffic back on, and leaves
  any other as it was. A deleted service's name is free on the panel again.
- A client's name is 3 to 32 English letters, digits and `_ . @ -` ([Bot settings](Bot-Settings.md#service-usernames)).
- The link is the user's subscription link — on the panel's own address when Rebecca gives only its path —, or
  «آدرس اشتراک» in front of its token. A link signed afresh in an answer changes the service's kept link at a sync —
  every one keeps working until the link is revoked.
- **A link a customer claims** ([Services added by their link](Service-Claims.md)) is a subscription link — its token or
  key shown to the panel's own subscription route, a route of several folders too —, or a **VLESS or VMess** config,
  whose id is the user's key. A Trojan or Shadowsocks config is found by no one: its password is a hash of the key that
  nothing on the panel looks up — such a customer sends the subscription link. (A user made before Rebecca changed its
  configs' ids, in its early versions, may be found by its subscription link alone.)
- **Usage hidden**: an admin whose traffic mode is «حجم ساخته‌شده» with «نمایش ترافیک کاربران» off sees every user's
  usage as zero — so would the shop, which could then never end a service by its traffic. Give the key of an admin who
  sees its users' usage.
- Its status shows the panel host's CPU, RAM, disk and uptime, and Xray's state, version and last error. A user counts
  as online when Rebecca saw it in its last 20 seconds.
- The sync reads a full access or sudo admin's users 500 at a time; a panel of more than 50,000 is asked service by
  service instead.

**Remnawave** (2.1 and later, 3.x included — a panel of Xray nodes, its users reaching them through squads):

- **Its API token is the one way in**: made in its *Remnawave Settings → API Tokens*, the whole token — `eyJ…`, three
  parts joined by dots —, which the panel shows once, with the scopes `users:*`, `internal-squads:read` and
  `system:read` (the scope `*` works too, but opens the whole panel). Remnawave takes no admin's password for API calls, nor an admin's own sign-in token: given in its
  place, one is refused, saying to make an API token. Every token has a lifetime: make a new one before it ends, and
  put it here. A refused token says it is wrong, of another panel or past its time; one without a scope a call needs,
  or deleted on the panel, says which scopes to give.
- **Only through an HTTPS reverse proxy**: Remnawave drops — with no answer — every request that did not come through
  one. Give the https address of the panel's domain, where its dashboard opens. The panel's own port over plain http
  works only when the panel is on the shop's own host or its private network — `localhost`, a loopback, private or
  Tailscale-style (`100.64.0.0/10`) IP address, a host name of one word, as a Docker service's, or one under `.lan`,
  `.local`, `.internal` or `.home.arpa` —: the shop then adds what such a proxy would. Across
  the internet it adds nothing, so Remnawave drops the request: the token and the customers' data would travel
  unencrypted. A connection cut off with no answer — or a failed TLS handshake on an https address that names a port
  other than 443, the panel's plain port (a certificate refused is the certificate's) — is said as nothing having
  answered, with that rule and what to give; a proxy in front
  of the panel that got no answer says the panel is down, or the proxy does not send `X-Forwarded-For` and
  `X-Forwarded-Proto`.
- **A panel behind an auth portal** — Caddy's or TinyAuth, as Remnawave's own security guides set up — lets the shop's
  calls through only with the portal's key: give it as «کلید دروازه» under «تنظیمات پیشرفته» (the portal's API key, or
  TinyAuth's whole `Basic …` value); the shop sends it with every call. «آدرس پنل» stays the panel's own address,
  without the portal's secret sign-in path. A portal that turns the shop away — its page, or a redirect, in place of
  the API — says to give the gate key.
- **Its internal squads are the inbounds**: «اینباندها» lists them by name, each with its inbounds' tags and its
  members. A squad with no inbound gives a user nothing: it shows switched off and cannot be sold. A user may be in
  several squads, so a plan on this server may sell several together. An update leaves a user's squads as they are.
- **No term from the first connection**: Remnawave has none, so a service's time runs from the sale — a term counted
  from the first connection, a plan's or a free test's, starts as the service is made, and the shop keeps the end the
  panel then has.
- A service is made switched on or off in one step. One switched off stays off whatever its time and traffic. One
  renewed, or updated while on, runs again when its new term has time and traffic left; otherwise the panel keeps it
  ended. One switched back on runs until the panel's next review — every 30 seconds for time, 45 for traffic — ends it
  again if its time or traffic is over. A term cut to end now ends a minute or so later: Remnawave takes no end that is
  not in the future. A renewal whose answer never came (a timeout) is asked about on the panel, which shows a term from
  the first connection as the deadline it started — or a deadline gone by as the soonest it takes —: the shop knows it
  there, and delivers it once, never twice.
- **The quota is once a term**: a user is made and updated with no traffic reset.
- The comment, naming the customer's Telegram id, is the user's description, and the Telegram id also goes in
  Remnawave's own field for it (Remnawave sends nothing to users through it); a service handed to another customer
  clears the previous owner's id there.
- **A plan's devices are its HWID device limit** (0 for none), and «کاربر اضافه» is sold on it. Remnawave holds it
  only while «HWID Device Limit» is on in its subscription settings (off by default), and counts only the apps that send
  a device id when they fetch the link (Happ, v2RayTun and a few more); while it is on, an app that sends none gets no
  subscription. With it off, the device count is the shop's word alone.
- A client's name is 3 to 36 English letters, digits, `_` and `-`, matched as it is written
  ([Bot settings](Bot-Settings.md#service-usernames)).
- «تغییر لینک» revokes the subscription: a new short id — so a new link — and new credentials; every link and config
  handed out before stops working. A traffic reset puts a user Remnawave had limited for its traffic back on, and leaves
  any other as it was. A deleted service is deleted for good: its name is free on the panel again.
- The link is the user's subscription link — Remnawave's subscription domain and the user's short id —, or «آدرس
  اشتراک» in front of the short id.
- **A link a customer claims** ([Services added by their link](Service-Claims.md)) is a subscription link — up to four
  of its parts asked of the panel as a short id —, or a VLESS, Trojan or Shadowsocks config, matched against every
  user's credentials (a VLESS id even when a host's route replaced its third group). A short id looked up is masked in
  the log and in any error that would quote it.
- Its status shows the panel host's RAM and uptime: Remnawave reports no CPU load or disk («—»), and its Xray cores run
  on its nodes, so their state reads «نامشخص». A user counts as online when Remnawave saw its traffic in the last 30
  seconds.
- The sync reads the users 1,000 at a time; a panel of more than 50,000 is asked service by service instead.
- **An error of Remnawave's own** (an answer of 500 or more) to a change counts as no clear answer: the panel may have
  made the change before it failed, so the shop asks the panel before doing it again. An end the panel refuses as past
  says to check that the shop's host and the panel's server keep the same time.

Another panel is a driver ([Drivers](Drivers.md#a-vpn-panel-connector)). For developers, `panel:probe` tries a panel
from a console as «تست اتصال» does ([Console commands](Console-Commands.md)); a shop never needs it.

## Watch out for

- **A server whose panel is down still sells**: its purchases are paid, then wait under «سفارش‌ها» › «نیازمند رسیدگی»
  for a retry. For a long outage, switch the server off («تنظیمات اتصال» › «تنظیمات پیشرفته» › «فعال»).
- **After changing the panel, press «بررسی اتصال»**: the subscription service turned off on 3X-UI is noticed only by a
  check — until then sales are offered and fail («… لینک اشتراک نساخت …») —, and inbounds added or deleted there are
  unknown until a check or «به‌روزرسانی از پنل».
- **Two buttons**: «تست اتصال» tries what the form holds and keeps nothing; «بررسی اتصال» checks the saved server and
  keeps what it learns.
- **Secrets are trimmed**: a panel password that starts or ends with a space will not sign in.
