[فارسی](fa/Custom-Services.md)

# Custom services

A customer builds a service of the traffic and the days they choose, instead of picking a plan. «سرویس دلخواه» sells
each quantity by **tiers** — the more they take, the less a gigabyte or a day may cost —, on the servers you choose,
with the number of devices you set. The main shop's alone, and off until you switch it on.

## Setting it up

«فروشگاه › سرویس دلخواه» in the owner's panel (an agent's shop has no such page):

- **«سرویس دلخواه فعال باشد»** (off) — on, the bot shows «🛠 ساخت سرویس دلخواه» under the plans and the website sells it
  too, while both price lists have a tier and one of its servers can take a new client — and a service without a plan
  may be [renewed with a package built](#renewing-a-built-service); off, nothing is built, the services built already
  stay, and they renew on a plan picked.
- **حجم** and **مدت** — each with its own:
  - **tiers**: rows of «از» and «قیمت» — from so many gigabytes (or days) on, so many Toman each. Up to ten rows, in any
    order; a list without a row sells nothing.
  - **the bounds** («کمترین», «بیشترین»): what a customer may build, 1 to 10,000 GB and 1 to 365 days. Nothing below
    the first tier is sold, whatever the least says.
  - **the buttons** («دکمه‌های پیشنهادی»): up to eight amounts offered as buttons; the customer may type any other amount
    within the bounds. A button outside the bounds is not shown.
- **سرور و کاربر** — «تعداد کاربر هم‌زمان», the devices at once on a built service (0 for unlimited), and the servers
  it is built on. A server switched off, full or without subscription links is not offered until it can take a client
  again.
- **ماشین حساب قیمت** — type a GB and a number of days: the price a customer sees for them, before their discounts, from
  the tiers as typed — before you save, too.

### How a price is worked out

The **whole** quantity is priced at the rate of the tier it falls into — the last tier whose «از» it reaches. With tiers
«از ۱: ۳٬۰۰۰» and «از ۵۰: ۲٬۵۰۰»:

| GB | Price |
|---|---|
| 49 | 49 × 3,000 = 147,000 |
| 50 | 50 × 2,500 = 125,000 |
| 60 | 60 × 2,500 = 150,000 |

So 50 GB can cost less than 49: set the tiers so a step down never undercuts the amount below it, or let it — a
customer who notices builds the larger one. The days are priced the same way, and **the two prices are added**: 50 GB
for 30 days at «از ۳۰: ۱٬۵۰۰» a day costs 125,000 + 45,000 = 170,000 Toman.

Then the customer's discounts come off it, as for a purchase of no plan: their own percent, their loyalty tier's or a
sale's — only a sale of every plan reaches it —, and a discount code that is not limited to certain plans (one limited to
servers holds on those servers). A first-purchase price or an agent price is a plan's: a built service has none.

## What the customer does

In «خرید اشتراک» the bot shows «🛠 ساخت سرویس دلخواه» under the plans (or the categories) — even when no plan is on sale:

1. **The GB** — the tiers of a gigabyte, a button per amount offered, or any amount typed («۵۰ گیگ» and «۲ هزار گیگ» are
   fine: [amounts typed](Bot-Guide.md#amounts-typed)). One outside the bounds is refused, saying them, and another is
   awaited.
2. **The days** — the same, for the days.
3. **The server** — a button per server, by name; with only one, this step is skipped.
4. **The checkout** — the GB and its price, the days and theirs, the devices, the server and what is paid, with the
   discount lines, «🏷 کد تخفیف دارم», the service's name and the ways to name it, as a plan's checkout has them
   ([Bot settings](Bot-Settings.md#service-usernames)), and the ways to pay. Nothing is ordered before a way to pay is picked.

Paid, it is delivered as any purchase: one client on every inbound the server sells, named as the customer chose, its
term from the first connection, with the devices you set. The service has **no plan**: «🌿 نام سرویس» reads «سرویس
دلخواه». Its extras sell at the shop's prices ([Bot settings](Bot-Settings.md#extra-traffic-time-and-devices)).

Every text of the builder is in the shop group of the bot texts, their names starting `custom_`
([Bot texts reference](Bot-Texts-Reference.md#group-shop)). On the shop's website the same is `GET /custom-services`
and `POST /custom-services/orders` ([Store API: buying](Store-API-Buying.md#a-service-the-customer-builds)).

## Renewing a built service

A built service has no plan, so its renewal — «تمدید» on its screen, or «♻️ تمدید سرویس» in the menu — first lists what
to renew it on, as for a service added by its link ([Services added by their link](Service-Claims.md#renewing-one)): the
plans on sale on its server (their category asked first when they are in more than one), and under them, while the
builder is on, **«🛠 تمدید با سرویس دلخواه»** — alone when no plan is sold there. That button leads through the same GB and days (buttons, or any amount typed within the bounds) — no
server to pick: a renewal makes no new client — to the renewal's checkout: «🌿 نام سرویس» reads «سرویس دلخواه · ۵۰ گیگ ·
۳۰ روز», and the rest is any renewal's ([Bot guide](Bot-Guide.md#renewing)): the shop's renewal window, its methods, the
discount code and the ways to pay.

The price is the builder's tiers, discounted as a renewal of no plan: a sale for renewals of every plan, the customer's
own percent or tier, and a code not limited to certain plans (one limited to servers holds on the service's own server).
Paid, the renewal adds the GB and the days by the method picked and gives the builder's devices (as a plan's, from the
end of the period in use: [Plans](Plans.md#devices-and-renewals)); the service **still has no plan**, so its next
renewal is picked again — a plan picked then becomes its own — and **«تمدید خودکار» is never offered for it**, in the
bot or on the website, until a renewal on a plan gives it one. On the website the same is `gb` and `days` in place of
`plan_id` ([Store API: buying](Store-API-Buying.md#renewing-with-a-package-built)).

## In the panels and the report group

A built purchase or renewal is an order without a plan that carries its own GB, days and devices. The orders and
payments lists, the dashboard and the report group's «خریدها» and «تمدیدها» read it as «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز»
where a plan's name would be; the service itself reads «سرویس دلخواه» in «اشتراک‌ها» and its window, as on its card in
the bot. Its refund takes the service back as any purchase's, or what the renewal gave as any
renewal's ([Orders and payments](Orders-And-Payments.md#refunds)).

## Watch out for

- **Tiers set per unit.** A tier's «قیمت» is the price of one gigabyte or one day, not of the whole tier.
- **Changing the tiers** changes the price of a checkout already on a customer's screen: what they pay is worked out
  again when they pay. An unpaid order keeps the price it was made at.
- **Changing the devices** applies to what is built from then on — an unpaid order of the same, found again at its
  checkout, takes the new number —; a service built already keeps its own.
