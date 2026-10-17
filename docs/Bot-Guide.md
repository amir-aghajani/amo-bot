[فارسی](fa/Bot-Guide.md)

# Bot guide

What the bot offers a customer and its admins. Every shop's bot — the main one and each agent's — works the same way, in
its own shop, with its own wording: you (or the agent) reword every text a customer reads and arrange the menu ([Bot texts
and keyboards](Bot-Texts-And-Keyboards.md)). The labels below are the shop's defaults. Buttons that share a row read
right to left, as Persian does: the first one is on the right.

## Before the menu

`/start` (or `/menu`) greets the customer by their Telegram name, with the menu. On the way, in this order:

1. **A banned customer** gets one answer to everything: their account is blocked ([Customers](Customers.md#banning)).
2. **The bot switched off** — «⛔️ ربات فعلا غیرفعال است…», the menu taken away ([Bot settings](Bot-Settings.md#general)).
3. **The phone**, when the shop asks it — «📱 ارسال شماره موبایل», their own Telegram number, once.
4. **The channels**, when the shop asks them — a button per channel to join, then «✅ عضو شدم».

The bot's admins pass 2 to 4. A first `/start` from an invite link makes the newcomer the link owner's referral, before
any of these ([Referrals](Referrals.md)). A `/start` from the test link (`?start=usertest`) that 2 to 4 hold back waits
in the chat for an hour: the test screen opens once the customer gets through — the number sent, «✅ عضو شدم», or a
`/start` or `/menu` in that hour ([a free test](#a-free-test)).

Anything the bot does not understand — a word typed, a button of a screen long gone — is answered «متوجه نشدم. لطفا از
منوی زیر استفاده کنید.» with the menu. A chat that sends more than 20 updates in ten seconds is not answered while it
keeps on.

## For customers

| Button | What it opens |
|---|---|
| «🔐 خرید اشتراک» | [Buying](#buying) |
| «🧪 اکانت تست» | While the shop gives tests: the servers giving one now, each with its traffic and term; a tap makes one ([a free test](#a-free-test)) |
| «🛍️ سرویس‌های من» | The running services (and those waiting for their first connection), newest first, five a page; each opens [its screen](#a-services-screen). While the shop takes services by their link, «➕ افزودن سرویس» under them ([adding a service by its link](#adding-a-service-by-its-link)) |
| «♻️ تمدید سرویس» | The services that may be renewed — running or ended, on a plan still there, or without a plan (added by their link, or built) to pick what to renew them on —, five a page; each opens its renewal ([renewing](#renewing)) |
| «💰 کیف پول + شارژ» | The balance («… تومان بدهی» for a debt; an agent's credit), the loyalty tier while the shop has tiers, the latest five lines, «➕ افزایش موجودی» ([the wallet](#the-wallet)) and, while the shop offers it, «💸 انتقال موجودی» on its left ([sending money](#sending-money-to-another-customer)) |
| «👥 زیرمجموعه‌گیری» | The invite link (a tap copies it), the terms (the start gift and the plan reward while they are on), what it brought, «📤 ارسال لینک برای دوستان» — while the program runs ([Referrals](Referrals.md)) |
| «📚 آموزش» | A short guide to connecting — the shop's, which you may reword |
| «☎️ پشتیبانی» | Your contact, and the tickets ([support](#support)) |
| «🤝 نمایندگی» | The main bot only, while the program runs: the agency's terms and «📝 درخواست نمایندگی»; an agent's account — with their agency's end, this month's traffic bought against their monthly minimum, and why their bot sells nothing when it does not ([Agency](Agency.md)) |
| «🎁 کد هدیه» | While gift codes are on: a code typed is redeemed — Toman into the wallet, or a plan's service ([Gift codes](Gift-Codes-And-Lucky-Wheel.md#how-a-customer-uses-a-code)) |
| «🎡 گردونه شانس» | While the wheel is on: its prizes, what a spin costs, and «🎡 بچرخان» — or when the next spin is ([The lucky wheel](Gift-Codes-And-Lucky-Wheel.md#a-spin)) |

### Amounts typed

Wherever the bot waits for a number — the GB or days of a service the customer builds, extra traffic, time or devices,
a wallet top-up, the amount of a transfer, an agent's traffic — the customer may type it as they would say it:

- Persian or Latin digits, with or without thousands separators: «۱۵۰٬۰۰۰», `150,000`, `150 000`;
- «هزار» after the number, for money and GB: «۵۰ هزار» is 50,000 Toman, «۲ هزار گیگ» 2,000 GB (an agent buys traffic
  by the thousand); «میلیون» for money alone: «۲ میلیون» is 2,000,000 Toman. Days and devices are digits alone;
- a word of the step's own unit at the end: Toman («تومان», «تومن», «ت»), GB («گیگ», «گیگابایت», `GB`), days («روز»),
  devices («کاربر», «نفر», «دستگاه»): «۵۰ هزار تومان», «۲۰ گیگ», «۷ روز», «۲ کاربر».

Any other word — «ریال», «ساعت», `50k`, or another step's unit («۲۰ روز» where GB are awaited) — makes it no amount: the
step says what it takes, and waits for another.

### Buying

1. **The categories** — when the shop has an active one; then «سایر پلن‌ها» for the rest. Otherwise the plans at once.
2. **The plans** that can be delivered today — in an agent's bot, those their traffic covers, on the servers and of the
   plans the main shop leaves to it —, each a button of its name, traffic, term and price (the text «دکمه پلن»), in the
   colour and the row width its plan's form gives it.
3. **A plan's details** and its locations («🌍 …»): the servers that can sell it now, each in its server's colour and
   row width.

   A list longer than one keyboard holds — more than 90 categories, plans or locations — is paged: «بعدی» and «قبلی»
   under it, and «صفحه ۲ از ۳» under its title. «بازگشت» from a plan or a location leads to the list's first page.
4. **The checkout** — what is bought and its price, and a button per way to pay that takes that price (its least and most
   amount, a card method's cards with room left today: [Payment methods](Payment-Methods.md#the-least-and-most-amount);
   with none left, «برای این مبلغ روش پرداختی نیست…» under it). A free plan (price `0`) has one «🎁 دریافت رایگان»
   button instead, which delivers it at once, nothing taken. The plan's note comes under it all when it has one
   («📌 …», [Plans](Plans.md#a-plan)). **Nothing is ordered until a way to pay is picked**; there is no cancel — a
   checkout left alone orders nothing.

   While the shop lets buyers name their service ([Bot settings](Bot-Settings.md#service-usernames)), a line says what it
   will be called — «ترتیبی», the shop's pattern, until they choose — over a button per way: «🔢 ترتیبی», «🎲 رندوم» (a
   name drawn at random, another at each tap) and «✏️ دلخواه» (the name typed next: 3 to 32 English letters, digits, `_`
   and `-`, Persian digits fine; one refused says why, and another is awaited). The name is kept for that checkout,
   through a discount code and back from a card's screen, and the service is delivered with it — or with the pattern's,
   should a service on that server have taken it meanwhile.

   A plan whose term would end the service after 17 January 2038 («۲۸ دی ۱۴۱۶») — the last day the shop's dates hold —
   orders nothing: a way to pay tapped turns the checkout into «تاریخ پایان سرویس نمی‌تواند بعد از ۲۸ دی ۱۴۱۶ باشد؛ مدت
   کوتاه‌تری انتخاب کنید.» with «بازگشت».
5. **The way to pay** picked:
   - **The wallet** pays at once, and only the price the checkout showed: the checkout gives way to the service — a QR
     card of its subscription link, or the link as text. A checkout whose price changed since it was drawn (a sale
     over, a first purchase paid elsewhere) is drawn again at its new price first, and nothing is taken until the
     customer taps again; so is a «🎁 دریافت رایگان» whose plan has a price now, whatever way to pay its button sends. A wallet
     short of the price says how much is missing, with «➕ افزایش موجودی».
   - **A card** — see [paying by card](#paying-by-card).
   - **In support's private chat**, while the shop takes card payments there, a card shows no card: the order's number
     and amount, «به پیوی پشتیبانی پیام بدهید: …» with «💬 پیام به پشتیبانی» (when the contact is a handle or a link),
     what comes once support approves it, and the method's note under it all. The bot waits for no receipt; support
     approves the payment in the panel. A payment reminder of such a payment sends them there too, in place of «ارسال
     رسید» — and one of a payment shown a card asks for its receipt here, whatever the shop does now.
   - **An online gateway** — see [paying online](#paying-online).

An order left unpaid expires after 48 hours of nothing — except one whose receipt is with support, whose card payment
went to support's private chat, whose online page still takes money, or whose online payment is held for support and
still waits.

### Paying by card

The card's screen comes in place of the checkout: the amount, the card's number — one of the method's cards picked at
random, and always that one for this payment ([Payment methods](Payment-Methods.md#its-cards-and-their-rotation)) —, its
holder, its bank when you named one, and your words. Under them, «📋 کپی شماره کارت» and «📋 کپی مبلغ»; a tap on either
number in the text copies it too. The amount goes in Latin digits without separators, as a banking app takes it.

By default the message is protected — it cannot be forwarded, saved or screenshotted — and comes as a message of its own
in place of the checkout, which is deleted (one older than Telegram's 48 hours for deleting loses its buttons instead)
([Bot settings](Bot-Settings.md#card-to-card-payments)).

The customer transfers the money and **sends a picture of the receipt**:

- a photo, or a picture sent as a file (10 MB at most); its caption is their note;
- sent as an album, its first picture is the receipt and the others pass unanswered;
- words typed are answered with a reminder — «لطفا عکس رسید پرداخت را بفرستید. برای کار دیگری /menu را بفرستید.» —, and
  anything else that is no picture (a sticker, a voice, another file) with «فقط عکس رسید پذیرفته می‌شود…».

The bot says the receipt is with support — «حداکثر تا … دقیقه دیگر» when its method approves receipts by itself —, and
the verdict comes later as a reply to it ([Orders and payments](Orders-And-Payments.md#receipts)). While a receipt of
theirs waits for review, another receipt or another card payment is refused with «⏳ رسید سفارش #… هنوز در حال بررسی
پشتیبانی است» — the wallet still pays.

### Paying online

An online gateway ([CubePay](Payment-Methods.md#cubepay), [Zarinpal](Payment-Methods.md#zarinpal),
[AtlasPay](Payment-Methods.md#atlaspay), [AbanGateway](Payment-Methods.md#abangateway), [Plisio](Payment-Methods.md#plisio),
[AqayePardakht](Payment-Methods.md#aqayepardakht), [Variza](Payment-Methods.md#variza)) shows its page in place of the
checkout:

- **«💳 پرداخت آنلاین سفارش #…»** — the exact sum the page asks (a gateway may add a few Toman to the order's, to tell
  transfers apart; the fee you pass on to customers is said apart: «(شامل … کارمزد درگاه)»), until when the page takes
  money («⏳ مهلت پرداخت: تا …», on the shop's clock) and what comes once paid.
- **Its buttons** — «💳 پرداخت» (the gateway's page), «🔄 بررسی پرداخت» and «بازگشت» to the checkout. AqayePardakht's
  «💳 پرداخت» opens a page of the shop's first, whose «ورود به درگاه پرداخت» opens the bank's.
- **A card to show** — when the gateway gives one (CubePay's «🤖 نمایش کارت در ربات», AtlasPay's «نمایش مستقیم اطلاعات
  پرداخت», AbanGateway's «نمایش کارت در ربات و سایت»), the card — with its holder and its bank when the gateway gave them
  — and the exact sum come under the page («مبلغ باید دقیقا همین باشد، تا آخرین رقم»), with «📋 کپی مبلغ» and
  «📋 کپی شماره کارت», protected as a card transfer's message is.

**No receipt is sent, and none is taken.** The order is delivered once the gateway says it was paid — when it calls the
shop back, when the customer taps «🔄 بررسی پرداخت», or when the shop asks it every few minutes; Variza's word comes by
the webhook it signs. The customer gets the delivery as any approved payment's notice. Zarinpal, AbanGateway, Plisio,
AqayePardakht and Variza send the customer's browser back to a page of the shop's saying what came of it, with
«بازگشت به ربات».

**«🔄 بررسی پرداخت»** answers in a popup — the gateway is asked once in five seconds per payment, and a tap sooner is told
nothing came yet:

| Answer | When |
|---|---|
| «هنوز پرداختی ثبت نشده است…» | Nothing came yet |
| «پرداخت شما به درگاه رسیده و هنوز در حال بررسی است؛ دوباره پرداخت نکنید…» | Money came in that the gateway still reviews (a receipt at AtlasPay, part of a split AbanGateway sum) |
| «✅ پرداخت شما تایید شد…» | Paid: the delivery follows as its own message |
| «این پرداخت قبلا تایید شده است» | Paid before |
| «الان نمی‌شود از درگاه پرسید؛ اگر پرداخت کرده‌اید کمی بعد دوباره بزنید.» | The gateway cannot be asked now |
| «پرداخت شما برای بررسی به پشتیبانی رسید؛ دوباره پرداخت نکنید و برای پیگیری به پشتیبانی پیام بدهید.» | The payment is [held for support](Payment-Methods.md#a-payment-held-for-you) |

**What becomes of the page:**

- Answered paid, it turns into «✅ پرداخت آنلاین سفارش #… تایید شد.», its buttons and card gone.
- Its time run out, it turns into «⌛ مهلت پرداخت سفارش #… در صفحه درگاه تمام شد…» with «بازگشت» to the order's
  checkout, where the same way to pay opens a new page.
- Its order closed meanwhile (paid another way, cancelled, expired), it turns into «این سفارش دیگر قابل پرداخت نیست.».
- The checkout opens a new page whenever the old one has three minutes or less left, once the gateway has been asked
  about the old one (paid there, the order is delivered instead); until then it shows the same page again.

A gateway that will not open a page says «پرداخت انجام نشد: درگاه پرداخت الان در دسترس نیست…» with «بازگشت»; nothing is
paid, and its own reason is in the shop's log. An order whose payment is held for support is paid no other way while
that payment waits: paying it again, by any way, says the same «پرداخت شما برای بررسی به پشتیبانی رسید؛ …», with
«💬 پیام به پشتیبانی» and «بازگشت», and nothing is paid. Once support cancels that payment, it holds nothing more.

### An agent's bot whose selling is suspended

An agent's bot whose selling the main shop suspended
([Agency](Agency.md#the-terms-of-selling-an-end-and-a-monthly-minimum)) sells nothing new: «خرید اشتراک» says «🛑 فعلا
خرید، تمدید و اکانت تست در این ربات ممکن نیست…» (a text the agent may reword, a message's) in place of the plans. So do a
renewal, extra traffic, time or devices, a wallet top-up — whose «➕ افزایش موجودی» leaves «کیف پول» meanwhile —, a free
test and a checkout drawn before, each in place of the screen with «بازگشت». The lucky wheel draws only its Toman and
«پوچ» slices, and a gift code worth a service says it waits. Its customers' services keep working, a receipt sent for an
order made before is still reviewed, and what was paid is delivered.

### A service the customer builds

In the main bot, while «سرویس دلخواه» is on ([Custom services](Custom-Services.md)), «🛠 ساخت سرویس دلخواه» stands under
the plans (or the categories), even when no plan is on sale:

1. **The GB** — each gigabyte's price by its tiers, and a button per amount offered, two a row; any amount within the
   bounds may be typed instead ([amounts typed](#amounts-typed)). One outside them is refused, saying them, and another
   is awaited.
2. **The days** — the same, for the days; «بازگشت» to the GB.
3. **The server** — a button per server it is built on now; with only one, this step is skipped.
4. **The checkout** — the GB and their price, the days and theirs (each whole at the rate of its tier, the two added),
   the devices, the server and what is paid after the customer's discounts, with «🏷 کد تخفیف دارم» and the ways to pay.
   Then as any purchase: the service comes with no plan, named «سرویس دلخواه» where a plan's name would be.

A step tapped after the bounds changed shows that step again; once nothing may be built, the bot says so, with
«بازگشت» to the plans. A payment reminder's «پرداخت دوباره» opens the checkout of what was built, as it is priced now.

### First-purchase prices

A plan may have a price of its own for a customer's first purchase — «قیمت خرید اول» on its form
([Plans](Plans.md#first-purchase-price)): a discount on the first one, or `0` for a free first purchase. A customer who
has never bought a service in your shop pays it. A purchase of theirs paid and not given back counts as one (delivered,
being delivered, or failed and waiting for you); one refunded since does not — its customer pays the first-purchase
price again —, and neither does an order never paid, one cancelled unpaid, a service given free or a wallet top-up. Every plan is offered to everyone; only what it costs differs. Each shop counts its own: a customer of the main
bot is a newcomer in an agent's bot.

- **Until they have bought**, the plan is at that price on its button in «خرید اشتراک», in its details and at its
  checkout, and the details say under them «🎁 این قیمت ویژه اولین خرید شماست؛ قیمت عادی این پلن … است.» — a line you
  may reword, or empty to drop it ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md)). A free first purchase's
  checkout is one «🎁 دریافت رایگان» button.
- **Once they have**, every screen says the plan's price, and that is what they pay. A checkout drawn before at the
  first-purchase price is drawn again at the plan's price before the wallet pays it.
- **Two at once** — two purchases ordered at that price, or with a discount code for a first purchase only, before
  either was paid, say one by card waiting for its receipt while the wallet buys another —: the purchase paid first is
  their first purchase (of two paid in the same second, the one ordered first). The other fails as it is delivered, «این
  سفارش با قیمت خرید اول ثبت شده بود، ولی خرید دیگری از همین مشتری زودتر پرداخت شد؛ مبلغ آن را به مشتری برگردانید.»
  (for a code, «این سفارش با کد تخفیف خرید اول (…) ثبت شده بود، …»), for you to give its money back
  ([Orders and payments](Orders-And-Payments.md#payments)); its failure reaches the report group as any failed delivery.
  The customer is told why: «⚠️ پرداخت سفارش #… ثبت شد، ولی این سفارش با قیمت یا کد تخفیف خرید اول ثبت شده بود و خرید
  دیگری از شما زودتر پرداخت شد؛ پس دیگر خرید اول شما نبود و انجام نشد.» and «پشتیبانی مبلغ آن را با شما تسویه
  می‌کند.» — a text you may reword. One whose delivery failed and that you retry later is still their first purchase,
  whatever they bought meanwhile.
- **Its service renews at the plan's price**, as any — from the bot, or by itself with «تمدید خودکار».

The report group's sale says «🎁 قیمت خرید اول» beside what was paid, and the orders screen marks the order so.

### Agent prices

A plan may have a price for your agents and your colleagues — «قیمت نماینده» on its form
([Plans](Plans.md#agent-price)). An agent of the shop pays it, and so does every customer of a group you mark «همکار»
([Customers](Customers.md#groups)): the plan is at that price on its button, in its details — which say under them
«🤝 این قیمت ویژه نمایندگان و همکاران است؛ قیمت عادی این پلن … است.», a line you may reword or empty — and at its
checkout, and their services renew at it, by hand or by «تمدید خودکار». A colleague who has bought nothing yet and could
pay a first-purchase price too pays the lower. The report group's sale says «🤝 قیمت نماینده».

### A free test

«🧪 اکانت تست» on the menu, `/free`, or the shop's link `https://t.me/<bot>?start=usertest` (the greeting and the menu,
then the screen under them) opens the test screen: «🧪 اکانت تست رایگان», a line for each server giving a test now — its
traffic and term, «(از اولین اتصال)» when the term counts from the first connection — and a «🌍 …» button for each, in
its server's colour and row width as a purchase's locations are. More than 20 servers are paged, 20 a page, with «بعدی»
and «قبلی».

A tap makes the test at once and sends it as a bought service comes — a QR card of its link, or text —, with «📊 مشاهده
سرویس». A test that could not be sent still counts: the bot says so («⚠️ اکانت تست شما ساخته شد ولی ارسال آن ناموفق
بود؛ …») with the same button to open it. It is then one of their services, its plan named «اکانت تست»: no renewal, no
extra traffic, time or devices, no reminders. Once it ends, or if it is never connected, the shop removes it after a
while, without a word — its screen says when.

What a customer may take is the shop's rule — so many tests each, in all or within so many days, a cap a day, perhaps
for newcomers only ([Test accounts](Test-Accounts.md#the-quota)). A test refused says why, with «🔐 خرید اشتراک» under
it where buying is the way on; a quota used for now says when the next test is possible. A tap on a server that
stopped giving tests since the screen was drawn shows the screen again as it is now. A panel that fails to make one
says so («⚠️ ساخت اکانت تست الان ممکن نشد؛ …»), and nothing of the quota is spent. The bot's admins are held to the
switch alone, though their tests count toward the shop's cap a day.

### Adding a service by its link

While the main shop takes services by their link ([Services added by their link](Service-Claims.md)), a customer who
was given a service's subscription link or one of its configs — a client support made on a panel by hand — sends it to
the bot, anywhere it waits for nothing else, or taps «➕ افزودن سرویس» under «سرویس‌های من» and sends it there.

The service is added to their account at once («✅ سرویس … روی لوکیشن … به سرویس‌های شما اضافه شد.», with «📊 مشاهده
سرویس»); a link the bot does not take — one no server has, one another customer has, one theirs already — is said in
a word. A message holding no service's link is answered so only after «➕ افزودن سرویس», where the link stays awaited.
Sent at no step, only a config or a web address on one of the shop's own subscription addresses is looked for: a
channel's post forwarded, any other web address, and a link no server has are any message the bot does not
understand. A picture's caption counts. While the shop takes no links, a link sent is an unknown message.

### A service's screen

Its status, name, plan, location, quota, what is used and left, when it ends — «⏳ در انتظار اولین اتصال (n روز)» until
the first connection starts its days —, how many devices may connect at once (with any «کاربر اضافه» bought, «👥 تعداد
کاربر: ۳ کاربر»), its last connection and whether it is online now. They are read from its panel as the screen opens; a
panel that cannot be read shows the last numbers, said to be so. A service its panel no longer has shows as «❌ حذف
شده», with only «⬅️ بازگشت به لیست سرویس‌ها» under it.

While the shop removes ended services by itself ([Bot settings](Bot-Settings.md#ended-services)), the screen of one that
ended — and its renewal's checkout, from «♻️ تمدید سرویس» too — says when it goes: «🗑️ اگر این سرویس تا … تمدید نشود،
حذف می‌شود.». Renewed before then, it stays; removed, it is gone with its link, and a new purchase brings a new one. A
free test's screen says «🗑️ این اکانت تست … حذف می‌شود.» by the shop's own rule for tests.

Its buttons:

- «🔄 به‌روزرسانی اطلاعات» — read it again;
- «♻️ تمدید سرویس» — [its renewal](#renewing) (a free test has none; one without a plan picks what to renew on first);
- «🔗 لینک اشتراک» — the link in place of the screen, as a QR card or text, a tap to copy — no panel asked, so it works
  while the panel is down;
- «🔋 حجم اضافه», «⏳ زمان اضافه» and «👥 کاربر اضافه» — [more traffic, time or devices](#extra-traffic-time-and-devices) for
  this service, each while the shop sells it for the service (never for a free test);
- «🔁 تمدید خودکار: روشن / خاموش» — the wallet renews it days before its end on its plan (shortly before the end where
  the shop renews automatically by «ریست زمان») — while the shop offers it
  ([Bot settings](Bot-Settings.md#renewal)) and it has a plan (not one added by its link or built, until a renewal on a
  plan); turned on, it says when and how much;
- «⚠️ ارسال گزارش اختلال» — a support ticket about this service, its first message awaited;
- «⚙️ تغییر لینک» — new credentials and a new link, asked first (every device on the old one drops), when its server can;
  the new link comes as a delivery;
- «🔁 انتقال سرویس» — [to another customer](#handing-a-service-to-another-customer), while the shop lets customers (never
  a free test);
- «⬅️ بازگشت به لیست سرویس‌ها».

### Handing a service to another customer

While the shop lets customers ([Bot settings](Bot-Settings.md#service-transfers)), «🔁 انتقال سرویس» on a service's screen
gives it to another customer of the shop, free:

1. **The recipient's numeric Telegram id** is asked for in place of the screen («بازگشت» leads back to it); Persian
   digits are fine. They must have started the bot once. An id that names nobody of the shop, the customer themselves or
   a banned account is said so in a word, and another awaited.
2. **Who that is** — their name and @handle — with «✅ تایید انتقال» and «❌ انصراف» (back to the service). Another id
   sent there asks about that one instead. A confirmation is good once: tapped again, or after the customer went
   elsewhere, it hands nothing over («این تایید دیگر معتبر نیست؛ …»).
3. **Confirmed**, the service is theirs: the question turns into «✅ سرویس … به … منتقل شد …», with the way back to
   «سرویس‌های من». The recipient gets «🎁 سرویس … به حساب شما منتقل شد …» with «📊 مشاهده سرویس».

The service goes as it is — its link, end and traffic —, its «تمدید خودکار» off. The link going with it, the previous
owner's devices stay connected until the recipient taps «⚙️ تغییر لینک»: where its server can change a link, the
recipient's message says so («🔐 صاحب قبلی این سرویس هنوز لینک آن را دارد؛ …»). A checkout of the service still on the
previous owner's screen orders nothing once it is handed on: a tap on it says the service is not theirs any more.

A service with an order the customer made still open waits for it («⚠️ این سرویس الان منتقل نمی‌شود: …») — a renewal or
an extra waiting for its payment or receipt, or being delivered. An unpaid one is dropped by itself after 48 hours,
unless its card transfer went to support's private chat; one whose delivery failed sends the customer to support, who
retries it or gives the money back. A customer who looked up 10 ids in 10 minutes, or handed on 5 services in an hour,
waits a while.

Support transfers a service from the panel whatever the switch says, and without waiting for its orders
([Services](Services.md#handing-a-service-to-another-customer)). The previous owner's unpaid renewals and extras of it
are cancelled; one whose payment is already with support — a receipt in review, say — stays, and once paid it is never
delivered. Its customer is told the service is no longer theirs and support settles the payment with them: «⚠️ پرداخت
سفارش #… ثبت شد، ولی سرویس … پیش از انجام آن به حساب دیگری منتقل شد و دیگر سرویس شما نیست؛ پس چیزی به آن اضافه
نشد.», then «پشتیبانی مبلغ آن را با شما تسویه می‌کند.»

### Renewing

A service is renewed on its own plan, at its price today: the checkout shows the service **as the renewal would leave
it** — its new end, its traffic, what the period in use leaves, and the devices the renewal gives (from the end of the
period in use, when the devices bought for it stay until then) —, then the ways to pay. The plan's checkout note is
under it too.

- **A service without a plan** (added by its link, or built) first lists the plans on sale on its server, each at the
  customer's renewal price; the plan picked is the checkout's — and, once renewed, the service's own. While the builder
  is on, «🛠 تمدید با سرویس دلخواه» comes under them — alone when no plan is sold there —: the builder's GB and days,
  then the renewal's checkout of that package, «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز». Renewed so, the service still has no
  plan, and «تمدید خودکار» is never offered for it ([Custom services](Custom-Services.md#renewing-a-built-service)). With
  nothing to pick: «فعلا پلنی برای تمدید این سرویس فروخته نمی‌شود …».
- **Paid**, the wallet renews it at once («📊 مشاهده سرویس» under the word); a card waits for its receipt, an online
  gateway's page for its word.
- **Its price**: a service bought at a [first-purchase price](#first-purchase-prices) renews at the plan's price — an
  agent's or a colleague's at its [agent price](#agent-prices).
- **Not renewed**: a service switched off by support, one without a plan while neither a plan nor the builder is there
  to pick, or — in an agent's bot — one beyond the agent's traffic. One whose renewal is already with support or being
  delivered says so. A checkout of a service that is no longer theirs (handed on, or gone) orders nothing and says so.
  A renewal that would end the service after 17 January 2038 («۲۸ دی ۱۴۱۶») is refused in a popup, «تاریخ پایان سرویس
  نمی‌تواند بعد از ۲۸ دی ۱۴۱۶ باشد؛ مدت کوتاه‌تری انتخاب کنید.» — and «تمدید خودکار» is not offered for it: its
  customer is reminded of its end instead.
- **Many plans to pick from** are paged past 90, as in [buying](#buying).

While the shop offers more than one renewal method ([Bot settings](Bot-Settings.md#renewal)) the checkout has a row of
them — «✅ تمدید پلن فعلی», «ریست حجم + افزایش روز», «ریست زمان + انتقال حجم» —, opens on the first one on, and draws the
service's end and traffic again by the one tapped; a discount code applied stays, and the ways to pay renew by the one
marked. A shop with one method shows no choice.

A shop may hold a running service back until it nears its end («آستانه مجاز تمدید», [Bot
settings](Bot-Settings.md#renewal)). The service is still listed in «♻️ تمدید سرویس», but a tap answers «⏳ تمدید این
سرویس از … ممکن است.» — with the date and hour, «از ۳ روز مانده به پایان آن» for one not yet connected, or «وقتی ٪۲۰ از
حجم یا کمتر مانده باشد» — and nothing more happens. An ended service may always be renewed.

### Extra traffic, time and devices

While you sell them ([Bot settings](Bot-Settings.md#extra-traffic-time-and-devices)), a service's screen offers «🔋 حجم اضافه»,
«⏳ زمان اضافه» and «👥 کاربر اضافه» (more devices at once) — each bought on its own, at the prices of the service's plan
when it has its own, else the shop's:

1. **The kind's screen** — the service's traffic left and its end (for devices, how many it has now and the days their
   price is for), and a button per amount you offer with its price, two a row. With a price per GB, day or device the
   customer may type any amount within your bounds right there instead ([amounts typed](#amounts-typed)); one you do not
   sell is said so, with what is, and another awaited. Every price there — the buttons', the price per unit for an
   amount typed, the list in a refusal — is the customer's own, less their automatic discount (a sale, their own
   percent or their loyalty tier's), as the checkout charges it. Days stop at what keeps the service's end by 17
   January 2038 («۲۸ دی ۱۴۱۶»).
2. **The checkout** — what it costs and the service **as it would leave it** — its traffic left, its end, its devices
   —, then the ways to pay. Nothing is ordered until one is picked.
3. **The wallet** adds it at once («📊 مشاهده سرویس» under the word); **a card** waits for its receipt and **an online
   gateway** shows its page, as a purchase does. A checkout of a service that is no longer theirs orders nothing and
   says so.

Devices are priced for 30 days and charged for the days left of the service's period — a service 15 days from its end
pays half the price, its screen saying «قیمت‌ها برای ۱۵ روز مانده از دوره فعلی سرویس حساب شده است» —, and last as long:
a renewal brings its plan's number of devices, and they are bought again for the new period.

Traffic brings a service whose traffic ran out back to life; days for one whose time ran out count from its next
connection, as a renewal's do (its screen says so). What is not offered:

- neither, for a service switched off by support, nor for one that ended on both counts — that is a renewal's;
- traffic, for an unlimited service; time, for one that never ends;
- devices, for a service of unlimited devices, one whose time or traffic ran out, or one on a server whose panel has no
  device limit (PasarGuard).

A service whose traffic or time ran out is not in «🛍️ سرویس‌های من», which lists the running ones: the menu's
«♻️ تمدید سرویس» list reaches it, and its renewal's checkout offers the extra it may take beside the renewal. In an
agent's bot no more traffic is sold than the agent still has, and what is sold comes out of it; days and devices cost
the agent nothing. A button kept from before, for a service that can no longer take it, is answered in a popup and
orders nothing.

### The wallet

«➕ افزایش موجودی» offers your amounts as buttons, two a row — then, when you ask for them, amounts drawn at random within
your range, new ones each time the screen opens ([Bot settings](Bot-Settings.md#wallet)) — and «✏️ مبلغ دلخواه». The
customer picks one, or types any amount within your bounds right there ([amounts typed](#amounts-typed)); then the
checkout, every way to pay but the wallet itself that takes that amount.

Paid by card, the top-up may ask a few Toman more — the whole of it credited — while the shop adds a random sum to
card-to-card top-ups ([Payment methods](Payment-Methods.md#a-random-sum-on-card-to-card-top-ups)). An online gateway's
page may ask a few Toman of its own too (CubePay's); the top-up's amount is what is credited.

### Sending money to another customer

While the shop lets customers ([Bot settings](Bot-Settings.md#wallet-transfers)), the wallet's screen shows «💸 انتقال
موجودی» beside «➕ افزایش موجودی», which comes first (on the right):

1. The bot asks for the recipient's numeric Telegram id (they get it from a bot such as @userinfobot; Persian digits are
   fine) and says how much may go now: from your least to the most the customer may send — their own balance, never an
   agent's credit, no more than your most per transfer and what the daily cap leaves today. One who may send nothing (a
   balance below the least, the day's cap reached) is told so at once.
2. An id is answered with who it is and the amount asked for; one refused — not a number, nobody of this shop (they
   must have started the bot once), the customer themselves, a banned account — says why and waits for another.
3. The amount typed ([amounts typed](#amounts-typed)) brings the question: «💸 ۵۰٬۰۰۰ تومان به این مشتری منتقل شود؟»
   with the recipient's name and the balance left after it, under it «❌ انصراف» (back to the wallet) and «✅ تایید
   انتقال». One out of range says the range again and waits; another amount typed at the question asks about that one
   instead. Once nothing may go (the balance spent, the cap reached or the shop switching it off meanwhile), an amount
   typed is told why and the step ends.
4. Confirmed, the money moves at once — every rule asked again: a balance spent meanwhile, the cap reached, the shop
   switching it off each say why —, and the answer replaces the question: «✅ ۵۰٬۰۰۰ تومان به … منتقل شد.» with the
   balance now. A second tap, or one after the chat moved on, sends nothing.

The recipient is told («💰 ۵۰٬۰۰۰ تومان از طرف … به کیف پول شما منتقل شد.») and both wallets' histories have a line
naming the other. A customer looks up at most 10 ids in 10 minutes (with service transfers' lookups) and sends at most 10
transfers an hour.

### Support

«☎️ پشتیبانی» shows your contact ([Bot settings](Bot-Settings.md#general)), «📨 تیکت جدید» and «🗂️ تیکت‌های من»:

- **A new ticket** — which running service it is about (the five newest, or «بدون سرویس مشخص»), then one message: words,
  and a picture if there is one, the picture's words as its caption. Its first line is the subject. A picture alone is
  kept until its words come. An album is one message: its first picture goes with the words (on that picture, or the
  next text), and the album's other pictures pass unanswered.
- **What follows joins the ticket** — whatever the customer sends next is added to it, until they go elsewhere, a quarter
  of an hour passes since their last message, or a notice of another of their tickets arrives.
- **«🗂️ تیکت‌های من»** — the latest activity first, five a page («🟡» waiting on support, «🟢» answered, «🔒» closed). A
  ticket's screen shows its last five messages and offers «✍️ پاسخ», «🖼️ تصویر n» for each picture, «🔒 بستن تیکت» while
  open, and «⭐ امتیاز» once closed. A message to a closed ticket opens it again.
- **Support's answer** arrives in the chat — its picture with it, when it fits —, with «✍️ پاسخ» and «🗂️ مشاهده تیکت».

### What the bot tells a customer by itself

| When | They get |
|---|---|
| A card payment approved, an online payment its gateway confirmed — or retried, or delivered after a restart | Their service (a QR card), its renewal or the traffic, time or devices it bought, the wallet charged or the agent's traffic added — or that the delivery failed and support will finish it |
| An online payment that came in after its order had closed (paid another way, cancelled, expired) | «💳 پرداخت شما برای سفارش #… پس از بسته شدن آن سفارش رسید؛ …»: its money went to their wallet, with the balance now |
| A receipt rejected, an order cancelled, a payment refunded | A word, with your note, as a reply to their receipt |
| A payment reminder from the panel | A nudge, with «📎 ارسال رسید» and «🔄 پرداخت دوباره» |
| A service switched off or on, deleted (while running), moved, given days or traffic | A word — a moved one with its new link |
| A service handed to them, by its previous customer or support — or handed on by support | A word — theirs now with «📊 مشاهده سرویس» |
| Money another customer sent to their wallet («انتقال موجودی») | A word, with the balance now (the sender's own notice is kept on the website, in Telegram only when they sent it from there) |
| A service near its end or its traffic's end (when the shop turned reminders on) | A reminder, «📊 مشاهده سرویس» |
| An automatic renewal — done, short of money (once a window), failed | A word; short, «➕ افزایش موجودی» |
| A newcomer their link brought, a commission earned — or taken back, the wallet top-up that earned it refunded | A word |
| A paid order never delivered: no longer their first purchase (made at a first-purchase price or with a first-purchase-only code, another purchase paid first), or a renewal or extra of a service support [handed to another customer](#handing-a-service-to-another-customer) meanwhile | «⚠️ پرداخت سفارش #… ثبت شد، ولی …»: support settles its money with them |
| Support's answer to a ticket, its closing | The answer; the closing with «⭐ امتیاز» |
| An agent's request decided, their terms changed, their agency ended, their traffic short for a delivery; their agency's end or a month short of their minimum near, their bot's selling suspended or resumed | A word in the main bot — a short month and a suspension for the minimum with «💾 خرید حجم» |
| A change of how their website account is signed in to, or wrong codes tried on it | A word — in Telegram **and** by email |

Every notice is kept for the customer's website too, and one Telegram cannot bring — no Telegram account, or the bot
blocked — goes by email when the shop's email is set up ([Store API: notifications](Store-API-Notifications.md)).

## For the bot's admins

A bot admin is a customer you (or the agent) made one — «کاربران», the row's menu «مدیر ربات کردن»; an agent is their
own bot's admin by itself ([Customers](Customers.md#the-bots-admins)). They pass the bot's switch, phone and channels —
not a ban —, and have what answers nobody else at all:

- **`/broadcast`** — a message to many customers, composed in the bot: the message, then a draft card — how it goes, to
  whom, pinned or not, link buttons —, then the run's progress with its controls ([Broadcasts](Broadcasts.md)).
- **`/emoji`** — premium emoji for the bot's texts and buttons: a message with them, written the way a customer should see
  it; the bot keeps their ids for the panel's picker, sends the message back as itself — whether they stay premium says
  whether the bot may use them —, and answers with a template to paste into a text ([Bot texts and
  keyboards](Bot-Texts-And-Keyboards.md#premium-emoji)).
- **The report group** — a receipt's «✅ تایید» and «❌ رد», a failed delivery's retry, an agency request's verdict, a
  ticket's answer by reply and its «🔒 بستن تیکت» ([Report group](Telegram-Group.md#acting-from-the-group)).
- **The shop's website** — the shop's daily work from their own account there, while the website lets its admins in
  ([Website](Website.md#the-shops-admins)).

An admin is a customer still: what is their own — their payment, their wallet, their service, their review, their agency
request — another admin decides.

## Watch out for

- **A relabelled keyboard button** — a customer's old keyboard sends the old label once, answered with the new menu.
- **A contact card sent at any step** goes to the menu, leaving the step.
- **The menu's buttons for free tests, referrals, the agency, gift codes and the wheel** show only while their programs
  run; a menu left with none of its buttons shows the shop's built-in menu instead ([the
  menu](Bot-Texts-And-Keyboards.md#the-menu)).
