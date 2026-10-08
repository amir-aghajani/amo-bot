# Referrals

The referral program («زیرمجموعه‌گیری») pays a customer a share of what the customers they bring pay. Every shop runs its
own: the owner's, and each agent's in their bot.

## Switching it on

«تنظیمات ربات › زیرمجموعه‌گیری» — off until switched on:

- «زیرمجموعه‌گیری فعال باشد» — the program. Off, the menu's button is taken away, links make nobody a referral and no
  payment earns a commission; switched on again, the button comes back, and the referrals and commissions of before are
  where they were.
- «درصد پورسانت» — 1 to 100 percent of each payment, 10 by default.
- «فقط اولین پرداخت هر زیرمجموعه» — only each referral's first payment earns, instead of every one.

The menu's «👥 زیرمجموعه‌گیری» button must be on the bot's menu for customers to find their link: the settings section and
the referrals page warn while the program runs and the menu lacks it, with a link to «کیبوردها».

## How a customer brings others

In the bot, «👥 زیرمجموعه‌گیری» shows their invite link — `https://t.me/<bot>?start=ref_<code>`, a tap copies it —, the
terms with the share, how many they brought and what it earned them, and «📤 ارسال لینک برای دوستان» to share it. While
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
- **One level**: a referral's referrals earn the referrer nothing.

## The page

«فروش › زیرمجموعه‌گیری», in both panels: the program's figures — «معرف‌ها», «زیرمجموعه‌ها», «پورسانت‌های پرداخت‌شده» and
«جمع پورسانت‌ها» — and three sections, each searchable and sorted from its headers:

- **«معرف‌ها»** — the customers who brought others, most first: how many («n نفر» — a link to their referrals), what it
  earned them, their latest referral.
- **«زیرمجموعه‌ها»** — who brought whom, newest first, with what each one's payments earned their referrer; opened from a
  referrer, it is narrowed to theirs («معرف … ✕»).
- **«پورسانت‌ها»** — every commission, newest first: the amount and the share it was earned at, who earned it, whose
  payment it was (a link to the payment) and when. `#12` finds payment 12's commission.

«قوانین پورسانت» leads to the program's settings; every name leads to the customer's page, whose «زیرمجموعه‌گیری» card
says the same of one customer.

## Watch out for

- **Switch it on before you share links**: someone who arrives while it is off is never made a referral later.
- **Two accounts merged** on the website may give the account that stays the other's referrer, when it had none; a
  commission already earned stays with whoever earned it.
