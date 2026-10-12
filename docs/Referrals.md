# Referrals

The referral program («زیرمجموعه‌گیری») pays a customer a share of what the customers they bring pay — and, when the shop
says so, a start gift for each newcomer («هدیه شروع») and a free service each time enough of them bought («جایزه دعوت»),
while it watches for referrals that look made up («هشدار زیرمجموعه‌گیری فیک»). Every shop runs its own: the owner's,
and each agent's in their bot.

## Switching it on

«تنظیمات ربات › زیرمجموعه‌گیری» — off until switched on, one card in four parts saved together:

- «زیرمجموعه‌گیری فعال باشد» — the program. Off, the menu's button is taken away, links make nobody a referral and no
  payment earns a commission or a gift; switched on again, the button comes back, and the referrals and commissions of
  before are where they were.
- **«پورسانت»**: «درصد پورسانت» — 1 to 100 percent of each payment, 10 by default; «درصد پورسانت تمدید» — 0 to 100, what
  a renewal's payment earns in its place (0: renewals earn nothing), 10 by default; «فقط اولین پرداخت هر زیرمجموعه» —
  only each referral's first payment earns, instead of every one.
- **«هدیه شروع»**: «هدیه معرف» and «هدیه زیرمجموعه» — Toman to each wallet, 0 for none (both 0 by default); «زمان هدیه» —
  «با اولین خرید» (the default) or «با عضویت».
- **«جایزه دعوت»**: «به ازای هر چند زیرمجموعه خریدار» (0, the default, for none) and «پلن جایزه» — one of the shop's
  plans, required while the number is above 0.
- **«هشدار زیرمجموعه‌گیری فیک»**: «عضویت پشت سر هم» (10), «بازه هشدار» in minutes (60) and «زیرمجموعه بدون خرید» (20)
  — 0 switches either alert off.

The menu's «👥 زیرمجموعه‌گیری» button must be on the bot's menu for customers to find their link: the settings section and
the referrals page warn while the program runs and the menu lacks it, with a link to «کیبوردها».

## How a customer brings others

