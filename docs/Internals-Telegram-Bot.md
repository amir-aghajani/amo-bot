[فارسی](fa/Internals-Telegram-Bot.md)

# Internals: Telegram bot

How the bot serves its customers and its admins: the dispatcher and its routes, the conversation state, the handlers
and their shared screens, the texts and keyboards the admin rewords, the gates, the report group and broadcasts. What an
owner and a customer see of it is in the [Bot guide](Bot-Guide.md), [Bot settings](Bot-Settings.md), [Bot texts and
keyboards](Bot-Texts-And-Keyboards.md), [Report group](Telegram-Group.md) and [Broadcasts](Broadcasts.md).

Everything lives in `app/Modules/Telegram`, the routes in `routes/bot.php`.

| Folder | Holds |
|---|---|
| `Update/` | `Dispatcher` (the router), `Update` (a raw update, read), `CallbackData`, `ReceivedUpdates`, `AgentWebhookBudget` |
| `Session/`, `Context.php` | A chat's conversation state, and what a handler gets for one update |
| `Handlers/` | One handler per feature, and the shared pieces: `CheckoutScreen`, `Checkouts`, `CustomerServices`, `TypedAmounts`, `TypedRecipients` |
| `Keyboard/` | The admin's main menu (`MainMenu`, `KeyboardLayout(s)`), the shared `Buttons`, the keyboard builders |
| `Texts/` | `BotText` and `TextCatalog` (every reworded text), `BotTexts` (the admin's wording, rendered), `TelegramHtml`, `WebText` |
| `Gates/`, `Channels/` | The checks before any handler: master switch, phone, required channels |
| `Reports/` | The admins' report group: queueing (`ShopReports`), sending (`ReportSender`), the group's buttons and replies |
| `Broadcasts/` | `/broadcast` runs: audiences, the paced sender, the panel's view |
| `Api/` | `BotApi`, `Refusal` (the one reading of Telegram's errors), `Limits`, `Pacer`, `BotToken` |
| `Polling/`, `Controllers/`, `Services/` | `bot:poll`'s loop, the webhook endpoint, webhook and identity management |
| `Notifications/`, `Qr/`, `Emoji/` | `ServiceCard` (a service as a QR card or text), the QR picture, premium emoji |

Open first: `routes/bot.php`, `Update/Dispatcher.php`, `Context.php`, `Handlers/CheckoutScreen.php`,
`Texts/BotTexts.php` and `Reports/ShopReports.php`.

## How an update is served

An update arrives by webhook (`Controllers\WebhookController`) or by `bot:poll` (`Polling\Poller`). Either way it is
served in its bot's shop (`CurrentBot::run()`), then the reports that bot's group has queued are sent
(`ReportSender::flush()`).

`Dispatcher::dispatch()` then:

1. **Sorts it by chat.** A group's update goes to the group routes (`groupCallback`, `groupCommand`, `group`) after
   steps 2 and 3: no user, no session, no gates. Customers are served in private chats only, and a private chat whose
   id is not its sender's is ignored (an agent's webhook can be posted anything).
2. **Claims the update** (`ReceivedUpdates::claim()`): its id is recorded before anything is served, so a copy Telegram
   sends again is ignored. This is *at most once* on purpose — an update whose serving died halfway is not retried
   either: a second purchase or link change is worse than a lost tap.
3. **Ignores a flooding chat** — more than `ReceivedUpdates::FLOOD_UPDATES` (20) in `FLOOD_SECONDS` (10): every answer
   is a Telegram call, and one chat must not slow the bot down for everyone. In a report group only what is meant for
   the bot counts (`Update::isForBot()`: a press on its button, a command, a reply to one of its messages — never to
   the first message of a topic, which every message there replies to), so the group's own talk never holds back an
   admin's press or reply; nor does an update Telegram held back while the bot was away (sent over a minute before it
   arrived).
