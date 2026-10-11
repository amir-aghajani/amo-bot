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
| `Handlers/` | One handler per feature, and the shared pieces: `CheckoutScreen`, `CustomerServices` |
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

1. **Claims the update** (`ReceivedUpdates::claim()`): its id is recorded before anything is served, so a copy Telegram
   sends again is ignored. This is *at most once* on purpose — an update whose serving died halfway is not retried
   either: a second purchase or link change is worse than a lost tap.
2. **Ignores a flooding chat** — more than `FLOOD_UPDATES` in `FLOOD_SECONDS`: every answer is a Telegram call, and one
   chat must not slow the bot down for everyone.
3. **Sends a group's update to the group routes** (`groupCallback`, `groupCommand`, `group`): no user, no session, no
   gates. Customers are served in private chats only, and a private chat whose id is not its sender's is ignored (an
   agent's webhook can be posted anything).
4. **Resolves the customer** (`UserResolver`), refuses a banned one, runs the **gates** in order, then the first route
   that matches: a command, a reply-keyboard label, a contact card, a callback prefix (longest wins), the conversation
   state (longest prefix), else the fallback (the menu).

A route marked **navigation** (commands, labels, the menu's `menu:*` taps, the fallback, every ticket tap) clears the
flow the chat was in, so what the customer types next is never taken as an old step's answer. An **admin-only** route
(`/broadcast`, `/emoji`) answers anyone else nothing at all.

Telegram takes one answer per tap: `Context::answer()` sends only the first, and the dispatcher stops the spinner of a
tap nobody answered. A handler that throws is logged and the customer told — on the button while the tap is unanswered,
else in a message.

### Conversation state

`Session\ChatSession` holds the step a chat is at (`state`, e.g. `topup.amount`, `receipt:12`) and that step's scratch.
`enter()` moves to a step, `clear()` leaves the flow. Keys starting with `_` are the chat's own facts (whether a reply
keyboard is shown, a recent channel check, a kept discount code) and survive every flow. A last step that must run once
— a broadcast sent, a link changed, a wallet payment — ends with `claim(state)`, one conditional update, so of two taps
at the same moment only one acts. The dispatcher saves the session once, after the update.

### Callback data

`Update\CallbackData` is the one format: a prefix and `:`-separated arguments, 64 bytes at most (`plan:12:srv:3`).
Every prefix is a constant on its handler, with static builders (`PurchaseHandler::planCallback()`,
`SubscriptionHandler::serviceCallback()`); handlers read arguments with `$ctx->update->callbackArgs(PREFIX)` and never
build or split the string by hand.

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
  Buttons switched off with their program are hidden but stay on the admin's layout.
- **`CheckoutScreen`** is the one checkout of every purchase in the bot. `show()` draws what is bought with a button per
  way to pay; `buy()` runs the shop's checkout (`Payments\Services\Checkout`, the website's too) and renders the outcome.
  **Nothing is ordered until a way to pay is picked**, so a checkout left alone costs nothing. A wallet payment claims
  the checkout state first, so a double tap pays once. A discount code typed (`DiscountCodeHandler`) is kept in the
  session for that checkout only and judged again as it is paid (`priceToPay()`); handlers whose checkout takes a code
  implement `DiscountableCheckout`.
- **`CustomerServices`** is the one rule for which service a customer's button may open: their own, not gone from its
  panel.
- **Tickets**: `TicketHandler` draws the screens, `TicketMessageHandler` takes what the customer writes. After a message
  the chat stays on the ticket, like a chat, until the customer goes elsewhere, `REPLY_SECONDS` pass, or a notice of
  another ticket arrives (`leaveOtherTicket()`).
