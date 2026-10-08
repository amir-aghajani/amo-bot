# Services

«فروش › اشتراک‌ها», in both panels: the customers' services. A service is one client on a server's panel, with the one
subscription link the customer adds to their app. The shop keeps a copy of each service's numbers, and the panel is the
truth: about every 15 minutes the running services are read from their panels — counters, quota, end, link —, and a
service whose time or traffic ran out there ends here too.

**The term starts at the first connection**: a new service waits for it — «در انتظار اولین اتصال (n روز)» — and its end
is set then.

## The list

| Tab | What it holds |
|---|---|
| «همه» | Every service |
| «فعال» | Running, or waiting for their first connection |
| «رو به انقضا» | Active services ending within three days (counted beside it); one still waiting has no end yet |
| «منقضی‌شده» | Ended by time or traffic — renewed, they run again |
| «غیرفعال» | Switched off by support |
| «حذف‌شده» | Gone from their panel, found so by a sync or a read — nothing but delete is offered |

- **Filters**: «سرور» (a server's page links here, with its active services), and a customer, from their page. Search by
  a service's number (`#12` is service 12 alone), its name on the panel, a piece of its link, or the customer.
- **Sorting**: newest first, or by «پایان» (soonest first; the ones that never end, or have not started, last) or
  «مصرف» from their headers.
- **A row**: the service's name with its plan, the customer, the server, the traffic used («X از Y», amber at 80%, red
  when used up) and its end («۵ روز مانده», amber within three days), its status, and «جزئیات».

Tick services to [move them](#moving-services) together: the selection stays while you page and sort, and goes with
another tab, server, customer or search.

## A service's dialog

The link, with «کپی» (a link that no longer works — the client gone from its panel — is shown for what it was, said
dead); the customer, the plan, the server, the traffic, the end and the term, the devices, when it was first used and
bought, whether «تمدید خودکار» is on, the next period when a renewal is queued («دوره بعدی»), and when the panel was last
asked. Then what may be done with it now:

| Operation | Offered | What it does | The customer |
|---|---|---|---|
| «به‌روزرسانی از پنل» | Anything not gone from its panel | Reads it from the panel now and puts the copy right — an ended service the panel extended runs again; a client the panel no longer has marks it «حذف‌شده» | Not told |
| «افزایش زمان و حجم» | An active service that ends some day or has a quota | [Below](#adding-days-and-traffic) | Told, unless you untick it |
| «غیرفعال کردن» | An active service | Asks for an optional note; the client is switched off on its panel | Told, with your note |
| «فعال کردن دوباره» | A service switched off here (an ended one needs a renewal) | The client switched on again | Told |
| «انتقال به سرور دیگر» | An active service | [Below](#moving-services) | Gets the new link |
| «حذف سرویس» | Always | Asks for an optional note; the client off its panel and the service off the shop — no trace; the orders that sold it keep their record | Told when it was still active; an ended or switched-off one goes quietly |

A change made on a panel holds the service while it runs: another change of the same service waits a few seconds, then
is refused («این سرویس همین حالا در حال تغییر است؛ …»). A panel that fails says so — the full diagnosis for you, in
any shop; a short word for an agent and the shop's admins on its website — and nothing changes. A service deleted elsewhere meanwhile stays on screen
as it was last read, said to be gone.

### Adding days and traffic

«افزایش زمان و حجم» makes one customer whole: «تعداد روز» (0 to 365) onto its end — or a longer term, for one that has
not started —, «حجم (گیگابایت)» (0 to 10000) on top of its quota, an optional note the customer reads under the gift, and
«پیام به مشتری» (on). Days only for a service that ends some day, traffic only for one with a quota. The panel is read
first: a service it has ended or switched off takes nothing.

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
the delete, the new client is taken back and the service stays where it was.

- **The old server does not answer**: the batch pauses on a question — «انتقال بدون حذف از سرور قبلی» moves this service
  (and the rest from that server, without asking again) and leaves the old client on its panel for you to remove;
  «لغو انتقال» stops there — what moved stays moved.
- **The target refuses or fails**: the batch stops, since the next ones would fail the same way.
- **One service's own trouble** (it ended meanwhile, it is busy): the batch goes on; failed ones stay ticked.

While it runs, the dialog's one button is «توقف بعد از این سرویس», and closing the tab asks first.

### When the panel fails a delete

A panel that fails «حذف سرویس» asks whether to delete the service from the shop alone: «حذف فقط از فروشگاه» deletes it
here and leaves its client on the panel — **it keeps working until you remove it there** —; «لغو حذف» changes nothing.

## Agents' shops

Working an agent's shop — the agent in their panel, or you with their shop open — has rules of its own:

- **A client is left on its panel** (a delete from the shop alone, a move without deleting the old client) only while
  that panel does not answer — its last contact failed within ten minutes. A panel that answers refuses it («پنل سرور
  «…» در دسترس است؛ …»): a client left on a working panel would be a second service nobody paid for. Removing it from
  the panel is then yours.
- **60 panel operations a minute** — reads, extensions, switches, each moved service, deletes — then a short wait
  («درخواست زیادی به پنل سرورها فرستاده‌اید؛ …»); a longer move batch marks the rest failed. Your own work in the main
  shop has no such limit.
- **Traffic added** comes out of the agent's pool ([above](#adding-days-and-traffic)); a deleted service gives none back.
- A panel's failure reads to the agent as a short word; you still read the diagnosis.

## The shop's admins on its website

The bot's admins may work the services from the shop's website while it lets them in ([Website](Website.md#the-shops-admins)):
read one from its panel, switch it off or on, move it — and, when the website grants it, give it days and traffic or
delete it. They are held to an agent's shop's rules in every shop, the main one too: a client left on its panel only
while the panel does not answer, 60 panel operations a minute (theirs together, in an agent's shop with the agent's
own), a failure in a short word. And **an admin never changes their own service** — another admin, or you, does.

## What runs by itself

- **The sync**, every 15 minutes: the running services read from their panels; a panel whose last contact failed is
  left alone ten minutes ([Servers](Servers.md#health)).
- **Renewal** — the customer renews a service on its own plan at its price today, in the bot or on the website, an ended
  one too: the days left always carry over, the traffic left by the shop's rule ([Bot settings](Bot-Settings.md#renewal)).
- **«تمدید خودکار»** — the customer's switch: the wallet renews the service days before its end; a wallet short of it is
  told once a window and tried again until it can pay.
- **The next period** — with the traffic not carried, a renewal queues the next period; when the paid one ends, what is
  left of the old traffic goes and the new period's begins.
- **«یادآوری»** — a service near its end, or near its traffic's end, is told once while it stays there (both off until
  switched on, [Bot settings](Bot-Settings.md#reminders)).

## Watch out for

- **«حذف سرویس» leaves no trace** and cannot be undone; the customer of a running service is told. To stop a service
  for a while, switch it off.
- **A client left on its panel keeps working** until you remove it there.
- **«غیرفعال» is yours, «منقضی‌شده» the panel's**: a switched-off service is switched on here; an ended one is renewed.
- **The subscriptions list shows the open shop's services**: an agent's customers' services on your servers are in their
  shop.
