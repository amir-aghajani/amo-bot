# Gift codes and the lucky wheel

Two ways a shop gives its customers something: gift codes («کد هدیه») — one-time codes worth Toman in the wallet or a
free service, which the owner makes in batches and hands out —, and the lucky wheel («گردونه شانس») — a wheel the
customer spins in the bot every so often for a prize. Both are under «تخفیف و جوایز» in either panel, and every shop runs
its own: the owner's, and each agent's in their bot.

What either gives reaches the customer the shop's one way for prizes and gifts: Toman is a line of their wallet's
ledger; traffic or days go to their newest running service that takes them — never a free test — (in an agent's shop,
the GB come out of the agent's traffic); a plan's service is an order at no price, settled by the shop and delivered
as any order — sent to the customer like a bought one, and never counted as their first purchase.

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

- what each code is worth — «شارژ کیف پول» (a whole number of Toman, 1,000 or more) or «سرویس رایگان» (one of the shop's
  plans — a plan off sale too, kept for gifts);
- «تعداد کدها» — 1 to 500;
- «نام سری» — the label the batch is listed, copied and deleted by («نوروز ۱۴۰۵»);
- «پیشوند» (optional) — what every code starts with: Latin letters and digits, 12 at most («NOWRUZ»);
- «مهلت استفاده (روز)» (optional) — how many days from today they may be taken; blank, they never end.

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

A code is taken once: of two customers — or two taps — at once, one gets it. It is refused when it is not the shop's
(another shop's code is none of this one's), taken, past its last day, or worth a plan no server can deliver right now
— or any plan, in an agent's shop whose selling is suspended ([Agency](Agency.md#the-terms-of-selling-an-end-and-a-monthly-minimum)),
which it says —; then it stays the customer's to try again later. Every try counts until it names one of the shop's codes: a customer
who sends 10 wrong codes in an hour waits (30 for a website's address network), so codes are not guessed.

## The lucky wheel

### Its rules

«گردونه شانس › تنظیمات» — off until switched on:

- «گردونه شانس فعال باشد» — while on, the bot's menu shows «🎡 گردونه شانس»; off, the button is taken away and the prizes
  and spins stay as they are.
- «فاصله دو چرخش (ساعت)» — 1 to 720, 24 by default: a customer spins once in that many hours.
- «هزینه هر چرخش (تومان)» — taken from the wallet for each spin, 0 (free) by default. An agent's credit does not pay it.
- «فقط مشتری‌هایی که خرید کرده‌اند» — only customers who have bought something with a price may spin (a free plan or a
  service given free is no purchase); the bot's admins may always.

### Its prizes

«جوایز» lists the wheel's slices in the order it shows them (▲▼ to move one), each with what it gives, its weight, the
chance that weight makes among the enabled slices, and its switch. «افزودن جایزه»:

- «متن روی گردونه» — what the customer sees, 32 characters at most;
- «جایزه» — «شارژ کیف پول» (Toman), «حجم» (GB, decimals too), «زمان» (days), «سرویس رایگان» (a plan), or «پوچ» —
  nothing, so not every spin wins;
- «وزن» — 1 to 10,000: a slice's chance is its weight over the enabled slices' together.

At the moment of a spin, a slice that cannot reach that customer is left out and its chance shared among the rest:
traffic or days for a customer without a running service that takes them (or whose server the shop is leaving alone
after a failure; traffic in an agent's shop beyond the agent's traffic), a plan no server delivers now — and in an
agent's shop whose selling is suspended, every slice but Toman and «پوچ». A slice deleted leaves the spins that landed
on it as they were.

### A spin

«🎡 گردونه شانس» shows the prizes, what a spin costs and how often one may be, and «🎡 بچرخان» — or, before the customer's
turn, when it is. A spin answers at once, in place of the screen: the prizes, the one won marked «👉», and under them
what came of it: what was won and where it went («به سرویس ali_1 اضافه شد», the wallet's balance), «پوچ», or —
when the prize could not be given right then, a server out of reach — why, the cost given back; such a spin does not
count, and the customer may spin again. A short wallet is offered the top-up; two taps make one spin.

Every word of both screens is a bot text the admin may reword («متن‌های ربات», the groups «کد هدیه» and «گردونه شانس»).

### The spins

«چرخش‌ها» lists every spin, the newest first: the customer, the prize as it was, and «داده شد» — with the order or the
service it went to — or «داده نشد» and why. Search by the customer.