4. **Resolves the customer** (`UserResolver`), refuses a banned one, runs the **gates** in order, then the first route
   that matches: a command, a reply-keyboard label, a contact card, a callback prefix (longest wins), the conversation
   state (longest prefix), a text pattern (a link to a service, `ServiceClaimHandler` — after the state, so a link
   written in a ticket stays the ticket's; it looks only for configs and web addresses on a host the shop's
   subscription links are on, so a channel's post forwarded asks no panel), else the fallback (the menu).

A route marked **navigation** (commands, labels, the menu's `menu:*` taps, the `claim:` tap, a text pattern, the
fallback, every ticket tap) clears the flow the chat was in, so what the customer types next is never taken as an old step's answer. An
**admin-only** route (`/broadcast`, `/emoji`) answers anyone else nothing at all.

Telegram takes one answer per tap: `Context::answer()` sends only the first, and the dispatcher stops the spinner of a
tap nobody answered. A popup only shows for a tap, so a refusal that may answer something typed as well goes through
`Context::alert()`: a popup on the button, or — for a message — a message of its own, the step over. A handler that
throws is logged and the customer told — on the button while the tap is unanswered, else in a message.

### Conversation state

`Session\ChatSession` holds the step a chat is at (`state`, e.g. `topup.amount`, `receipt:12`) and that step's scratch.
`enter()` moves to a step, `clear()` leaves the flow. Keys starting with `_` are the chat's own facts (whether a reply
keyboard is shown, a recent channel check, a kept discount code, a `/start` link a gate held back) and survive every
flow. A last step that must run once
— a broadcast sent, a link changed, a wallet payment — ends with `claim(state)`, one conditional update, so of two taps
at the same moment only one acts. The dispatcher saves the session once, after the update.

### Callback data

`Update\CallbackData` is the one format: a prefix and `:`-separated arguments, 64 bytes at most (`plan:12:srv:3`).
Every prefix is a constant on its handler, with static builders (`PurchaseHandler::planCallback()`,
`SubscriptionHandler::serviceCallback()`); handlers read arguments with `$ctx->update->callbackArgs(PREFIX)` and never
build or split the string by hand. An enum's case goes in as its place among the cases (`CallbackData::place()`, read
back with `caseAt()`): short whatever its value, so a new case is only ever added at the end.

## Every bot is its own shop

The main bot and every agent's bot run on one code base. `CurrentBot` says whose shop the code is in; `BotApi` speaks
with that bot's token, and the settings, texts, keyboards, sessions and report group are per bot. An agent's webhook
secret is readable by the agent with their own token, so `AgentWebhookBudget` rate-limits what arrives there
(updates per minute, newcomers per hour). An agent's bot has no agency of its own: no «نمایندگی» button
(`MainMenu::absent()`), no agency texts or report topic. See [Internals: services](Internals-Services.md) for
`CurrentBot` and the agent bots.

## Handlers and their shared screens

A handler is one feature (`PurchaseHandler`, `RenewalHandler`, `ExtrasHandler`, `TopUpHandler`, `AgencyHandler`,
`SubscriptionHandler`, `TicketHandler`…); its class comment lists its callbacks and states. A screen is drawn in place of
the tapped message (`Context::edit()`); the end of a flow arrives as a fresh message (`Context::replace()`), like the
notification it is. Rules live in the services the handler calls (Orders, Subscriptions, Support…), never in the
handler: the bot only renders.

- **`MainMenu`** knows the menu's actions and whether the admin laid it out as a reply keyboard or inline buttons:
  `show()` sends a text with the menu, `screen()` draws a screen the menu opens (with «بازگشت» to the menu when inline).
  Buttons switched off with their program are hidden but stay on the admin's layout. A layout of nothing but such
  buttons, all off, shows the built-in menu in its form (reply keyboard or inline) — never an empty keyboard, which
  Telegram refuses —, and its labels route while it stands in (`markup()`, `routed()`).