- **Pictures** (a receipt, a ticket's picture) are a photo or a file judged by its bytes: `Update::picture()` with
  `CustomerPictures::isPicture()`.

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
and goes in as it is. A wording is checked before it is stored (`BotTexts::problem()`, `TelegramHtml::problem()` reads
it as Telegram's parser does), because a text Telegram refuses would leave the customer with nothing. A wording equal to
the default is not stored, so an improved default still reaches every shop.

Lengths are counted as Telegram counts them: UTF-16 units of what is shown (`Api\Limits`, `TelegramHtml::
visibleLength()`). `Messages` keeps what the admin does not reword: the formatters' units, the default menu labels, and
the admins' own words (/broadcast, /emoji, the report group). `WebText` turns a text into plain text or safe HTML for the
website and emails.

Adding a text: a `BotText` case, its spec in `TextCatalog` (`TextCatalogTest` checks every default is savable), the call
through `BotTexts`, then `php scripts/docs.php`.

## Keyboards and gates

The main menu's layout is the admin's (`KeyboardLayouts`, settings `bot.keyboard.start`). Rows are kept in reading
order and reversed when rendered, since Telegram lays a row out left to right. A stored layout is read back through the
same checks as a save; one that fails them shows the built-in menu, so a bad row never takes the menu away. A reply
keyboard's tap arrives as its label, so labels are unique and double as routes.

The **gates** (`MaintenanceGate`, `PhoneGate`, `ChannelGate`, in that order) run before any handler; a gate that holds an
update back answers it itself, and bot admins pass them all. `ChannelMembership` trusts a confirmed membership for a few
minutes; a channel Telegram cannot answer for counts as joined and is flagged, because a broken channel must not lock
the shop.

## Delivering a service

`Notifications\ServiceCard` is the one place a service goes out: the subscription link as a QR card with the service's
text as caption, or the text alone when the card is off, GD cannot draw it or the text is too long for a caption — a
missing picture never costs the customer the link. A customer always gets the one subscription link, never the
per-inbound config links. Notices the shop sends on its own go through the Notifications module (kept for the website,
emailed when Telegram cannot reach the customer); see [Internals: services](Internals-Services.md).

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
  Telegram or trouble with the group pauses everything (`ReportGroup::hold()`); nothing sleeps and nothing is lost.
- **Acting** — the buttons (`ReceiptReview`, `DeliveryRetry`, `AgencyReview`, `TicketClose`) and replies
  (`TicketReplies`, a receipt's reason) work for bot admins only, judged by the account that pressed — never by being in
  the group or by the button's data (`GroupButtons::admin()`, `presser()`, `writer()`). They act through the same
  services as the panel, with the admin as `Actor::groupAdmin()`. A reply is matched to what it answers by the message
  id the bot recorded, never by that message's words, which may be a customer's.

## Broadcasts

`/broadcast` (`Handlers\BroadcastHandler`) builds a draft card in the session; «ارسال» claims the draft once and starts a
run (`Broadcasts\BroadcastService`). A run is worked by whoever holds its lease — the bot for the first `INLINE_LIMIT`,
then `SendBroadcastsTask` — past a cursor, **each customer claimed before their message goes**, so nobody gets it twice.
Sends are paced (`Api\Pacer`); a flood limit puts the customer back for the next batch. Pause, resume and cancel are a
compare-and-swap on the status. `Audience` decides who is reached; an audience whose group or server is gone reaches
nobody, never everyone.

## Telegram's API

`Api\BotApi` is a thin client: one method per call, refusals as `TelegramApiException`, whose `refusal()` (`Api\Refusal`)
is the one place Telegram's codes and descriptions are read. A short flood wait is honoured once. Answering a tap and
deleting a message never throw. A message Telegram refuses over its premium emoji (allowed only while the bot's owner
has Telegram Premium) is resent with plain emoji, and the "no" is remembered for a while (`PremiumEmojiStatus`).
`BotApi::longestCall()` sizes every lease on work that calls Telegram.

`Polling\Poller` keeps one long poll open per bot at once (`LongPolls`) and runs the scheduler in a process of its own,
because the polls only move while the poller waits on them. `Services\BotLifecycle` and `BotWebhooks` move bots between
webhooks and polling; `BotHealth` keeps why an agent's bot cannot run on its row.

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
