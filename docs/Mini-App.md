[فارسی](fa/Mini-App.md)

# Mini app

The shop's Telegram mini app is the customer side of the shop inside Telegram: a page the bot opens over the chat, where
a customer buys, pays, manages their services and wallet, and writes to support without leaving Telegram. It is the
same shop as the bot and the website — the same account, services, wallet, prices and rules —, a third door onto them:
what a customer does in one, the other two show. AmoBot ships it ready: there is nothing to build or host, only to
switch on. «مینی‌اپ تلگرام», in your panel and an agent's alike, after «تنظیمات وب‌سایت» in the settings column, sets it
up — every shop has its own — and shows it as a customer sees it ([Preview](#preview)).

## What customers do in it

Each part shows only while the shop offers it, as the bot's buttons do.

- **Home** — a hello under the shop's logo and name, a bell with the unread notices, the running services as cards
  (a ring for the traffic left and one for the days), the wallet with a quick top-up, banners — the timed sales with the
  time each has left, the shop's announcement, the lucky wheel, the nightly draw and invitations while they run — and
  shortcuts to every part of the app. While the shop takes no orders (the bot switched off, an agent's selling
  suspended) it says so; services and the wallet stay open.
- **Buying** — the plans the shop can deliver now, by category, narrowed by term or location; each plan's card wears
  its label («پرفروش‌ترین», «پیشنهاد ما», «VIP», [Plans](Plans.md#a-plan)) and the customer's own price, a sale's before
  it struck through. The checkout takes the location, the service's name where the shop lets buyers choose one, a
  discount code and the way to pay. A service the customer builds ([Custom services](Custom-Services.md)) and a free
  test account ([Test accounts](Test-Accounts.md)) are beside the plans.
- **Paying** — the shop's own ways, as in the bot: the wallet, card-to-card — the card and the amount to copy (the amount
  in Rial), then a photo of the receipt sent from the phone — or one of the shop's online gateways, whose page opens in
  Telegram and whose outcome the app follows by itself ([Payment methods](Payment-Methods.md)). The mini app adds no way
  to pay.
- **Services** — each service's page: its usage, end and devices, its subscription link to copy and the shop's QR card
  (enlarged with a tap, kept with a long press), and «اتصال سریع», which adds the link to v2rayNG, Happ, Hiddify or
  Streisand with one tap — Telegram opens only web addresses, so the tap goes through a small page of the shop's that
  hands the link to the app — and gives v2rayN's steps with the link to copy (as for any app that did not open).
  Then renewing — early too, within the shop's renewal window —, extra traffic, time and devices, «تمدید خودکار», a new
  link, the customer's own name for the service ([Services](Services.md#a-customers-own-name-for-a-service)), handing it
  to another customer, a ticket about it, and adding a service by its link ([Services added by their
  link](Service-Claims.md)).
- **Wallet** — the balance, a top-up, the history of the wallet and of the orders — each order's invoice, which
  «ذخیره تصویر» draws as a picture to keep —, gift codes, and sending money to another customer
  ([Bot settings](Bot-Settings.md#wallet-transfers)).
- **The club** («باشگاه مشتریان») — the loyalty tier and how far the next one is, cashback, the lucky wheel (the shop
  draws where it stops; the app only turns it there), the nightly draw — the time left, the customer's own entries, the
  last winners —, and the invite link, copied or sent to a chat through Telegram's share sheet
  ([Loyalty, cashback and draws](Loyalty-Cashback-And-Draws.md), [Gift codes and the lucky
  wheel](Gift-Codes-And-Lucky-Wheel.md), [Referrals](Referrals.md)).
- **Support** — the customer's tickets as a chat: a new one about a service or none, with a picture if they have one;
  their own messages show one tick once sent and two once support has read them
  ([Support tickets](Support-Tickets.md#read-ticks)); a closed ticket is rated with one to five stars.
- **The tutorial** — the bot's «آموزش» text as you worded it ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md)),
  then short guides of the app's own to adding the link in v2rayNG, Happ, Streisand and v2rayN.
- **Reviews** — the approved reviews with their average, and a review of their own, which waits for your approval, while
  the app takes them ([below](#appearance)).
- **The account** («حساب من») — the customer's card; the ways their account signs in: their Telegram, always, an email
  they add with a code sent to it (when the address is another account's, the app explains a merge before offering
  it), and Google, which only the website adds — Google refuses to sign in inside Telegram —; «امنیت»: confirming with
  their fingerprint or face («تایید با اثر انگشت», on a phone that has it), a password once they have an email, and the
  devices signed in, each of which they may sign out. While the shop asks every customer for their number («تایید شماره
  موبایل», [Bot settings](Bot-Settings.md#general)) and theirs is not in, «ثبت شماره تلفن»: Telegram sends their own
  number to the bot, which records it as if they had tapped «📱 ارسال شماره موبایل» there (an older Telegram opens the
  bot instead).

## How it signs customers in

There is no password and no sign-in screen. Telegram hands the app, as it opens, a proof of who the customer is, signed
with the shop's bot's token; the shop checks it and signs the customer in to their account — the bot's own, with their
services and wallet — or registers a newcomer as the bot does. The app keeps its session on the device and goes on with
it the next time it opens. Its sessions and the website's are one list: the devices under «امنیت» show both.

What moves a service or money away, or changes how the account signs in — handing a service on, sending money, adding
an email, setting a password, switching the fingerprint on — needs a recent sign-in, so a leaked session alone gives
nothing away. The app proves it by itself for 15 minutes after it was opened; after that, by the customer's fingerprint
or face, once they have switched it on; else the app asks them to close it and open it again from the bot.

## Switching it on

Telegram opens a mini app only from an `https://` address, and the app lives on the shop's own: the shop's «آدرس سایت»
(`APP_URL`, [Panel settings](Panel-Settings.md#the-app)) must begin with `https://` before the app can be switched on.
While it does not, the page says so — and, in your panel, links the setting; an agent's says the owner sets it.

### Connection

«اتصال», card «مینی‌اپ فروشگاه»:

- **«مینی‌اپ روشن باشد»** (off) — off, the app's address shows nothing but that the app is off, and the bot offers no
  button for it. Held while the address is not https. One already on stays on if the address stops being https, but
  Telegram no longer opens it and the bot's buttons for it go.
- **«دکمه منوی ربات»** (on) — the button beside the message box in the bot's chat opens the app while it is on.
- **«متن دکمه منو»** — that button's words, 32 characters at most; blank, «فروشگاه».

Card **«آدرس مینی‌اپ»**: the app's address — `<APP_URL>/app/<key>/`, its key the app's own, not the website's — with
«کپی», the shop's bot, and, on https, **«تنظیم دوباره دکمه منو»** while the app and its menu button are on (it sets the
button again with nothing changed: [watch out for](#watch-out-for)) and how to make the app the bot's main one in
@BotFather ([below](#the-bots-buttons)). Opened in a browser, the address says to open it from the bot, with a button
that does.

**Each save sets the bot's menu button again** in Telegram: the app's, with its words, while the app is on and «دکمه
منوی ربات» is too; otherwise Telegram's own button comes back — but only in place of a button that opens this app: a
button you set yourself in @BotFather (the commands, a page of your own) stays as it is. When Telegram does not take the
button, the save still stands and a note above the cards says why:

- «… دکمه منوی ربات تنظیم نشد: تلگرام در دسترس نبود؛ کمی بعد دوباره ذخیره کنید.» — the shop could not reach Telegram,
  or Telegram asked it to wait;
- «… دکمه منوی ربات تنظیم نشد: تلگرام آن را نپذیرفت؛ توکن ربات و آدرس برنامه را بررسی کنید و دوباره ذخیره کنید.» —
  Telegram refused it: the bot's token, or an address it will not open.

### Appearance

«ظاهر», four cards:

- **«ظاهر مینی‌اپ»** — «نام مینی‌اپ» (64 characters; blank, the shop's name), «رنگ اصلی» (the buttons' and highlights'
  colour, `#rrggbb`; blank — «رنگ تلگرام» — each customer's own Telegram button colour; the rest follows their
  Telegram's theme, light or dark) and **«تمام‌صفحه روی گوشی»** (on: the app takes the whole screen of a phone; off, it
  opens as a sheet from the bottom).
- **«لوگو»** — the shop's logo in the app: JPG, PNG or WebP, 2 MB at most, a picture larger than 512 pixels made smaller.
  It applies at once; «حذف لوگو» asks first. Without one, the app shows the first letter of its name on its colour.
- **«اطلاعیه»** — «متن اطلاعیه», a word to every customer on the home screen (500 characters; blank, none), and «لینک
  اطلاعیه», where a tap on it leads — an `https://` address only, such as `https://t.me/channel`.
- **«نظرات مشتریان در مینی‌اپ»** — **«نظرات در مینی‌اپ»** (off): on, the app shows the reviews you approved on «نظرات»
  and takes new ones ([Reviews](Reviews.md)); off, neither. It is the app's own switch: the website has its own. A
  customer in the app is always signed in, so each review comes from a customer.

## Preview

«پیش‌نمایش», the page's third section, shows the app as one of the shop's customers sees it, on a phone drawn as
Telegram draws it — before you switch it on, too: it works while the app is off, and the customers still see nothing.

- **«مشتری»** — whose app to see: the newest customers are listed, and a search finds one by name, @username, Telegram
  id or phone (a banned customer is not offered). The app opens with their account — their services, wallet, tickets —
  as it opens in their Telegram.
- **Only to look** — nothing done in the preview goes through: no order, payment, ticket, spin or change of any kind,
  not even a notice marked read. The customer is told nothing, and the preview is not one of their devices.
- **30 minutes** — each preview lasts 30 minutes, and the card says until when. Then a cover over the phone says it
  ended, with **«باز کردن دوباره»**, which opens a new one; the same button on the card does it at any time.
- **«تم تلگرام»** — Telegram's own light or dark theme (the panel's to start with), and **«اندازه گوشی»** — a small
  phone or a large one. The app opens again with each change, and with each save on «اتصال» and «ظاهر», so what you
  change there shows at once.

What Telegram draws around the app is drawn too: with «تمام‌صفحه روی گوشی» on, the app takes the whole screen as
Telegram does, its header giving way to the floating «بستن» and ⋯ over the app's top; the main button, the back button,
the app's questions and the fingerprint's prompt (acted out: a button stands in for the touch) are Telegram's own. A
link the app opens goes to a new tab; a Telegram link is only named.

## The bot's buttons

Three ways open the app from Telegram:

- **The menu button**, beside the message box — above.
- **«📱 اپلیکیشن فروشگاه» in the bot's menu** — the action «اپلیکیشن» of «ربات › کیبوردها»
  ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md#the-mini-apps-button)). It shows only while the app is on and
  on https. With «دکمه‌های شیشه‌ای» the button opens the app itself. On «کیبورد پایین صفحه» it opens a short screen with
  «📱 باز کردن اپلیکیشن» under it, which opens the app: Telegram signs no one in to an app opened from a keyboard's
  button. The shop's own menu has it in its second row; **a menu you saved yourself has it only once you add it**.
  Its words are bot texts you may reword: «اپلیکیشن فروشگاه», «دکمه باز کردن اپلیکیشن», and «اپلیکیشن در دسترس نیست»
  for a tap after the app was switched off.
- **The bot's profile** — optional: in @BotFather, pick the shop's bot › *Bot Settings › Configure Mini App*, switch it
  on and send the app's address as its *Main App URL* (the card shows it). The bot's profile then shows a button that
  opens the app.

## Agents' shops

Every agent's shop has a mini app of its own — its own address and key, its own settings on the same page of their
panel, and the menu button of their own bot — on the shop's address (`APP_URL`), which the owner sets. An agent makes
the app their bot's main one in their own @BotFather. Their app closes while their agency is ended.

## When something is wrong

- **The switch is held** — «آدرس سایت» is not https. Set it on «تنظیمات پنل › برنامه» first.
- **A note says the menu button is not set** — the save stood; read the note ([connection](#connection)), then save
  again or press «تنظیم دوباره دکمه منو». The «📱 اپلیکیشن فروشگاه» button and the bot's profile do not depend on it.
- **The customer sees «این صفحه را از ربات باز کنید»** — they opened the address in a browser, where Telegram signs no
  one in. The app works only opened from the bot.
- **The app says «مینی‌اپ این فروشگاه فعلا خاموش است…»** — it is switched off (or, in an agent's shop, the agency
  ended); switched on again, it opens with the next launch.
- **The preview says «پیش‌نمایش باز نشد»** — the reason is under the customer: a customer who was banned meanwhile, say.
  Pick another.
- **«ورود انجام نشد»** — Telegram's proof did not hold: the app was opened more than a day before it signed in, or from
  a bot other than the one whose token the shop has («تنظیمات پنل › ربات تلگرام»). Closing the app and opening it again
  from the bot signs in afresh.
- **A customer in Iran whose app stays blank** — the app loads nothing from telegram.org: its code is bundled, and its
  scripts — Telegram's own among them —, fonts and pictures come from the shop's own address, with the API beside them.
  The one thing from elsewhere is the customer's Telegram profile photo, from t.me: where it does not load, their
  initials show in its place and the app works the same. If the chat works but the app does not, their network does not
  reach the shop's address — as it would not reach the website either.

## Watch out for

- **The bot's menu button keeps the address of the last save.** After the shop moves to another «آدرس سایت», or the bot
  gets a new token, press «تنظیم دوباره دکمه منو» on the «آدرس مینی‌اپ» card (it sets the button again with nothing
  changed), and give @BotFather the new address if the app is the bot's main one. The «📱 اپلیکیشن فروشگاه» button
  follows the address by itself.
- **A saved menu of your own lacks «📱 اپلیکیشن فروشگاه»** until you add it.
- **Reviews in the app have their own switch** — «نظرات در مینی‌اپ» is off until you turn it on.

What a website's developer should know about the app's door to the Store API:
[Store API: two doors](Store-API.md#two-doors).