- **`CheckoutScreen`** is the one checkout of every purchase in the bot. `show()` draws what is bought with a button per
  way to pay; `buy()` runs the shop's checkout (`Payments\Services\Checkout`, the website's too) and renders the outcome.
  **Nothing is ordered until a way to pay is picked**, so a checkout left alone costs nothing. While a checkout is on
  screen the chat's state is `checkout.{amount}.{checkout}`: the amount it showed and the checkout it is — its callback
  data, which says what it sells (64 bytes at most, the amount 15 characters, within the 128 characters of the
  session's state column, `telegram_sessions.state`). The wallet, and a free order, claim that state first
  (`ChatSession::claim()`), so a double tap — or a tap on a checkout the chat has left — pays once at most; and they pay
  only that checkout at the amount shown: a checkout priced otherwise since (a sale over, a first purchase paid
  elsewhere), or a tap on an older checkout's message while another is on screen, is drawn again first, so nothing the
  customer did not see is taken from their wallet. So is a checkout that showed «🎁 دریافت رایگان» and is no longer free,
  whichever way to pay was tapped, and one whose chat is still at the older `checkout.{amount}` state (from before an
  upgrade), once. A card or an online gateway needs no such guard: the amount is shown before anything is paid. A
  discount code typed (`DiscountCodeHandler`) is kept in the session for that checkout only and judged again as it is
  paid; `pay()` is that pay step for every discountable checkout (a purchase, a built service, a renewal, an extra): the
  way to pay still offered, the price with the kept code, the order paid. A purchase's checkout says its service's name,
  with a button per way the shop lets buyers name it (`ClientNameHandler`, which judges the name on the server the
  checkout names); the name drawn or typed is kept the same way (`keepName()`) and handed to the order
  (`requestedName()`) — «پرداخت دوباره» keeps its order's (`keepOrderName()`), so paying finds it. An online gateway's
  outcome (`CheckoutOutcome::Link`) is its page (`link()`): the exact sum it asks, until when, «💳 پرداخت» to it and «🔄
  بررسی پرداخت» — `OnlinePaymentHandler` (`online:{payment}`), which asks the gateway through
  `Payments\Services\OnlinePayments::check()` (the customer's own payment, once in `ASK_SECONDS` by the `RateLimiter`)
  and answers in a popup — a payment held for support and still waiting never asked about (`PayOnlineHeld`), a gateway
  out of reach `PayOnlineUnreachable` —, a paid page edited to `PayOnlineDone` without its buttons, a closed one to its
  word (or `OrderNotPending` once its order closed) —; a card the gateway gave to show in the bot comes under it,
  protected as a card transfer is. A checkout of an order whose payment is held (`PaymentHeldException`) says
  `PayOnlineHeld` with the way to support. The settled payment's notice is `CustomerNotifier`'s, whoever asked; no
  receipt is awaited. A purchase or a renewal whose term would end the service past the last day the shop keeps orders
  nothing: the checkout says `TermTooLongException`'s words in its place (`CheckoutScreen`, `RenewalHandler`).
- **`Checkouts`** draws a checkout again from its callback data (`plan:…`, `renew:…`, `extra:…`, `custom:…`, each
  handler implementing `RedrawableCheckout`, which also says the server a purchase's checkout names) for the steps
  under it — a discount code, a name — and runs their one "typed for a checkout" step: what was typed taken and the
  checkout redrawn, or the refusal said with «بازگشت» to it.
- **`TypedAmounts`** reads a number typed at a step that awaits one (GB, days, devices, Toman — a wallet transfer's
  amount among them, `WalletTransferHandler`) and refuses one its step does not take, saying what may be typed.
- **`TypedRecipients`** is the one recipient step of handing something to another customer by numeric Telegram id
  (`ServiceTransferHandler`, `WalletTransferHandler`): a recipient refused (a refusal on `telegram_id`) is said and
  another id awaited; any other refusal, or too many lookups, ends the step; its `confirm()` claims the confirmation
  once and hands over, or says why in place of the question.
- **A last step that must run once** — a link changed, a service handed on, money sent — waits on a state that names
  what it confirms (`transfer.{id}.{recipient}`, `wsend.{recipient}.{amount}`) and is claimed before anything is done
  (`ChatSession::claim()`): a second tap, or one after the chat moved on, does nothing. Its «انصراف» | red «تایید» row
  is `Buttons::confirm()`.
- **`CustomerServices`** is the one rule for which service a customer's button may open: their own, not gone from its
  panel (`find()`: else they are told it is not there, with «بازگشت»). An order of a service refused because it is no
  longer theirs since its screen was drawn — handed on, or gone (`OrderService`'s 422 on `status`) — is said in place
  of the screen by `movedOn()`: the renewal's and the extras' pay steps catch it, and nothing is ordered. A service
  whose panel turns out to have lost it shows what it was with the way back alone (`SubscriptionHandler`), since every
  other button would only answer that it is not there.
- **Tickets**: `TicketHandler` draws the screens, `TicketMessageHandler` takes what the customer writes. After a message
  the chat stays on the ticket, like a chat, until the customer goes elsewhere, `REPLY_SECONDS` pass, or a notice of
  another ticket arrives (`leaveOtherTicket()`). A message sent once that time passed is no longer the ticket's: it is
  routed as one sent at no step (`Dispatcher::atNoStep()`) — the text pattern it matches, or the fallback.
- **Pictures** (a receipt, a ticket's picture) are a photo or a file judged by its bytes: `Update::picture()` with
  `CustomerPictures::isPicture()`. Telegram sends an **album** (a media group) one picture at a time, each an update of
  its own, and `Context::album()` names the album a message came in. A message takes one picture, the first: a receipt
  sent as an album is its first picture, and the chat waits on the album so its other pictures pass unanswered
  (`ReceiptHandler`); a ticket's message is the album's words with its first picture, and the album's later pictures
  are not asked about again (`TicketMessageHandler`).
- **Free tests**: `TestAccountHandler::home()` draws the test screen for the menu, `/free` and the `?start=usertest`
  link — the link's as a new message under the greeting (`StartHandler::open()`, `MainMenu::screen(fresh: true)`).
  `test:{server}` takes one through `Subscriptions\Services\TestAccounts` and sends it with `ServiceCard`
  (`TestDelivered`). The servers' buttons follow each server's colour and row, as a purchase's locations do
  (`Buttons::server()`, `InlineKeyboard::flow()`), `TestAccountHandler::PER_PAGE` (20) a page (`menu:test[:{page}]`).
  Every refusal is a text of its own, and a refused tap redraws the screen through `home()`, as it
  judges now — the servers still giving a test, or why none. A test made whose card Telegram refused still counts: the
  customer gets `TestUnsent` with the same «📊 مشاهده سرویس» button. A test's service screen has no renewal button:
  a test is never renewed (a service without a plan that is no test — added by its link, or built — renews on a plan
  it picks, `RenewalHandler`'s picker, or with a package built: `CustomServiceHandler`'s `custom:r:…` steps, which
  hand the checkout back to `RenewalHandler::checkout()`).

Adding a screen: a handler with its prefix constant and builders, its line in `routes/bot.php` (with `navigation: true`
when it should leave the flow), its texts as `BotText` cases, and a feature test on `BotTestCase`.

## Texts

Everything a customer reads is a `Texts\BotText` the admin may reword. **The enum's values are stored** (settings
`bot.text.<value>`): never rename one. `TextCatalog` describes each — group, kind, title, default wording, variables —
one method per group, through the kind helpers (`message()`, `caption()`, `popup()`, `button()`, `part()`). A variable
means the same everywhere (`VARIABLES`), and `required` ones cannot be removed (a delivery without its link). The
generated [Bot texts reference](Bot-Texts-Reference.md) is built from it (`php scripts/docs.php`).

`BotTexts` renders them: `get()` (no variables), `render()` (exactly the declared variables — a mismatch is a
`LogicException`, the code's mistake), and `part()` for a piece another text takes in. **Escaping happens there
alone**: a value is plain text, escaped into an HTML text and left as it is in a popup or a button; a part is an `Html`
and goes in as it is. A wording is checked before it is stored (`BotTexts::problem()`), because a text Telegram
refuses would leave the customer with nothing. A wording equal to the default is not stored, so an improved default
still reaches every shop.

**A row's number, a command or a handle after Persian words** has a left-to-right mark (U+200E) right before it: right
to left, «#12» would read «12#», «/menu» «menu/» and «@sara» «sara@». The mark goes only where a word comes before it on
its line (`Messages::wordsBefore()`: a letter outside tags, entities and `%variables%`) — never on a line it starts,
such as a ticket's button «🟡 #37 · …»: a line takes the direction of its first letter, and the mark would be it.

- The defaults write it (`TextCatalog::LRM`, before `#%order%`, `/menu`, `@BotFather`). A line that starts with a flag
  starts with a right-to-left mark instead (`TextCatalog::RLM`): a flag's letters are left to right.
- `BotTexts::marked()` puts it before a filled-in value that starts with `@`, or with `#` and a digit, wherever a word
  comes before the variable on its line — in the admin's wording too.
- Words composed in code write it themselves: the report group's (`ShopReports` — a customer's handle after their
  name, an admin after «توسط پشتیبانی:», a row's number —, `ReceiptReview`), the broadcasts' progress (`Messages`), a
  handle after a customer's name (`User::shownAs()`), a wallet transfer's line (`WalletTransfers`), the refusals that
  name an order or a server, and the page a gateway returns the customer to (`Payments\Controllers\ReturnPage`).
- `Messages::rowNumbers()` marks the numbers in a wallet line's stored note — written bare by the server («شارژ کیف پول
  (سفارش #4)») or by support — for the bot's wallet history (`MenuHandler::wallet()`).

`TextCatalogTest` and `BotCustomTextsTest` hold every default to the rule, filled with handles and numbers too. The
panel's preview marks its samples the same way (`fillSamples()`), and the searches ignore the marks (`Page::term()`,
the bot texts screen's `normalize()`), so a number pasted from a bot message is found. The code writes a mark as
`"\u{200E}"`, never the invisible character itself (`DirectionMarksTest`).

`TelegramHtml` reads a wording as Telegram's own parser (tdlib's) reads it:

- **`problem()`** — the first thing Telegram would not take, in the admin's words: only the Bot API's tags, quoted
  attributes, tags closed in order, no stray «<» — and what Telegram would silently drop or show as typed: formatting
  inside `<code>` or `<pre>`, a link in a link, an attribute name in the wrong case, an entity it does not read. Tag
  names are read in any case (`<B>` is bold); an attribute name only as written (`href`, `class`, `emoji-id`,
  `expandable`, `unix`, `format` — `HREF` is passed by). A premium emoji is `<tg-emoji emoji-id="…">😀</tg-emoji>`: a
  numeric id and the plain emoji it stands for.
- **A date and time** is `<tg-time unix="…" format="…">…</tg-time>`, which the customer's own app writes in their
  language and zone: `unix` a Unix time from 1 to 2147483647; `format` either `r` (relative to now) or built of `w`
  (the weekday), then `d` or `D` (a short or long date), then `t` or `T` (a short or long time) — `wDT`, say — and in
  quotes when it has a capital, which Telegram reads in lower case bare; or no `format`, the words shown as they are.
  The words inside are required: they are what a reader sees where the time cannot be shown. A link and a date hold
  nothing but formatting — no link, code, quote, premium emoji or date inside either. `/emoji` turns a forwarded
  message's dates into this tag too (`EntityHtml`).
- **Entities** — Telegram reads only `&lt;`, `&gt;`, `&amp;`, `&quot;` and numeric ones (`&#…;`, `&#x…;`); any other
  named entity (`&nbsp;`) it shows as typed, so `problem()` refuses one written with its «;», along with a bad number or
  half of a surrogate pair. A «&» that means no entity («R&D») is fine.
- **`decode()`** — the text with those entities read as Telegram reads them, any other «&» left as typed (the report
  group's plain-text fallback sends a report so); `visible()` is what the customer sees — tags dropped, then
  `decode()`.

A wording the customer would see nothing of — its tags dropped and its entities read, nothing but spaces of any kind,
line breaks, zero-width or filler characters left (`Support\Text::blank()`) — is refused as an empty one
(`BotTexts::problem()`): Telegram refuses a message of nothing.

The panel's preview and its own check (`resources/panel/src/components/bot-texts/wording-problem.ts`) mirror these
rules.

Lengths are counted as Telegram counts them: UTF-16 units of what is shown (`Api\Limits`,
`TelegramHtml::visibleLength()`). `Messages` keeps what the admin does not reword: the formatters' units, the default menu labels, and
the admins' own words (/broadcast, /emoji, the report group). `WebText` turns a text into plain text or safe HTML for the
website and emails.

Adding a text: a `BotText` case, its spec in `TextCatalog` (`TextCatalogTest` checks every default is savable), the call
through `BotTexts`, then `php scripts/docs.php`.

## Keyboards and gates

The main menu's layout is the admin's (`KeyboardLayouts`, settings `bot.keyboard.start`). Rows are kept in reading
order — the first button the rightmost on a Persian screen — and reversed when rendered, since Telegram lays a row out
left to right. A stored layout is read back through the same checks as a save; one that fails them shows the built-in
menu, so a bad row never takes the menu away. A reply keyboard's tap arrives as its label, so labels are unique and
double as routes.

The keyboards the code builds keep the same reading order. `InlineKeyboard::row()` takes its buttons left to right, as
Telegram does; `grid()` lays buttons out N to a row with each row's first button on the right (the top-up amounts, the
extras, the builder's GB and days, an agent's traffic presets), and so does `flow()` for the plans' and servers' own
`button_row`. That is 1 to `KeyboardLayout::ROW_MOST` (3) buttons a row, and their colour is one of
`KeyboardLayout::STYLES` or Telegram's default; the refusals of anything else are `ROW_REFUSED` and `STYLE_REFUSED`. The
wallet's screen lays out «➕ افزایش موجودی» first, on the right, then «💸 انتقال موجودی».

**A list the shop makes as long as it likes is paged** past one keyboard's worth: Telegram refuses a message of more
than `Api\Limits::KEYBOARD_BUTTONS` (100) buttons whole, so a page holds `Buttons::LIST_PAGE` (90), leaving room for the
rows under it. `Buttons::page()` and `slice()` pick the page — the first or the last for one past either end, a list
that shrank since its screen was drawn — and `paged()` puts «بعدی» / «قبلی» under it (each only where there is a page to
go to) and «صفحه ۲ از ۳» on its text; a list of one page is left as it is. Paged so: the categories and the plans
(`menu:plans[:{page}]`, `cat:{id}[:{page}]`), a plan's locations (`plan:{id}[:{page}]`), the renewal's plan picker,
`/broadcast`'s groups and servers, and the free tests' servers, 20 a page. A way back from a plan or a location returns
to the list's first page.

The **gates** (`MaintenanceGate`, `PhoneGate`, `ChannelGate`, in that order) run before any handler; a gate that holds an
update back answers it itself, and bot admins pass them all. A `/start` link a gate held back is kept as the chat fact
`_start` (`Dispatcher::keepStartLink()`) — only what a t.me link can carry (Latin letters, digits, `_` and `-`, 64 at
most), with when it was kept — and taken by the update that gets through within the hour — the contact card, a later
`/start` or `/menu` (`StartHandler`), or «عضو شدم» (`JoinHandler`) —, so a newcomer the gates stopped still lands
where the link leads (`ChatSession::takeStartLink()`; an older one goes unopened). `ChannelMembership` trusts a
confirmed membership for a few minutes (`RECHECK_AFTER`). A channel Telegram cannot answer for counts as joined, because
a broken channel must not lock the shop; it is flagged for the admin only when the channel itself is the reason — not
a moment's outage, nor a refusal about this one customer (an id Telegram does not know there:
`Api\Refusal::aboutTheUser()`).

## Delivering a service

`Notifications\ServiceCard` is the one place a service goes out (a delivery, a new link, a move; `send()`, or `show()`
in place of a tapped message): the subscription link as a QR card with the service's text as caption. **A missing
picture never costs the customer the link** — the text goes alone when:

- the card is switched off, or the text is too long for a caption;
- `Qr\QrCard` cannot draw it: no GD, a background GD cannot read or too large to draw on, a link no QR code can hold,
  or a card Telegram would not take as a photo (a banner-shaped background, or a JPEG past Telegram's photo size —
  `Api\Limits::isPhoto()`). Each is a warning in the log. A background too small to hold the code readably is drawn
  larger first, so each square of the code is at least a few pixels;
- Telegram refuses the card for anything but being out of reach or asking to wait (`cardRefused()`, logged): `send()`
  sends the text instead, and `show()` sends it as a new message, the tapped one perhaps gone by then. Out of reach or
  a flood wait goes up to the caller, whose retry sends the card again.

A customer always gets the one subscription link, never the per-inbound config links. Notices the shop sends on its own
go through the Notifications module (kept for the website, emailed when Telegram cannot reach the customer); see
[Internals: services](Internals-Services.md).

## The report group

A supergroup with topics, one per `Reports\Topic`, handed to the bot by a one-time link (`ReportGroup`,
`ReportGroupConnect`); its state is `ReportGroupState` (`report_chats`, read fresh every time, changed by conditional
updates), its topics `ReportTopics` (each made under a lease, so two processes never make one twice).

- **Queueing** — `ShopReports` writes a `report_messages` row **in a savepoint of the caller's transaction**: a report
  stands or falls with what it reports, and a change is never kept without its report. Words that cannot be composed are
  logged and the report dropped; a database error goes up. A ticket's burst of messages is folded into its last waiting
  report.
- **Sending** — `ReportSender` sends the lowest `Topic::priority()` first (receipts and failed deliveries: a customer
  waits on them; reviews last), at most `PER_MINUTE` a minute per group. Each row is leased while it is sent and marked
  sent only while still held, so the poller, the cron and a webhook never send one twice. A flood limit, an unreachable
  Telegram or trouble with the group pauses everything (`ReportGroup::hold()`); nothing sleeps and nothing is lost. A
  report with a picture (a receipt, copied from the customer's chat or uploaded) goes as the photo with the report as
  caption; a picture Telegram refuses for its own sake lets the report go as text, so one customer never holds the
  group back — «🖼 تصویر کپی نشد…» for a copy that failed (the customer deleted their message, or blocked the bot),
  «🖼 تصویر فرستاده نشد…» for an upload it would not take as a photo. Only Telegram out of reach or asking to wait, or
  the topic gone or closed, goes up. Markup Telegram cannot parse
  goes as plain words (`TelegramHtml::decode()`).
- **Acting** — the buttons (`ReceiptReview`, `DeliveryRetry`, `AgencyReview`, `TicketClose`) and replies
  (`TicketReplies`, a receipt's reason) work for bot admins only, judged by the account that pressed — never by being in
  the group or by the button's data (`GroupButtons::admin()`, `presser()`, `writer()`). They act through the same
  services as the panel, with the admin as `Actor::groupAdmin()`. A reply is matched to what it answers by the message
  id the bot recorded, never by that message's words, which may be a customer's. An agency request's approval refused
  as it stands — the admin's own request, or an agency the program's term would end past the last day the shop keeps —
  leaves its buttons in place for another try; only a request decided meanwhile takes them away.

## Broadcasts

`/broadcast` (`Handlers\BroadcastHandler`) builds a draft card in the session; «ارسال» claims the draft once and starts a
run (`Broadcasts\BroadcastService`). A run is worked by whoever holds its lease — the bot for the first `INLINE_LIMIT`,
then `SendBroadcastsTask` — past a cursor, **each customer claimed before their message goes**, so nobody gets it twice.
Sends are paced (`Api\Pacer`); a flood limit puts the customer back for the next batch. Pause, resume and cancel are a
compare-and-swap on the status. A message run whose admin has no Telegram chat any more (the account gone, or its
Telegram taken off) has nothing to copy its message from: it ends cancelled («لغوشده»), logged. `Audience` decides who
is reached; an audience whose group or server is gone reaches nobody, never everyone.

## Telegram's API

`Api\BotApi` is a thin client: one method per call, refusals as `TelegramApiException`, whose `refusal()` (`Api\Refusal`)
is the one place Telegram's codes and descriptions are read. A short flood wait is honoured once. Answering a tap and
deleting a message never throw. A message Telegram refuses over its premium emoji (allowed only while the bot's owner
has Telegram Premium) is resent with plain emoji, and the "no" is remembered for a while (`PremiumEmojiStatus`).
`BotApi::longestCall()` sizes every lease on work that calls Telegram.

`Polling\Poller` keeps one long poll open per bot at once (`LongPolls`) and runs the scheduler in a process of its own,
because the polls only move while the poller waits on them. An agent's bot given a new token counts as a new bot: the
poll still open with the old token is let go; the main bot's token change restarts the worker.
`Services\BotLifecycle` and `BotWebhooks` move bots between webhooks and polling; `BotHealth` keeps why an agent's bot
cannot run on its row.

## Things to know

- **At most once**: a served update is never served again, even if its serving died.
- **A handler never sleeps**: under `bot:poll` every other chat would wait. A wheel spin answers at once, its slices
  with the one drawn marked and the result under them — no animation.
- **The settings are cached per process**: the poller re-reads them every few seconds, so a long-lived reader must too.

## Tests

Bot flows extend `Tests\BotTestCase`: `send($this->message(...)|tap(...)|contact(...))` drives the real dispatcher
against the fake Telegram, and `said()`, `popup()`, `calls()`, `inlineKeyboard()` read what the bot did; the report
group has `groupTap()`, `groupReply()` and `botMembership()`. `BotQueryBudgetTest` holds each common screen to a fixed
budget of queries and Telegram calls — a budget raised says what the new query buys. Unit tests of the API, texts and
keyboards are in `tests/Unit/Telegram`. See [Testing](Testing.md).
