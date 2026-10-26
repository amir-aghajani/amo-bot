[فارسی](fa/Service-Claims.md)

# Services added by their link

A customer adds a service you made by hand on a panel to their account by sending the bot its link: its subscription
link, or one of its configs. «افزودن سرویس با لینک» is for the clients you make on the panel itself — for a customer
who paid you some other way, a friend, a replacement — so they still see them in «سرویس‌های من», renew them and buy
extras for them like any service the shop sold. The main shop's alone, and off until you switch it on.

## Switching it on

«تنظیمات ربات › افزودن سرویس با لینک» ([Bot settings](Bot-Settings.md#services-added-by-their-link)), in the main shop:

- **«مشتری‌ها بتوانند سرویس را با لینکش اضافه کنند»** (off) — on, a customer may send a service's link to add it to
  their account; off, a link sent to the bot is an unknown message, as it always was, and the services added already
  stay. Its notes link to the report group and to the services added this way.

An agent's shop never takes one: its services are sold from the agent's traffic, and the clients made by hand are on
your panels.

## What the customer does

They send the link to the bot — anywhere the bot waits for nothing else —, or tap «➕ افزودن سرویس» under
«سرویس‌های من» and send it there. The bot takes:

- a **subscription link** — `https://…/sub/…` as the panel gives it, through another domain or path too: the part of
  it that names the client is what counts;
- a **config** — `vless://`, `vmess://`, `trojan://`, `ss://`, `hysteria2://` (`hy2://`) or `tuic://`: its id or
  password is what counts.

A message may hold the link among other words, or several of a service's configs: its links are read together, five
at most, so an unrelated address beside them does no harm.

Sent at no step — not after «➕ افزودن سرویس» —, a subscription link counts only on an address the shop's own links
are on: a server's panel address, its «آدرس اشتراک», or the address of its newest service's link. Any other web
address — a channel's post forwarded, a website — is a message the bot does not understand, and asks no panel. After
the button, any link is looked for.

The shop asks its panels whose client it is, in the servers' order, and adds it to the customer's account at once:
«✅ سرویس … روی لوکیشن … به سرویس‌های شما اضافه شد.», with «📊 مشاهده سرویس» under it. From then on it is one of their
services, as its panel has it: its traffic, its term (a deadline — what is left of it counts as its term —, or one
waiting for the first connection), its IP limit. One you switched off on the panel while it still runs comes switched
off, as if support had switched it off here ([Services](Services.md#a-services-dialog)); one that ended — its time or
traffic used up, switched off by the panel or not — comes ended, to renew, and while the shop removes ended services
([Bot settings](Bot-Settings.md#ended-services)) their hours count from the claim, not from an end long past.

A link the bot does not take is said in a word, every one a bot text you may reword
([Bot texts reference](Bot-Texts-Reference.md)):

| What happened | The bot says |
|---|---|
| No service's link in the message | after «➕ افزودن سرویس»: that it is no service's link, and what to send — the link is still awaited; sent at no step, it is any message the bot does not understand («متوجه نشدم») |
| None of the panels has the client | after «➕ افزودن سرویس»: no service was found with this link; sent at no step, it is any message the bot does not understand |
| Another customer has it already | it is in another account; if it is theirs, they contact support |
| It is the customer's own already | it is in their services already, with «📊 مشاهده سرویس» |
| The panel serves no subscription link for it | to contact support: a customer gets a subscription link and nothing else ([Servers](Servers.md)) |
| A panel that might have it did not answer | to try again a little later |
| More than 10 links in 10 minutes | to try again in so many minutes: each link sent asks every panel |

A link sent while the customer is in the middle of something else — writing a ticket, typing an amount — is part of
that, not a claim.

On the shop's website the same is `POST /subscriptions/claim`
([Store API: after buying](Store-API-After-Buying.md#adding-a-service-by-its-link)).

## Who gets it

**The first customer to send it.** A client the shop keeps already — any customer's service, in any shop, a free test
too — is never taken from them. So send a link only to the customer it is for, and ask them to add it soon. One
exception: a customer's own service the shop lists as gone from its panel («حذف‌شده»), whose client is on the panel
again, comes back to them as a service added now, with its plan and «تمدید خودکار»; anyone else's such record refuses the
link, for you to look into.

Every service added this way is reported in the report group's «خریدها» topic: who, which client, which server
([Report group](Telegram-Group.md#the-topics)). A service in the wrong account is put right from its dialog in the
panels: «حذف فقط از فروشگاه (کلاینت روی پنل می‌ماند)» removes it from the shop and leaves its client working on the
panel; the right customer then sends the link again. The list of services finds them under «افزوده‌شده با لینک»
([Services](Services.md#services-added-by-their-link)).

## Renewing one

A service added by its link has no plan. Its first renewal — «تمدید» on its screen, or «♻️ تمدید سرویس» in the menu —
asks the customer to pick one among the plans on sale on its server — whether or not the server takes new sales now:
a renewal makes no new client —, each at their renewal price, their category asked first when they are in more than
one ([Bot guide](Bot-Guide.md#renewing)). The renewal then goes as any other, and **the plan they paid for becomes the
service's plan**: its next renewal is on it, and «تمدید خودکار» may renew it. While the builder
is on, the customer may renew it with a package they build instead, and it keeps having no plan
([Custom services](Custom-Services.md#renewing-a-built-service)). With neither a plan on sale on its server nor the
builder, it cannot be renewed («فعلا پلنی برای تمدید این سرویس فروخته نمی‌شود …»). A refund of that
first renewal ([Orders and payments](Orders-And-Payments.md#refunds)) takes the plan back with the time and traffic it
gave: the service has no plan again, to pick at its next renewal. Extra traffic, time and devices work from the start, at the shop's prices ([Bot settings](Bot-Settings.md#extra-traffic-time-and-devices)).

## Watch out for

- **A link is a key.** Whoever has it can use the service, and — while it is in nobody's account — add it to theirs.
  Send it to its customer alone, and keep the subscription ids and passwords the panel makes: one you type by hand can be
  guessed (one set to the client's own name is never matched at all, so such a client is added by a config).
- **Your own clients on the panel** can be claimed too, by anyone with their link: keep the switch off if you hand
  links around, or keep such clients on a server the shop does not know.
- **A client's name stays as you made it.** The shop does not rename it; its comment and Telegram id on the panel
  become the customer's as it is added, as for a service [handed to another customer](Services.md#handing-a-service-to-another-customer)
  (a panel that does not answer then gets them at the shop's next change of it: a renewal, extras).
- **Each link sent asks every panel** for its whole list of clients, which is why a customer has 10 tries in 10
  minutes. A panel the shop leaves alone after a failure is not asked: for a link it holds, the bot says to try again
  later, until the panel answers again.
