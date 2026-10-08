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
| «ظرفیت» | How many **active** services it may hold — every shop's together. Blank is unlimited; **`0` is full** |
| «یادداشت» | Your own note, 1000 characters at most; shown nowhere but this form |
| «فعال» | Off, nothing new is sold on it or moved to it |

**«تست اتصال»** tries the form as it is and stores nothing: whether the panel answers and accepts the credentials, its
status (the core, CPU, RAM, disk), its inbounds, and whether it serves subscription links. It needs the whole form
filled in right, the name too. A failure says what to fix in plain words — the domain, the port or firewall, a timeout,
the certificate, the token or password refused, a redirect to follow, a wrong address — and the panel's own answer under
it.

**«افزودن سرور»** saves it, then checks it at once — tested or not — and opens its page with what the check found. **It
is saved even when that check fails**: fix the connection on its page. The check lists its inbounds, **all of them not
for sale** until you mark them ([inbounds](#inbounds)).

**The secrets** — the token or key, the password, the TOTP secret — are kept encrypted and never shown again: a kept one
shows as dots (a token with its last four characters). Left blank on an edit, it stays; «پاک کردن مقدار ذخیره‌شده»
removes it. **A secret stays only while the address keeps its origin** — the same http or https, host and port (a path
may change): moved, it is never sent to the new address, not even by «تست اتصال», and the form asks for it again
(«آدرس پنل عوض شده است؛ … را دوباره وارد کنید.»). Saving another way in removes the other way's credentials.

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
  how the shop signs in, where the links come from («از تنظیمات پنل», or your prefix), the TLS check, the timeout, and
  its active services: every shop's — what its capacity counts —, and, when agents' customers have services here too,
  how many of them are the open shop's (a link to them on «اشتراک‌ها», which lists the open shop's alone).
- **«اینباندها»** — [below](#inbounds).
- **«تنظیمات اتصال»** — the form again, with «تست اتصال», «بازگردانی تغییرات» and «ذخیره تغییرات» (both held until a
  field differs from what is saved). Every field but the
  connector changes here (another kind of panel is another server). **Saving does not check it again**: press «بررسی
  اتصال» after. The switch «فعال» is here, under «تنظیمات پیشرفته».
- **«افزودن زمان و حجم»** — days and traffic for its services ([below](#adding-days-and-traffic)).
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

### Adding days and traffic

«افزودن زمان و حجم» › «افزودن به سرویس‌ها» gives the services on this server days and traffic — an outage made good, a
gift:

- «تعداد روز» (0 to 365) — onto each service's end, or a longer term for one not started yet; «حجم (گیگابایت)» (0 to
  10000) — on top of its quota. At least one above zero.
- «دلیل» — optional, 300 characters at most: the customers read it under the gift («توضیح پشتیبانی: …»).
- «سرویس‌های در انتظار اولین اتصال هم شامل شوند» — off: an outage cost a service whose clock has not started nothing.
- «پیام به مشتری‌ها» — on: each customer is told by their shop's bot, or by email when Telegram cannot reach them, and
  the notice is kept on their website.

**Who gets it**: the services of every shop that were on this server when you gave it — none bought afterwards — and
running. Running is the panel's word, asked at each service's turn: one that ended by time or traffic, or was switched
off — by support or on the panel — is passed by; days go only to a service that ends some day, traffic only to one with
a quota. The dialog counts whom it reaches from the shop's copy now; the card's progress says how many were checked,
given, passed by and failed.

**How it runs**: one service at a time, by the card while the page is open, else by the scheduler every minute. One
gift runs on a server at a time — a [mass gift](Broadcasts.md#the-mass-gift)'s part on it too. A panel out of reach
holds the gift at that service, with an amber note saying why, and it goes on once the panel answers; a service the
panel refused, or whose update timed out, is counted failed and not tried again (the panel may have taken it).
**«توقف»** stops it there — what was given stays given. The card lists the latest ten.

### Deleting a server

«حذف سرور» is refused while **any** service of any shop is on it, in whatever state — ended, switched off, gone from
the panel: move them or delete them from «اشتراک‌ها» first ([Services](Services.md)) — an agent's customers' from that
agent's shop —, or switch the server off instead. Deleting removes its inbounds, its place on every plan and its gifts'
history; orders keep their record. The panel itself is not touched.

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
a self-signed certificate; `pasarguard` for the other) tries an address and its credentials as «تست اتصال» does, stores
nothing, and prints the status, the inbounds and the subscription server ([Console commands](Console-Commands.md)).

## Watch out for

- **A server whose panel is down still sells**: its purchases are paid, then wait under «سفارش‌ها» › «نیازمند رسیدگی»
  for a retry. For a long outage, switch the server off («تنظیمات اتصال» › «تنظیمات پیشرفته» › «فعال»).
- **After changing the panel, press «بررسی اتصال»**: the subscription service turned off on 3X-UI is noticed only by a
  check — until then sales are offered and fail («… لینک اشتراک نساخت …») —, and inbounds added or deleted there are
  unknown until a check or «به‌روزرسانی از پنل».
- **Two buttons**: «تست اتصال» tries what the form holds and keeps nothing; «بررسی اتصال» checks the saved server and
  keeps what it learns.
- **Secrets are trimmed**: a panel password that starts or ends with a space will not sign in.
