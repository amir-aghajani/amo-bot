[فارسی](fa/Gift-Codes-And-Lucky-Wheel.md)

# Gift codes and the lucky wheel

Two ways a shop gives its customers something: gift codes («کد هدیه») — one-time codes worth Toman in the wallet or a
free service, which the owner makes in batches and hands out —, and the lucky wheel («گردونه شانس») — a wheel the
customer spins in the bot every so often for a prize. Both are under «تخفیف و جوایز» in either panel, and every shop runs
its own: the owner's, and each agent's in their bot.

What either gives reaches the customer the shop's one way for prizes and gifts:

- **Toman** is a line of their wallet's ledger.
- **Traffic or days** go to their newest running service that takes them — never a free test, nor one the days would
  end after [the last day](Services.md#watch-out-for). In an agent's shop, the GB come out of the agent's traffic.
- **A plan's service** is an order at no price, settled by the shop and delivered as any order: sent to the customer
  like a bought one, and never counted as their first purchase. Once its order is made, the service counts as given,
  even when its delivery fails (its panel down, a fault of the shop's own): the order waits under «نیازمند رسیدگی» for
  support's retry ([Orders and payments](Orders-And-Payments.md#orders)), which sends the service once it works.

## Gift codes

### Switching them on

The «کدهای هدیه» page opens with the card «کد هدیه در ربات و وب‌سایت»: «استفاده از کد هدیه فعال باشد» — off until switched
on. While it is on, the bot's menu shows «🎁 کد هدیه» and the shop's website takes codes; off, the button is taken away and
nobody can use a code (they stay as they are). Codes may be made either way.

The «🎁 کد هدیه» button must be on the bot's menu for customers to find it. A fresh shop's menu has it on its last row
beside «🎡 گردونه شانس»; a menu saved before has not — the page warns while the switch is on and the menu lacks it, with
a link to «کیبوردها».

### Making a batch

«ساخت کدها» opens the form:

- what each code is worth: «شارژ کیف پول» — «مبلغ هر کد (تومان)», a whole number of Toman from 1,000 to 500,000,000 —
  or «سرویس رایگان» — one of the shop's plans (a plan off sale too, kept for gifts);
- «تعداد کدها» — 1 to 500;
- «نام سری» — the label the batch is listed, copied and deleted by («نوروز ۱۴۰۵»), 64 characters at most;
- «پیشوند» (optional) — what every code starts with: Latin letters and digits, 12 at most («NOWRUZ»);
- «مهلت استفاده (روز)» (optional) — how many days from today they may be taken: they work until the end of that day on
  the shop's clock (0: today only), and the form says which day that is. The last day may be 17 January 2038
  («۲۸ دی ۱۴۱۶») at most, the last the shop can keep. Blank, they never end.

The codes made are shown at once, one a line, with «کپی همه کدها». Each is the prefix and ten random characters —
capital letters and digits without the ones that look alike (0/O, 1/I/L) —, unique in the shop.

### The list

Every code, the newest first: the code, what it is worth, its batch, where it stands («استفاده‌نشده», «استفاده‌شده»,
«منقضی‌شده» — the tabs), who took it and when, and the order a plan's service was given by. Search by a piece of the code
or by the customer who took it; «سری» narrows the list to one batch, and then shows the batch's counts with «کپی کدهای
استفاده‌نشده» (the codes still to be taken, to hand out) and «حذف کدهای استفاده‌نشده». A code nobody took — still to be
taken, or past its last day — is deleted from its row's menu; a code taken stays, for the record.

### How a customer uses a code

In the bot, «🎁 کد هدیه» asks for the code, and the next message is it — typed as a person types it: any case, dashes and
spaces anywhere, Persian digits. Toman are confirmed with the new balance; a plan's service is sent as a bought one
is, then confirmed. A code refused gets its reason and another one is awaited, until the customer goes elsewhere. On the
website, the same through the Store API ([gift codes](Store-API-After-Buying.md#gift-codes)).

A code is taken once: of two customers — or two taps — at once, one gets it. It is refused when:

- it is not the shop's (another shop's code is none of this one's);
- it was taken already, or is past its last day;
- it is worth a plan no server can deliver right now, or one whose term would end the service after
  [the last day](Services.md#watch-out-for) — or any plan, in an agent's shop whose selling is suspended
  ([Agency](Agency.md#the-terms-of-selling-an-end-and-a-monthly-minimum)), which the refusal says. Such a code stays
  the customer's, to try again later.

A plan's service whose delivery fails after the code was taken is given all the same (above): the code stays used, and
the service comes with support's retry.

Every try counts until it names one of the shop's codes, so codes are not guessed:

- a customer who sends 10 wrong codes in an hour waits;
- on the website, one IP address (for IPv6, one /64 network) waits after 30 wrong codes in an hour, whichever customers
  sent them — many customers can share one address on a mobile network.

## The lucky wheel

### Its rules

«گردونه شانس › تنظیمات» — off until switched on:

- «گردونه شانس فعال باشد» — while on, the bot's menu shows «🎡 گردونه شانس»; off, the button is taken away and the prizes
  and spins stay as they are.
- «فاصله دو چرخش (ساعت)» — 1 to 720, 24 by default: a customer spins once in that many hours.
- «هزینه هر چرخش (تومان)» — taken from the wallet for each spin, 0 (free) by default. An agent's credit does not pay it.
- «فقط مشتری‌هایی که خرید کرده‌اند» — only customers who have bought something with a price and kept it may spin (a
  free plan, a service given free or a purchase refunded since is no purchase); the bot's admins may always.

While the wheel is off, «فاصله دو چرخش» and «هزینه هر چرخش» are held as they are saved — unless you changed one: then it
stays open, since the save still checks it.

### Its prizes

«جوایز» lists the wheel's slices in the order it shows them (▲▼ to move one), each with what it gives, its weight, the
chance that weight makes among the enabled slices, and its switch. «افزودن جایزه»:

- «متن روی گردونه» — what the customer sees, 32 characters at most;
- «جایزه» — «شارژ کیف پول» (1,000 to 500,000,000 Toman), «حجم» (up to 10,000 GB, decimals too), «زمان» (1 to 3650
  days), «سرویس رایگان» (a plan), or «پوچ» — nothing, so not every spin wins;
- «وزن» — 1 to 10,000: a slice's chance is its weight over the enabled slices' together.

At the moment of a spin, a slice that cannot reach that customer is left out and its chance shared among the rest:
traffic or days for a customer without a running service that takes them (or whose server the shop is leaving alone
after a failure; traffic in an agent's shop beyond the agent's traffic; days that would end the service after
[the last day](Services.md#watch-out-for)), a plan no server delivers now — and in an
agent's shop whose selling is suspended, every slice but Toman and «پوچ». A slice deleted leaves the spins that landed
on it as they were.

### A spin

«🎡 گردونه شانس» shows the prizes, what a spin costs and how often one may be, and «🎡 بچرخان» — or, before the customer's
turn, when it is. A spin answers at once, in place of the screen: the prizes, the one won marked «👉», and under them
what came of it:

- what was won and where it went («به سرویس ali_1 اضافه شد», the wallet's balance), or «پوچ»;
- or, when the prize could not be given right then — traffic or days on a server out of reach, say —, why. The cost is
  given back, the spin does not count, and the customer may spin again.

A plan's service whose delivery fails is given all the same ([above](#gift-codes-and-the-lucky-wheel)): the spin counts,
its cost is not given back, and the service comes with support's retry. A short wallet is offered the top-up; two taps
make one spin.

Every word of both screens is a bot text the admin may reword («متن‌های ربات», the groups «کد هدیه» and «گردونه شانس»).

### The spins

«چرخش‌ها» lists every spin, the newest first: the customer, the prize as it was, and «داده شد» — with the order or the
service it went to — or «داده نشد» and why. Search by the customer.
