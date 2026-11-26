[فارسی](fa/Internals-Telegram-Bot.md)

# Internals: Telegram bot

How the bot serves its customers and its admins: the dispatcher and its routes, the conversation state, the handlers
and their shared screens, the texts and keyboards the admin rewords, the gates, the report group, broadcasts and the
shop's management in the private chat. What an owner and a customer see of it is in the [Bot guide](Bot-Guide.md),
[Bot settings](Bot-Settings.md), [Bot texts and keyboards](Bot-Texts-And-Keyboards.md), [Report group](Telegram-Group.md),
[Broadcasts](Broadcasts.md) and [Managing the shop from the bot](Bot-Management.md).

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
| `Contracts/` | What the bot asks of another module through an interface of its own: `MiniAppAddress`, the shop's mini app's address |
| `Admin/` | The shop's management in the private chat: who manages (`AdminSeats`, `OwnerLinks`), the door to the panels' API (`Door/`), the screens' framework (`Ui/`) and each area's screens (`Sections/`) ([below](#the-shops-management)) |

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
**admin-only** route answers anyone else nothing at all; `/broadcast` and `/emoji` ask for themselves — a role admin, or
anyone `AdminSeats` gives a seat — and so does the management. A step marked **captures** takes any message as its
answer, a reply keyboard's label too; only a command leaves it. The management's questions wait in `Admin::INPUT`,
where a menu label is the menu's (a label is never sent as an answer, to a ticket say), and only those whose answer may
be any words — a keyboard button's new label, a bot text — in `Admin::INPUT_ANY`, which captures (`Prompt::$anyText`).

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
- **An agent's renewal request**: `Buttons::renewAgencyFor()` is «🔄 درخواست تمدید نمایندگی» (`agency:renew`) while
  `AgencyActions::mayRequestRenewal()`, under the agent's account (`AgencyHandler`); `Buttons::renewAgency()` always sits
  under the notices of the end near and passed (`CustomerNotifier`), whose texts tell them to press it.
  `AgencyHandler::renew()` files it (`AgencyActions::requestRenewal()`) and says `AgencyRenewalRequested`; refused, it
  says why in a popup (`RENEWAL_WAITING`, `RENEWAL_NOT_NEEDED`) and draws the account as it stands, where
  `AgencyTermLines::standing()` says `AgencyRenewalPending` under the end while one waits.

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

**Premium emoji in names and in what Telegram shows as typed.** A plan's, a category's or a server's name stays plain
everywhere — the panels, the mini app, the website, the reports —; the premium emoji the shop picked for its leading
emoji is kept beside it (`emoji_id`; a server's in its `meta`, through `Emoji\PremiumEmojiField`, a field of the
generic form of type `premium_emoji` whose `bound_to` names the name field, so the panel draws its picker beside it).
`Texts\PremiumText` is such a name as a bot text's value (`plan()`, `category()`, `server()`): `BotTexts` fills it in
with its leading emoji as a `<tg-emoji>`, and `nameEmoji()` judges a request's `emoji_id` — digits, a name that starts
with an emoji, never the tag written into the name itself. A wording Telegram shows as typed — a button's label, a
popup's notice (`TextKind::html()` false) — reads no tag, so `Texts\PlainWording` turns each premium emoji into what it
can show: a button's one premium emoji at the start of its label — or right after the label's own leading emoji, which
it then takes the place of («🌍 %server%») — becomes the button's icon, which the API client sets as it sends
(`icons()`, Telegram's `icon_custom_emoji_id`); a notice shows its plain emoji (`shown()`); and the same words sent as a
message keep it (`html()`). Its `problem()` refuses any other tag in such a wording, and in a button's label more than
one premium emoji, one anywhere but at the start, or one with no words beside it.

**The shop's premium emoji in every text** — `Emoji\EmojiMap` («ایموجی پرمیوم متن‌ها»), kept per shop in the settings
row `bot.premium_texts`, `{enabled, items: [{emoji, emoji_id}]}` (`stored()`; the row is forgotten when saved off and
empty):

- **`save($input)`** takes the switch and the whole list. `enabled` turns on (from off) only while `canEnable()` —
  `PremiumEmojiStatus::current()['ok'] === true` —, else a 422 on `enabled` (`NOT_ABLE`); one on stays on, and its list
  may change, whatever was found since. `items`, at most `MAX` (100): each `emoji` one emoji as a reader sees it
  (`isOne()`: `PremiumText::leadingEmoji()` of it is itself), unique by `key()` — U+FE0E and U+FE0F dropped, so ❤ and ❤️
  are one (`TWICE_EMOJI`); each `emoji_id` 1 to 20 digits (`ID_REFUSED`), unique (`TWICE_ID`), and one the list had or
  the bot knows (a `CustomEmoji` row; else `NOT_KNOWN`). Refusals go on `items.N.emoji` and `items.N.emoji_id`.
- **`apply($wording, $kind)`**, while on: in a kind Telegram reads as HTML (a message, a caption, a part),
  `TelegramHtml::mapWords()` rewrites each run of words outside `SKIP` — `tg-emoji`, `code`, `pre`, `a`, `tg-time` —:
  each grapheme (`\X`) whose `key()` the map has, and that `TelegramHtml::isEmoji()` calls an emoji, becomes
  `<tg-emoji emoji-id="…">…</tg-emoji>`; applied twice, it changes nothing. A button's wording and a popup's come back as
  they are. It never asks `PremiumEmojiStatus`: while the bot is found unable, `BotApi` sends the message plain
  ([below](#telegrams-api)).
- **`label($wording, $label)`** decides a button's icon on its filled label: the leading emoji wrapped when the unfilled
  wording starts with it, the map has it and the label still starts with it — not when a `<tg-emoji>` follows it after
  spaces (a name's own, `PremiumText`, which is the icon then), nor when nothing does (Telegram takes no button without
  words). `PlainWording::icons()` makes it the icon as `BotApi` sends.
- **`icon($label)`** is the id the map gives a menu label's leading emoji (null while off), for `KeyboardLayout`
  ([below](#keyboards-and-gates)); **`uses($emojis, $wordings)`** counts by `key()` the wordings each emoji would be drawn
  in, through the same rewrite whatever the switch — the API's `texts`.

`TelegramHtml::mapWords($text, $map, $skip)` passes each run of words outside the tags `$skip` names through `$map` —
the tags, their attributes and the words inside them untouched; a text with no «<» is mapped whole, and one `problem()`
refuses comes back as it is. `BotTexts::compose()` maps the wording in use (`template()`, unfilled) with `apply()`
before `marked()` and `Messages::fill()` put the values in — so a filled-in value is never mapped —, and passes a
button's filled label through `label()`. `BotTexts::wordings()` is every text `TextCatalog::says()` in the shop, as
`[kind, template]`.

`Emoji\PremiumCheck` finds out whether the bot may send premium emoji, the one way Telegram allows: `sample()` is the
list's first item, else the newest premium emoji the bot knows (null for none); `probe($chat, $sample)` forgets the
status (a "no" held would have `BotApi` send it plain), sends «🔍 بررسی ایموجی پرمیوم: …» with it, records whether a
`custom_emoji` entity came back, and deletes the message (best effort: left, it says what it is) — null when it could
not be sent, nothing learned and the status as it was before put back (`PremiumEmojiStatus::restore()`). `/emoji`
(`CustomEmojiHandler`) does the same with its own copy.

The panels' API, `Admin\Api\PremiumTextsController` in `routes/api.php`'s `$configuration` group:

- `GET /bot/premium-texts` → `{premium_texts: {enabled, items: [{emoji, emoji_id, texts}], emojis: [{emoji, texts, menu,
  emoji_id}], status: {ok, checked_at} | null, can_enable, max}}`, `texts` from `EmojiMap::uses()` over
  `BotTexts::wordings()`. `emojis`, the list the admin swaps from, is `EmojiMap::inventory()`: every plain emoji the
  wordings carry where the map would draw it (`rewrite()` with no map finds them all, drawing none) and the leading emoji
  of the start menu's buttons — the menu as a customer is shown it (`MainMenu::shown()`: programs switched off left out,
  the built-in menu when nothing is left), its buttons `KeyboardLayout::iconable()` lists (an inline menu's with no icon
  of their own, the ones `premium()` gives one) —, counted by `key()`, the most carried first, each with the map's
  premium emoji or null; then the map's emoji nothing carries any more. Signs that are emoji only by their code point
  (━, ★, ✓) are left out unless the map has one: `drawnAsEmoji()` takes a variation selector or keycap mark, a
  pictograph past U+1F000, or a character of Unicode's Emoji property below it (`DRAWN`, held to Unicode by the panel
  mirror's test). A half-space (U+200C, or U+200D) a grapheme ends in is no part of the emoji (`untailed()`): split off
  before its key and kept after its tag when drawn; a button's or a menu label's leading emoji ending in one is left, as
  `label()` and `icon()` leave it. `save()` trims it off an item's emoji and refuses a sign new to the map with
  `NOT_EMOJI` — one saved before stays;
- `PUT /bot/premium-texts` `{enabled?, items?}` (the whole list) → the same;
- `POST /bot/premium-texts/check` → the same, with the new status. It goes to `?chat=` only through the bot's door
  (`Principal::$inBot`: the chat the admin tapped in); for an agent, to their own account, from their bot; for the owner,
  to the main shop's linked accounts (`OwnerLinks::ids()`), each tried until one is sent. A 422 on `check` says why not:
  `PremiumCheck::NOTHING_TO_TEST`, `NOWHERE` (no account), `OTHER_BOT` (the owner on an agent's shop, whose bot has no
  chat with them) or `NOT_SENT`.

The bot's screen is `Sections\Bot\Texts\PremiumTextsScreen` (`txp`), the panel's card
`components/bot-texts/premium-texts-card.tsx`; the settings search has both at `/bot-texts`
(`scripts/settings-index.mjs`'s `SECTIONS` → `PremiumTextsCard`, `SettingsIndex::SECTIONS` → `txp`).

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

The shop's premium emoji ([above](#texts)) give an inline menu its icons: `MainMenu::markup()` sends
`KeyboardLayout::premium(EmojiMap::icon(...))`, where each inline button with no `icon` of its own whose label starts with
a mapped emoji has that emoji wrapped as a `<tg-emoji>` in its label — which `BotApi` sends as the button's icon, and
plain again, in the label, when the message goes plain; a label of the emoji alone is left. «⚙️ مدیریت» is added after
(`withManagement()`) and never gets one. A reply keyboard is left as it is: its tap sends the label without its icon, and
those words alone would be taken for the button wherever a customer typed them — a step's answer too —, so there only a
button's own `icon` shows, and labels route as they always did (`labels()`, `actionFor()`).

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
update back answers it itself. Whoever manages the shop passes them all (`AdminSeats::exempt()`: a role admin, the
owner's linked account in the main bot, the agent in their own bot), and so does the `/start` of the owner's open link
(`AdminSeats::passGates()`), so nobody who manages is locked out. A `/start` link a gate held back is kept as the chat fact
`_start` (`Dispatcher::keepStartLink()`) — only what a t.me link can carry (Latin letters, digits, `_` and `-`, 64 at
most), with when it was kept — and taken by the update that gets through within the hour — the contact card, a later
`/start` or `/menu` (`StartHandler`, which `ContactHandler` hands the card to), or «عضو شدم» (`JoinHandler`) —, so a
newcomer the gates stopped still lands
where the link leads (`ChatSession::takeStartLink()`; an older one goes unopened). `ChannelMembership` trusts a
confirmed membership for a few minutes (`RECHECK_AFTER`). A channel Telegram cannot answer for counts as joined, because
a broken channel must not lock the shop; it is flagged for the admin only when the channel itself is the reason — not
a moment's outage, nor a refusal about this one customer (an id Telegram does not know there:
`Api\Refusal::aboutTheUser()`).

## The mini app

The shop's Telegram mini app is the Store module's ([Internals: website API](Internals-Website-API.md#mini-app-settings),
[Internals: mini app](Internals-Mini-App.md)); the bot only opens it. It learns where through its own interface,
`Contracts\MiniAppAddress` — `url()`, the app's address while it is switched on and on https, else `null` —, which
`Store\Services\MiniApps` implements (bound in `bootstrap/container.php`), so no Telegram file names a Store class.

- **The menu button** beside the message box is set by the Store module as the owner saves the mini app
  (`MiniApps::applyMenuButton()`, `BotApi::setChatMenuButton` and `getChatMenuButton`), never by a handler.
- **The main menu's `app` action** («📱 اپلیکیشن فروشگاه», `MainMenu::APP`, `menu:app`) is in a fresh shop's layout
  under «خرید اشتراک», and switched like a program's: `MainMenu::markup()` leaves it out while `url()` gives none. In an
  inline menu `KeyboardLayout::markup($opens)` makes its button a `web_app` button (`InlineKeyboard::webApp()`), which
  opens the app with the customer's launch data. On a reply keyboard it is a label like any other — Telegram hands an
  app opened from a reply keyboard's button no launch data to sign in with —, so its tap draws a screen
  (`MenuHandler::app()`, `BotText::AppScreen`) with that inline button under it (`AppButton`), or, the app gone since the
  menu was drawn, `AppUnavailable`.
- **A contact card** that passed the gates goes to `ContactHandler` (the `contact` route). The card the phone gate took
  on this very update (the customer's phone just changed) opens the bot as `/start` does, the flow left. Any other — the
  mini app's «share my number» (Telegram's `requestContact`) sends the customer's own card whenever it is tapped, at
  whatever step the chat is — gets a word alone, the chat left at its step: `PhoneVerified` for their own card with a
  number on file, else `PhoneNotNeeded`. Only the phone gate stores a number, so nothing the shop did not ask for is
  kept.
- **A service's name** as its customer reads it in the screens they open is `Subscription::shownAs()`: their own name
  for it (`label`, set from the mini app or a website) beside its client name — «گوشی من (ali_1)» —, or the client name
  alone. Direction marks keep it in order inside a Persian line whatever its letters: a right-to-left mark after the
  name and after the bracket, so a Latin name keeps its brackets, and a left-to-right one before the client name, which
  may start with digits. The services list and the screens a service opens — a renewal, extras, a transfer, a ticket's
  service — and their buttons use it. A service's card (`ServiceCard::values()`: a delivery, the service's details, the
  reminders and the notices built from it) fills `%client%` with the client name alone, its line to copy; the panels
  and the report group name a service by its client too.
- **The query budget**: every menu reads the shop's mini app row once, to know whether its button shows — one query more
  on `/start` and on any message that lands on the menu (`BotQueryBudgetTest`).

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
  leaves its buttons in place for another try; only a request decided meanwhile takes them away. A renewal request's
  «تایید» picks no level: `AgencyReview::renew()` gives `AgencyActions::termEnd()` at once, its refusals — no term, no
  end, past that day — kept as a popup over the buttons; its report and verdict are `ShopReports`' own wording.
- **The backup** — `Topic::Backups` has no queued report: `Backups\Tasks\SendBackupsTask` sends the shop's file to
  its thread itself, at its own time, apart from the queue and its pace ([Architecture](Architecture.md#backups)).

### A customer's card

The panel's customer page, in the group: `Reports\UserManagement` is the card's front — «⚙️ مدیریت کاربر» under every
report about a customer (`ShopReports::managing()`, only for a customer of the group's shop) posts one under it, and
stays when the report's other buttons go (`GroupButtons::buttons()` and the sender's `clears_buttons` keep the rows
`UserManagement::kept()` finds; a customer gone takes only their own button off, in Telegram and in the report's
row, `GroupButtons::forget()`) — and
`/manage <who>` (`COMMAND`) anywhere in the group answers with one, `<who>` a
Telegram id, an @username or the customer's number in the shop (`#12`). The front says who they are and opens the
sections, each a handler of its own callback prefix under `Reports\Customers` that rewrites the card in place, with
«⬅️ بازگشت» back to the front: `CustomerServices` (a service's sync, time and traffic, switch off and on, deletion),
`CustomerWallet` (an increase or a decrease with a note; a decrease into debt asked again, its «✅ تایید» acting once
under the question's key: what it writes — the customer, the amount, the question, the note — recorded on the card
with its nonce by `CustomerCards::replyNonce()`, never read from the button's data; «⬅️ انصراف», or a new ask on the
card, drops it, `CustomerCards::dropNonces()`), `CustomerOrder` (a service made by hand, as the panel's
«افزودن سفارش»: `ManualOrders`, the confirm button carrying a nonce as the order's key), `CustomerDiscount`,
`CustomerHistory` (orders, payments, tickets, referrals — reading only), `CustomerAccount` (the website's sign-in:
two-factor off, every device signed out) and `CustomerMessage` (a message from support, as the panel's «ارسال پیام»,
on every card but the admin's own); the groups, the phone excuse, the role and the ban are the front's own.

- **What every section shares** is `Customers\CustomerCards`: `press()` checks a press once for all — in the shop's
  connected report group, by one of its bot admins (`GroupButtons::admin()`, never the button's data), about a customer
  of the shop — and hands the section a `CardPress`; `header()`, `back()`, `show()` and `refused()` are the card's look;
  `mayChange()` and `lockOf()` the report group's own rule: never the admin's own account nor an agent's (the panel's),
  which the card only reads.
- **Typed input** — an amount, a percent, a price — is a question: `ask()` posts it under the card as a ForceReply and
  records it as a `report_messages` row whose `ref` names the section, the kind, the admin, the card and its arguments.
  `ReportGroupUpdates` routes a reply to it back through `reply()` — by the message id it answers, written by one of the
  bot's admins — as a `CardReply` to that section (`AnswersPrompts::answer()`), which ends it with `done()` (the card
  rewritten, the question deleted) or `retry()` (a word under the reply, the question kept).
- **Every change** goes through the service the panel uses, under the admin's name (`Actor::groupAdmin()`), by its
  rules — `UserActions` (a wallet, the role, the ban, the phone excuse, the groups, the sign-in, a message),
  `SubscriptionActions`, `PersonalDiscounts::set()`, `ManualOrders` — and a refusal is a popup. Never an admin banned,
  put in a «همکار» group, or their discount or website sign-in changed.

## Broadcasts

`/broadcast` (`Handlers\BroadcastHandler`) builds a draft card in the session; «ارسال» claims the draft once and starts a
run (`Broadcasts\BroadcastService`). A run is worked by whoever holds its lease — the bot for the first `INLINE_LIMIT`,
then `SendBroadcastsTask` — past a cursor, **each customer claimed before their message goes**, so nobody gets it twice.
Sends are paced (`Api\Pacer`); a flood limit puts the customer back for the next batch. Pause, resume and cancel are a
compare-and-swap on the status. A message run whose admin has no Telegram chat any more (the account gone, or its
Telegram taken off) has nothing to copy its message from: it ends cancelled («لغوشده»), logged. `Audience` decides who
is reached; an audience whose group or server is gone reaches nobody, never everyone. The management's «➕ پیام همگانی
تازه» starts the same compose (`BroadcastHandler::compose()`), in the bot of the shop on screen only.

## The shop's management

`Admin/` runs the shop from the bot's private chat ([Managing the shop from the bot](Bot-Management.md)): the panels'
sections as the bot's own screens, **every read and every write through the panels' own API**, so no rule lives twice.

**Who manages** — `AdminSeats::of()`, asked on every update, gives an `AdminSeat`: its kind (`SeatKind`), the
`Principal` its requests run as, and the account. In the main bot, a Telegram account `OwnerLinks` holds (the main
shop's setting `owner.telegram_ids`, filled by the one-time `/start owner-<code>` link `StartHandler` redeems: the code
kept as its sha256 and spent by a compare-and-swap, `LINK_MINUTES` to open it, at most `MOST` accounts; the panel's
`POST /settings/owner-telegram/link` asks `current_password` unless the request carries the door's `BotAdminDoor::SEAT`,
and `OwnerAuth::changeCredentials()` ends every link with `OwnerLinks::revokeAll()`) is the owner, in
the shop the chat picked — and so is every role admin of the main bot: an Owner `Principal` with their account as its
`customer` and their own name (`Reviewers::forAdmin()`), no grants. In an agent's bot, while their agency stands (as
`AgentAuth` asks it), the agent's own account is the agent, and every role admin of it has the agent's seat the same
way. There is no third seat and no switch: `SeatKind` is `Owner` or `Agent`, and the role is asked on every update. A
ban ends any of them. `AdminSeat::isAdmin()` (a `customer` on the principal) marks one of the admins: services refuse
them a decision about their own account (`Actor::is()`, `ActorRefusedException::own…()`), `Reviewers::present()` shows
them the owner's login as «پشتیبانی», and what is the owner's own — the linked accounts (`OwnerAccountsScreen`, the
`/settings/owner-telegram` routes, `ActorRefusedException::ownerOnly()`), a linked account's ban and role
(`ownerBanned()`, `ownerRole()`), the Bot API's address and the main bot's webhook secret (`ConfigSettings`:
`ownersPassword()`, `ownersSecret()`) — asks `Principal::isTheOwner()` (`Actor::isTheOwner()`); an agent's own account
in their bot is banned by `Actor::isTheAgent()` or the owner alone (`agentBanned()`). `AdminSeat::in()` carries an
admin's account in the shop picked, so `Actor::is()` holds there too. Every such principal has `inBot`, which reaches
`Actor::$inBot`: a ticket's answer from here is `TicketChannel::Manage`.

**The door** — `Door\BotAdminDoor`, mounted in `routes/api.php` at `/api/bot-admin/{owner|agent}` over the panels' own
route groups: the owner's `$operations`, `$configuration`, `$system`, `$everywhere` (with `shop.everywhere`), `$main`
(with `shop.main`) and `/shops`; the agent's `$agentAccount`, `$operations` and `$configuration`. It has no session,
cookie or CSRF header: a request gets in only with an `AdminSeat` in its `SEAT` attribute, which only the process can
set — from outside, every path is a 404. Then the seat's kind must be the door's (an agent's seat never takes the
owner's); no grant is asked. The request runs in the seat's shop as its principal, and every change (not a GET) is
logged with the seat, the path and the status.

**The bridge** — `Door\PanelBridge::for($seat)` builds each request (`get`, `post`, `put`, `patch`, `delete`, `upload`
for a multipart file, `file` for an answer that is a file) and hands it to the app's own HTTP kernel in-process: the
panels' validation, refusals, change feed and log lines, the same JSON. A 2xx answers its body; anything else is a
`Door\ApiRefusal` with the status, the Persian message and the field errors. Paths are the panels', without
`/api/admin`.

**The handler** — `Ui\AdminHandler` takes `/admin`, `/panel`, the «⚙️ مدیریت» label (`MainMenu` adds it for a seat), the
`Nav::PREFIX` taps (navigation) and the `Admin::INPUT` and `Admin::INPUT_ANY` states; without a seat it hands the update
to the fallback. A chat's updates take turns (a lock file a chat, in `storage/cache/bot-admin-turns`): two taps a webhook delivers
together run one after the other, the second reading what the first saved. One message is the window: a tap edits it
(a screen turning into a picture or back takes a new one), a command, the label or a typed answer sends it anew and
deletes the last; a typed answer is deleted once read, whatever came of it. An `ApiRefusal` is a popup for a tap, and
for a typed answer the question asked again as it was asked — its hint and quick choices — with the refusal over it,
still waiting. A file Telegram refuses (its size limits) is said in a popup.

**Screens** — `Ui\Screen` (`allows()`, `show()`, `act()`, `input()`, each answering a `Ui\View`), registered by a short
id in `Ui\Screens`: the core's (`h` the home, `sr` the search, `ba`, `ol`, `shop`, `pgi` the pages' middle button) and
each area's `Sections\<Area>\<Area>Screens::SCREENS`. The home's entries (`Screens::homeEntries()`) and the hubs (`hl`,
`hs`, `hm`, `hb`, `hc`) are lists of ids there, a hub's entry with the queue it counts (a badge; the home's waiting work
is its «🔔 در انتظار» buttons instead, `HomeScreen::QUEUES`); an entry whose screen is
missing or that the seat may not open is left out. **Each section has one entry**: a feature's settings card is reached
from the feature's own screen, never from «🤖 تنظیمات ربات» too; a window of 0.10.0 that opened one from there still
carries `from.<menu>` (`Settings\Origin`), and its «⬅️ بازگشت» goes back there. The bases: `BaseScreen`; `ListScreen`;
`Settings\SettingsScreen`; `HubScreen`. A button is `Button::to(label, screen, action, ...args)` — its label cut at 48
characters, its callback `Nav::to()`'s `a:<screen>:<action>:<args>`, 64 bytes at most, each part `[A-Za-z0-9_.-]` (Nav
throws otherwise): never text in it.

**The words and the icons** — `Ui\Words` holds every word the management repeats, and a screen never types one again:
moving around (`BACK` «⬅️ بازگشت», `HOME` «🏠 خانه», `REFRESH`, `HELP`, `SEARCH`, `CLEAR_SEARCH`, `PREVIOUS`, `NEXT`), the
actions (`EDIT`, `CREATE` «✔️ ساختن», `SAVE` «💾 ذخیره», `DELETE`, `CANCEL` «✖️ انصراف» — the one way out of a question, a
confirmation or a form —, `TURN_ON` / `TURN_OFF` for a setting, `ACTIVATE` / `DEACTIVATE` for a row, `UP`, `DOWN`, `CLEAR`
«🗑 خالی کردن», `RESET` «↩️ پیش‌فرض», `PREVIOUS_STEP`, `SKIP`), the chips (`ON`, `OFF`, `ACTIVE`, `INACTIVE`, `PENDING`,
`FAILED`, `REJECTED`, `NONE` «—», `PICKED` «✅ », `CHIP` «● »), a multi-pick's marks (`tick($on)`: `PICKED` or
`UNTICKED` «◻️ »; `TICK_PAGE`, `TICK_NONE`), a question's lines (`CURRENT`, `EXAMPLE`, `LIMITS`, the refusals
`NOT_NUMBER` and `NOT_TOMAN`), the home's (`WAITING`, `NOTHING_WAITING`, `OLD_BUTTON`), and the builders: `edit($label)` «✏️ …»,
`add($noun)` «➕ افزودن …», `yes($what, $icon)` «✔️ بله، …», `copy($what)` «📋 کپی …», `turn($on, $label)` «▶️ روشن
کردن «…»» / «⏸ خاموش کردن «…»» (the state a tap sets), `page()`, `step()` «گام ۲ از ۶». `Ui\Icons` gives each thing
one emoji — a section, a hub entry, a screen's title, a settings card — so it looks the same wherever it is reached
from: a title is `Icons::X . ' ' . its name`. An area's own words live beside it (`Sections\Sales\Sales`,
`Sections\Marketing\Words`, `Sections\Owner\Words`…).

**The view** — a `View` is the title, the path under it (`View::make(title, ...path)`, small: «مدیریت › فروش ›
پرداخت‌ها»), what was just done over the title (`notices()`), its lines and rows of `Button`s:
- `card(facts)` — the summary card, a fact a line in one `<blockquote>` («label: <b>value</b>», a null value left out,
  an empty one «—», a value in `<code>` not made bold); `section(heading)` — a blank line and a bold heading;
  `help(html)` — help folded away, `<blockquote expandable>❔ …</blockquote>`; `note(html)` — one italic hint;
  `total(count, noun, ...filters)` — a list's «۲۳ پرداخت · در انتظار بررسی».
- `row(...)` — buttons in **reading order**: `keyboard()` reverses each row, as Telegram draws a row left to right (the
  customer keyboards do the same), so the first button written is the rightmost; a null button is left out. `grid(n,
  ...)` — rows of n; `tiles(...)` — two a row where both labels are short (`TILE_MOST`), else one; `pager(page, pages,
  screen, action, ...args)` — «‹ قبلی» · «۲ / ۵» · «بعدی ›», the middle a button to `Screens::PAGE` (`PageScreen`),
  which answers «صفحه ۲ از ۵» in a toast and leaves the window as it is (`View::stay()`); `paged()` — choices
  `CHOICES_MOST` (40) a page; `slice()` — a page of a list read whole.
- `back(screen, action, ...args)` — **the one way around**, drawn under every other row whatever was added after it:
  «⬅️ بازگشت» and «🏠 خانه» (the home alone when the way back is the home); `refresh(screen, ...)` adds «🔄 تازه‌سازی»
  beside them, for a screen whose figures move (the home, the dashboard, a list, a queue).
- It cuts a text past `TEXT_MOST` (4000) between lines — a quote never cut open — and a caption past `CAPTION_MOST`;
  `picture()` makes the screen a picture (a receipt); `transient()` marks a question or a confirmation, which takes no
  place of its own in the trail the next one shows.

**Buttons** — `Button::to()` an action, `Button::url()` a link, `Button::copy(label, text)` a value copied whole at a
tap (Telegram's `copy_text`: an address, a key, a link, a code, a card number). A copy button of no value, or of one past
`COPY_MOST` (256 characters, what Telegram copies), is left out of the keyboard (`fits()`): a key or a link cut short is
a wrong one; the value stays on the screen to copy. A button to an action takes a
colour — `success()` green for what confirms, saves, creates, turns on or approves; `danger()` red for what deletes,
turns off, rejects or disconnects; `primary()` blue for the screen's one main action (at most one); each is a new button.
`labelled()` renames one, `markup()` is Telegram's shape and `kept()` reads it back (a waiting question keeps its
buttons so).

**Questions and confirmations** — `Ui\Prompt` is a question: its field, the screen, action and arguments its answer goes
to, a hint, `current(value, copyable)` («مقدار فعلی», shown to copy; a secret's «ذخیره شده» not), `example()`,
`limits()`, quick picks (`choices()`), `clearable(screen, action, ...)` «🗑 خالی کردن», `resettable(...)` «↩️ پیش‌فرض»,
and a wizard step's `ways()` («⬅️ گام قبل», «⏭ رد شدن») in a row of their own; `file` for a picture or a file, `anyText`
for an answer that may be a menu button's label (`Admin::INPUT_ANY`). `Admin::ask()` draws it and waits in
`adm.input`; the answer reaches the asking screen's `input()` as an `Answer` (`text()`, `latin()`, `integer()`,
`amount(Unit)`, `decimal()`, `numbers()`, `picture()`, `document()`); refused, it is asked again as kept
(`Prompt::kept()`), «⚠️» and the reason over it. `Admin::confirm(question, yes, no, detail, danger)` asks before a
change that is not undone: the yes is green, or red where it destroys — `danger: true`, or left null a yes whose label
starts with 🗑 🚫 ⛔ 🛑 ✂️; build it with `Words::yes('حذف شود', '🗑')` so the icon says it —, a yes given its own colour
keeps it. Both draw any cancel word their way out has as `Words::CANCEL`. A question two screens share has one home:
an agency's end is `Owner\Agency\AgencyEnd::prompt()` (typed days or a Jalali day, «بدون پایان», `DAYS` from now, a
screen's own picks first) and `read()`, asked by an agent's terms (`TermsScreen`) and by a renewal approved
(`AgencyRenewScreen`, `agrn`: a renewal's «✅ تایید» on `agrv` opens it, and `AgencyApproveScreen` hands it an older
window's renewal), which sends the program's end as no `expires_at` for the API to reckon.

**Settings cards** — `Settings\SettingsScreen` is a card of the panel's as `FieldDef`s over a `SettingsSource`
(`BotGroupSource` for a «تنظیمات ربات» group, `PatchSource`, `PutSource`), saved a field at a time as the panel saves
the card. Its summary says every field's value once (a switch as its chip, the ones its conditions hide left out); its
buttons say what they do — a switch `Words::turn()`, carrying the value it sets (so a second tap changes nothing), and
asking first where `FieldDef::asks()`; any other field `Words::edit($label)`, its question with the value now, an
example and the limits — a «تنظیمات ربات» card takes a number's bounds and default from the server's own form
(`BotCard::described()`) —, «🗑 خالی کردن» where `FieldDef::nullable()`, «↩️ پیش‌فرض» where `FieldDef::byDefault()`. Hooks: `parts()` — the panel's FormParts (`[label, [api names]]`), each a
screen of its own whose way back is the card, opened by `SettingsScreen::PART` (`p`, n); `chips()` — a card of many alike
switches shows each as a chip («🟢 خریدها»), a tap the other state, left out of the summary (the report's topics);
`warnings()`, `explain()` (the panel's hint that follows a value), `notes()`, `shows()`, `extra()`, `intro()`. **A field
is opened by its API name** from anywhere — a search's result, a warning's way to fix it —: `Button::to(Words::edit($label),
Card::ID, SettingsScreen::FIELD, 'topup_min')` asks a typed field, offers a choice's choices, and shows a switch on its
card or part, never flipping it; a field another one hides now shows its card, saying which field shows it («این گزینه
با روشن کردن «…» دیده می‌شود.»).

**Lists** — `ListScreen` reads a panel list route (`endpoint()`, its rows under `key()`), `PER_PAGE` (8) rows cut from
the panel's pages of 25 (or from every row, for a route that answers them all). It says **first** how many and what
narrows them — `total()` with `noun()`, the chip in force, the search and `narrowedBy()` (a select, a range of days:
«تاریخ: ۷ روز اخیر») —, then `summary()` (what the list adds up to), the rows (`item()`, each a button that is its row:
chip, name, key figure), `extra()` (the list's own actions: «➕ افزودن …», a bulk action), the `filters()` as chips with
`Words::CHIP` on the one in force, «🔎 جستجو» beside `tools()` (one or two: «🎛 فیلتر و ترتیب», «↕️ ترتیب», «✖️ حذف
فیلترها»), the pager, and «🔄 تازه‌سازی». `keepsRows()` keeps a page's rows for a row's screen where no route reads one
row, and that screen reads its row back with `ListScreen::keptRow()`. The filter, the search and the page are the
screen's state; a hook reads a filter in force with `filterValue()`.

**The old button** — a tap whose screen is gone, whose data Nav cannot read, or that the seat may no longer open lands on
the home, `Words::OLD_BUTTON` over it («⚠️ این دکمه قدیمی است؛ از همین‌جا ادامه دهید.»), never an error; so does an
answer to a question whose screen went. Screen ids and callback formats stay as they are once released, so an older
window's buttons keep working.

**The search** — `Core\SearchScreen` (`sr`, «🔎 جستجو» on the home) asks one value and looks for it through the lists'
own searches (`/users`, `/orders`, `/payments`, `/subscriptions`), those the seat may open: `SHOWN` (5) rows of each, a
phone typed the Iranian way read as its last ten digits, past them a button that opens the list searched.

**The settings search** — «🔎 جستجوی تنظیمات» (`Panel\Search\SettingsSearchScreen`, `ss`, every seat) searches
the panel's own settings index: `Panel\Search\PanelSettings` is a copy of the panel's
`lib/settings-search/settings.generated.ts` that `pnpm settings:index` writes beside it (a release ships no panel
source), never edited by hand. `Panel\Search\SettingsIndex` maps it onto the bot: `SECTIONS`, each panel address → the
bot screen, its name, the menus it is under and other words; `SETTINGS`, the settings opened otherwise than their section
— `f` a field by its API name (`SettingsScreen::FIELD`), `p` a part, `s`/`a` another screen and its arguments, `only` why
the panel alone sets it, `w` more words. `find()` reads what is typed as the panel's search does (`normalize()`: Persian
however typed, Arabic letters, digits and half-spaces folded), finds every word typed (AND) in a title, its words, its
place or its hint — whole, as a word's start, inside a word, or a letter off in a title —, ranks the title most and keeps
`MOST` (20), what the seat may open. **`SettingsParityTest`** holds the two together: `PanelSettings` is
`settings.generated.ts` word for word; every panel section has its place in `SettingsIndex::SECTIONS`; every place names
a screen the management has, and a field or a part that card has; what the panel alone sets says why. A setting the panel
adds fails it until the bot has a place for it ([Extending](Extending.md#a-setting)).

**State** — `Admin` is one update as a screen sees it: `seat`, `api` (the bridge for the seat), `state()`,
`remember()` and `forget()` per screen (the chat fact `_adm`, so a customer's flow in between loses none of it — the
owner's shop pick too: `pickShop()` empties every screen's state), `open()` another screen, `ask()`, `confirm()`,
`notice()` (a toast for a tap, a line atop the next screen after a typed answer), `alert()` and `send(ApiFile)`.
`here()` keeps where the window is, for the path a question or a confirmation shows. `Ui\Fmt` formats what a screen
shows (`e()` for whatever came from a person or the panel, `ltr()` and `code()` for Latin in a Persian line, money,
quotas, dates in the Jalali calendar, `onOff()` and `active()` for the chips, `badge()` for a count).

**Permissions mirror the door**: a screen allows every seat (`BaseScreen::allows()`) — the routes it reads are both
panels'; over the servers, the agency, the mass gifts and the shop switcher, `$seat->isOwner()`; over what the main shop
alone has (the custom services, the link claims), `$seat->shop()->isMain()`; over the panel's settings, `Access::main()`
(the owner in the main shop) or `Access::system()`; over the agent's account, `$seat->isAgent()`; over the owner's linked
accounts, `$seat->principal->isTheOwner()`. An admin's own account (`Customers\Customer::own()`) shows no wallet credit
or debit, order or own-discount button. Hiding is the screens' courtesy: the door and the services refuse anything else.

## Telegram's API

`Api\BotApi` is a thin client: one method per call, refusals as `TelegramApiException`, whose `refusal()` (`Api\Refusal`)
is the one place Telegram's codes and descriptions are read. A short flood wait is honoured once. Answering a tap and
deleting a message never throw. `BotApi::longestCall()` sizes every lease on work that calls Telegram.

**Premium emoji** go only while the bot's owner has Telegram Premium, which no call tells — `call()` finds it out from
what Telegram does with them (`PremiumEmojiStatus`, settings `bot.premium_emoji` `{ok, at}`). A call that carries
some — a `<tg-emoji>` in its text or caption, a button's icon; `withoutPremiumEmoji()` gives its plain twin, tags
unwrapped, a label's plain emoji back, icons off —:

- goes plain from the start while `refusedLately()`: a "no" younger than `HOLD_SECONDS` (600);
- else goes `capped()` — no more than `Limits::CUSTOM_EMOJI` (100, what Telegram draws in one message) `<tg-emoji>` in
  a `text`, a `caption` or a `media.caption`, the rest unwrapped — with its icons (`withIcons()`);
- refused with `Refusal::CustomEmoji`, or with any other 400 but `Refusal::NotModified`, is sent plain once, so a
  customer never loses a message to an emoji; only `CustomEmoji` records "no";
- sent, `learn()` reads what Telegram kept: for a `text`, a `caption` or an edited picture's `media.caption` read as
  HTML (`parse_mode`) with a `<tg-emoji>`, sent to a `private`, `group` or `supergroup` chat, a `custom_emoji` entity (in
  `entities` or `caption_entities`) records yes unless it was yes already; none records no — every message plain for the
  hold — only from a new message (`sendMessage`) to a `private` chat, the one place a drop is known to mean it, and only
  unless a "no" is still held: an edit, a caption or a group's message dropping them records nothing. `/emoji` (`CustomEmojiHandler`) and `PremiumCheck` record it from their own message.

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
budget of queries and Telegram calls — a budget raised says what the new query buys (the menu's mini app row, for one).
`miniApp()` switches the shop's mini app on for a test of its button (`BotMenuTest`). Unit tests of the API, texts and
keyboards are in `tests/Unit/Telegram`. The shop's premium emoji: `PremiumTextsTest` (the map in texts, parts, popups,
buttons, the menu and its taps), `AdminPremiumTextsApiTest` (the switch, the emoji the texts carry, the list's refusals, where the check goes) and
`PremiumEmojiTest` (what `BotApi` learns, the plain resend, the cap). The management's are in `tests/Feature/BotAdmin`: `BotAdminDoorTest` (the door's
guards), `BotAdminFlowTest` (an owner linked by `OwnerLinks::link()` and `redeem()`, `/admin`, taps by `Nav::to()`,
typed answers), `BotAdminFoundationTest` (the `Ui` layer as every screen uses it: colours, copy buttons, rows drawn
right to left, the one way around, the card and folded help, the pages' middle button, a question's value now and its
«🗑 خالی کردن» / «↩️ پیش‌فرض», a confirmation's colour, an old window's button landing on the home, the home and the
search), `SettingsParityTest` (every panel setting has its place in the bot — [above](#the-shops-management)) and a
file or more per area, named by its folder — `Support*`, `Customers*`, `Sales*`, `Catalog*`,
`Marketing*`, `BotArea*`, `Owner*` and `Panel*` (`PanelConfigTest`, `PanelWebsiteTest`, `PanelMiniAppTest`,
`PanelUpdateTest`, `PanelImportsTest`, `PanelOperationsTest`) —, each walking list → row → action → the API's effect, an
admin's seat where it differs (their own account, the owner's own screens), and a typed answer. See [Testing](Testing.md).
