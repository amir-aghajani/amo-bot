[فارسی](fa/Services.md)

# Services

«فروش › اشتراک‌ها», in both panels: the customers' services. A service is one client on a server's panel, with the one
subscription link the customer adds to their app. The shop keeps a copy of each service's numbers, and the panel is the
truth: about every 15 minutes the running services are read from their panels — counters, quota, end, link —, and a
service whose time or traffic ran out there ends here too.

**The term starts at the first connection**: a new service waits for it — «در انتظار اولین اتصال (n روز)», an hourly
plan's «(n ساعت)» — and its end is set then. A term is kept in hours (an hourly plan's hours, a daily one's days × 24);
less than a day before its end the bot and the panels give its hour too, and the hours left.

## The list

| Tab | What it holds |
|---|---|
| «همه» | Every service |
| «فعال» | Running, or waiting for their first connection |
| «رو به انقضا» | Active services ending within three days (counted beside it); one still waiting has no end yet, and a free test is never here |
| «منقضی‌شده» | Ended by time or traffic — renewed, they run again |
| «غیرفعال» | Switched off by support |
| «حذف‌شده» | Gone from their panel, found so by a sync or a read — nothing but delete is offered here, and in the bot its screen has only the way back to the list |

- **Filters**: «سرور» (a server's page links here, with its active services), «نوع» — «همه», «اکانت تست» for the
  free tests ([below](#test-services)), «سرویس عادی» for the services sold or given, neither a test nor added by their
  link, and in the main shop alone «افزوده‌شده با لینک» for the services customers added by their link
  ([below](#services-added-by-their-link)) —, and a customer, from their page. Search by a service's number (`#12` is
  service 12 alone), its name on the panel, a piece of its link, or the customer.
- **Sorting**: newest first, or by «پایان» (soonest first; the ones that never end, or have not started, last) or
  «مصرف» from their headers.
- **A row**: the service's name with its plan (a free test's «تست» badge, or a «با لینک» badge beside the plan of one
  added by its link), the customer, the server, the traffic used («X از Y», amber at 80%, red when used up) and its end
  («۵ روز مانده», amber within three days), its status, and «جزئیات».

Tick services to [move them](#moving-services) together: the selection stays while you page and sort, and is cleared
by another tab, server, «نوع», customer or search.

## A service's dialog

The link, with «کپی» (a link that no longer works — the client gone from its panel — is shown for what it was, said
dead); the customer, the plan, the server, the traffic, the end and the term, the devices (and the limit a renewal
queued, from when it holds), when it was first used and bought, whether «تمدید خودکار» is on, the next period when a
renewal is queued («دوره بعدی»), and when the panel was last asked. While the shop is to remove it by itself — an ended service while [the automatic removal](#what-runs-by-itself)
is on, a free test by its rules —, «حذف خودکار» says when, with the time left; «در نوبت حذف» once that moment passed,
until the next run takes it. Then what may be done with it now:

| Operation | Offered | What it does | The customer |
|---|---|---|---|
| «به‌روزرسانی از پنل» | Anything not gone from its panel | Reads it from the panel now and puts the copy right — an ended service the panel extended runs again; a client the panel no longer has marks it «حذف‌شده» | Not told |
| «افزایش زمان و حجم» | An active service that ends some day or has a quota | [Below](#adding-days-and-traffic) | Told, unless you untick it |
| «غیرفعال کردن» | An active service | Asks for an optional note; the client is switched off on its panel | Told, with your note |
| «فعال کردن دوباره» | A service switched off here (an ended one needs a renewal) | The client switched on again | Told |
| «انتقال به سرور دیگر» | An active service | [Below](#moving-services) | Gets the new link |
| «انتقال به مشتری دیگر» | Anything but a free test or a service gone from its panel | [Below](#handing-a-service-to-another-customer) | Both told: it left the one, it is the other's |
| «حذف سرویس» | While no order of it is open | Asks for an optional note; the client off its panel and the service off the shop — no trace; the orders that sold it keep their record | Told when it was still active; an ended or switched-off one goes quietly |

**«حذف سرویس» waits for the service's open orders.** While a renewal or an extra of it is unpaid, being delivered or
failed, the button is not offered, and a delete sent from elsewhere is refused: «سفارش #… این سرویس هنوز تمام نشده
است…». Deliver, cancel or refund that order first ([Orders and payments](Orders-And-Payments.md)), then delete the
service.

A change made on a panel holds the service while it runs: another change of the same service waits a few seconds, then
is refused («این سرویس همین حالا در حال تغییر است؛ …»). A panel that fails says so — the full diagnosis for you, in
any shop; a short word for an agent and the shop's admins on its website — and nothing changes. A service deleted elsewhere meanwhile stays on screen
as it was last read, said to be gone.

### Adding days and traffic

«افزایش زمان و حجم» makes one customer whole: «تعداد روز» (0 to 365) onto its end — or a longer term, for one that has
not started —, «حجم (گیگابایت)» (0 to 10000) on top of its quota, an optional note the customer reads under the gift, and
«پیام به مشتری» (on). Days only for a service that ends some day, traffic only for one with a quota. Days that would
end it after [the last day](#watch-out-for) are refused under «تعداد روز». The panel is read first: a service it has
ended or switched off takes nothing.

**In an agent's shop the traffic is the agent's**: it comes out of their bot's pool first (the field's hint says how
much is left, in the agent's own panel), refused while the pool is short, and given back if the extension fails; the
days cost nothing. In the main shop it costs nobody.

A whole server's services get days and traffic from [its page](Servers.md#adding-days-and-traffic), every server's from
the [mass gift](Broadcasts.md#the-mass-gift).

### Moving services

«انتقال به سرور دیگر» — from a service's dialog, or the bar under the list for the services ticked — moves active
services to another server. Pick «سرور مقصد»: a server that cannot take a service is shown with why (switched off, full,
not checked, no subscription links, its connector missing), and a server whose inbounds offer nothing to sell for a
service's plan refuses it («اینباند قابل فروشی ندارد؛ …»). «انتقال n سرویس» then moves them one at a time, a line of progress each; services not active, or on that server
already, are left as they are.

For each: the old panel is read, one client is made on the target — the same name unless the target has it, the traffic
left as its quota, the same end (or the same term still waiting for its first connection) —, then the old client is
deleted, and the customer gets the new link (a QR card, or text). If the target gives no link, or the old panel refuses
the delete, the new client is taken back and the service stays where it was. A panel that stops answering half-way is
asked again before anything is undone; a client it may still have — the old one, or one the target may have made — is
listed on that server's page and removed by itself once the panel answers ([Servers](Servers.md#the-clients-a-rescue-left)),
so the customer never keeps two working services.

- **The old server does not answer**: the batch pauses on a question — «انتقال بدون حذف از سرور قبلی» moves this service
  (and the rest from that server, without asking again) and leaves the old client on its panel until it answers again,
  then it is removed by itself; «لغو انتقال» stops there — what moved stays moved.
- **The target refuses or fails**: the batch stops, since the next ones would fail the same way.
- **One service's own trouble** (it ended meanwhile, it is busy): the batch goes on; failed ones stay ticked.

While it runs, the dialog's one button is «توقف بعد از این سرویس», and closing the tab asks first. To move every service
on a server — every shop's, with no tab to keep open, or rescued from a panel that is gone —, use «انتقال سرویس‌ها» on
[the server's page](Servers.md#moving-its-services).

### Handing a service to another customer

«انتقال به مشتری دیگر» gives the service to another customer of the shop — one who moved to a new Telegram account, a
friend they bought it for. Type their numeric Telegram id («شناسه عددی تلگرام مشتری جدید»): the customer it names in
this shop is shown as you type — they must have started the bot once —, and the strip's «انتقال به مشتری دیگر» hands the
service over. You may always, whatever the customers' own switch says ([Bot settings](Bot-Settings.md#service-transfers):
with it on, customers do it themselves in the bot and on the website).

- **The service stays as it is** — its link, end, traffic, plan and server — and is the new customer's from then on: in
  their «سرویس‌های من», renewed and reminded for them. Its «تمدید خودکار» goes off: the new customer switches it on if
  their wallet should pay.
- **The link goes with it**, so the previous customer's devices stay connected until the new one taps «تغییر لینک» on
  its screen: the new customer's message says so, where its server can change a link. The previous customer can no
  longer change the link or the «تمدید خودکار» switch — a press made just as the service changes hands is refused too
  («این سرویس همین حالا به حساب دیگری منتقل شد.»).
- **Both customers are told** in the bot: the previous one that support moved it to another account (and which of their
  unpaid orders were cancelled with it, below), the new one what it is, with «📊 مشاهده سرویس».
- **The report group's «خریدها»** hears of every transfer: from whom, to whom, and who did it.
- **On its panel** the client takes the new customer's Telegram id and comment — as a service added by its link does
  ([below](#services-added-by-their-link)); its name there stays — by the default pattern the previous customer's
  username and a number (`ali_1`), with `{tg_id}` their id ([Bot settings](Bot-Settings.md#service-usernames)): a
  client is never renamed —, and one switched off stays off. A panel that does not
  answer leaves them as they were (logged) and the service changes hands all the same; its next change on the panel
  writes them.
- **Refused**: a free test, a service gone from its panel, an id that names no customer of this shop, the service's own
  customer, a banned account — and, on a shop's website, an admin's own service or one handed to themselves.
- **A customer's own transfer** ([Bot settings](Bot-Settings.md#service-transfers)) also waits while an order of it they
  made is open. One whose delivery failed sends them to you: retry it, or give the money back
  ([refunds](Orders-And-Payments.md#refunds)), and they may transfer it.
- The orders that sold or renewed it stay their buyer's. A renewal or an extra of it the previous customer left unpaid
  is cancelled with the transfer — it is no longer theirs to pay for —, unless its receipt waits for your review: that
  one stays, and approved, it is not delivered onto the new customer's service: it fails, its note asking you to give
  the money back ([refunds](Orders-And-Payments.md#refunds)). So does one paid but not yet sent to the panel as you
  hand it on. The previous customer is told when such an order fails: the service is no longer theirs, and support
  settles the payment with them («⚠️ پرداخت سفارش #… ثبت شد، ولی سرویس … پیش از انجام آن به حساب دیگری منتقل شد و دیگر
  سرویس شما نیست؛ …»). While one is being sent to the panel right then, the transfer waits for it: «این سرویس همین حالا
  روی سرور در حال تغییر است؛ چند لحظه بعد دوباره امتحان کنید.» Nor does a refund of what the previous customer bought
  take anything from the new one: it is refused until you take the service back by hand.

### When the panel fails a delete

A panel that fails «حذف سرویس» asks whether to delete the service from the shop alone: «حذف فقط از فروشگاه» deletes it
here and leaves its client on the panel — **it keeps working until you remove it there** —; «لغو حذف» changes nothing.

## Test services

A free test a customer took in the bot ([Test accounts](Test-Accounts.md)) is a service like the others, marked «تست»
where a service shows its plan — in the list and on the customer's page —; its dialog's «پلن» reads «اکانت تست رایگان».
It has no plan, so it is never renewed — by the customer or by «تمدید خودکار», which its dialog does not show —, buys no
extra traffic, time or devices, is not reminded of its end, and a server's «افزودن زمان و حجم» and the mass gift pass it
by. It is never among «رو به انقضا» — here or on the dashboard, whose «اشتراک‌های فعال» leaves it out too — and its end
is never amber; it still takes room in its server's «ظرفیت».

Every operation above still works on it: read it, give it days and traffic (it runs again until it ends again), switch
it off or on, move it (if the target has its name, the new one follows the shop's test pattern — `test_…` by default,
[Bot settings](Bot-Settings.md#service-usernames)), delete it. A test deleted
here still counts in its customer's quota. The tests that ended, and those never connected, are removed by themselves
([below](#what-runs-by-itself)).

## Services added by their link

A client you made by hand on a panel becomes a customer's service when they send its link to the bot or the website
([Services added by their link](Service-Claims.md)). It is a service like the others, kept as its panel had it — its
client there takes the customer's Telegram id and comment, as a [transferred](#handing-a-service-to-another-customer)
one does — and filed under «نوع › افزوده‌شده با لینک»; its dialog says when it was added. It has no plan until its
first renewal, when the customer picks one sold on its server; from then on it renews on that plan. Until then the
dialog's «پلن» reads «بدون پلن» and says the customer picks one when renewing. (A plan a service names cannot be
deleted, only switched off: [Plans](Plans.md).)

Every operation works on it. Its delete confirmation has one more choice, «حذف فقط از فروشگاه (کلاینت روی پنل
می‌ماند)»: what puts right a service added to the wrong account — the client keeps working on its panel, and the right
customer sends the link again. As with «حذف سرویس», the customer of a running service is told it was deleted, with your
note.

## Agents' shops

Working an agent's shop — the agent in their panel, or you with their shop open — has rules of its own:

- **A client is left on its panel** (a delete from the shop alone, a move without deleting the old client) only while
  that panel does not answer — its last contact failed within ten minutes. A panel that answers refuses it («پنل سرور
  «…» در دسترس است؛ …»): a client left on a working panel would be a second service nobody paid for. A client the shop
  leaves on a panel stays there until you delete it on the panel yourself.
- **60 panel operations a minute** — reads, extensions, switches, each moved service, deletes — then a short wait
  («درخواست زیادی به پنل سرورها فرستاده‌اید؛ …»); a longer move batch marks the rest failed. Your own work in the main
  shop has no such limit.
- **Traffic added** comes out of the agent's pool ([above](#adding-days-and-traffic)); a deleted service gives none back.
- A panel's failure reads to the agent as a short word; you still read the diagnosis.

## The shop's admins on its website

The bot's admins may work the services from the shop's website while it lets them in ([Website](Website.md#the-shops-admins)):
read one from its panel, switch it off or on, move it — and, when the website grants it, give it days and traffic, or
delete it or hand it to another customer (the delete's grant). They are held to an agent's shop's rules in every shop,
the main one too: a client left on its panel only while the panel does not answer, 60 panel operations a minute (theirs
together, in an agent's shop with the agent's own), a failure in a short word. And **an admin never changes their own
service**, nor takes a customer's — another admin, or you, does.

## What runs by itself

- **The sync**, every 15 minutes: the running services read from their panels — their usage, and what you changed
  there by hand: traffic, end, and on 3X-UI the device limit, which the next extra then builds on (a renewal brings its
  plan's); a panel whose last contact failed is left alone ten minutes ([Servers](Servers.md#health)).
- **Renewal** — the customer renews a service on its own plan at its price today, in the bot or on the website, an ended
  one too — a running one, once it nears its end if the shop holds it back till then («آستانه مجاز تمدید»; a service
  that never ends has no end to near, so only a traffic percent can hold it back) —, by one of
  the shop's renewal methods: the days left carried over and the traffic left by the shop's rule («تمدید پلن فعلی»), the
  traffic counted afresh («ریست حجم + افزایش روز»), or the term started again with the traffic carried («ریست زمان +
  انتقال حجم») ([Bot settings](Bot-Settings.md#renewal)). Each brings its plan's device limit, the extra devices bought
  for the period before gone with it ([Plans](Plans.md#devices-and-renewals)).
- **«حجم، زمان و کاربر اضافه»** — the customer buys more traffic, more days or more devices for a service, each on its
  own, in the bot or on the website, at its plan's prices or the shop's (off until you turn it on,
  [Bot settings](Bot-Settings.md#extra-traffic-time-and-devices)). Traffic brings a service whose traffic ran out back. Days for one
  whose time ran out start its term again from its next connection. Devices last until the period in use ends. One
  ended on both counts needs a renewal.
- **«تمدید خودکار»** — the customer's switch: the wallet renews the service days before its end; a wallet short of it is
  told once a window and tried again until it can pay. Not on a plan whose term is shorter than those days — an hourly
  plan's: a renewal adds the plan's term, and a shorter one would leave the service due again at once —, and never for
  a service without a plan (added by its link, or built: [Custom services](Custom-Services.md#renewing-a-built-service))
  until a renewal on a plan gives it one. A service bought
  at a first-purchase price renews at the plan's price, as any; an agent's or a colleague's at the plan's agent price —
  and only a price within the wallet's least and most amount: one outside them is renewed by hand, its customer reminded
  of its end ([Payment methods](Payment-Methods.md#the-least-and-most-amount)). The service's dialog shows the switch as
  the customer left it, whether it renews then or not.
- **The next period** — a running service renewed before its end goes on with the paid period: when it ends, the plan's
  device limit takes over and — with the traffic not carried — what is left of the old traffic goes and the new
  period's begins. The dialog's «دوره بعدی» shows the traffic part; «کاربر هم‌زمان» says from when the plan's device
  limit holds («از …: ۱ کاربر») while it differs from the one now — also for a renewal whose traffic counted at once.
- **«یادآوری»** — a service near its end, or near its traffic's end, is told once while it stays there (both off until
  switched on, [Bot settings](Bot-Settings.md#reminders)). A free test never is.
- **The automatic removal**, every 15 minutes after the sync, in every shop that has ended services or tests — a
  stopped agent's too —, by each shop's rules, quietly (the customer not told): a service that ended — its time or
  traffic ran out, or its panel no longer has it — is removed from its panel and the shop «فاصله حذف» hours after it
  ended, while the shop's «حذف خودکار سرویس‌های منقضی‌شده» is on (off until switched on,
  [Bot settings](Bot-Settings.md#ended-services)); the orders that sold it keep their record. A free test that ended
  goes after the shop's own grace, and one never connected after its days
  ([Test accounts](Test-Accounts.md#the-clean-up)). When a service ended is the deadline it passed, else when its panel
  said its traffic was used up or its client gone; one that runs again starts afresh. Each is read from its panel again
  first, so one renewed, extended or connected to since the sync stays. One switched off here is left for you, and one
  with an order about it still open — a renewal waiting for its receipt, a delivery waiting for support's retry —
  waits for it. A run takes at most 100 of each rule in a shop, the oldest first; the rest wait for the next. The bot's
  service screen and the shop's website tell the customer when an ended service goes.

## Watch out for

- **«حذف سرویس» leaves no trace** and cannot be undone; the customer of a running service is told. To stop a service
  for a while, switch it off.
- **No service may end after 17 January 2038 («۲۸ دی ۱۴۱۶»)**, the last day the shop can keep. A purchase, a renewal,
  extra days or days you give that would end one later are refused before anything is charged or sent to a panel: «تاریخ
  پایان سرویس نمی‌تواند بعد از ۲۸ دی ۱۴۱۶ باشد؛ مدت کوتاه‌تری انتخاب کنید.» A new service is judged as if it connected
  at once. Extra days are offered only as far as that day; a server's or the mass gift's days pass such a service by;
  «تمدید خودکار» does not renew it — its customer is reminded of its end instead.
- **A service the automatic removal takes is gone for good** too: its customer can no longer renew it, and a new
  purchase gets a new link to add to their app. Set «فاصله حذف» with that in mind — long enough for a customer to come
  back and renew.
- **A client left on its panel keeps working** until you remove it there.
- **A service handed on keeps its link**: devices the previous customer added it to still connect. The new customer
  cuts them off with «تغییر لینک» on the service's screen in the bot, where its panel can.
- **«غیرفعال» is yours, «منقضی‌شده» the panel's**: a switched-off service is switched on here; an ended one is renewed.
- **The subscriptions list shows the open shop's services**: an agent's customers' services on your servers are in their
  shop.