In the bot, «👥 زیرمجموعه‌گیری» shows their invite link — `https://t.me/<bot>?start=ref_<code>`, a tap copies it —, the
terms with the share (and a renewal's own, when it differs; the start gift and the plan reward while they are on, each a
bot text the admin may reword), how many they brought and what it earned them, and «📤 ارسال لینک برای دوستان» to share it. While
the program is off — or the bot's @username is not known yet — the screen says the program is not active. The shop's
website gives the same code its own link and its sign-ups ([invite codes](Store-API-Sign-In.md#invite-codes-at-sign-up)).

**Who becomes a referral: only a newcomer.** Someone the shop did not know, whose **first** contact carries the code —
the first `/start` in the bot (even when the phone or channel rule then holds them up), or the sign-up on the website
that makes their account —, while the program is on at that moment, with a code that is a customer's of the same shop
who is not banned (and not themselves). The code's owner is told («یک زیرمجموعه جدید با لینک دعوت شما وارد ربات شد»), and
the report group's «کاربران جدید» says who brought them. A customer the shop already knew never becomes anyone's
referral, whatever link they open later — so a link opened in a Telegram account that pressed Start before brings
nobody.

## What earns a commission

Money that came in: a payment that is not from the wallet — a card transfer — for a service, a renewal, a top-up of the
wallet or an agent's traffic, by a referral whose referrer is not banned, while the program is on at the moment it is
settled. The commission is the share of it, in whole Toman rounded down (a tiny payment may earn nothing), and goes
into the referrer's wallet as the payment settles — the line «پورسانت زیرمجموعه (پرداخت #N)» —; the referrer is told
when the paying customer is told of their payment, and the report group's report of the purchase, renewal or top-up
names it («👥 پورسانت معرف: …»).

- A purchase **from the wallet** earns nothing: its money was counted when the wallet was charged.
- A **refund** leaves the commission standing — it was earned for bringing a customer who paid.
- With «فقط اولین پرداخت…», a payment earns only when no money of that customer came in before it; one refunded since
  still counts as their first (but a refunded top-up).
- A **renewal** earns at «درصد پورسانت تمدید» — nothing at 0; every other payment at «درصد پورسانت».
- **One level**: a referral's referrals earn the referrer nothing.

## The start gift

«هدیه شروع»: once a newcomer, «هدیه معرف» goes to the referrer's wallet and «هدیه زیرمجموعه» to the newcomer's — each
line in plain words («هدیه شروع زیرمجموعه‌گیری (کاربر #N)», «هدیه شروع (عضویت با لینک دعوت)») — and each is told in the
bot (and on their website, by email for one without Telegram). It is paid:

- **«با اولین خرید»** (the default): when the newcomer's first purchase **with a price** is paid — any way to pay, the
  wallet too. A free plan neither brings it nor uses it up: their first paid purchase later still does. A
  service given free (a prize, a gift code's, a reward's) is no purchase, nor is a top-up. A newcomer made up for the
  gift buys nothing and brings nothing.
- **«با عضویت»**: as the newcomer joins through the link — the bot's first `/start` or the website's sign-up.

It is decided once a newcomer, whatever comes later: a later purchase, a setting changed since, two first purchases paid
in the same moment.

## The plan reward

«جایزه دعوت»: each time the number of a referrer's referrals **who bought** something with a price (a free plan does not
count) reaches another multiple of «به ازای هر چند زیرمجموعه خریدار» — checked as a referral's first paid purchase is
paid —, the referrer is given a service of «پلن جایزه» free:
an order at no price on the first server that sells the plan now, delivered and sent as any order, after a message that
says what it is for. Once a milestone. A plan no server can deliver now gives nothing then: the next referral's first
purchase tries again while that milestone is still the one reached. In an agent's shop the service's traffic comes out of
the agent's pool, as any sale's. The customer's website says how many they were given.

## Alerts on made-up referrals

As a newcomer joins through a link, the shop raises an alert on the referrer when:

- **a burst** — the newcomer is the «عضویت پشت سر هم»-th through that link within «بازه هشدار» minutes; or
- **nobody buys** — the referrer's referrals reach «زیرمجموعه بدون خرید» (or as many again) and none of them ever bought
  anything with a price (a free plan is no purchase here).

Each kind is raised at most once in the window — a burst that goes on raises no second alert. It is reported to the
report group under «کاربران جدید» («⚠️ هشدار زیرمجموعه‌گیری»), and listed on the referrals page's «هشدارها». While an
alert on a referrer stands, they earn **no start gift and no plan reward** — the newcomer's gift is held back too —; their
commissions are earned as ever (real money came in). Support clears an alert from the page; what was held back meanwhile
is **not** given later.

## The page

«فروش › زیرمجموعه‌گیری», in both panels: the program's figures — «معرف‌ها», «زیرمجموعه‌ها», «پورسانت‌های پرداخت‌شده» and
«جمع پورسانت‌ها» — and four sections, each searchable (the first three sorted from their headers):

- **«معرف‌ها»** — the customers who brought others, most first: how many («n نفر» — a link to their referrals), what it
  earned them, their latest referral; «هشدار» beside one an alert stands on, a link to their alerts.
- **«زیرمجموعه‌ها»** — who brought whom, newest first, with what each one's payments earned their referrer; opened from a
  referrer, it is narrowed to theirs («معرف … ✕»).
- **«پورسانت‌ها»** — every commission, newest first: the amount and the share it was earned at, who earned it, whose
  payment it was (a link to the payment) and when. `#12` finds payment 12's commission.
- **«هشدارها»** — the alerts that stand, newest first: the referrer, why («۱۰ عضویت پشت سر هم با یک لینک», «۲۰ زیرمجموعه
  بدون هیچ خرید»), when, and «رفع هشدار» (asks first). `#12` finds customer 12's. One of the shop's admins on its website
  may clear one too — never one on themselves.

«قوانین پورسانت» leads to the program's settings; every name leads to the customer's page, whose «زیرمجموعه‌گیری» card
says the same of one customer.

## Watch out for

- **Switch it on before you share links**: someone who arrives while it is off is never made a referral later.
- **A start gift paid on joining** is the easiest to farm with made-up accounts; «با اولین خرید» asks a real purchase first.
- **A cleared alert gives back nothing**: the gifts held back while it stood stay so.
- **The renewal rate is its own setting**: a shop that set «درصد پورسانت» before it existed has renewals earning the
  default 10% until it says otherwise.
- **Two accounts merged** on the website may give the account that stays the other's referrer, when it had none; a
  commission already earned stays with whoever earned it.
