/*
 * The shapes of the API's answers and of the bodies its requests carry, and each API's writes by method and path,
 * generated from resources/api/openapi.yaml by `pnpm api:types` — do not edit by hand: change the description, then run
 * it again. The PHP tests check every request and every response against the same file, and so do the panels' tests.
 */

/** The shape of every refusal and failure, whatever produced it. A 429 also sends the wait in seconds in `Retry-After`. */
export interface ErrorResponse {
  /** A message for the reader: the admin on the panels, the customer on the Store API. */
  message: string
  /** Field errors, keyed by the field they are about */
  errors?: Record<string, string[]>
  /** The request's id, the same as the `X-Request-Id` header and the id on every log line of the request. The panel shows it on a server failure («کد پیگیری») so the request can be found in the log. */
  request_id: string
  /** Debug details. Only on a server failure (a 500, never a refusal), with APP_DEBUG on, and only for a request made directly from the server's own machine (never one forwarded by a proxy or tunnel). Paths are relative to the app's folder; call arguments are never included. */
  debug?: {
    exception: string
    detail: string
    trace: string[]
  }
}

/** A paged list's position. A sorted list's meta also has its order (`sort`, `dir`). */
export interface PageMeta {
  page: number
  per_page: number
  total: number
  last_page: number
}

/** The sort direction. `asc` puts the oldest, smallest or soonest first; `desc` the reverse. */
export type SortDirection = 'asc' | 'desc'

/** A reference to a customer, as every screen shows one: their id, the three Telegram identifiers kept separate (a customer who signed up on the website has only a name), and their email. */
export interface UserRef {
  id: number
  /** "first last" from Telegram, or as they signed up with; null when the account has none. */
  name: string | null
  /** Telegram handle without "@"; null when the account has none. */
  username: string | null
  /** Their Telegram user id; null for a customer who signed up on the website (the bot cannot message them). */
  telegram_id: number | null
  /** Their verified email, in lower case; null when they have none. */
  email: string | null
}

/** A whole number as a form sends it: a JSON number, or text (Persian or Arabic digits accepted). */
export type WholeNumberInput = number | string

/** An amount as a form sends it: a JSON number, or text (Persian or Arabic digits and thousands separators accepted). Toman are whole numbers: the API's own output form "120000.00" is accepted, any other fraction is refused. Gigabytes may have up to two decimals. */
export type AmountInput = number | string

/** A short list of whole numbers, such as the amounts a screen offers as buttons. A list, or text as the admin types it ("50, 100, 200", or separated by spaces; a comma between groups of three digits is a thousands separator, so "50,000, 100,000" is two numbers). Stored without repeats, smallest first. */
export type NumbersInput = WholeNumberInput[] | string

/** Support's note to the customer with a decision (a cancellation, a rejection, a service switched off, an agency refused or ended). It is a separate line of the message they get. At most 300 characters; blank for none. */
export interface NoteRequest {
  note?: string
}

/** Support's note on a refund. It is a line of the message the customer gets and the description of the wallet line the refund writes, so at most 190 characters (a ledger line's limit). Blank for none. */
export interface RefundRequest {
  note?: string
}

/** The rows of an admin-ordered list in their new order, by id. At most 1000. */
export interface ReorderRequest {
  ids: number[]
}

/** A row's on/off switch (a plan, a category). A JSON boolean only. */
export interface ActivationRequest {
  is_active: boolean
}

export interface Health {
  status: 'ok' | 'degraded'
  version: string
  installed: boolean
  /** Never the reason; that is in the log. */
  database: 'ok' | 'unavailable'
}

/** The answer of `GET /api/app`, which the panels call first. */
export interface AppInfo {
  /** The shop's name (APP_NAME). */
  name: string
  /** False until the web installer has finished; the panel then shows the installer. */
  installed: boolean
  /** The shop's time zone (APP_TIMEZONE, an IANA name such as Asia/Tehran). The panels show every date and time in it, as the bot does. */
  timezone: string
}

/** A field's kind, which decides how a generic form draws it: a line of text, a whole number, an amount of Toman, a short list of numbers typed as text, an on/off switch, one of a few values, a secret, an email address, a web address, a file path, a few lines of text, or a bank card number (drawn in groups of four and sent as digits). */
export type FieldType = 'text' | 'number' | 'amount' | 'list' | 'toggle' | 'choice' | 'secret' | 'email' | 'url' | 'path' | 'textarea' | 'card'

/** One field of a driver's form, described for a generic form (`Field::describe()`), so the screens need no driver-specific code. */
export interface FieldDescription {
  /** The key the value is sent under, and the field a 422 names. */
  name: string
  type: FieldType
  label: string
  /** The help line under the field. */
  hint: string | null
  placeholder: string | null
  /** The form is refused when it is empty. For a secret: a value must end up stored, and a blank one keeps the stored value. */
  required: boolean
  /** Never sent back (its value is a SecretState). Left blank or out, the stored value stays; `clear_<name>: true` empties it. */
  secret: boolean
  /** For a secret: the fields it belongs with. When one of them changes, a blank secret is refused (with the `moved` message) and must be typed again or cleared. */
  bound_to: string[]
  /** The server's message for a blank secret after a field it belongs with changed; show it as a hint before saving. */
  moved: string | null
  /** Drawn under «تنظیمات پیشرفته». */
  advanced: boolean
  /** A choice's values and their labels, in order. */
  options: {
    value: string
    label: string
  }[]
  /** The field is shown (and read, and required if it is) only while each named earlier field holds one of these values: a switch as "true" or "false", a number as its digits. While hidden, its stored value is kept. */
  when: Record<string, string[]>
  /** Shown after a number, such as «ثانیه». */
  unit: string | null
  /** The smallest value of a number, an amount, or each item of a list. */
  min: number | null
  /** The largest value. */
  max: number | null
  /** Latin content (a host, a username), drawn left to right like numbers, secrets, emails, web addresses and paths. */
  ltr: boolean
  /** The value while nothing is stored; null for a secret. */
  default: string | number | boolean | number[] | null
}

/** A driver (a database, a mail transport, a panel connector, a payment gateway, a captcha) as the panels show it (`Descriptor::toArray()`): its key, label and description, and its form with every field described. */
export interface DriverDescription {
  /** The name config.php, a database row or a request uses for it. */
  key: string
  label: string
  description: string
  /** Short notes under the description, such as required versions and caveats. */
  notes: string[]
  fields: FieldDescription[]
  /** Extra facts specific to the driver's family. A database driver: `installable` (the installer offers it). A mail driver: `sender` (which address it can send from, as text). A payment gateway: `kind` (`instant`, settling at once like the wallet, or `manual`, a card transfer whose receipt a person approves) and `builtin` (part of every shop; never added or deleted). A panel connector: `mark` (the two letters on its card), `vendor`, `docs_url` (its project's address), `inbounds` (services are sold on inbounds the owner picks; without it a server is sold whole) and `link_rotation` («تغییر لینک»). A captcha driver has none. */
  traits: Record<string, string | number | boolean | (string | number | boolean)[] | null>
}

/** A driver's field values by name, as a screen shows them (`Form::present()`): the stored values, else the defaults. A secret is a SecretState, never the secret itself. */
export type DriverValues = Record<string, string | number | boolean | number[] | SecretState>

/** The database settings in config.php: the driver DB_CONNECTION names, the drivers the admin may pick (each described with its form), and the chosen driver's stored values. */
export interface DatabaseSettings {
  /** The chosen driver (`DriverDescription.key`) named in config.php. */
  driver: string
  /** The installable drivers, plus the one the shop runs on */
  drivers: DriverDescription[]
  values: DriverValues
}

/** The database settings as a form sends them (the installer's step, and the settings screen's save and test): the driver (one of `drivers`) and its form's fields by name, as typed. A secret left blank or out keeps the stored one while the address it was given for stays the same (the installer has nothing stored, so it is empty); `clear_<name>: true` empties it. One closed shape per driver. */
export type DatabaseRequest = DatabaseMysqlRequest | DatabaseSqliteRequest

/** MySQL or MariaDB: the server, the account, the database and an optional table prefix. */
export interface DatabaseMysqlRequest {
  driver: 'mysql'
  host: string
  port: WholeNumberInput
  database: string
  username: string
  /** Left out or blank: the stored password stays, as long as the host, port and socket stay the same. */
  password?: string
  /** True empties the stored password. */
  clear_password?: boolean
  /** Blank for none. */
  socket?: string
  /** Blank for none. */
  prefix?: string
}

/** SQLite: a database file. */
export interface DatabaseSqliteRequest {
  driver: 'sqlite'
  /** The file's path, relative to the application folder or absolute; never under public/. */
  path: string
}

/** The installation's progress. The answer of `GET /api/install` and of every installer step. */
export interface InstallStatus {
  requirements: {
    name: string
    /** The installer's label for it */
    label: string
    ok: boolean
    /** Not needed to run the shop (only the panel's self-update needs it). Shown, but blocks nothing. */
    optional: boolean
  }[]
  /** Every non-optional requirement is met. */
  ready: boolean
  /** The database step's settings, and whether the database config.php names answers (not checked before the step has written one). */
  database: {
    /** The chosen driver (`DriverDescription.key`) named in config.php. */
    driver: string
    /** The installable drivers, plus the one the shop runs on */
    drivers: DriverDescription[]
    values: DriverValues
    connected: boolean
    /** Every table of database/schema.php exists. */
    tables: boolean
    /** Why the database does not answer, for the admin */
    error: string | null
  }
  admin: {
    configured: boolean
    username: string
  }
  site: {
    name: string
    url: string
  }
  telegram: {
    configured: boolean
    username: string
  }
}

export interface InstallFinished {
  installed: boolean
}

/** The panel's login: a username of 2 to 64 letters, digits, `.`, `_`, `@` or `-`, and a password of at least 8 characters and at most 72 bytes, typed twice. */
export interface InstallAdminRequest {
  username: string
  /** As typed; spaces at either end are part of it. */
  password: string
  password_confirmation: string
}

/** The shop's name and public address (APP_NAME, APP_URL), and optionally the main bot's token, which is saved once Telegram confirms which bot it belongs to. */
export interface InstallSiteRequest {
  name: string
  /** An http(s) address, including any sub-folder. */
  url: string
  /** From @BotFather. Blank or left out to set it later on the settings screen. */
  token?: string
}

/** Who is signed in to a panel, and the shop the request worked in. On the owner's panel: the login from config.php and the shop the request named (`X-Shop`). On an agent's panel: their bot and its shop. */
export interface Session {
  /** The owner's login, or the agent's bot (@username). Their decisions are recorded under this name. */
  name: string
  shop: ShopRef
}

export interface SessionResponse {
  session: Session
}

export interface ShopsResponse {
  /** Every shop the owner may open, the main bot's first. */
  shops: ShopRef[]
}

/** A bot's shop: the main bot's, or an agent's. */
export interface ShopRef {
  /** The bot's id; 1 is the main bot. */
  id: number
  /** «فروشگاه اصلی» for the main bot; an agent's bot's title, or «نماینده: » and the agent's name while the agent has not connected a bot yet. */
  name: string
  /** The bot's username, without the @. */
  username: string | null
  /** `disabled`: an agent whose agency ended. The shop is kept, but its bot does not run. */
  status: 'active' | 'disabled'
}

/** The owner's panel login (kept in config.php). */
export interface LoginRequest {
  username: string
  /** As typed; spaces at either end are part of it. */
  password: string
}

/** A new panel login: the current password, then the new login with the installer's rules (InstallAdminRequest). Leaving the password and its confirmation blank keeps the current password. A request that changes nothing is refused. */
export interface CredentialsRequest {
  current_password: string
  username: string
  /** As typed; spaces at either end are part of it. */
  password?: string
  password_confirmation?: string
}

/** A new panel login set without the old one: the recovery key from the host's files (`RecoveryKeyResponse.file`), then the login with the installer's rules (InstallAdminRequest). */
export interface RecoveryRequest {
  /** As written in the file; case and surrounding spaces do not matter. */
  key: string
  username: string
  /** As typed; spaces at either end are part of it. */
  password: string
  password_confirmation: string
}

export interface RecoveryKeyResponse {
  /** The key file, relative to the app folder: storage/recovery-key.txt. */
  file: string
  /** When the key expires: an hour after it was made. */
  expires_at: string
}

/** The code from the agent's one-time sign-in link (the main bot's «ورود به پنل»), read from the address's fragment (`#code=`). */
export interface AgentLinkRequest {
  code: string
  /** Sign in even if another agent is signed in in this browser; that session ends. Without it, such a request gets a 409 on `replace` and the link stays unused. */
  replace?: boolean
}

/** Each area's version number from the change feed. An area nothing was written to yet is absent. */
export interface ChangesResponse {
  versions: {
    /** Customers, their wallets and their groups. */
    users?: number
    /** The servers and their inbounds. */
    servers?: number
    /** Grants and their per-server parts. A running grant's cursor moves every second. */
    grants?: number
    /** The plans, their categories and their server entries. */
    plans?: number
    payment_methods?: number
    orders?: number
    payments?: number
    subscriptions?: number
    /** The free test accounts given. */
    tests?: number
    /** Referral commissions, bonuses, rewards and alerts. */
    referrals?: number
    /** The agency's levels and requests, the agents' bots and their traffic. */
    agency?: number
    /** The channels a customer must join. */
    channels?: number
    /** The broadcasts and their pins. */
    broadcasts?: number
    /** The report group, its topics and its queue. */
    reports?: number
    /** The premium emoji the bot has seen. */
    emoji?: number
    /** The support tickets and their messages. */
    tickets?: number
    /** The customers' reviews of the shop. */
    reviews?: number
    /** Gift codes, the lucky wheel's slices and spins, loyalty tiers, cashback rules, and the nightly draws with their winners. */
    rewards?: number
    /** Discount codes and timed sales. A code's use count changes with orders. */
    discounts?: number
  }
}

/** An error in the panel's own code, reported by the page. It becomes one error line in the server log (with the app's version) and is stored nowhere else. Each part is truncated and stripped of secrets. A body over 16 KB is a 413; each user or address may send 20 reports in ten minutes and 100 a day (then a 429 with `Retry-After`). */
export interface ClientErrorRequest {
  /** `render`: a page failed to render (an error boundary or the router's error screen). `unhandled`: an error from a click handler or a promise that nothing handled. */
  kind: 'render' | 'unhandled'
  /** The error's name and message. */
  message: string
  /** The top of the error's stack. */
  stack?: string | null
  /** For a render failure: the React component stack. */
  component_stack?: string | null
  /** The panel address where it happened: its path and query, never the fragment (which may hold a sign-in code). */
  address: string
  /** The panel build: when `vite build` made it. */
  build: string
}

/** What waits for support: the sidebar's counts, and the first rows of the dashboard's attention card. */
export interface QueueCounts {
  /** Receipts waiting for review. */
  payments_to_review: number
  /** Paid orders not delivered because the delivery failed or stalled (the orders list's `stuck` tab). */
  stuck_orders: number
  /** Support tickets waiting for an answer (the tickets list's `open` tab). */
  open_tickets: number
  /** Reviews waiting for approval (the reviews list's `pending` tab). */
  pending_reviews: number
}

export interface QueuesResponse {
  queues: QueueCounts
}

export type UserStatus = 'active' | 'banned'

/** `admin`: a bot admin. They can use /broadcast, are exempt from the bot's gates, and can use the website's admin API while the website lets admins in. Unrelated to the panel login. */
export type UserRole = 'customer' | 'admin'

/** A customer, as a row of the users table. (The panel login is not a customer.) */
export interface UserRow {
  id: number
  /** "first last" from Telegram, or as they signed up with; null when the account has none. */
  name: string | null
  /** Telegram handle without "@"; null when the account has none. */
  username: string | null
  /** Their Telegram user id; null for a customer who signed up on the website (the bot cannot message them). */
  telegram_id: number | null
  /** Their verified email, in lower case; null when they have none. */
  email: string | null
  /** In E.164 form, once shared with the bot. */
  phone: string | null
  status: UserStatus
  role: UserRole
  balance: string
  /** Their personal discount («تخفیف اختصاصی»): a percent off what they buy for themselves. It is one of the automatic discounts, of which the largest applies. 0 for none. */
  discount_percent: number
  counts: {
    orders: number
    subscriptions: number
    active_subscriptions: number
  }
  /** The customer groups they are in, in the groups' order. */
  groups: CustomerGroupRef[]
  created_at: string
  last_seen_at: string | null
}

/** The users table's sort keys: sign-up time (the default), last seen, balance (no ledger counts as 0), orders, active services. */
export type UserSort = 'joined' | 'last_seen' | 'balance' | 'orders' | 'services'

export interface UsersResponse {
  users: UserRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: UserSort
    dir: SortDirection
  }
}

export interface UserResponse {
  user: UserRow
}

/** One customer, as their page shows them. Their services, orders and payments come from those lists, filtered with `user=`. */
export interface UserDetailResponse {
  user: UserRow
  referral: CustomerReferral
  /** The customer as an agent of the main bot, as the agents list shows them (level and credit, their bot and its sales); null for anyone who is not an agent, and always in an agent's shop. */
  agency: AgentRow | null
  account: CustomerAccount
  loyalty: LoyaltyStanding
}

/** The customer's website account, as support sees it: how they sign in, whether password sign-in has a second step, the signed-in devices, and the accounts merged into it. */
export interface CustomerAccount {
  /** Their verified email; null without one. */
  email: string | null
  /** They can sign in with a Google account. */
  google: boolean
  /** They can sign in with their email and a password. */
  has_password: boolean
  /** Password sign-in also asks for their authenticator app's code. */
  two_factor: boolean
  /** The number of devices signed in on the website (sessions not ended). */
  sessions: number
  /** The accounts merged into this one, newest first. */
  merges: CustomerMerge[]
}

/** An account merged into this customer's account (its row no longer exists): how it signed in, its name, and when the customer merged them. Accounts are only merged by the customer, from the website. */
export interface CustomerMerge {
  /** Its former customer id. */
  merged_user_id: number
  merged: {
    /** Its Telegram user id; null for none. */
    telegram_id: number | null
    /** Its Telegram handle, without the @. */
    username: string | null
    email: string | null
    /** It could sign in with a Google account. */
    google: boolean
    /** Its "first last" name; null without one. */
    name: string | null
  }
  created_at: string
}

export interface CustomerAccountResponse {
  account: CustomerAccount
}

/** Ban or unban a customer. */
export interface UserStatusRequest {
  status: UserStatus
}

/** Make a customer a bot admin, or take the role back. */
export interface UserRoleRequest {
  role: UserRole
}

/** A customer's personal discount («تخفیف اختصاصی»), until changed: a percent off everything they buy for themselves. */
export interface UserDiscountRequest {
  /** 0 to 100; 0 removes it. */
  percent: WholeNumberInput
}

/** The customer's groups: exactly the ones listed. An empty list removes them from all groups. */
export interface UserGroupsRequest {
  /** Group ids (`CustomerGroupRow.id`). */
  group_ids: number[]
}

/** A customer's referral details: who referred them, how many customers they referred, and what that earned them. */
export interface CustomerReferral {
  /** The customer whose link brought them; null if none. */
  referrer: UserRef | null
  /** How many customers their link brought. */
  referrals: number
  /** The total of the commissions credited to them, in Toman. A commission stays with whoever earned it, even if accounts were merged since. */
  earned: string
}

/** A customer group, as a customer's row lists it. */
export interface CustomerGroupRef {
  id: number
  name: string
}

/** A customer group («گروه»), in the admin's order. A broadcast can target one. */
export interface CustomerGroupRow {
  id: number
  name: string
  /** Its members pay the plans' agent prices («قیمت نماینده»), as agents do (a «همکار» group). */
  agent_pricing: boolean
  sort: number
  counts: {
    users: number
  }
  created_at: string
}

export interface CustomerGroupsResponse {
  groups: CustomerGroupRow[]
}

export interface CustomerGroupResponse {
  group: CustomerGroupRow
}

/** A customer group to add or update. */
export interface CustomerGroupRequest {
  /** Unique within the shop. */
  name: string
  /** Its members pay the plans' agent prices. Left out: off for a new group, unchanged for an existing one. */
  agent_pricing?: boolean
}

export type WalletTransactionType = 'credit' | 'debit'

/** One line of a customer's wallet ledger. */
export interface WalletTransactionRow {
  id: number
  type: WalletTransactionType
  amount: string
  balance_after: string
  description: string | null
  /** Who added the line by hand: a panel user or a website admin. Anyone but the owner sees the owner's login as «پشتیبانی». Null for lines the shop wrote automatically. */
  reviewer: string | null
  created_at: string
}

export interface UserWalletResponse {
  user: UserRow
  transactions: WalletTransactionRow[]
}

export interface WalletAdjustmentResponse {
  user: UserRow
  transaction: WalletTransactionRow
  transactions: WalletTransactionRow[]
}

/** A manual credit or debit, with the line the customer sees in their ledger. A debit cannot exceed the balance. */
export interface WalletAdjustmentRequest {
  type: WalletTransactionType
  /** Whole Toman, above zero. */
  amount: AmountInput
  /** The ledger line, at most 190 characters. Blank for the default wording. */
  description?: string
}

export type RangeDays = 7 | 30 | 90

export interface DashboardResponse {
  range: {
    days: RangeDays
    from: string
    to: string
  }
  generated_at: string
  kpis: {
    /** Money received, in Toman: paid payments not made from the wallet, plus refunded ones except wallet top-ups. */
    revenue: {
      value: string
      previous: string
    }
    orders: CountKpi
    new_users: CountKpi
    /** Active services, free test accounts aside. */
    active_subscriptions: number
    users_total: number
  }
  series: SeriesPoint[]
  attention: {
    /** Receipts waiting for review. */
    payments_to_review: number
    /** Paid orders not delivered because the delivery failed or stalled (the orders list's `stuck` tab). */
    stuck_orders: number
    /** Support tickets waiting for an answer (the tickets list's `open` tab). */
    open_tickets: number
    /** Reviews waiting for approval (the reviews list's `pending` tab). */
    pending_reviews: number
    pending_orders: number
    /** Servers whose panel failed its last check. Always 0 in an agent's shop. */
    servers_with_errors: number
    /** Active services ending within `expiring_days`, free test accounts aside. */
    expiring_soon: number
  }
  /** Set when an agent's shop has too little traffic to sell anything; null otherwise, and always null in the main shop. */
  traffic_shortage: TrafficShortage | null
  /** The "ending soon" window in days, the same as on the subscriptions screen. */
  expiring_days: number
  recent_orders: RecentOrder[]
  recent_users: RecentUser[]
}

export interface CountKpi {
  value: number
  previous: number
}

export interface SeriesPoint {
  date: string
  revenue: string
  orders: number
  users: number
}

export type OrderStatus = 'pending' | 'paid' | 'processing' | 'fulfilled' | 'cancelled' | 'failed' | 'refunded'

/** Which price a purchase or renewal was made at: `regular`, the plan's price (also used for every other order type); `first_purchase`, its first-purchase price («قیمت خرید اول», for a customer who had bought no service); `agent`, its agent price («قیمت نماینده», for an agent or a member of a group with agent prices); or `gift`, free (a prize, or a service from a gift code or referral reward). */
export type OrderPricing = 'regular' | 'first_purchase' | 'agent' | 'gift'

/** What an order buys: a plan (`purchase`), a service's `renewal`, a `wallet_topup`, `traffic` for an agent's bot (main bot only), or `extra_traffic`, `extra_time` or `extra_devices` for an existing service. */
export type OrderType = 'purchase' | 'renewal' | 'wallet_topup' | 'traffic' | 'extra_traffic' | 'extra_time' | 'extra_devices'

export interface RecentOrder {
  id: number
  type: OrderType
  status: OrderStatus
  amount: string
  /** Its plan's name — a service the customer built in words, «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز» —; null for an order of neither. */
  plan: string | null
  user: UserRef
  created_at: string
}

export interface RecentUser {
  id: number
  /** "first last" from Telegram, or as they signed up with; null when the account has none. */
  name: string | null
  /** Telegram handle without "@"; null when the account has none. */
  username: string | null
  /** Their Telegram user id; null for a customer who signed up on the website (the bot cannot message them). */
  telegram_id: number | null
  /** Their verified email, in lower case; null when they have none. */
  email: string | null
  balance: string
  created_at: string
}

/** How a bot runs. The main bot's mode applies to the whole installation: every bot gets updates the same way (all on webhooks, or all polling), and the main bot's token is in config.php. */
export interface BotRuntime {
  /** It has a token. */
  configured: boolean
  /** The shop's master switch (bot settings), whether or not the process is running. */
  enabled: boolean
  /** Its username, without the @; null while unknown. */
  username: string | null
  mode: BotMode
  last_update_at: string | null
}

/** How a bot gets its updates: `webhook` (Telegram calls it), `polling` (a `bot:poll` process with a fresh heartbeat), or `offline` (neither). */
export type BotMode = 'webhook' | 'polling' | 'offline'

/** The system's health: the version, PHP, the bots and the scheduler. Owner only. */
export interface SystemStatus {
  version: string
  php: string
  /** The bot of the shop the request names (the dashboard's shop): the main bot or an agent's. */
  bot: {
    /** The bot's id; 1 is the main bot. */
    id: number
    /** It has a token. */
    configured: boolean
    /** The shop's master switch (bot settings), whether or not the process is running. */
    enabled: boolean
    /** Its username, without the @; null while unknown. */
    username: string | null
    mode: BotMode
    last_update_at: string | null
  }
  main_bot: BotRuntime
  cron: {
    last_run_at: string | null
    tasks: number
  }
}

export interface SystemResponse {
  system: SystemStatus
}

/** The result of setting or deleting the webhook for one bot. The message never contains a token or the webhook address's secret. */
export interface WebhookOutcome {
  bot: {
    /** The bot's id; 1 is the main bot. */
    id: number
    /** Its username, without the @; null while unknown. */
    username: string | null
  }
  done: boolean
  /** What happened, or why it failed. */
  message: string
}

export interface WebhooksResponse {
  /** Every running bot, the main one first. Empty while no bot has a token. */
  bots: WebhookOutcome[]
}

/** AmoBot's update status: the running version, the newest release on GitHub, and the current or last update. Only releases signed with the release key built into the app are installed. */
export interface UpdateStatus {
  /** The running version. */
  current: string
  /** The newest release, as last read; null when GitHub has none, or before the first check. */
  latest: ReleaseInfo | null
  /** When GitHub was last checked; null before the first check. */
  checked_at: string | null
  /** The newest release is newer than the running version, and no update is running or has just installed it. */
  available: boolean
  /** Why the shop cannot update itself (no release key, PHP without the sodium or zip extension, PHP not allowed to change the app's files, or a release without its signed files), with the alternative: a manual update (docs/Upgrading.md). Null when nothing blocks it. */
  blocker: string | null
  /** The current or last update; null when there is none. */
  run: UpdateRun | null
}

/** A release as GitHub lists it. */
export interface ReleaseInfo {
  /** Its version (the tag without the v). */
  version: string
  published_at: string | null
  /** Its release notes as plain text, line breaks kept. Never render them as HTML. */
  notes: string
  /** Its page on GitHub. */
  url: string
}

/** An update's step: `download` (fetch and verify the files), `extract` (unpack), `preflight` (check the host), `install`, then `done`, or `rolled_back` after a rollback. A failed install is rolled back automatically and stays at `install` with its error. */
export type UpdateStep = 'download' | 'extract' | 'preflight' | 'install' | 'done' | 'rolled_back'

export interface UpdateRun {
  /** The version being installed. */
  version: string
  /** The version running when it started; a rollback restores it. */
  from: string
  step: UpdateStep
  /** Progress of the current step, in percent. */
  progress: number
  /** Why the step last failed; null when nothing is wrong. */
  error: string | null
  /** It can still be cancelled: none of the app's files were replaced yet. */
  cancel: boolean
  /** It can be rolled back: it is installed, the replaced version is kept, and it did not change the database. */
  rollback: boolean
  /** When it was installed or rolled back; null while running. */
  finished_at: string | null
}

export interface UpdateResponse {
  update: UpdateStatus
}

export interface UpdateStartRequest {
  /** The newest release (`latest.version`). Updating to any other version is refused. */
  version: string
}

export type PaymentStatus = 'pending' | 'awaiting_review' | 'paid' | 'failed' | 'cancelled' | 'refunded'

/** How a gateway settles: `instant` (at once, like the wallet), or `manual` (once a person, or the method's automatic approval window, accepts the receipt of a card transfer). */
export type GatewayKind = 'instant' | 'manual'

/** A payment, as a row of the payments screen. */
export interface PaymentRow {
  id: number
  status: PaymentStatus
  amount: string
  /** Driver key ("manual", "wallet"). */
  gateway: string
  /** How the driver settles; null when the driver is no longer installed. */
  kind: GatewayKind | null
  /** The payment method's label ("کارت به کارت (ملت)"). A method with payments is never deleted. */
  method: string
  /** What the customer was told to pay to, from the driver (the masked card number and holder); null for a driver without one (the wallet). */
  summary: string | null
  /** The gateway's own id for the payment (the wallet's ledger line); null when it has none (a card transfer). */
  reference: string | null
  /** The note on its latest decision: why it failed or was cancelled (the admin's reason or note, the gateway's refusal, the order paid another way or left unpaid, or its service handed to another customer: «سرویس این سفارش به حساب دیگری منتقل شد»), or a refund's note (also in `refund_note`). */
  description: string | null
  user: UserRef
  order: {
    id: number
    type: OrderType
    status: OrderStatus
    /** What it buys in words, as `OrderPackageName`: its plan's name (an extra's: its service's plan's), or a service the customer built as «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز»; null for any other order. */
    plan: string | null
    server: string | null
    subscription_id: number | null
    /** The traffic it adds, in bytes: an agent's traffic, a service's extra traffic, or a built service's traffic; null otherwise. */
    traffic_bytes: number | null
    /** The days it adds to a service (extra time), or a built service's term; null otherwise. */
    days: number | null
    /** The devices it adds to a service's IP limit (extra devices), or a built service's limit; null otherwise. */
    devices: number | null
    /** Why its delivery failed; null unless it did. The owner sees the panel's full diagnosis, anyone else a short summary. */
    notes: string | null
    /** The total its discounts took off (the automatic one and the code's), in Toman; 0 for none. */
    discount_amount: string
    /** The discounts applied, the automatic one first. */
    discounts: DiscountLine[]
    /** The discount code used; it counts as one of the code's uses unless the order is cancelled. Null for none. */
    discount_code: OrderDiscountCode | null
  }
  /** The customer's receipt, always a picture: sent in the bot (which accepts only pictures) or uploaded on the website. Null until sent. `GET …/receipt` serves either. */
  receipt: {
    name: string | null
    note: string | null
    sent_at: string | null
  } | null
  auto_approved: boolean
  /** Who decided it by hand: the agent's @bot, a bot admin's @username, tg:<id> or user#<id> (in the report group or on the website), or the owner's login, which anyone but the owner sees as «پشتیبانی». */
  reviewer: string | null
  refund_note: string | null
  paid_at: string | null
  created_at: string
  /** Which operations its current state allows (the server decides). */
  actions: {
    approve: boolean
    reject: boolean
    cancel: boolean
    remind: boolean
    retry: boolean
    refund: boolean
  }
}

/** The payments list's sort keys: creation time (the default) or amount. */
export type PaymentSort = 'created' | 'amount'

export interface PaymentsResponse {
  payments: PaymentRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: PaymentSort
    dir: SortDirection
    /** Receipts waiting for review, regardless of the filters. */
    awaiting_review: number
    /** How many of the listed payments (tab, search and dates) are paid. */
    paid: number
    /** Their total, in Toman. */
    paid_amount: string
  }
}

export interface PaymentResponse {
  payment: PaymentRow
}

/** What happened to a notice the bot sent a customer: `told` (delivered in Telegram); `emailed` (Telegram could not reach them, because they have no Telegram account or blocked the bot, so it was emailed); `turned_away` (they blocked the bot or their account is gone, and no email went); `unreachable` (Telegram, or the mail server for an email, could not be reached); `refused` (Telegram refused it); `no_telegram` (no Telegram account and no email went, so nothing was sent). Either way the notice is kept for their website. */
export type Delivery = 'told' | 'emailed' | 'turned_away' | 'unreachable' | 'refused' | 'no_telegram'

export interface ReminderResponse {
  payment: PaymentRow
  delivery: Delivery
}

/** An order, as a row of the orders screen: what the customer asked for, and how far it got. */
export interface OrderRow {
  id: number
  type: OrderType
  status: OrderStatus
  amount: string
  pricing: OrderPricing
  /** The total its discounts took off (the automatic one and the code's), in Toman; 0 for none. */
  discount_amount: string
  /** The discounts applied, the automatic one first. */
  discounts: DiscountLine[]
  /** The discount code used; it counts as one of the code's uses unless the order is cancelled. Null for none. */
  discount_code: OrderDiscountCode | null
  user: UserRef
  /** The plan of a purchase or renewal; for extras, the service's plan. */
  plan: NamedRef | null
  package_name: OrderPackageName
  /** How a renewal adds its package (a renewal from before renewal methods, `extend`); null for any other order. */
  renewal_method: RenewalMethod | null
  /** The server the customer chose; for a renewal or extras, the service's server. */
  server: NamedRef | null
  /** The service it bought, renewed or extended; null for a top-up, or once the service was deleted. */
  subscription: {
    id: number
    name: string
    status: SubscriptionStatus
  } | null
  /** The traffic it adds, in bytes: an agent's traffic, a service's extra traffic, or a built service's traffic; null otherwise. */
  traffic_bytes: number | null
  /** The days it adds to a service (extra time), or a built service's term; null otherwise. */
  days: number | null
  /** The devices it adds to a service's IP limit (extra devices), or a built service's limit; null otherwise. */
  devices: number | null
  /** Why it failed or was cancelled. For a failed delivery, the owner sees the panel's full diagnosis; anyone else (an agent, a website admin) a short summary. */
  notes: string | null
  /** The payment attempts, newest first. */
  payments: OrderPayment[]
  paid_at: string | null
  fulfilled_at: string | null
  created_at: string
  actions: {
    retry: boolean
    cancel: boolean
  }
}

/** A reference by id and name (a plan, a server). */
export interface NamedRef {
  id: number
  name: string
}

export interface OrderPayment {
  id: number
  status: PaymentStatus
  amount: string
  /** Driver key ("manual", "wallet"). */
  gateway: string
  /** The payment method's label. A method with payments is never deleted. */
  method: string
  paid_at: string | null
  created_at: string
}

/** The orders list's sort keys: creation time (the default) or amount. */
export type OrderSort = 'created' | 'amount'

export interface OrdersResponse {
  orders: OrderRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: OrderSort
    dir: SortDirection
    /** Stuck orders (paid but not delivered), regardless of the filters. */
    stuck: number
    /** How many of the listed orders (tab, type, search and dates) are sales: paid, being delivered or delivered. */
    sold: number
    /** Their total, in Toman. */
    sold_amount: string
  }
}

export interface OrderResponse {
  order: OrderRow
}

export type SubscriptionStatus = 'active' | 'expired' | 'disabled' | 'deleted'

/** A service, as a row of the subscriptions screen: a client on a panel, as last read from it. */
export interface SubscriptionRow {
  id: number
  status: SubscriptionStatus
  /** The client's name on the panel ("amir_2", "USER_7"). */
  name: string
  /** The customer's subscription link. */
  link: string
  /** A free test account («اکانت تست»): no plan, never renewed, never counted as ending soon (`expiring_soon`); removed by the tests' own rule (`removes_at`). */
  is_test: boolean
  /** When the customer added it by sending its link («افزودن سرویس با لینک», `claim_enabled`): a client made by hand on its panel. Null for any other service. Such a service has no plan until a renewal on one the customer picks, sold on its server (a renewal with a package built leaves it without). */
  claimed_at: string | null
  user: UserRef
  /** Null for a service without a plan — a test account, a service added by its link (`claimed_at`) or built by the customer («سرویس دلخواه»), until a renewal on a plan; one renewed with a package built stays so — and once the plan is deleted. */
  plan: NamedRef | null
  server: NamedRef
  /** In bytes; a limit of 0 means unlimited. */
  traffic: {
    limit: number
    used: number
  }
  /** Devices allowed at once; 0 means unlimited. */
  ip_limit: number
  /** The term in whole days, rounded up; 0 only when it never expires. `duration_hours` is exact. */
  duration_days: number
  /** The term in hours, counted from the first connection; 0 never expires. */
  duration_hours: number
  /** The customer's auto-renewal («تمدید خودکار»): renewed from their wallet before the deadline. */
  auto_renew: boolean
  /** A renewal queued after the current period (unused traffic of the current period is not carried over): when it starts, and the most traffic left then, in bytes. Null while no traffic cap waits for it — a renewal may still have queued its device limit alone (`next_devices`). */
  next_period: {
    starts_at: string
    traffic: number
  } | null
  /** A renewal's device limit queued after the current period: a running service renewed by `extend` or `reset_traffic` keeps the devices it has (extra devices bought included) until the period in use ends, and has the renewal's from then. Null while the limit stays as `ip_limit` says. */
  next_devices: NextDevices | null
  /** When the first connection started the term; null until then. */
  starts_at: string | null
  /** Null while waiting for the first connection, or without a term. */
  expires_at: string | null
  /** Active, no free test account, and ending within the list's `meta.expiring_days`. */
  expiring_soon: boolean
  /** When the panel was last read for it; the numbers are from then. */
  last_synced_at: string | null
  disabled_at: string | null
  /** When it ended: the deadline it passed, or when the shop found its traffic used up or its client gone from the panel. Null while it runs, and past a deadline until the shop next reads its panel. */
  ended_at: string | null
  /** When the shop removes it from its panel and the shop by itself, by its rules now: `expired_cleanup_hours` after it ended while `expired_cleanup` is on; a test, while `test_cleanup` is on, `test_cleanup_hours` after it ended — or, never connected, `test_unused_days` after it was made. Null when no rule removes it: it runs (a test never connected aside), it is switched off, or the rule is off. A moment already past: it goes at the next check (every 15 minutes), unless an order of it is still open. */
  removes_at: string | null
  created_at: string
  /** Which operations its current state allows (the server decides). */
  actions: {
    sync: boolean
    disable: boolean
    enable: boolean
    move: boolean
    /** Handing it to another customer («انتقال سرویس»): anything but a free test account or a service gone from its panel. */
    transfer: boolean
    delete: boolean
    /** Adding days and traffic: for an active service with an end date or a quota. */
    extend: boolean
  }
}

/** Days and traffic to add to one service («افزایش زمان و حجم»), as a grant adds them. At least one amount must be above zero; days only for a service with an end date, traffic only for one with a quota. In an agent's shop the traffic is taken from the agent's bot (refused on `traffic_gb` when it is short); days cost nothing. */
export interface SubscriptionExtensionRequest {
  /** Whole days, 0 to 365; blank for none. */
  days?: WholeNumberInput
  /** Gigabytes, 0 to 10000; blank for none. */
  traffic_gb?: AmountInput
  /** Support's note to the customer, a separate line of the message; at most 300 characters. */
  note?: string
  /** Whether to notify the customer; default true. */
  notify?: boolean
}

/** Move an active service to another server, keeping its remaining days and traffic. `leave_previous` moves it without contacting the previous panel (when it is unreachable); the old client stays there for the owner to remove. In an agent's shop this is allowed only while that panel is unreachable. */
export interface SubscriptionMoveRequest {
  server_id: number
  leave_previous?: boolean
}

/** Delete a service from its panel and from the shop, permanently. The customer is notified only if the service was still active; the note goes with that message. `leave_panel` deletes it without contacting the panel (when it is unreachable); the client stays there. In an agent's shop this is allowed only while that panel is unreachable; the owner in the main shop may always (not the website's admins, who need the panel out of reach as any other shop's), which is how a service added by its link to the wrong customer (`SubscriptionRow.claimed_at`) is taken back for the right one to send its link again. */
export interface SubscriptionDeleteRequest {
  note?: string
  leave_panel?: boolean
}

/** «انتقال سرویس»: whom to hand the service to — a customer of the shop, by their numeric Telegram id; they must have started the bot once. */
export interface SubscriptionTransferRequest {
  /** Their numeric Telegram id: a number, or its digits as typed. */
  telegram_id: WholeNumberInput
}

export interface SubscriptionTransferRecipientResponse {
  recipient: UserRef
}

/** The services list's `kind` filter: the free test accounts (`test`), the services customers added by their link (`claimed`, «افزودن سرویس با لینک»: `SubscriptionRow.claimed_at` set), or every other service — sold, renewed or given (`regular`: neither a test nor a claimed one). */
export type SubscriptionKind = 'test' | 'regular' | 'claimed'

/** The services list's sort keys: creation time (the default), end date (services that never end or have not started come after every deadline), or traffic used. */
export type SubscriptionSort = 'created' | 'expires' | 'used'

export interface SubscriptionsResponse {
  subscriptions: SubscriptionRow[]
  /** `expiring` counts active services ending within `expiring_days`, free test accounts aside (the tab's count; the dashboard uses the same window). */
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: SubscriptionSort
    dir: SortDirection
    expiring: number
    expiring_days: number
  }
}

export interface SubscriptionResponse {
  subscription: SubscriptionRow
}

/** A ticket's status: `open` (waiting for support), `answered` (support wrote last; waiting for the customer) or `closed`. A customer's message makes it open (reopening a closed one); support's answer makes it answered. Either side can close it; support can reopen it. */
export type TicketStatus = 'open' | 'answered' | 'closed'

/** Who wrote a ticket message: the customer or support. */
export type TicketAuthor = 'customer' | 'support'

/** Where a ticket message was written. The customer writes on the website (`web`) or in the bot (`bot`); support in a panel (`panel`), the website's admin API (`staff`) or the report group (`group`). */
export type TicketChannel = 'web' | 'bot' | 'panel' | 'staff' | 'group'

/** A message's picture. Its bytes are at the message's attachment address (`…/messages/{message}/attachment`). */
export interface TicketAttachment {
  /** Its file name, from the sender's device or generated. */
  name: string
  /** Whether the file is still stored. Uploaded pictures are deleted 30 days after the ticket closes (reopening keeps the pictures still there; deleted ones do not come back). When false, the message still shows it had a picture, and its address is a 404. */
  kept: boolean
}

/** A support ticket, as the list shows it. */
export interface TicketRow {
  id: number
  subject: string
  status: TicketStatus
  customer: UserRef
  /** The customer's service it is about, by its panel name; null for none, or when the service was deleted. */
  subscription: NamedRef | null
  /** When its latest message was written; the list is sorted by it. */
  last_message_at: string
  messages_count: number
  /** The customer's rating after it closed, 1 to 5; null for none. */
  rating: number | null
  created_at: string
  /** When it was closed; null while open (reopening clears it). */
  closed_at: string | null
}

/** A ticket message. */
export interface TicketMessageRow {
  id: number
  author: TicketAuthor
  /** Which support person wrote it: a panel user, or a bot admin's @username, tg:<id> or user#<id> (in the report group or on the website). Anyone but the owner sees the owner's login as «پشتیبانی». Null for the customer's messages. */
  reviewer: string | null
  body: string
  /** Its picture; null for none. */
  attachment: TicketAttachment | null
  channel: TicketChannel
  created_at: string
}

/** A support ticket with its whole conversation, oldest message first. */
export interface TicketDetail {
  id: number
  subject: string
  status: TicketStatus
  customer: UserRef
  /** The customer's service it is about, by its panel name; null for none, or when the service was deleted. */
  subscription: NamedRef | null
  /** When its latest message was written; the list is sorted by it. */
  last_message_at: string
  messages_count: number
  /** The customer's rating after it closed, 1 to 5; null for none. */
  rating: number | null
  created_at: string
  /** When it was closed; null while open (reopening clears it). */
  closed_at: string | null
  /** The note the customer left with their rating. */
  rating_note: string | null
  messages: TicketMessageRow[]
}

export interface TicketsResponse {
  tickets: TicketRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    /** Tickets waiting for an answer, regardless of the filters. */
    open: number
  }
}

export interface TicketResponse {
  ticket: TicketDetail
}

/** A ticket message's text: support's answer on a panel, or the customer's message on the website. To attach a picture, send a form instead (TicketMessageUploadRequest). */
export interface TicketMessageRequest {
  /** 1 to 4000 characters; line breaks are kept. */
  body: string
}

/** A ticket message with a picture, as a browser form uploads it. A form without a file is a message without a picture. */
export interface TicketMessageUploadRequest {
  /** 1 to 4000 characters; line breaks are kept. */
  body: string
  /** The picture: a JPEG, PNG or WebP (checked by its content), at most 10 MB. Optional. */
  file?: Blob
}

/** A review's status: `pending` (waiting for support), `approved` (shown on the website) or `rejected` (kept but not shown). Support can change it either way at any time. */
export type ReviewStatus = 'pending' | 'approved' | 'rejected'

/** A review written on the website, as the moderation screen lists it. */
export interface ReviewRow {
  id: number
  /** The name it is signed with. */
  name: string
  /** 1 to 5. */
  rating: number
  /** Its text, with line breaks as written. */
  body: string
  /** A line on where the service is used («ایرانسل · اندروید · Happ»); null for none. */
  context: string | null
  status: ReviewStatus
  /** The signed-in customer who wrote it; null for a guest, or when the account is gone. */
  customer: UserRef | null
  /** Who last approved or rejected it: a panel user, or a website admin (@username, tg:<id> or user#<id>). Anyone but the owner sees the owner's login as «پشتیبانی». Null while pending. */
  reviewer: string | null
  /** When it was last approved or rejected; null while pending. */
  decided_at: string | null
  created_at: string
  /** Which decisions its status allows (deleting is always allowed). */
  actions: {
    approve: boolean
    reject: boolean
  }
}

export interface ReviewsResponse {
  reviews: ReviewRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    /** Reviews waiting for support, regardless of the filters. */
    pending: number
  }
}

export interface ReviewResponse {
  review: ReviewRow
}

/** The panel connectors a server can use, in order. Each has its description and required versions (`notes`), what the add-server picker shows (`traits`: `mark`, two letters; `vendor`; `docs_url`), what its panels support (`inbounds`, `link_rotation`), and the whole server form for it: the name, the connector's connection fields, then the server's own fields. */
export interface ServerDriversResponse {
  drivers: DriverDescription[]
}

export interface ServerRow {
  id: number
  name: string
  driver: string
  driver_label: string
  base_url: string
  /** The server's stored form values, with the fields its connector lists (ServerDriversResponse): how the shop signs in to the panel (`auth_mode`, and each secret as a SecretState that says whether one is stored, never the secret), the connection options, and the server's own fields. Null when this installation does not have its connector. */
  form: DriverValues | null
  is_active: boolean
  capacity: number | null
  sort: number
  notes: string | null
  last_checked_at: string | null
  last_error: string | null
  /** Whether the panel served subscription links at the last check; null if never checked. A server without them cannot be sold. */
  serves_subscriptions: boolean | null
  /** Why nothing can be sold on or moved to the server now (switched off, no subscription links, full, connector missing…); null when it can. */
  unsellable_reason: string | null
  counts: {
    inbounds: number
    selectable_inbounds: number
    /** Its active services across all shops (the main bot's and the agents'); this is what its capacity counts. */
    active_subscriptions: number
    /** Of those, the ones in the shop the panel has open: what its subscriptions screen lists with `?server=`. */
    shop_active_subscriptions: number
  }
  created_at: string
  updated_at: string
}

/** An inbound as the panel reports it, from a probe or from the stored copy. */
export interface ProbeInbound {
  /** The panel's own identifier (opaque). */
  remote_key: string
  tag: string
  /** Null when it is not a single protocol (a PasarGuard group). */
  protocol: string | null
  /** Null when it has no port of its own (a PasarGuard group). */
  port: number | null
  remark: string
  enabled: boolean
  network: string | null
  security: string | null
  client_count: number
}

export interface ServerInboundRow {
  /** The panel's own identifier (opaque). */
  remote_key: string
  tag: string
  /** Null when it is not a single protocol (a PasarGuard group). */
  protocol: string | null
  /** Null when it has no port of its own (a PasarGuard group). */
  port: number | null
  remark: string
  enabled: boolean
  network: string | null
  security: string | null
  client_count: number
  id: number
  is_selectable: boolean
  synced_at: string | null
}

export interface PanelStatusInfo {
  /** The name of the panel's core ("Xray"); null when it has none. */
  core_name: string | null
  core_state: 'running' | 'stopped' | 'error' | 'unknown'
  core_version: string
  core_error: string
  cpu_percent: number
  memory_used: number
  memory_total: number
  disk_used: number
  disk_total: number
  uptime_seconds: number
  connections: number
}

/** The result of contacting a panel. */
export interface Probe {
  ok: boolean
  /** A Persian sentence: what went wrong and what to change. */
  error: string | null
  /** The technical detail behind it (the panel's message, cURL text), in English. */
  error_detail: string | null
  status: PanelStatusInfo | null
  /** Null when the panel could not list them; a check then leaves the stored ones unchanged. */
  inbounds: ProbeInbound[] | null
  /** Whether the panel serves subscription links; null when that could not be checked. */
  serves_subscriptions: boolean | null
  /** False when the connection worked but the subscription check failed. */
  subscription_probed: boolean
}

export interface ServersResponse {
  servers: ServerRow[]
}

export interface ServerResponse {
  server: ServerRow
}

export interface ServerDetailResponse {
  server: ServerRow
  inbounds: ServerInboundRow[]
}

export interface ServerCreatedResponse {
  server: ServerRow
  probe: Probe
}

export interface ServerCheckResponse {
  server: ServerRow
  probe: Probe
  inbounds: ServerInboundRow[]
}

export interface ProbeResponse {
  probe: Probe
}

/** A server's whole form, to add or update it: its connector (`driver`; a saved server keeps its connector) and the fields the connector lists (ServerDriversResponse), by name, as typed. That is: the name; the panel's address and one way in, an API token or an admin's username and password (the other way's credentials are cleared); the connection options; and the server's own fields. A secret left blank or out keeps the stored one while the panel's address stays on the same host (after a host change it must be typed again); `clear_<name>: true` empties it. One closed shape per connector, checked against the connector's form by tests/Unit/Drivers/DriverFormsTest. */
export type ServerRequest = ServerThreeXuiRequest | ServerPasarGuardRequest

/** A server on a 3X-UI panel: its API token, or an admin's username and password plus the two-factor secret when the panel asks for a code. */
export interface ServerThreeXuiRequest {
  driver: '3x-ui'
  /** Shown only in the panels; at most 100 characters. */
  name: string
  /** The panel's address, including its path: http(s), without credentials, query or fragment, and not one of the panel's own pages (3X-UI's /panel, PasarGuard's /dashboard). */
  base_url: string
  /** How to sign in: an API token, or an admin's username and password. */
  auth_mode: 'token' | 'password'
  /** For `token`: the panel's API token (PasarGuard's whole `pg_key_…` key). Left out or blank: the stored token stays. */
  api_token?: string
  /** True empties the stored token. */
  clear_api_token?: boolean
  /** For `password`: a panel admin's username. */
  username?: string
  /** For `password`. Left out or blank: the stored password stays. */
  password?: string
  /** True empties the stored password. */
  clear_password?: boolean
  /** Whether to verify the panel's TLS certificate; off for a self-signed one. */
  verify_tls: boolean
  /** Request timeout in seconds, 5 to 120. */
  timeout: WholeNumberInput
  /** A base address to use before the token in subscription links instead of the panel's own: a full address without credentials, query or fragment. Blank: the panel's. */
  subscription_url?: string
  /** How many active services it takes. Blank for no limit; 0 for full. */
  capacity?: WholeNumberInput
  /** The owner's notes, at most 1000 characters. */
  notes?: string
  /** Off: no new service is placed on it. */
  is_active: boolean
  /** The colour of its button among a plan's locations in the bot: blue, green or red. Blank or left out: the client's default. */
  button_style?: '' | 'primary' | 'success' | 'danger'
  /** How many location buttons share its row in the bot. Left out: a row of its own. */
  button_row?: '1' | '2' | '3'
  /** For `password`: the two-factor secret (Base32) the shop makes sign-in codes from, as an authenticator app shows it (spaces, dashes and lower case accepted). Left out or blank: the stored secret stays. */
  totp_secret?: string
  /** True empties the stored two-factor secret; the sign-in then asks for no code. */
  clear_totp_secret?: boolean
}

/** A server on a PasarGuard panel: its API key, or an admin's username and password. */
export interface ServerPasarGuardRequest {
  driver: 'pasarguard'
  /** Shown only in the panels; at most 100 characters. */
  name: string
  /** The panel's address, including its path: http(s), without credentials, query or fragment, and not one of the panel's own pages (3X-UI's /panel, PasarGuard's /dashboard). */
  base_url: string
  /** How to sign in: an API token, or an admin's username and password. */
  auth_mode: 'token' | 'password'
  /** For `token`: the panel's API token (PasarGuard's whole `pg_key_…` key). Left out or blank: the stored token stays. */
  api_token?: string
  /** True empties the stored token. */
  clear_api_token?: boolean
  /** For `password`: a panel admin's username. */
  username?: string
  /** For `password`. Left out or blank: the stored password stays. */
  password?: string
  /** True empties the stored password. */
  clear_password?: boolean
  /** Whether to verify the panel's TLS certificate; off for a self-signed one. */
  verify_tls: boolean
  /** Request timeout in seconds, 5 to 120. */
  timeout: WholeNumberInput
  /** A base address to use before the token in subscription links instead of the panel's own: a full address without credentials, query or fragment. Blank: the panel's. */
  subscription_url?: string
  /** How many active services it takes. Blank for no limit; 0 for full. */
  capacity?: WholeNumberInput
  /** The owner's notes, at most 1000 characters. */
  notes?: string
  /** Off: no new service is placed on it. */
  is_active: boolean
  /** The colour of its button among a plan's locations in the bot: blue, green or red. Blank or left out: the client's default. */
  button_style?: '' | 'primary' | 'success' | 'danger'
  /** How many location buttons share its row in the bot. Left out: a row of its own. */
  button_row?: '1' | '2' | '3'
}

/** A connection to test before saving: a new server's form (ServerRequest), or a saved server's form with its `id`, whose stored secrets fill in blank ones while the address stays on the same host. One closed shape per connector. */
export type ServerTestRequest = ServerThreeXuiTestRequest | ServerPasarGuardTestRequest

/** A 3X-UI server to test. */
export interface ServerThreeXuiTestRequest {
  /** The saved server this form belongs to. */
  id?: number
  driver: '3x-ui'
  /** Shown only in the panels; at most 100 characters. */
  name: string
  /** The panel's address, including its path: http(s), without credentials, query or fragment, and not one of the panel's own pages (3X-UI's /panel, PasarGuard's /dashboard). */
  base_url: string
  /** How to sign in: an API token, or an admin's username and password. */
  auth_mode: 'token' | 'password'
  /** For `token`: the panel's API token (PasarGuard's whole `pg_key_…` key). Left out or blank: the stored token stays. */
  api_token?: string
  /** True empties the stored token. */
  clear_api_token?: boolean
  /** For `password`: a panel admin's username. */
  username?: string
  /** For `password`. Left out or blank: the stored password stays. */
  password?: string
  /** True empties the stored password. */
  clear_password?: boolean
  /** Whether to verify the panel's TLS certificate; off for a self-signed one. */
  verify_tls: boolean
  /** Request timeout in seconds, 5 to 120. */
  timeout: WholeNumberInput
  /** A base address to use before the token in subscription links instead of the panel's own: a full address without credentials, query or fragment. Blank: the panel's. */
  subscription_url?: string
  /** How many active services it takes. Blank for no limit; 0 for full. */
  capacity?: WholeNumberInput
  /** The owner's notes, at most 1000 characters. */
  notes?: string
  /** Off: no new service is placed on it. */
  is_active: boolean
  /** The colour of its button among a plan's locations in the bot: blue, green or red. Blank or left out: the client's default. */
  button_style?: '' | 'primary' | 'success' | 'danger'
  /** How many location buttons share its row in the bot. Left out: a row of its own. */
  button_row?: '1' | '2' | '3'
  /** For `password`: the two-factor secret (Base32) the shop makes sign-in codes from, as an authenticator app shows it (spaces, dashes and lower case accepted). Left out or blank: the stored secret stays. */
  totp_secret?: string
  /** True empties the stored two-factor secret; the sign-in then asks for no code. */
  clear_totp_secret?: boolean
}

/** A PasarGuard server to test. */
export interface ServerPasarGuardTestRequest {
  /** The saved server this form belongs to. */
  id?: number
  driver: 'pasarguard'
  /** Shown only in the panels; at most 100 characters. */
  name: string
  /** The panel's address, including its path: http(s), without credentials, query or fragment, and not one of the panel's own pages (3X-UI's /panel, PasarGuard's /dashboard). */
  base_url: string
  /** How to sign in: an API token, or an admin's username and password. */
  auth_mode: 'token' | 'password'
  /** For `token`: the panel's API token (PasarGuard's whole `pg_key_…` key). Left out or blank: the stored token stays. */
  api_token?: string
  /** True empties the stored token. */
  clear_api_token?: boolean
  /** For `password`: a panel admin's username. */
  username?: string
  /** For `password`. Left out or blank: the stored password stays. */
  password?: string
  /** True empties the stored password. */
  clear_password?: boolean
  /** Whether to verify the panel's TLS certificate; off for a self-signed one. */
  verify_tls: boolean
  /** Request timeout in seconds, 5 to 120. */
  timeout: WholeNumberInput
  /** A base address to use before the token in subscription links instead of the panel's own: a full address without credentials, query or fragment. Blank: the panel's. */
  subscription_url?: string
  /** How many active services it takes. Blank for no limit; 0 for full. */
  capacity?: WholeNumberInput
  /** The owner's notes, at most 1000 characters. */
  notes?: string
  /** Off: no new service is placed on it. */
  is_active: boolean
  /** The colour of its button among a plan's locations in the bot: blue, green or red. Blank or left out: the client's default. */
  button_style?: '' | 'primary' | 'success' | 'danger'
  /** How many location buttons share its row in the bot. Left out: a row of its own. */
  button_row?: '1' | '2' | '3'
}

/** Whether an inbound is sold on plans that sell the whole server. */
export interface InboundSellableRequest {
  is_selectable: boolean
}

export interface InboundsResponse {
  inbounds: ServerInboundRow[]
}

/** A server's free test accounts («اکانت تست»): whether it gives them, and what each gets. A test is made on every inbound the server sells. */
export interface ServerTestAccount {
  /** The server gives free tests (the bot settings switch them on for the shop as a whole). */
  test_enabled: boolean
  /** A test's traffic, in MB. */
  test_traffic_mb: number
  /** A test's term, in hours. */
  test_duration_hours: number
  /** The hours count from the customer's first connection; otherwise from the moment the test is made. */
  test_on_hold: boolean
}

export interface ServerTestAccountResponse {
  test_account: ServerTestAccount
}

/** A server's test terms, saved whole. Every refusal is returned at once, under its field. */
export interface ServerTestAccountRequest {
  test_enabled: boolean
  /** 1 to 102,400 MB. */
  test_traffic_mb: WholeNumberInput
  /** 1 to 720 hours. */
  test_duration_hours: WholeNumberInput
  test_on_hold: boolean
}

export type ServerGrantStatus = 'running' | 'done' | 'cancelled'

/** A grant's part on one server («افزودن زمان و حجم»): the grant's terms and its progress there. It is applied a batch at a time. */
export interface ServerGrantRow {
  /** The part's id, as used in `/servers/{id}/grants/{grant}`. */
  id: number
  /** The mass gift («هدیه همگانی») this is a part of, when the grant covers more than this server; null for a grant for this server only. */
  mass_grant_id: number | null
  /** Only the agents' services (a mass gift for agents). */
  agents_only: boolean
  days: number
  traffic_bytes: number
  /** The reason the customers read with it. */
  reason: string | null
  /** Whether customers are notified in the bot. */
  notify: boolean
  /** Services still waiting for their first connection get it too, not only running ones. */
  include_unstarted: boolean
  status: ServerGrantStatus
  /** The active services it goes through, counted when issued. The panel decides for each one, at its turn, whether it takes the grant. granted + skipped + failed is the progress. */
  total: number
  granted: number
  /** Services it did not apply to when their turn came (switched off, gone, ended, or still waiting for their first connection without `include_unstarted`). */
  skipped: number
  failed: number
  /** Why it is paused at its current service: what keeps the shop from using the panel (unreachable, a credential refused or missing…). It retries by itself. Null while it is moving. */
  waiting_reason: string | null
  /** The last service the panel refused, and why. */
  last_failure: string | null
  reviewer: string | null
  created_at: string
  finished_at: string | null
}

/** A server's grants card: its latest grants, and how many services a new grant would reach (from the stored data). */
export interface ServerGrantsResponse {
  grants: ServerGrantRow[]
  audience: {
    running: number
    unstarted: number
  }
}

export interface ServerGrantResponse {
  grant: ServerGrantRow
}

/** Days and traffic for the services on a server, to make up for an outage or as a gift. At least one amount must be above zero. It reaches the services running when it is issued, and those still waiting for their first connection only with `include_unstarted`. Customers are notified with the reason unless `notify` is false. */
export interface ServerGrantRequest {
  /** Whole days, 0 to 365; blank for none. */
  days?: WholeNumberInput
  /** Gigabytes, 0 to 10000; blank for none. */
  traffic_gb?: AmountInput
  /** A separate line of the message; at most 300 characters. */
  reason?: string
  notify?: boolean
  include_unstarted?: boolean
}

/** How many services a grant would reach, from the stored data: running ones, and ones still waiting for their first connection. */
export interface GrantReach {
  running: number
  unstarted: number
}

/** Who a mass gift reaches: `all` services, only the `agents`' services, one `server`'s, or one customer `group`'s (a group of the main shop). */
export type MassGrantAudience = 'all' | 'agents' | 'server' | 'group'

/** A mass gift's part on one server: a server grant, applied separately. */
export interface MassGrantPart {
  id: number
  server: NamedRef
  status: ServerGrantStatus
  total: number
  granted: number
  skipped: number
  failed: number
  /** Same as `ServerGrantRow.waiting_reason`. */
  waiting_reason: string | null
  last_failure: string | null
}

/** A grant: days and traffic for the running services on every server, or only the agents', one customer group's, or one server's (including grants made on a server's page). It has one part per server; its numbers are the parts' totals, and its status and end come from its parts. */
export interface MassGrantRow {
  id: number
  days: number
  traffic_bytes: number
  reason: string | null
  notify: boolean
  include_unstarted: boolean
  audience: MassGrantAudience
  /** The server, for the `server` audience. */
  server: NamedRef | null
  /** The customer group, for the `group` audience; also null once the group is deleted, after which the gift reaches no one else. */
  group: NamedRef | null
  status: ServerGrantStatus
  total: number
  granted: number
  skipped: number
  failed: number
  parts: MassGrantPart[]
  reviewer: string | null
  created_at: string
  finished_at: string | null
}

/** The mass gift card: the latest grants (including those made on servers' pages), and how many services a new one would reach for each audience: everyone, the agents, each server, and each customer group of the main shop. */
export interface MassGrantsResponse {
  grants: MassGrantRow[]
  audience: {
    all: GrantReach
    agents: GrantReach
    servers: {
      id: number
      name: string
      running: number
      unstarted: number
    }[]
    /** The main shop's customer groups, in the admin's order, including groups with no running services. */
    groups: {
      id: number
      name: string
      running: number
      unstarted: number
    }[]
  }
}

export interface MassGrantResponse {
  grant: MassGrantRow
}

/** A server grant's terms (ServerGrantRequest) for every server at once, or only for the agents' services (sold by the bots of agents with an active agency), one customer group's, or one server's. */
export interface MassGrantRequest {
  /** Whole days, 0 to 365; blank for none. */
  days?: WholeNumberInput
  /** Gigabytes, 0 to 10000; blank for none. */
  traffic_gb?: AmountInput
  /** A separate line of the message; at most 300 characters. */
  reason?: string
  notify?: boolean
  include_unstarted?: boolean
  audience: MassGrantAudience
  /** For the `server` audience: the server's id (`ServerRow.id`). Blank otherwise. */
  server_id?: WholeNumberInput
  /** For the `group` audience: a customer group of the main shop (`CustomerGroupRow.id`). Blank otherwise. */
  group_id?: WholeNumberInput
}

export type BroadcastStatus = 'sending' | 'paused' | 'done' | 'cancelled'

/** Who a broadcast goes to: everyone, buyers, non-buyers, the inactive (no running service), a customer group, agents, or one server's customers. */
export type BroadcastAudience = 'all' | 'buyers' | 'non_buyers' | 'inactive' | 'group' | 'agents' | 'server'

export interface BroadcastButton {
  text: string
  url: string
}

/** A broadcast run («ارسال همگانی»): a message a bot admin sent with /broadcast, copied or forwarded to an audience; or (kind `unpin`) a run that removes an earlier broadcast's pins. */
export interface BroadcastRow {
  id: number
  kind: 'message' | 'unpin'
  /** For an unpin run: the broadcast whose pins it removes. */
  source_id: number | null
  /** Copied by the bot (with the link buttons), or forwarded from its original sender. */
  mode: 'copy' | 'forward'
  audience: {
    key: BroadcastAudience
    /** The group or server the audience names. */
    id: number | null
    label: string
  }
  /** Each message is pinned in its chat. */
  pin: boolean
  /** Chats where its message is still pinned (what unpin, «لغو پین», would remove). */
  pinned: number
  /** The link buttons under a copied message, as rows in reading order. */
  buttons: BroadcastButton[][]
  /** The message type: text, photo, video, animation, document, audio, voice, video_note, sticker, poll, other. */
  content: string | null
  /** Its text or caption, shortened. */
  excerpt: string | null
  status: BroadcastStatus
  /** Recipients when it started; sent + blocked + failed is the progress. */
  total: number
  sent: number
  /** Recipients who blocked the bot. */
  blocked: number
  failed: number
  /** The bot admin who sent it; null for a run started from the panel. */
  admin: UserRef | null
  /** Who started it from a panel or the website: the agent's @bot, a bot admin's @username, tg:<id> or user#<id>, or the owner's login (which anyone but the owner sees as «پشتیبانی»). */
  reviewer: string | null
  created_at: string
  finished_at: string | null
  actions: {
    pause: boolean
    resume: boolean
    cancel: boolean
    unpin: boolean
  }
}

export interface BroadcastsResponse {
  broadcasts: BroadcastRow[]
  meta: PageMeta
}

export interface BroadcastResponse {
  broadcast: BroadcastRow
}

/** A secret as a settings screen sees it: never the value, only whether one is stored and a short hint to recognise it. */
export interface SecretState {
  set: boolean
  hint: string
}

export type ConfigGroup = 'app' | 'database' | 'telegram' | 'mail' | 'advanced'

export interface ConfigAppSettings {
  name: string
  /** APP_URL: the shop's public address, including any sub-folder. Every address the shop gives out is built on it. */
  url: string
  debug: boolean
  timezone: string
}

export interface ConfigTelegramSettings {
  token: SecretState
  username: string
  api_url: string
  /** Seconds. */
  poll_timeout: number
  webhook_secret: SecretState
}

/** How the shop sends email: `none`, or a mail driver's key (`DriverDescription.key`): `smtp`, an account on a mail server; `native`, the host's own mail through PHP's mail() (php.ini's sendmail_path); `resend`, Resend's API (resend.com), with an API key and the sender's domain verified there. */
export type MailTransport = 'none' | 'smtp' | 'native' | 'resend'

/** An SMTP connection's encryption: `tls` (STARTTLS, required), `ssl` (TLS from the first byte) or `none`. */
export type MailEncryption = 'tls' | 'ssl' | 'none'

/** The shop's email settings (MAIL_* in config.php), used for the website's sign-up and password reset codes and for notices to customers Telegram cannot reach: the transport, every mail driver described with its settings, and the sender. */
export interface ConfigMailSettings {
  transport: MailTransport
  /** The sender address of the shop's emails; empty means no email is sent. */
  from_address: string
  /** The sender name; empty means the shop's name. */
  from_name: string
  /** Every mail driver with its form. Its `sender` trait says which sender address it can use. */
  drivers: DriverDescription[]
  /** Each driver's settings, keyed by driver: the stored values, else the defaults. A secret is a SecretState. */
  values: Record<string, DriverValues>
}

export interface ConfigAdvancedSettings {
  log_level: string
  /** Minutes. */
  session_lifetime: number
  session_secure_cookie: boolean
  /** Seconds. */
  http_timeout: number
  cron_token: SecretState
}

/** The installation's configuration, read from config.php. */
export interface ConfigSettingsResponse {
  file: {
    path: string
    exists: boolean
    writable: boolean
  }
  groups: {
    app: ConfigAppSettings
    database: DatabaseSettings
    telegram: ConfigTelegramSettings
    mail: ConfigMailSettings
    advanced: ConfigAdvancedSettings
  }
  meta: {
    /** Windows-style groups: one IANA id per zone, labelled "(UTC+03:30) وقت ایران". */
    timezones: {
      id: string
      label: string
    }[]
    log_levels: string[]
    /** The cron trigger address with its token masked; null without a token. The full address is at `GET /api/admin/settings/config/cron-url`. */
    cron_url: string | null
    /** The main bot's webhook address, with its secret masked. */
    webhook_url: string
  }
}

export interface CronUrlResponse {
  /** The full cron trigger address, token included, for the copy button; null without a token. */
  url: string | null
}

export interface BotIdentity {
  id: number
  username: string
  name: string
}

export interface BotIdentityResponse {
  bot: BotIdentity
}

/** The database server a probe reached. */
export interface DatabaseCheck {
  /** The server and its version: "MariaDB 10.4.32", "SQLite 3.45.1" */
  version: string
  /** The number of tables in the database */
  tables: number
}

export interface DatabaseCheckResponse {
  database: DatabaseCheck
}

/** One group of the config.php settings, saved whole (`PUT /settings/config/{group}`), with the fields `ConfigSettingsResponse.groups` lists for it. A secret left blank or out keeps the stored one; `clear_<name>: true` empties it. */
export type ConfigSettingsRequest = ConfigAppRequest | ConfigTelegramRequest | ConfigMailRequest | ConfigAdvancedRequest | DatabaseRequest

export interface ConfigAppRequest {
  name: string
  url: string
  debug: boolean
  /** An IANA time zone id (one of `meta.timezones`). */
  timezone: string
}

export interface ConfigTelegramRequest {
  /** Left out or blank: the stored token stays, as long as the Bot API address (`api_url`) stays on the same host. */
  token?: string
  clear_token?: boolean
  /** The bot's username, with or without the @; blank for none. */
  username?: string
  /** The Bot API's address: http(s) with a host, without credentials, ? or #. Moving it to another origin (scheme, host or port) sends every bot's token there, agents' included, so the save then needs `current_password`. */
  api_url: string
  /** The owner's current panel password. Read only when `api_url` moves to another origin: blank or wrong (a wrong one counts as a failed sign-in) is a 422 on it, and nothing is saved. */
  current_password?: string
  /** Seconds, 1 to 60. */
  poll_timeout: WholeNumberInput
  webhook_secret?: string
  clear_webhook_secret?: boolean
}

/** The shop's email settings, saved whole: the transport and, for a mail driver, its settings and the sender. One closed shape per transport; the other drivers' stored settings are not changed. */
export type ConfigMailRequest = MailNoneRequest | MailSmtpRequest | MailNativeRequest | MailResendRequest

/** No email. The drivers' stored settings are kept, so they can be switched back on. */
export interface MailNoneRequest {
  transport: 'none'
}

/** An account on a mail server, over SMTP. */
export interface MailSmtpRequest {
  transport: 'smtp'
  /** Such as mail.example.com, without scheme or port. */
  host: string
  /** 1 to 65535: usually 587 with tls, 465 with ssl, 25 without. */
  port: WholeNumberInput
  encryption: MailEncryption
  /** Blank for none. */
  username?: string
  /** Left out or blank: the stored password stays, as long as the host, port, encryption and username stay the same. */
  password?: string
  /** True empties the stored password. */
  clear_password?: boolean
  /** An email address (stored in lower case). */
  from_address: string
  /** At most 64 characters; blank for the shop's name. */
  from_name?: string
}

/** The host's own mail, sent with PHP's mail(). */
export interface MailNativeRequest {
  transport: 'native'
  /** An email address (stored in lower case). */
  from_address: string
  /** At most 64 characters; blank for the shop's name. */
  from_name?: string
}

/** Resend's API. */
export interface MailResendRequest {
  transport: 'resend'
  /** Resend's API key (re_…, from resend.com › API Keys). Left out or blank: the stored key stays. A key is required. */
  resend_key?: string
  /** True empties the stored key. */
  clear_resend_key?: boolean
  /** An email address (stored in lower case). */
  from_address: string
  /** At most 64 characters; blank for the shop's name. */
  from_name?: string
}

/** Where to send the test email. */
export interface MailTestRequest {
  /** An email address. */
  to: string
}

export interface MailTestResponse {
  /** True: the transport accepted it. Whether it reaches the inbox is up to the mail server. */
  sent: boolean
}

export interface ConfigAdvancedRequest {
  /** One of `meta.log_levels`; anything else is refused. */
  log_level: string
  /** Minutes, 5 to 43200. */
  session_lifetime: WholeNumberInput
  session_secure_cookie: boolean
  /** Seconds, 5 to 120. */
  http_timeout: WholeNumberInput
  cron_token?: string
  clear_cron_token?: boolean
}

/** A token to check with Telegram. Blank or left out: the stored token. */
export interface TelegramTestRequest {
  token?: string
}

/** A server, as the plan form and the plans table show it. */
export interface PlanServerRef {
  id: number
  name: string
  is_active: boolean
  /** As on the servers screen: null if never checked, false if it serves no subscription links. Either way customers are not offered the entry. */
  serves_subscriptions: boolean | null
}

export interface PlanInboundRef {
  id: number
  remark: string | null
  /** Null when it is not a single protocol (a PasarGuard group). */
  protocol: string | null
  /** Null when it has no port of its own (a PasarGuard group). */
  port: number | null
  enabled: boolean
  is_selectable: boolean
}

/** A server a plan is sold on. `inbounds` is what a purchase gets today (resolved for whole-server entries). */
export interface PlanServerEntry {
  server: PlanServerRef
  all_inbounds: boolean
  inbounds: PlanInboundRef[]
  /** Why customers are not offered this entry now (the server is switched off, serves no subscription links, is full, has no sellable inbound…); null when they are. */
  unsellable_reason: string | null
}

export interface PlanCategoryRef {
  id: number
  name: string
  is_active: boolean
}

/** A plan category. */
export interface PlanCategoryRow {
  id: number
  name: string
  is_active: boolean
  sort: number
  counts: {
    plans: number
  }
  created_at: string
  updated_at: string
}

/** A plan, as the list shows it. */
export interface PlanRow {
  id: number
  /** Null when uncategorised (listed under "سایر پلن‌ها" when the shop has categories). */
  category: PlanCategoryRef | null
  name: string
  description: string | null
  price: string
  /** The price of a customer's first purchase in the shop («قیمت خرید اول»), below `price`; "0.00" makes the first purchase free. Null for none: everyone pays `price`. Renewals are always at `price`. */
  first_purchase_price: string | null
  /** The price for agents and members of groups with agent prices («همکار») («قیمت نماینده»), below `price`, for purchases and renewals. Null for none. */
  agent_price: string | null
  /** A note shown under the bot's checkout for this plan («یادداشت پیش‌فاکتور»), for purchases and renewals; null for none. */
  checkout_note: string | null
  /** The colour of its button in the bot's plan list; null for the client's default. */
  button_style: ButtonStyle | null
  /** How many buttons share its row there: 1 for a row of its own, or 2 or 3 (consecutive buttons with the same number share rows). */
  button_row: number
  /** The term in whole days, rounded up; 0 only for no time limit. `duration_hours` is exact. */
  duration_days: number
  /** The term in hours from the first connection (a daily plan's is days × 24); 0 for no time limit. */
  duration_hours: number
  /** Gigabytes, decimals allowed; 0 for unlimited */
  traffic_gb: number
  /** Devices at once; 0 for unlimited */
  ip_limit: number
  /** The plan's own prices for extra traffic, time and devices; null to use the shop's (the bot settings' `extra_…`). */
  extras: ExtraPrices | null
  servers: PlanServerEntry[]
  /** Why the bot does not offer the plan now, apart from its switch: it has no server, none of its servers can sell (each entry says why), or an agent's traffic does not cover it. Null when it is offered. */
  unsellable_reason: string | null
  is_active: boolean
  sort: number
  counts: {
    /** Its active services. */
    active_subscriptions: number
    /** Its sold orders: paid, being delivered or delivered. */
    sales: number
    /** All orders placed for it, whatever their status. A plan with any orders or services cannot be deleted (switch it off instead). */
    orders: number
    /** All services sold on it, ended ones included. */
    subscriptions: number
  }
  created_at: string
  updated_at: string
}

export interface PlanOptionServer {
  id: number
  name: string
  is_active: boolean
  /** As on the servers screen: null if never checked, false if it serves no subscription links. Either way customers are not offered the entry. */
  serves_subscriptions: boolean | null
  driver_label: string
  inbounds: PlanInboundRef[]
  /** Why nothing can be sold on the server now (switched off, no subscription links, full…); null when it can. */
  unsellable_reason: string | null
}

/** The servers, inbounds and categories the plan form can use. */
export interface PlanOptions {
  categories: PlanCategoryRef[]
  servers: PlanOptionServer[]
}

export interface PlansResponse {
  plans: PlanRow[]
}

export interface PlanResponse {
  plan: PlanRow
}

export interface PlanCategoriesResponse {
  categories: PlanCategoryRow[]
}

export interface PlanCategoryResponse {
  category: PlanCategoryRow
}

/** A plan's whole form, to add or update it: its texts, its price, what it gives (0 means no limit for the term, the traffic and the devices), its category, and the servers it is sold on (the list replaces the current one). */
export interface PlanRequest {
  name: string
  /** Shown with the plan in the bot; blank for none. */
  description?: string
  /** Whole Toman; 0 for a free plan. */
  price: AmountInput
  /** The term in hours from the first connection (a daily plan's is days × 24); 0 never ends. This or `duration_days` is required; when both are sent, this one is used. */
  duration_hours?: WholeNumberInput
  /** The term in whole days (the older alternative to `duration_hours`); 0 never ends. */
  duration_days?: WholeNumberInput
  /** Gigabytes; 0 for unlimited, which an agent's shop refuses (its traffic is prepaid). */
  traffic_gb: AmountInput
  /** Devices at once; 0 for unlimited. */
  ip_limit: WholeNumberInput
  /** The price of a customer's first purchase in the shop («قیمت خرید اول»), whole Toman below `price`; 0 makes it free. Blank or null for none. Left out: none for a new plan, unchanged for an existing one. */
  first_purchase_price?: AmountInput | null
  /** The price for agents and members of groups with agent prices («قیمت نماینده»), whole Toman below `price`. Blank or null for none. Left out: none for a new plan, unchanged for an existing one. */
  agent_price?: AmountInput | null
  /** A note shown under the bot's checkout for this plan, at most 500 characters. Blank or null for none. Left out: none for a new plan, unchanged for an existing one. */
  checkout_note?: string | null
  /** The colour of its button in the bot's plan list: blue, green or red. Blank or null for the client's default. Left out: the default for a new plan, unchanged for an existing one. */
  button_style?: '' | 'primary' | 'success' | 'danger' | null
  /** How many buttons share its row there, 1 to 3. Left out: a row of its own for a new plan, unchanged for an existing one. */
  button_row?: WholeNumberInput
  /** The plan's own prices for extra traffic, time and devices; null to use the shop's. Left out: the shop's for a new plan, unchanged for an existing one. */
  extras?: ExtraPricesRequest | null
  /** A category id (`PlanCategoryRow.id`); blank or 0 for none. */
  category_id?: WholeNumberInput
  /** At least one, each server once. */
  servers: PlanServerEntryInput[]
  /** Left out: on for a new plan, unchanged for an existing one. */
  is_active?: boolean
}

/** A bulk change of several plans' prices: raise or lower the named prices by a percent or a number of Toman, rounding each new price to the nearest step. A plan without one of the named prices still has none afterwards. */
export interface PlanPricesRequest {
  /** The plans to change; at least one. */
  plan_ids: number[]
  direction: 'increase' | 'decrease'
  /** A percent of each price (at most 1000, or 100 for a decrease), or a number of Toman. */
  mode: 'percent' | 'amount'
  /** The percent (decimals allowed) or whole Toman; above zero. */
  value: AmountInput
  /** Each new price is rounded to the nearest multiple of this; 1 means whole Toman. */
  round_to: 1 | 100 | 1000 | 10000
  /** Which prices change: the price, the first-purchase price, the agent price. */
  prices: ('price' | 'first_purchase_price' | 'agent_price')[]
}

export interface PlanPrices {
  price: string
  first_purchase_price: string | null
  agent_price: string | null
}

export interface PlanPricesPreviewResponse {
  /** Every plan named, in the plans list's order. */
  changes: {
    id: number
    name: string
    before: PlanPrices
    after: PlanPrices
    /** Why the plan cannot take the change: a price below zero or above the maximum, or a first-purchase or agent price no longer below the price. Null when it can. */
    problem: string | null
  }[]
}

/** A server the plan is sold on: the whole server (every inbound marked sellable at purchase time) or the picked inbounds. A connector without inbounds always sells the whole server. */
export interface PlanServerEntryInput {
  server_id: number
  /** Left out: only the picked inbounds. */
  all_inbounds?: boolean
  /** Enabled inbounds of this server (`PlanInboundRef.id`); empty for the whole server. */
  inbound_ids?: number[]
}

/** A plan category to add or update. */
export interface PlanCategoryRequest {
  /** Unique in the shop. */
  name: string
  /** Left out: on for a new category, unchanged for an existing one. */
  is_active?: boolean
}

/** The gateway drivers a payment method can use, in registration order. Each has its form (the method's settings) and its traits: `kind`, how it settles (a GatewayKind), and `builtin`, part of every shop (the wallet: one row per shop, never added or deleted, with no settings). */
export interface PaymentDriversResponse {
  drivers: DriverDescription[]
}

/** A payment method, in checkout order, as its driver describes it. `config` holds the driver's form values as stored (one closed shape per driver). */
export type PaymentMethodRow = ManualMethodRow | WalletMethodRow | UnregisteredMethodRow

/** A card-to-card method. */
export interface ManualMethodRow {
  id: number
  driver: 'manual'
  driver_label: string
  kind: GatewayKind
  builtin: boolean
  /** The button text customers see, such as "کارت به کارت (ملت)". */
  label: string
  /** The driver's one-line summary of the method (the masked card number and holder), as the payments screen shows it; null when it has none. */
  summary: string | null
  config: ManualGatewayConfig
  enabled: boolean
  sort: number
  counts: {
    /** The number of payments made with it. A method with payments cannot be deleted. */
    payments: number
  }
  created_at: string
  updated_at: string
}

/** The wallet, the built-in method. */
export interface WalletMethodRow {
  id: number
  driver: 'wallet'
  driver_label: string
  kind: GatewayKind
  builtin: boolean
  /** The button text customers see, such as "کارت به کارت (ملت)". */
  label: string
  /** The driver's one-line summary of the method (the masked card number and holder), as the payments screen shows it; null when it has none. */
  summary: string | null
  /** Nothing to configure. */
  config: Record<string, never>
  enabled: boolean
  sort: number
  counts: {
    /** The number of payments made with it. A method with payments cannot be deleted. */
    payments: number
  }
  created_at: string
  updated_at: string
}

/** A method whose driver is no longer installed. No checkout offers it, even when switched on; it can only be switched off or deleted. */
export interface UnregisteredMethodRow {
  id: number
  driver: string
  driver_label: string
  /** Unknown without the driver. */
  kind: null
  builtin: boolean
  /** The button text customers see, such as "کارت به کارت (ملت)". */
  label: string
  /** The driver's one-line summary of the method (the masked card number and holder), as the payments screen shows it; null when it has none. */
  summary: string | null
  /** Not shown, since without the driver it is unknown which settings are secrets. */
  config: Record<string, never>
  enabled: boolean
  sort: number
  counts: {
    /** The number of payments made with it. A method with payments cannot be deleted. */
    payments: number
  }
  created_at: string
  updated_at: string
}

/** A card-to-card method's settings (`ManualMethodRow.config`): the manual driver's form values as stored. A field added after the method was saved shows its default. */
export interface ManualGatewayConfig {
  /** 16 digits, no separators. */
  card_number: string
  card_holder: string
  instructions: string
  /** Minutes a receipt sent in the bot may wait for review before it is approved automatically; 0 for manual approval only. */
  auto_approve_after: number
}

export interface PaymentMethodsResponse {
  methods: PaymentMethodRow[]
}

export interface PaymentMethodResponse {
  method: PaymentMethodRow
}

/** A card-to-card method's form: the label customers pick it by, its switch, and the manual driver's form (the card number and holder the bot shows, the note under them, and how long a receipt may wait for review). */
export interface ManualMethodRequest {
  /** The checkout button's text, such as «کارت به کارت (ملت)». Left out on an update: unchanged. */
  label?: string
  /** Left out: on for a new method, unchanged for an existing one. */
  enabled?: boolean
  /** 16 digits; Persian digits, and spaces or dashes between them, are accepted. */
  card_number: string
  card_holder: string
  /** Shown to the customer with the card; blank for none. */
  instructions?: string
  /** Minutes a receipt sent in the bot may wait for review before it is approved automatically; blank or 0 for manual approval only. */
  auto_approve_after?: WholeNumberInput
}

/** The wallet, the built-in method: only its label and switch can be set. */
export interface WalletMethodRequest {
  label?: string
  enabled?: boolean
}

/** A new card-to-card method: its driver and its form (ManualMethodRequest), with the label required. */
export interface ManualMethodCreateRequest {
  /** A driver key (`DriverDescription.key`) from `GET /payment-methods/drivers`. */
  driver: 'manual'
  /** The checkout button's text, such as «کارت به کارت (ملت)». Left out on an update: unchanged. */
  label: string
  /** Left out: on for a new method, unchanged for an existing one. */
  enabled?: boolean
  /** 16 digits; Persian digits, and spaces or dashes between them, are accepted. */
  card_number: string
  card_holder: string
  /** Shown to the customer with the card; blank for none. */
  instructions?: string
  /** Minutes a receipt sent in the bot may wait for review before it is approved automatically; blank or 0 for manual approval only. */
  auto_approve_after?: WholeNumberInput
}

/** A new payment method for a driver that is not built in: the driver's key, the label customers pick it by, the switch and the driver's form (`DriverDescription.fields`). One closed shape per driver, checked against its form by tests/Unit/Drivers/DriverFormsTest. */
export type PaymentMethodCreateRequest = ManualMethodCreateRequest

/** A payment method's form, as its driver takes it. One closed shape per driver, checked against its form by tests/Unit/Drivers/DriverFormsTest. A method whose driver is no longer installed cannot be updated (switch it off or delete it). */
export type PaymentMethodUpdateRequest = ManualMethodRequest | WalletMethodRequest

/** A payment method's on/off switch. A JSON boolean only. */
export interface PaymentMethodSwitchRequest {
  enabled: boolean
}

/** Telegram's button colours (`style` on KeyboardButton and InlineKeyboardButton). Null means the client's default look. */
export type ButtonStyle = 'primary' | 'success' | 'danger'

export interface KeyboardButtonSpec {
  /** A bot action (`KeyboardAction.key`). */
  action: string
  /** The button text; on a reply keyboard, also what a tap sends. */
  label: string
  style: ButtonStyle | null
  /** A premium emoji's id (see CustomEmojiRow), shown before the label (Telegram's `icon_custom_emoji_id`). It shows only while the bot's owner has Telegram Premium. Null for none. */
  icon: string | null
}

export type KeyboardType = 'reply' | 'inline'

/** One of the bot's keyboards as laid out. Rows are in reading order (the first button is the rightmost). */
export interface KeyboardLayoutData {
  name: string
  title: string
  is_default: boolean
  type: KeyboardType
  rows: KeyboardButtonSpec[][]
}

/** An action a menu button can trigger. */
export interface KeyboardAction {
  key: string
  title: string
  /** The default label (emoji and title). */
  label: string
  /** The `menu:*` callback data an inline button carries. */
  screen: string
}

export interface KeyboardsResponse {
  keyboards: KeyboardLayoutData[]
  /** The actions this shop's menu buttons can have. An agent's bot has no «نمایندگی»: its layouts never show it, and saving it is refused. */
  actions: KeyboardAction[]
  styles: ButtonStyle[]
  limits: {
    rows: number
    per_row: number
    label: number
  }
}

export interface KeyboardResponse {
  keyboard: KeyboardLayoutData
}

/** A new keyboard layout: its type and its rows in reading order (the first button is the rightmost). Empty rows are dropped. */
export interface KeyboardRequest {
  type: KeyboardType
  rows: KeyboardButtonInput[][]
}

/** A button as the editor sends it (a KeyboardButtonSpec). Each action and each label may appear only once per keyboard. */
export interface KeyboardButtonInput {
  /** An action key (`KeyboardAction.key`). */
  action: string
  /** Plain text; a premium emoji goes in `icon`. */
  label: string
  style?: ButtonStyle | null
  /** A premium emoji's id; null for none. */
  icon?: string | null
}

/** Where a text is used: a `message` or the QR card's `caption` (Telegram HTML), a callback `popup` or a `button` (plain text), or a `part` included in other texts. */
export type BotTextKind = 'message' | 'caption' | 'popup' | 'button' | 'part'

/** A `%name%` placeholder the bot fills in when it sends the text. */
export interface BotTextVariable {
  name: string
  description: string
  /** The sample value the screen's preview uses. */
  sample: string
  /** The wording must contain it (a delivery message needs its link). */
  required: boolean
}

/** A text the bot sends customers, with its default wording and the current one. */
export interface BotTextRow {
  key: string
  group: string
  kind: BotTextKind
  /** Telegram reads it as HTML (a message, a caption or a part). */
  html: boolean
  /** The maximum length of the wording, in characters. */
  limit: number
  title: string
  /** Where the customer sees it. */
  description: string
  variables: BotTextVariable[]
  default: string
  /** The wording in use: the custom one, or the default. */
  value: string
  customized: boolean
}

export interface BotTextGroup {
  key: string
  title: string
  texts: BotTextRow[]
}

export interface BotTextsResponse {
  /** The text groups of the shop's bot, in the screen's order. An agent's bot has no «نمایندگی» group. */
  groups: BotTextGroup[]
}

export interface BotTextResponse {
  text: BotTextRow
}

/** A new wording: Telegram HTML for a message, a caption or a part, plain text for a popup or a button, with all its required variables. Sending the default wording restores the default. */
export interface BotTextRequest {
  value: string
}

/** A premium emoji an admin sent the bot with /emoji, offered by the bot texts and keyboards editors. */
export interface CustomEmojiRow {
  /** Telegram's custom emoji id: digits, as a string. */
  id: string
  /** The plain emoji it stands for, which goes inside its <tg-emoji> tag. */
  emoji: string
  /** How Telegram draws it: a still picture, a Lottie animation or a WebM video (see `…/animation`); null while unknown. */
  format: 'static' | 'animated' | 'video' | null
  /** Telegram paints it in the colour of the surrounding text (`needs_repainting`). */
  repaint: boolean
  updated_at: string
}

export interface CustomEmojisResponse {
  /** Most recently seen first. */
  emojis: CustomEmojiRow[]
  /** Whether the bot can send premium emoji, as the last /emoji check found; null before the first check. */
  status: {
    ok: boolean
    checked_at: string
  } | null
}

/** What a gift code gives: Toman in the wallet, or a free service of a plan. */
export type GiftCodeKind = 'wallet' | 'plan'

/** A gift code's status: `unused`, `redeemed` by a customer, or `expired` without being redeemed. */
export type GiftCodeStatus = 'unused' | 'redeemed' | 'expired'

/** A gift code («کد هدیه»), redeemable once by one customer of the shop. */
export interface GiftCodeRow {
  id: number
  /** Upper-case Latin letters and digits, as stored and matched. Customers may type it in any case, with dashes and spaces. */
  code: string
  /** Its batch label. */
  batch: string | null
  kind: GiftCodeKind
  /** Toman, for the wallet; null for a plan. */
  amount: string | null
  /** The plan it gives; null for a Toman code, or when the plan was deleted (then nobody can redeem it). */
  plan: RewardPlanRef | null
  /** When it expires (the first moment it can no longer be redeemed); null for never. */
  expires_at: string | null
  status: GiftCodeStatus
  /** Who redeemed it; null while unredeemed, or once their account is gone. */
  redeemed_by: UserRef | null
  redeemed_at: string | null
  /** For a redeemed plan code: the order that delivered its service. */
  order: RewardOrderRef | null
  created_at: string
}

export interface RewardPlanRef {
  id: number
  name: string
}

export interface RewardOrderRef {
  id: number
  status: OrderStatus
}

export interface GiftCodesResponse {
  codes: GiftCodeRow[]
  meta: PageMeta
}

/** A batch of gift codes to create. Every refusal is returned at once, under its field. */
export interface GiftCodeBatchRequest {
  /** How many codes, 1 to 500. */
  count: WholeNumberInput
  kind: GiftCodeKind
  /** For `wallet` codes (required): whole Toman each code adds to the wallet, 1,000 to 500,000,000. */
  amount?: AmountInput
  /** For `plan` codes (required): the plan whose service each code gives. Any plan of the shop, even one that is switched off. */
  plan_id?: number
  /** The batch label (at most 64 characters). A batch is listed, copied and deleted by its label. */
  batch: string
  /** A prefix for every code: Latin letters and digits, at most 12 (dashes and spaces are dropped, letters upper-cased). Blank for none. */
  prefix?: string
  /** The last day the codes can be redeemed, in the shop's time zone (YYYY-MM-DD, today or later). Blank or left out: never expires. */
  expires_on?: string
}

export interface GiftCodeBatchResponse {
  batch: string
  /** The new codes, for copying. */
  codes: string[]
}

export interface GiftCodeBatch {
  batch: string | null
  total: number
  redeemed: number
  /** Expired without being redeemed. */
  expired: number
  /** Not yet redeemed. */
  unused: number
  /** When its newest codes were created. */
  created_at: string
}

export interface GiftCodeBatchesResponse {
  batches: GiftCodeBatch[]
}

export interface GiftCodesUnusedResponse {
  codes: string[]
}

export interface GiftCodeDiscardRequest {
  /** The batch's label. */
  batch: string
}

export interface GiftCodesDeletedResponse {
  deleted: number
}

/** What a lucky wheel slice gives: `nothing` («پوچ»), Toman in the wallet (`wallet`), GB of `traffic` or `days` on one of the customer's running services, or a free service of a `plan`. */
export type WheelPrizeKind = 'nothing' | 'wallet' | 'traffic' | 'days' | 'plan'

/** A lucky wheel («گردونه شانس») slice. Its chance is its weight divided by the total weight of the enabled slices the customer can receive at the moment of the spin. */
export interface WheelPrizeRow {
  id: number
  /** The text the wheel shows. */
  label: string
  kind: WheelPrizeKind
  /** Toman (wallet), GB (traffic) or days (days); null for nothing and plan. */
  amount: string | null
  /** For a plan slice: the plan; null once the plan is deleted (then nobody can win it). */
  plan: RewardPlanRef | null
  weight: number
  enabled: boolean
  sort: number
}

export interface WheelPrizesResponse {
  prizes: WheelPrizeRow[]
}

export interface WheelPrizeResponse {
  prize: WheelPrizeRow
}

/** A wheel slice to add or update. Every refusal is returned at once, under its field. */
export interface WheelPrizeRequest {
  /** The text the wheel shows, at most 32 characters. */
  label: string
  kind: WheelPrizeKind
  /** For wallet: whole Toman, at least 1,000. For traffic: GB above zero, up to two decimals, at most 10000. For days: whole days, 1 to 3650. Ignored for nothing and plan. */
  amount?: AmountInput
  /** For a plan slice: the plan whose service it gives. */
  plan_id?: number
  /** 1 to 10000. */
  weight: WholeNumberInput
  /** Left out: on for a new slice, unchanged for an existing one. */
  enabled?: boolean
}

export interface WheelPrizeSwitchRequest {
  enabled: boolean
}

/** A lucky wheel spin: the slice it landed on (as the slice was then) and the result. */
export interface WheelSpinRow {
  id: number
  customer: UserRef
  label: string
  kind: WheelPrizeKind
  amount: string | null
  /** The prize was given (for a plan: ordered). False when it could not be: the cost was refunded and the spin does not count for the cooldown. */
  given: boolean
  /** Why it was not given; null when it was. */
  outcome: string | null
  /** For a plan slice: the order that delivered its service. */
  order: RewardOrderRef | null
  /** The service that got the traffic or days, or the plan's new service. */
  subscription: RewardServiceRef | null
  created_at: string
}

export interface RewardServiceRef {
  id: number
  /** The client's name on the panel. */
  name: string
}

export interface WheelSpinsResponse {
  spins: WheelSpinRow[]
  meta: PageMeta
}

/** Where a free test account stands: `making` (its panel is still being asked), `waiting` (its hours start at the first connection), `running`, `disabled` (switched off by support), `ended` (its time or traffic ran out, or its panel no longer has it), `removed` (deleted from its panel and the shop; it still counts in the customer's quota). */
export type TestAccountState = 'making' | 'waiting' | 'running' | 'disabled' | 'ended' | 'removed'

/** A free test account («اکانت تست») the shop gave, with the terms it got (as its server's were then). */
export interface TestAccountRow {
  id: number
  customer: UserRef
  /** Null once the server is deleted. */
  server: NamedRef | null
  traffic_bytes: number
  duration_hours: number
  /** Its hours counted from the first connection; otherwise from the moment it was made. */
  on_hold: boolean
  state: TestAccountState
  /** Its service, as last read from its panel; null while it is being made and once it is removed. */
  subscription: {
    id: number
    /** The client's name on the panel ("test_ali_1"). */
    name: string
    status: SubscriptionStatus
    /** In bytes; a limit of 0 means unlimited. */
    traffic: {
      limit: number
      used: number
    }
    /** When the first connection started its hours; null until then. */
    starts_at: string | null
    /** Null while it waits for the first connection. */
    expires_at: string | null
  } | null
  /** The customer bought a service with a price after taking it. */
  bought_after: boolean
  created_at: string
  /** When its service was made; null while it is being made. */
  issued_at: string | null
  /** When it ended — its deadline, or when the shop found its traffic used up or its client gone (its removal is counted from then); null while it runs (and past its deadline until the shop next reads its panel), and once it is removed. */
  ended_at: string | null
}

export interface TestAccountsResponse {
  tests: TestAccountRow[]
  meta: PageMeta
}

/** A server with its test terms (`ServerTestAccount`), and whether it would give a test in this shop now. */
export interface TestServerRow {
  id: number
  name: string
  /** The server gives free tests (the bot settings switch them on for the shop as a whole). */
  test_enabled: boolean
  /** A test's traffic, in MB. */
  test_traffic_mb: number
  /** A test's term, in hours. */
  test_duration_hours: number
  /** The hours count from the customer's first connection; otherwise from the moment the test is made. */
  test_on_hold: boolean
  /** The server would give a test in this shop now: it is switched on for tests (`test_enabled`), it can take a client (switched on, serving subscription links, with room, and an inbound it sells where its panel has inbounds), and in an agent's shop the agent's bot has a test's traffic left. The shop's own rules are not counted: its switch (the bot settings' `test_enabled`), the daily cap and each customer's quota. While the shop's switch is off, no customer gets a test, whatever this says. */
  available: boolean
}

export interface TestServersResponse {
  servers: TestServerRow[]
}

/** A loyalty tier («سطح وفاداری»). A customer is on the highest tier whose `min_spent` they reached; it gives them a discount on purchases and extra cashback on payments. */
export interface LoyaltyTierRow {
  id: number
  name: string
  /** Toman a customer must have spent: paid and unrefunded purchases, renewals and extras. */
  min_spent: string
  /** 0 to 100; 0 for none. At checkout the largest automatic discount applies (a sale, the personal discount, or this). */
  discount_percent: number
  /** 0 to 100; 0 for none. Added to the cashback rules' percent while cashback is on. */
  cashback_percent: number
  /** How many customers are on it now. */
  customers: number
}

export interface LoyaltyTiersResponse {
  /** Lowest threshold first. */
  tiers: LoyaltyTierRow[]
}

export interface LoyaltyTierResponse {
  tier: LoyaltyTierRow
}

/** A loyalty tier to add or update. Every refusal is returned at once, under its field. */
export interface LoyaltyTierRequest {
  /** At most 32 characters, unique within the shop. */
  name: string
  /** Whole Toman, 0 or more; no two tiers may share a threshold. 0 puts every customer on it from the start. */
  min_spent: AmountInput
  /** 0 to 100. */
  discount_percent: WholeNumberInput
  /** 0 to 100. */
  cashback_percent: WholeNumberInput
}

/** A customer's loyalty standing: their tier, what they spent, and the next tier. */
export interface LoyaltyStanding {
  /** Their tier; null when below the first tier, or when the shop has no tiers. */
  tier: {
    id: number
    name: string
    discount_percent: number
    cashback_percent: number
  } | null
  /** Toman spent: paid and unrefunded purchases, renewals and extras. */
  spent: string
  /** The next tier above their spending; null at the top, or when the shop has no tiers. */
  next: {
    name: string
    /** Toman. */
    min_spent: string
    /** Toman still to spend to reach it. */
    missing: string
  } | null
}

/** An order type a customer buys for themselves — what a discount and a cashback rule can apply to, and what loyalty and the nightly draw count: never a wallet top-up or an agent's traffic. */
export type OwnOrderType = 'purchase' | 'renewal' | 'extra_traffic' | 'extra_time' | 'extra_devices'

/** A cashback rule («کش‌بک»): this percent of what a delivered order paid goes back to the customer's wallet, for the listed order types, for members of its customer group (null: anyone) who paid with its payment method (null: any). Of the enabled rules matching an order, the highest percent applies; the customer's loyalty tier adds its own. */
export interface CashbackRuleRow {
  id: number
  percent: number
  order_types: OwnOrderType[]
  customer_group: {
    id: number
    name: string
  } | null
  payment_method: {
    id: number
    label: string
  } | null
  enabled: boolean
}

export interface CashbackRulesResponse {
  rules: CashbackRuleRow[]
}

export interface CashbackRuleResponse {
  rule: CashbackRuleRow
}

/** A cashback rule to add or update. Every refusal is returned at once, under its field. */
export interface CashbackRuleRequest {
  /** 1 to 100. */
  percent: WholeNumberInput
  /** At least one. */
  order_types: OwnOrderType[]
  /** A customer group of the shop; null or left out for anyone. */
  customer_group_id?: number | null
  /** The payment method the order must be paid with; null or left out for any. */
  payment_method_id?: number | null
  /** Left out: on for a new rule, unchanged for an existing one. */
  enabled?: boolean
}

export interface CashbackRuleSwitchRequest {
  enabled: boolean
}

/** What the nightly draw gives each winner: Toman in the wallet (`wallet`), GB of `traffic` or `days` on one of their running services, or a free service of a `plan`. */
export type LotteryPrizeKind = 'wallet' | 'traffic' | 'days' | 'plan'

/** One nightly draw among buyers («قرعه‌کشی شبانه»): its day, its entries, its prize (as it was then), and its winners in the order drawn. */
export interface LotteryDrawRow {
  id: number
  /** The day it was drawn for, in the shop's time zone. */
  day: string
  /** Its entries: the paid orders of the previous 24 hours, or the buyers (one entry each). */
  entries: number
  prize: {
    kind: LotteryPrizeKind
    /** Toman (wallet), GB (traffic) or days (days); null for a plan. */
    amount: string | null
    /** For a plan prize: the plan; null once it is deleted. */
    plan: RewardPlanRef | null
    /** What it gives, as the customer reads it. */
    words: string
  }
  winners: LotteryWinnerRow[]
  created_at: string
}

export interface LotteryWinnerRow {
  id: number
  customer: UserRef
  /** What they were given, as they read it. */
  outcome: string | null
  /** For a plan prize: the order that delivered its service. */
  order_id: number | null
  /** The service that got the traffic or days, or the plan's new service. */
  subscription_id: number | null
}

export interface LotteryDrawsResponse {
  draws: LotteryDrawRow[]
  meta: PageMeta
}

/** What a discount code takes off: a `percent` of the amount (capped at `max_discount` Toman when set), a `fixed` number of Toman (never more than the amount), or the whole amount (`free`). */
export type DiscountType = 'percent' | 'fixed' | 'free'

/** A code's or sale's current state: `active`, switched `off`, `scheduled` (not started), `ended`, or (codes only) `used_up`. */
export type DiscountState = 'active' | 'off' | 'scheduled' | 'ended' | 'used_up'

/** Where a discount came from: a timed `sale` («فروش ویژه»), the customer's `personal` percent («تخفیف اختصاصی») or their loyalty `tier` (only the largest of these three applies, never two together), or the `code` they typed, applied to the remaining amount. */
export type DiscountSource = 'sale' | 'personal' | 'tier' | 'code'

/** One discount applied to a price, labelled for the customer («فروش ویژه نوروز ۲۰٪», «تخفیف اختصاصی شما ۱۰٪», «سطح طلایی ۵٪», «کد NOWRUZ»). */
export interface DiscountLine {
  source: DiscountSource
  label: string
  /** Toman taken off. */
  amount: string
  /** The percent; null for a fixed or free code. */
  percent: number | null
}

/** The discount code used for an order. */
export interface OrderDiscountCode {
  /** Null once the code is deleted. */
  id: number | null
  code: string
}

/** What a purchase or a renewal buys, in words — the one wording the bot and the report group use too: its plan's name, or a service the customer built («سرویس دلخواه») as «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز». Extra traffic, time or devices: its service's plan's name (null for a service without a plan). Null for any other order (a wallet top-up, an agent's traffic). */
export type OrderPackageName = string | null

/** A discount code («کد تخفیف») a customer types at checkout, in the bot or on the website. Stored and matched in upper case. */
export interface DiscountCodeRow {
  id: number
  code: string
  /** The admin's private note about it. */
  note: string | null
  type: DiscountType
  /** For `percent`: the whole percent; for `fixed`: Toman; null for `free`. */
  value: string | null
  /** For `percent`: the most Toman it takes off; null for no cap. */
  max_discount: string | null
  /** The minimum order amount (after the automatic discount) for the code to apply; null for none. */
  min_amount: string | null
  /** The order types it applies to; null for every type a discount can apply to. */
  order_types: OwnOrderType[] | null
  /** The plans it applies to (for a renewal or extra, the service's plan); null for any. */
  plans: NamedRef[] | null
  /** The servers it applies to (for a purchase, the chosen server; for a renewal or extra, the service's); null for any. */
  servers: NamedRef[] | null
  /** Only for customers who have not bought a service yet. */
  first_purchase_only: boolean
  starts_at: string | null
  /** When it stops applying; it may end at any hour of the day. */
  ends_at: string | null
  /** Total uses allowed; null for no limit. */
  max_uses: number | null
  /** Uses allowed per customer; null for no limit. */
  per_user: number | null
  enabled: boolean
  /** Orders made with it that are not cancelled. An unpaid order frees its use when it is cancelled or expires (see the `hold_minutes` bot setting). */
  uses: number
  state: DiscountState
  created_at: string
}

export interface DiscountCodesResponse {
  codes: DiscountCodeRow[]
  meta: PageMeta
}

export interface DiscountCodeResponse {
  code: DiscountCodeRow
}

/** A discount code's whole form. Every refusal is returned at once, under its field. A field left out, null or blank means what its description says. */
export interface DiscountCodeRequest {
  /** 3 to 32 letters, digits, `-` and `_`, unique within the shop. Stored in upper case, with Persian digits converted. */
  code: string
  /** At most 191 characters. */
  note?: string | null
  type: DiscountType
  /** For `percent`: 1 to 100. For `fixed`: whole Toman above zero. Ignored for `free`. */
  value?: AmountInput | null
  /** For `percent`: the cap, whole Toman above zero; blank or null for none. Ignored for other types. */
  max_discount?: AmountInput | null
  /** Whole Toman; blank or null for none. */
  min_amount?: AmountInput | null
  /** At least one; null (or all of them) for every type. */
  order_types?: OwnOrderType[] | null
  /** Plan ids; null or empty for any. */
  plan_ids?: number[] | null
  /** Server ids; null or empty for any. */
  server_ids?: number[] | null
  first_purchase_only: boolean
  /** A local date and time in the shop's time zone (not the browser's), such as 2026-03-20T18:00. Blank or null: from now. */
  starts_at?: string | null
  /** The same format, after `starts_at`. Blank or null: no end. */
  ends_at?: string | null
  /** 1 or more; blank or null for no limit. */
  max_uses?: WholeNumberInput | null
  /** 1 or more; blank or null for no limit. */
  per_user?: WholeNumberInput | null
  enabled: boolean
}

export interface DiscountSwitchRequest {
  enabled: boolean
}

/** A timed sale («فروش ویژه»): a percent off the listed plans, for the listed order types, between its start and end. It is one of the automatic discounts; at checkout the largest applies. */
export interface SaleRow {
  id: number
  /** The name customers see, such as «فروش ویژه نوروز». */
  name: string
  percent: number
  /** Null for every type a discount can apply to. */
  order_types: OwnOrderType[] | null
  /** Null for every plan (for an extra, the service's plan counts). */
  plans: NamedRef[] | null
  starts_at: string | null
  ends_at: string | null
  enabled: boolean
  state: DiscountState
  created_at: string
}

export interface SalesResponse {
  sales: SaleRow[]
}

export interface SaleResponse {
  sale: SaleRow
}

/** A sale's whole form. Every refusal is returned at once, under its field. */
export interface SaleRequest {
  /** At most 64 characters. */
  name: string
  /** 1 to 100. */
  percent: WholeNumberInput
  /** At least one; null (or all of them) for every type. */
  order_types?: OwnOrderType[] | null
  /** Plan ids; null or empty for every plan. */
  plan_ids?: number[] | null
  /** A local date and time in the shop's time zone, such as 2026-03-20T00:00. Blank or null: from now. */
  starts_at?: string | null
  /** The same format, after `starts_at`. Blank or null: no end. */
  ends_at?: string | null
  enabled: boolean
}

export interface BotSettingsData {
  /** «کدهای تخفیف»: how many minutes an order made with a discount code may stay unpaid. After that, if untouched and no receipt is with support, it is cancelled and the code's use is freed. 0: as long as any unpaid order (two days). */
  hold_minutes: number
  enabled: boolean
  phone_required: boolean
  join_required: boolean
  /** The Telegram id or link the bot's «پشتیبانی» screen shows; may be empty. */
  support_contact: string
  /** The smallest top-up, in Toman. */
  topup_min: number
  /** The amounts offered as buttons, ascending. */
  topup_presets: number[]
  /** Whether a delivered service comes with a QR code of its link. */
  qr_enabled: boolean
  /** «تمدید سرویس»: whether a period's unused traffic is added to the renewed period (otherwise it expires when the period ends) — by the renewal method `extend` («تمدید پلن فعلی»). */
  carry_traffic: boolean
  /** «تمدید سرویس»: a customer may renew a running service only within this many days of its deadline (or with at most `renew_window_percent` of its traffic left, or once it ended); 0 — no such rule, and with `renew_window_percent` 0 too, any time. A service with no deadline yet (waiting for its first connection) or none at all is let in by its traffic alone. Automatic renewal goes by its own days. */
  renew_window_days: number
  /** A customer may renew a running service once at most this percent of its traffic is left (beside `renew_window_days`); 0 — however much is left. */
  renew_window_percent: number
  /** Renewal method «تمدید پلن فعلی» offered: the term on top of what is left, the traffic as `carry_traffic` says. */
  renew_extend: boolean
  /** Renewal method «ریست حجم + افزایش روز» offered: the term on top of what is left, the traffic counted afresh (what was left is lost). */
  renew_reset_traffic: boolean
  /** Renewal method «ریست زمان + انتقال حجم» offered: the term starts afresh (what was left of it is lost), the traffic left carries over. With more than one method on, the customer picks; with none on, `extend` is what renews. */
  renew_reset_time: boolean
  /** The method automatic renewal uses (the first one offered while this one is not). By `reset_time` it waits for the last half hour before the end, so the days left are not lost; a wallet short of it is still told `auto_renew_days` ahead. */
  renew_default_method: RenewalMethod
  /** «تمدید خودکار»: how many days before its end a service is renewed from the wallet. */
  auto_renew_days: number
  /** Whether a new service starts with auto-renewal on. */
  auto_renew_default: boolean
  /** «حجم، زمان و کاربر اضافه»: whether customers can buy extra traffic, time and devices («کاربر اضافه») for their services, at these prices or a plan's own (`PlanRow.extras`). When off, none is sold. */
  extras_enabled: boolean
  /** The shop's price per GB of extra traffic, in Toman; null when only the preset amounts with their own prices are sold (no custom amount). */
  extra_traffic_price: string | null
  /** The GB amounts offered as buttons, smallest first. */
  extra_traffic_options: ExtraOption[]
  /** The smallest custom GB amount (needs a price per GB). */
  extra_traffic_min: number
  /** The largest custom GB amount. */
  extra_traffic_max: number
  /** The shop's price per day of extra time, in Toman; null when only the preset periods with their own prices are sold (no custom amount). */
  extra_time_price: string | null
  /** The day counts offered as buttons, fewest first. */
  extra_time_options: ExtraOption[]
  /** The fewest custom days (needs a price per day). */
  extra_time_min: number
  /** The most custom days. */
  extra_time_max: number
  /** The shop's price per extra device (the service's IP limit) for 30 days, in Toman, charged for the days left of a service's period in use, which they last; null when only the preset counts with their own prices are sold (no custom count). */
  extra_devices_price: string | null
  /** The device counts offered as buttons, fewest first. */
  extra_devices_options: ExtraOption[]
  /** The fewest custom devices (needs a price per device). */
  extra_devices_min: number
  /** The most custom devices. */
  extra_devices_max: number
  /** «یادآوری»: remind the customer `expiry_reminder_days` days before a service ends. */
  expiry_reminder: boolean
  expiry_reminder_days: number
  /** Remind the customer once `traffic_reminder_percent` of a service's quota is used. */
  traffic_reminder: boolean
  traffic_reminder_percent: number
  /** «سرویس‌های منقضی‌شده»: an ended service — its time or traffic ran out, or its panel no longer has it — is removed from its panel and the shop by itself, `expired_cleanup_hours` after it ended (checked every 15 minutes), without a word to the customer; its orders stay. A service renewed meanwhile, switched off by support, or with an order still open (a renewal waiting for its receipt) is kept. Free test accounts go by their own rule (`test_cleanup`). Off by default. */
  expired_cleanup: boolean
  /** How many hours an ended service is kept before it is removed; 0 at once. */
  expired_cleanup_hours: number
  /** «اکانت تست رایگان»: customers can take a free test account with the bot's «اکانت تست» button, on a server that gives tests. What a test gets is each server's (`PUT /api/admin/servers/{id}/test-account`); in an agent's shop its traffic comes from the agent's bot. */
  test_enabled: boolean
  /** How many tests a customer may take: for good, or within `test_period_days`. Bot admins have no quota. */
  test_per_user: number
  /** The window `test_per_user` counts over, in days; 0 for good. */
  test_period_days: number
  /** The most tests the shop gives in one day of its time zone; 0 for no cap. */
  test_daily_limit: number
  /** Only customers who never bought a service with a price can take one. */
  test_newcomers_only: boolean
  /** Tests that ended are removed from their panels by themselves (checked every 15 minutes). A removed test still counts in the quota. */
  test_cleanup: boolean
  /** How many hours an ended test is kept before it is removed; 0 at once. */
  test_cleanup_hours: number
  /** While `test_cleanup` is on, after how many days a test that never connected is removed (its hours wait for the first connection); 0 never. */
  test_unused_days: number
  /** «افزودن سرویس با لینک», main shop only, always present there (an agent's shop has no such switch, nor the `service_claims` group): customers can add a client made by hand on a panel to their «سرویس‌های من» by sending the bot (or the website) its subscription link or one of its configs. The first to send it gets it, and each claim is reported in the report group's «خریدها» topic; a wrong one is fixed by deleting the service from the shop only (`leave_panel`), so the right customer can send it again. A claimed service (`SubscriptionRow.claimed_at`) has no plan until its first renewal, when the customer picks one sold on its server. Off by default. */
  claim_enabled?: boolean
  /** «انتقال سرویس»: customers may hand one of their services to another customer of the shop by their Telegram id (support transfers from the panels whatever this says). Off by default. */
  transfer_enabled: boolean
  /** «نام کاربری سرویس»: the pattern a sold service's client is named by on its panel, of the variables `BotSettingsResponse.name_variables` lists and the characters a panel takes (letters, digits, `_ - . @`); `{username}_{seq}` by default. */
  name_pattern: string
  /** The pattern a free test's client is named by; `test_{username}_{seq}` by default. */
  test_name_pattern: string
  /** A buyer may take a random name for their new service instead of the pattern. */
  name_random: boolean
  /** A buyer may type the name of their new service (letters, digits, `_` and `-`, 3 to 32) instead of the pattern. */
  name_custom: boolean
  /** «سرویس دلخواه», main shop only, always present there: customers build a service of the traffic and days they choose, each priced by its tiers, on `custom_servers`. Off by default. */
  custom_enabled?: boolean
  /** The price of a GB by tiers, the smallest `from` first: a built service's GB are priced whole at the tier they fall into. */
  custom_traffic_tiers?: PriceTier[]
  /** The least GB a customer may build. */
  custom_traffic_min?: number
  /** The most GB a customer may build. */
  custom_traffic_max?: number
  /** GB amounts offered as buttons, smallest first. */
  custom_traffic_options?: number[]
  /** The price of a day by tiers, the smallest `from` first. */
  custom_days_tiers?: PriceTier[]
  /** The least days a customer may build. */
  custom_days_min?: number
  /** The most days a customer may build. */
  custom_days_max?: number
  /** Day counts offered as buttons, smallest first. */
  custom_days_options?: number[]
  /** Devices at once on a built service; 0 — unlimited. */
  custom_devices?: number
  /** The servers a customer may build on, by id. */
  custom_servers?: number[]
  /** «زیرمجموعه‌گیری»: the referral program. Referral links bring new customers, and their payments earn the referrer `referral_rate` percent. */
  referral_enabled: boolean
  referral_rate: number
  /** The percent a referred customer's renewal earns instead (0: renewals earn nothing). */
  referral_renewal_rate: number
  /** Only the first payment of each referred customer earns a commission. */
  referral_first_only: boolean
  /** «هدیه شروع»: Toman added to the referrer's wallet for each new customer their link brings (0: none). */
  referral_start_referrer: string
  /** Toman added to the new customer's own wallet (0: none). */
  referral_start_invitee: string
  referral_start_when: ReferralStartTrigger
  /** «جایزه دعوت»: each time this many more of a referrer's referrals have bought, the referrer gets a free service of `referral_reward_plan_id` (0: never). */
  referral_reward_every: number
  /** The reward's plan (`PlanRow.id`); null for none. Required while the reward is on. */
  referral_reward_plan_id: number | null
  /** «هشدار زیرمجموعه‌گیری فیک»: raise an alert when this many customers join through one link within `referral_alert_minutes` (0: never). */
  referral_alert_burst: number
  /** The window for `referral_alert_burst`, in minutes. Each kind of alert is raised at most once per window. */
  referral_alert_minutes: number
  /** Raise an alert when a referrer has this many referrals and none of them bought, and again at each further multiple (0: never). While an alert stands, the referrer earns no start gift and no plan reward. */
  referral_alert_idle: number
  /** «گروه گزارش‌ها»: the `report_…` switches choose which report group topics get reports. All are on until switched off. */
  report_purchases: boolean
  report_renewals: boolean
  report_wallet: boolean
  report_receipts: boolean
  report_users: boolean
  report_errors: boolean
  /** Main shop only: agency requests and traffic purchases are reported to the main shop's group, so an agent's bot has no such switch. */
  report_agency?: boolean
  /** Support tickets and their messages (in every shop). */
  report_tickets: boolean
  /** Reviews written on the website (in every shop). */
  report_reviews: boolean
  /** «کد هدیه»: customers can redeem gift codes, with the bot's «🎁 کد هدیه» button or on the website. Codes can be created either way. */
  gift_codes_enabled: boolean
  /** «گردونه شانس»: customers can spin the lucky wheel with the bot's «🎡 گردونه شانس» button. */
  wheel_enabled: boolean
  /** Hours a customer waits between two spins that gave a prize. */
  wheel_cooldown_hours: number
  /** Only customers who have bought a service can spin (bot admins always can). */
  wheel_buyers_only: boolean
  /** The cost of a spin from the wallet, in whole Toman; 0 for free. */
  wheel_cost: number
  /** «کش‌بک»: whether delivered orders earn cashback, from the cashback rules (`GET /cashback/rules`) and the customer's loyalty tier. When off, none is earned. */
  cashback_enabled: boolean
  /** «قرعه‌کشی شبانه»: whether a draw among buyers runs every night. */
  lottery_enabled: boolean
  /** The hour (0 to 23, in the shop's time zone) from which the night's draw runs. */
  lottery_hour: number
  /** Winners per draw, 1 to 20. */
  lottery_winners: number
  lottery_prize: LotteryPrizeKind
  /** The prize in Toman, for a wallet prize. */
  lottery_wallet: string
  /** The prize in GB, for a traffic prize. */
  lottery_traffic_gb: number
  /** The prize in days, for a days prize. */
  lottery_days: number
  /** For a plan prize: the plan whose service it gives; null while none is chosen. */
  lottery_plan_id: number | null
  /** One entry per paid order, or one per buyer. */
  lottery_entries: 'order' | 'buyer'
  /** Leave agents out of the draw (bot admins and banned customers are always left out). */
  lottery_exclude_agents: boolean
}

/** The background picture QR cards are drawn on. */
export interface QrBackgroundInfo {
  /** False for the default picture. */
  custom: boolean
  mime: string
  width: number
  height: number
  size: number
  /** The file's modification time (unix seconds), used to bust the preview's cache. */
  updated_at: number
  /** The largest upload accepted. */
  max_bytes: number
}

export type BotSettingsGroup =
  | 'general'
  | 'channels'
  | 'wallet'
  | 'renewal'
  | 'auto_renew'
  | 'extras'
  | 'reminders'
  | 'expired_services'
  | 'test_accounts'
  | 'service_claims'
  | 'service_transfers'
  | 'client_names'
  | 'custom_services'
  | 'referral'
  | 'reports'
  | 'qr'
  | 'gift_codes'
  | 'wheel'
  | 'cashback'
  | 'lottery'
  | 'discounts'

export interface BotSettingsResponse {
  settings: BotSettingsData
  qr_background: QrBackgroundInfo
  /** «نام کاربری سرویس»: the variables `name_pattern` and `test_name_pattern` may hold, in the order a hint lists them. A pattern must hold `n` or `random`, or `seq` beside `username`, `tg_id` or `user_id`, so no two services are named alike. */
  name_variables: {
    /** Written in braces in a pattern: `{username}`. */
    name: string
    /** What it stands for, in Persian. */
    description: string
    /** Its value for a sample customer (@amir's third service, the shop's 128th; `date` today on the shop's clock), for the name a pattern would give as it is typed. */
    example: string
  }[]
}

export interface BotSettingsSaved {
  settings: BotSettingsData
}

export interface QrBackgroundResponse {
  qr_background: QrBackgroundInfo
}

/** A custom QR card background, as a browser form uploads it: a PNG, JPEG or WebP (checked by its content), up to `QrBackgroundInfo.max_bytes`. */
export interface QrBackgroundRequest {
  file: Blob
}

/** One group of the bot's settings, saved whole (`PUT /bot/settings/{group}`), with the fields named as in BotSettingsData. Every switch and number must be sent (a missing one is refused); a text or list left out counts as blank. */
export type BotSettingsRequest =
  | BotGeneralSettingsRequest
  | BotChannelsSettingsRequest
  | BotWalletSettingsRequest
  | BotRenewalSettingsRequest
  | BotAutoRenewSettingsRequest
  | BotExtrasSettingsRequest
  | BotRemindersSettingsRequest
  | BotExpiredServicesSettingsRequest
  | BotTestAccountsSettingsRequest
  | BotServiceClaimsSettingsRequest
  | BotServiceTransfersSettingsRequest
  | BotClientNamesSettingsRequest
  | BotCustomServicesSettingsRequest
  | BotReferralSettingsRequest
  | BotReportsSettingsRequest
  | BotQrSettingsRequest
  | BotGiftCodesSettingsRequest
  | BotWheelSettingsRequest
  | BotCashbackSettingsRequest
  | BotLotterySettingsRequest
  | BotDiscountsSettingsRequest

export interface BotDiscountsSettingsRequest {
  /** 0 to 1440 minutes. */
  hold_minutes: WholeNumberInput
}

export interface BotCashbackSettingsRequest {
  cashback_enabled: boolean
}

/** «قرعه‌کشی شبانه»: only the amount field of the chosen prize kind is read; the other prize fields keep their values. */
export interface BotLotterySettingsRequest {
  lottery_enabled: boolean
  /** 0 to 23. */
  lottery_hour: WholeNumberInput
  /** 1 to 20. */
  lottery_winners: WholeNumberInput
  lottery_prize: LotteryPrizeKind
  /** Whole Toman, 1,000 to 100,000,000. Read for a wallet prize. */
  lottery_wallet?: AmountInput
  /** 1 to 10000 GB. Read for a traffic prize. */
  lottery_traffic_gb?: WholeNumberInput
  /** 1 to 365 days. Read for a days prize. */
  lottery_days?: WholeNumberInput
  /** A plan id. Read for a plan prize. */
  lottery_plan_id?: WholeNumberInput | null
  lottery_entries: 'order' | 'buyer'
  lottery_exclude_agents: boolean
}

export interface BotGiftCodesSettingsRequest {
  gift_codes_enabled: boolean
}

export interface BotWheelSettingsRequest {
  wheel_enabled: boolean
  /** 1 to 720. */
  wheel_cooldown_hours: WholeNumberInput
  wheel_buyers_only: boolean
  /** Whole Toman, 0 to 10,000,000. Thousands separators are accepted only between groups of three digits ("10,000"). */
  wheel_cost: WholeNumberInput
}

/** «اکانت تست رایگان»: the switch, the quota and the clean-up. Every refusal is returned at once, under its field. */
export interface BotTestAccountsSettingsRequest {
  test_enabled: boolean
  /** 1 to 100. */
  test_per_user: WholeNumberInput
  /** 0 to 365; 0 for good. */
  test_period_days: WholeNumberInput
  /** 0 to 100,000; 0 for no cap. */
  test_daily_limit: WholeNumberInput
  test_newcomers_only: boolean
  test_cleanup: boolean
  /** 0 to 720. */
  test_cleanup_hours: WholeNumberInput
  /** 0 to 365; 0 never. */
  test_unused_days: WholeNumberInput
}

/** «افزودن سرویس با لینک»: the switch (`BotSettingsData.claim_enabled`); a 404 in an agent's shop, which has no such group. */
export interface BotServiceClaimsSettingsRequest {
  claim_enabled: boolean
}

/** «انتقال سرویس»: the customers' switch (`BotSettingsData.transfer_enabled`). */
export interface BotServiceTransfersSettingsRequest {
  transfer_enabled: boolean
}

/** «نام کاربری سرویس»: the patterns and the buyers' choices. A pattern left blank is its default; one with an unknown variable, another character, or neither `{n}` nor `{random}` nor `{seq}` beside `{username}`, `{tg_id}` or `{user_id}` (`{seq}` alone names every customer's first service alike) is refused under its field. */
export interface BotClientNamesSettingsRequest {
  /** At most 48 characters. */
  name_pattern?: string
  /** At most 48 characters. */
  test_name_pattern?: string
  name_random: boolean
  name_custom: boolean
}

/** «سرویس دلخواه»: the switch, the tiers, the bounds, the buttons, the devices and the servers; a 404 in an agent's shop, which has no such group. Every refusal is returned at once, under its field. */
export interface BotCustomServicesSettingsRequest {
  custom_enabled: boolean
  /** `from` 1 to 10000 GB, each once, at most 10; left out for none. */
  custom_traffic_tiers?: PriceTierInput[]
  /** 1 to 10000 GB. */
  custom_traffic_min: WholeNumberInput
  /** `custom_traffic_min` to 10000 GB. */
  custom_traffic_max: WholeNumberInput
  /** 1 to 10000 GB, at most 8. */
  custom_traffic_options?: NumbersInput
  /** `from` 1 to 365 days, each once, at most 10; left out for none. */
  custom_days_tiers?: PriceTierInput[]
  /** 1 to 365 days. */
  custom_days_min: WholeNumberInput
  /** `custom_days_min` to 365 days. */
  custom_days_max: WholeNumberInput
  /** 1 to 365 days, at most 8. */
  custom_days_options?: NumbersInput
  /** 0 to 100; 0 — unlimited. */
  custom_devices: WholeNumberInput
  /** Server ids, at most 50. */
  custom_servers?: NumbersInput
}

/** A tier of a price: from `from` units on, `price` Toman a unit. */
export interface PriceTier {
  from: number
  price: string
}

export interface PriceTierInput {
  from?: WholeNumberInput
  price?: AmountInput
}

/** How a renewal adds its package: `extend` «تمدید پلن فعلی» (the term on top of what is left; the traffic as the shop carries unused traffic or not), `reset_traffic` «ریست حجم + افزایش روز» (the term on top; the traffic counted afresh), `reset_time` «ریست زمان + انتقال حجم» (the term afresh; the traffic left carried over). */
export type RenewalMethod = 'extend' | 'reset_traffic' | 'reset_time'

/** How a buyer has their new service named: `sequential` by the shop's pattern, `random`, or `custom` (the name they type) — the latter two while the shop offers them. */
export type ClientNameMode = 'sequential' | 'random' | 'custom'

export interface BotGeneralSettingsRequest {
  enabled: boolean
  phone_required: boolean
  /** At most 190 characters. */
  support_contact?: string
}

export interface BotChannelsSettingsRequest {
  join_required: boolean
}

export interface BotWalletSettingsRequest {
  /** Toman. Thousands separators are accepted only between groups of three digits ("10,000"). */
  topup_min: WholeNumberInput
  /** Toman, none below `topup_min`, at most 8. */
  topup_presets?: NumbersInput
}

export interface BotRenewalSettingsRequest {
  carry_traffic: boolean
  /** 0 to 365; 0 — any time. */
  renew_window_days: WholeNumberInput
  /** 0 to 100; 0 — off. */
  renew_window_percent: WholeNumberInput
  /** At least one method must be on: a save with none is a 422 on `renew_extend`. */
  renew_extend: boolean
  renew_reset_traffic: boolean
  renew_reset_time: boolean
  /** One of the methods on (a 422 otherwise). */
  renew_default_method: RenewalMethod
}

export interface BotAutoRenewSettingsRequest {
  /** 1 to 30. */
  auto_renew_days: WholeNumberInput
  auto_renew_default: boolean
}

/** «حجم، زمان و کاربر اضافه»: the switch and the shop's price list (ExtraPricesRequest's fields, each prefixed `extra_`). */
export interface BotExtrasSettingsRequest {
  extras_enabled: boolean
  /** Whole Toman per GB, above zero. Blank, null or left out for none: only the preset amounts with their own prices are sold. */
  extra_traffic_price?: AmountInput | null
  /** GB amounts, 1 to 10000, each once, at most 8. Left out for none. */
  extra_traffic_options?: ExtraOptionInput[]
  /** 1 to 10000 GB. */
  extra_traffic_min: WholeNumberInput
  /** `extra_traffic_min` to 10000 GB. */
  extra_traffic_max: WholeNumberInput
  /** Whole Toman per day, above zero. Blank, null or left out for none: only the preset periods with their own prices are sold. */
  extra_time_price?: AmountInput | null
  /** Day counts, 1 to 365, each once, at most 8. Left out for none. */
  extra_time_options?: ExtraOptionInput[]
  /** 1 to 365 days. */
  extra_time_min: WholeNumberInput
  /** `extra_time_min` to 365 days. */
  extra_time_max: WholeNumberInput
  /** Whole Toman per device for 30 days, above zero. Blank, null or left out for none: only the preset counts with their own prices are sold. */
  extra_devices_price?: AmountInput | null
  /** Device counts, 1 to 20, each once, at most 8. Left out for none. */
  extra_devices_options?: ExtraOptionInput[]
  /** 1 to 20 devices. */
  extra_devices_min: WholeNumberInput
  /** `extra_devices_min` to 20 devices. */
  extra_devices_max: WholeNumberInput
}

/** An amount of extra traffic (GB), time (days) or devices offered as a button, at its own price or at the price per GB, day or device (a device's prices are for 30 days, prorated when sold). */
export interface ExtraOption {
  quantity: number
  /** Toman; null to charge the price per GB, day or device times the quantity. */
  price: string | null
}

/** An amount offered as a button, as a form sends it. A row with both quantity and price blank is ignored. */
export interface ExtraOptionInput {
  /** GB, days or devices. */
  quantity: WholeNumberInput | null
  /** Whole Toman, above zero. Blank or null to use the price per GB, day or device (which must then be set). */
  price: AmountInput | null
}

/** A plan's own price list for extra traffic, time and devices (the shop's list is BotSettingsData's `extra_…` fields). For each kind: the unit price, the preset amounts, and the smallest and largest custom amount. A device's prices are for 30 days, charged for the days a service has left. */
export interface ExtraPrices {
  /** Toman per GB; null when only the preset amounts with their own prices are sold (no custom amount). */
  traffic_price: string | null
  /** Smallest first. */
  traffic_options: ExtraOption[]
  traffic_min: number
  traffic_max: number
  /** Toman per day; null when only the preset periods with their own prices are sold (no custom amount). */
  time_price: string | null
  /** Fewest days first. */
  time_options: ExtraOption[]
  time_min: number
  time_max: number
  /** Toman per device for 30 days; null when only the preset counts with their own prices are sold (no custom count). */
  devices_price: string | null
  /** Fewest devices first. */
  devices_options: ExtraOption[]
  devices_min: number
  devices_max: number
}

/** A plan's own price list for extras, as a form sends it. Refusals are reported under `extras.<field>`. */
export interface ExtraPricesRequest {
  /** Whole Toman per GB, above zero. Blank, null or left out for none. */
  traffic_price?: AmountInput | null
  /** GB amounts, 1 to 10000, each once, at most 8. Left out for none. */
  traffic_options?: ExtraOptionInput[]
  /** 1 to 10000 GB. */
  traffic_min: WholeNumberInput
  /** `traffic_min` to 10000 GB. */
  traffic_max: WholeNumberInput
  /** Whole Toman per day, above zero. Blank, null or left out for none. */
  time_price?: AmountInput | null
  /** Day counts, 1 to 365, each once, at most 8. Left out for none. */
  time_options?: ExtraOptionInput[]
  /** 1 to 365 days. */
  time_min: WholeNumberInput
  /** `time_min` to 365 days. */
  time_max: WholeNumberInput
  /** Whole Toman per device for 30 days, above zero. Blank, null or left out for none. */
  devices_price?: AmountInput | null
  /** Device counts, 1 to 20, each once, at most 8. Left out for none. */
  devices_options?: ExtraOptionInput[]
  /** 1 to 20 devices; left out, 1 (a list sent as before 0.4.0 sold devices). */
  devices_min?: WholeNumberInput
  /** `devices_min` to 20 devices; left out, 5. */
  devices_max?: WholeNumberInput
}

export interface BotRemindersSettingsRequest {
  expiry_reminder: boolean
  /** 1 to 30. */
  expiry_reminder_days: WholeNumberInput
  traffic_reminder: boolean
  /** 50 to 99. */
  traffic_reminder_percent: WholeNumberInput
}

/** «سرویس‌های منقضی‌شده»: the automatic removal of ended services, and how long after its end a service goes. */
export interface BotExpiredServicesSettingsRequest {
  expired_cleanup: boolean
  /** 0 to 8760 (a year); 0 removes at once. */
  expired_cleanup_hours: WholeNumberInput
}

export interface BotReferralSettingsRequest {
  referral_enabled: boolean
  /** Percent, 1 to 100. */
  referral_rate: WholeNumberInput
  /** Percent, 0 to 100: what a renewal earns instead of `referral_rate`. */
  referral_renewal_rate: WholeNumberInput
  referral_first_only: boolean
  /** Whole Toman, 0 to 10,000,000. */
  referral_start_referrer: AmountInput
  /** Whole Toman, 0 to 10,000,000. */
  referral_start_invitee: AmountInput
  referral_start_when: ReferralStartTrigger
  /** 0 to 1000; 0 for no reward. */
  referral_reward_every: WholeNumberInput
  /** A plan id; null, blank or 0 for none, which is refused while `referral_reward_every` is above 0. */
  referral_reward_plan_id: WholeNumberInput | null
  /** 0 to 10000; 0 turns this alert off. */
  referral_alert_burst: WholeNumberInput
  /** 1 to 10080 minutes (a week). */
  referral_alert_minutes: WholeNumberInput
  /** 0 to 100000; 0 turns this alert off. */
  referral_alert_idle: WholeNumberInput
}

/** When the referral start gift («هدیه شروع») is paid: when the new customer `join`s through the link, or when their first `purchase` is paid (by any method, but never a free service). */
export type ReferralStartTrigger = 'join' | 'purchase'

export interface BotReportsSettingsRequest {
  report_purchases: boolean
  report_renewals: boolean
  report_wallet: boolean
  report_receipts: boolean
  report_users: boolean
  report_errors: boolean
  /** Main shop only, where it is required; an agent's bot has no such switch. */
  report_agency?: boolean
  report_tickets: boolean
  report_reviews: boolean
}

export interface BotQrSettingsRequest {
  qr_enabled: boolean
}

/** A channel or group customers must join before using the bot. */
export interface BotChannelRow {
  id: number
  chat_id: number
  type: 'channel' | 'supergroup'
  title: string
  /** Public handle without "@"; null for a private chat. */
  username: string | null
  /** The link customers follow to join. */
  link: string
  /** Whether the bot was an admin at the last check; it needs admin rights to see the members. */
  bot_is_admin: boolean
  checked_at: string | null
  sort: number
}

export interface BotChannelsResponse {
  channels: BotChannelRow[]
}

export interface BotChannelResponse {
  channel: BotChannelRow
}

/** A channel or group customers must join, as the admin pasted it: t.me/name, @name, or a private chat's numeric id (-100…). The bot must already be an admin there. */
export interface BotChannelRequest {
  link: string
}

export type ReportTopicKey = 'purchases' | 'renewals' | 'wallet' | 'receipts' | 'users' | 'errors' | 'agency' | 'tickets' | 'reviews'

/** A topic of the report group, in the group's order. */
export interface ReportTopicInfo {
  key: ReportTopicKey
  /** Its name in the group, and the label of its switch. */
  title: string
  /** What it reports. */
  about: string
  /** Whether the bot has created it in the connected group. */
  ready: boolean
  /** Whether its reports are on (the bot setting `report_<key>`). */
  enabled: boolean
}

/** The report group, where the bot reports to the shop's admins. */
export interface ReportGroupData {
  connected: boolean
  /** A Telegram chat id (-100…), shown as is. */
  chat_id: number | null
  title: string | null
  connected_at: string | null
  /** What keeps reports from reaching the group; null when nothing does. */
  problem: string | null
  /** Sending is paused until then (a flood limit, Telegram unreachable, or a problem with the group). */
  paused_until: string | null
  /** Reports queued but not sent yet. */
  waiting: number
  topics: ReportTopicInfo[]
  /** The pending connect link: Telegram's add-to-group link, the equivalent command to type in the group, and when both expire. */
  link: {
    url: string
    command: string
    expires_at: string
  } | null
  /** The last group that tried that link and was refused, and why. */
  attempt: {
    title: string
    message: string
    at: string
  } | null
  /** The bot's username without "@"; null until the bot has run once. */
  bot_username: string | null
}

export interface ReportGroupResponse {
  group: ReportGroupData
}

/** How many test messages were queued, and how many were sent right away. */
export interface ReportGroupTestResponse {
  group: ReportGroupData
  queued: number
  sent: number
}

/** The referral program's settings and numbers. */
export interface ReferralSummary {
  enabled: boolean
  rate: number
  /** The rate for renewals, used instead of `rate`; 0: renewals earn nothing. */
  renewal_rate: number
  first_only: boolean
  /** Customers whose link brought someone. */
  referrers: number
  /** Customers who joined through a link. */
  referred: number
  commissions: number
  /** The total paid out, in Toman. */
  paid: string
  /** Open alerts on referrers (`GET /referrals/alerts`). Their start gifts and plan rewards are withheld until support clears each alert. */
  alerts: number
}

export interface ReferralSummaryResponse {
  summary: ReferralSummary
}

/** A customer whose link brought someone. */
export interface ReferrerRow {
  /** The customer's id. */
  id: number
  user: UserRef
  status: UserStatus
  referrals: number
  /** The total of the commissions credited to them. */
  earned: string
  last_referral_at: string | null
  /** Open alerts on them (0: none). Their start gifts and plan rewards are withheld meanwhile. */
  alerts: number
}

/** A customer who joined through someone's link. */
export interface InviteeRow {
  /** The customer's id. */
  id: number
  user: UserRef
  status: UserStatus
  referrer: UserRef | null
  /** What this customer's payments earned their current referrer (the commissions credited to that referrer). */
  earned: string
  joined_at: string
}

/** A referral commission paid out. */
export interface ReferralCommissionRow {
  id: number
  /** Who it was credited to, as recorded when it was earned. */
  referrer: UserRef
  customer: UserRef
  payment: {
    id: number
    amount: string
  }
  order: {
    id: number
    type: OrderType
  }
  /** The percent in force at the time. */
  rate: number
  commission: string
  created_at: string
}

/** The referrers list's sort keys: number of referrals (the default), amount earned, or time of the latest referral. */
export type ReferrerSort = 'referrals' | 'earned' | 'last_referral'

export interface ReferrersResponse {
  referrers: ReferrerRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: ReferrerSort
    dir: SortDirection
  }
}

/** The invitees list's sort keys: sign-up time (the default), or what their payments earned their referrer. */
export type InviteeSort = 'joined' | 'earned'

export interface InviteesResponse {
  invitees: InviteeRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: InviteeSort
    dir: SortDirection
  }
}

/** The commissions list's sort keys: creation time (the default) or amount. */
export type ReferralCommissionSort = 'created' | 'commission'

export interface ReferralCommissionsResponse {
  commissions: ReferralCommissionRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: ReferralCommissionSort
    dir: SortDirection
  }
}

/** Why a referrer's referrals look fake. `burst`: the set number of them joined through the link within `referral_alert_minutes`. `idle`: the set number of them (or a further multiple) joined and none ever bought. */
export type ReferralAlertReason = 'burst' | 'idle'

/** An alert on a referrer whose referrals look fake («هشدار زیرمجموعه‌گیری فیک»). While it is open, they earn no start gifts or plan rewards (commissions continue). Support clears it; what was withheld meanwhile is not paid later. */
export interface ReferralAlertRow {
  id: number
  referrer: UserRef
  /** The referrer's status. */
  status: UserStatus
  reason: ReferralAlertReason
  /** The number of referrals when it was raised: those in the window, or those who never bought. */
  count: number
  created_at: string
}

export interface ReferralAlertsResponse {
  alerts: ReferralAlertRow[]
  meta: PageMeta
}

/** The agency program's settings (stored with the main bot's settings), set on the owner's agents page. */
export interface AgencySettings {
  /** Requests are accepted and customers see the menu button. Existing agents keep their accounts and bots either way. */
  enabled: boolean
  /** The credit a newly approved agent gets, in Toman. */
  default_credit: string
  /** The GB amounts an agent can buy with one tap, smallest first. */
  traffic_presets: number[]
  /** The smallest GB amount an agent can type. */
  traffic_min: number
}

export interface AgencySettingsResponse {
  settings: AgencySettings
}

/** The agency settings, saved whole, with the fields named as in AgencySettings. The switch and the numbers must be sent (a missing one is refused); presets left out means none. */
export interface AgencySettingsRequest {
  enabled: boolean
  /** Whole Toman. */
  default_credit: AmountInput
  /** Gigabytes, none below `traffic_min`, at most 8. */
  traffic_presets?: NumbersInput
  /** Gigabytes. */
  traffic_min: WholeNumberInput
}

/** The agency program's numbers, shown above the lists (its settings are AgencySettings). */
export interface AgencySummary {
  levels: number
  agents: number
  /** Agents' bots that are running (connected and switched on). */
  bots: number
  /** Requests waiting for a decision. */
  pending: number
}

export interface AgencySummaryResponse {
  summary: AgencySummary
}

/** An agency level, as other lists reference it. */
export interface AgencyLevelRef {
  id: number
  name: string
  /** What agents on this level pay per GB of traffic for their bots, in Toman. */
  price_per_gb: string
}

/** An agency level. */
export interface AgencyLevelRow {
  id: number
  name: string
  /** What agents on this level pay per GB of traffic for their bots, in Toman. */
  price_per_gb: string
  sort: number
  counts: {
    agents: number
  }
  created_at: string
  updated_at: string
}

export interface AgencyLevelsResponse {
  levels: AgencyLevelRow[]
}

export interface AgencyLevelResponse {
  level: AgencyLevelRow
}

/** An agency level to add or update. */
export interface AgencyLevelRequest {
  /** Must be unique. */
  name: string
  /** Whole Toman that agents on this level pay per GB of their bot's traffic. */
  price_per_gb: AmountInput
}

export type AgencyRequestStatus = 'pending' | 'approved' | 'rejected'

/** A customer's request to become an agent. */
export interface AgencyRequestRow {
  id: number
  user: UserRef
  user_status: UserStatus
  /** Whether the customer is an agent now. */
  agent: boolean
  status: AgencyRequestStatus
  /** What the customer wrote. */
  note: string | null
  /** The level given on approval. */
  level: AgencyLevelRef | null
  /** Support's note on a rejection. */
  reason: string | null
  /** Who decided it: the panel login, or a bot admin (@username or tg:<id>). */
  reviewer: string | null
  decided_at: string | null
  created_at: string
  actions: {
    approve: boolean
    reject: boolean
  }
}

/** The agency requests list's sort key: creation time. */
export type AgencyRequestSort = 'created'

export interface AgencyRequestsResponse {
  requests: AgencyRequestRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: AgencyRequestSort
    dir: SortDirection
  }
}

export interface AgencyRequestResponse {
  request: AgencyRequestRow
}

/** An agent's terms, when approving a request or changing an agent: their level, and how far below zero their wallet may go. */
export interface AgencyTermsRequest {
  /** A level id (`AgencyLevelRow.id`). */
  level_id: WholeNumberInput
  /** Whole Toman; 0 for none. */
  credit_limit: AmountInput
}

/** An agent (a main-bot customer on an agency level), with their bot. */
export interface AgentRow {
  /** The customer's id. */
  id: number
  user: UserRef
  status: UserStatus
  level: AgencyLevelRef
  /** How far below zero their wallet may go, in Toman. */
  credit_limit: string
  /** Their wallet balance in Toman; below zero while they use their credit. */
  balance: string
  /** Their bot (its shop is created when they are approved). */
  bot: AgentBot | null
  counts: {
    /** Their bot's customers. */
    customers: number
    /** Services their bot sold (delivered purchases). */
    sold: number
    /** Their bot's active services. */
    active: number
  }
}

/** An agent's bot: its identity, whether it runs and why not, and the traffic it can still sell. */
export interface AgentBot {
  id: number
  /** Its username without the @; null until the agent connects its token. */
  username: string | null
  /** The name Telegram shows for it. */
  title: string | null
  /** `disabled`: the agency ended. */
  status: 'active' | 'disabled'
  /** Whether it has a usable token: connected, and still decryptable. */
  connected: boolean
  /** What keeps it from running (Telegram refused its token…). */
  problem: string | null
  /** The traffic it can still sell, in bytes. */
  traffic_balance: number
  /** When its token was first connected (a later new token keeps this time); null before. */
  connected_at: string | null
}

/** An agent's bot whose traffic is used up or cannot cover even its smallest plan on sale. The bot offers no plan and renews no service until the agent buys traffic in the main bot. */
export interface TrafficShortage {
  /** The traffic it can still sell, in bytes. */
  balance: number
  /** The traffic its smallest plan on sale needs, in bytes; null while no plan is on sale. */
  smallest_plan: number | null
}

export interface AccountResponse {
  /** Set when their traffic is too low to sell anything; null otherwise. */
  traffic_shortage: TrafficShortage | null
  account: {
    bot: AgentBot
    agent: UserRef
    /** Their level; null once the agency ended. */
    level: AgencyLevelRef | null
    /** How far below zero their main-bot wallet may go, in Toman. */
    credit_limit: string
    /** Their wallet balance in the main bot, in Toman. */
    balance: string
  }
}

/** One line of an agent's traffic ledger. */
export interface TrafficLine {
  id: number
  /** `purchase`: bought. `sale`, `renewal`, `extension`: used by a sale or renewal of their bot, or by traffic the shop added to one of its services. `refund`: returned after a failed delivery or extension. `adjust`: corrected by the shop. */
  type: 'purchase' | 'sale' | 'renewal' | 'extension' | 'refund' | 'adjust'
  /** Signed: added (+) or used (-). */
  bytes: number
  balance_after: number
  description: string | null
  order_id: number | null
  /** Who made the change, for an adjustment or extension: the agent's bot, the owner's login (which anyone but the owner sees as «پشتیبانی»), or a website admin. */
  reviewer: string | null
  created_at: string | null
}

export interface TrafficLinesResponse {
  lines: TrafficLine[]
  meta: PageMeta
}

/** The agents list's sort keys: sign-up time (the default), wallet balance, the bot's traffic, or its sales (none counts as 0). */
export type AgentSort = 'joined' | 'balance' | 'traffic' | 'sold'

export interface AgentsResponse {
  agents: AgentRow[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    sort: AgentSort
    dir: SortDirection
  }
}

export interface AgentResponse {
  agent: AgentRow
}

/** A manual adjustment of an agent's traffic (never below zero), with the note for their ledger. */
export interface TrafficAdjustmentRequest {
  /** Gigabytes to add; a negative number (or text after a minus sign) takes them away. */
  gb: AmountInput
  /** The ledger line's note, at most 190 characters; blank for none. */
  note?: string
}

export interface AgentChangedResponse {
  agent: AgentRow
  /** Whether the agent was notified of their new terms; null when the terms did not change (no notice was sent). */
  delivery: Delivery | null
}

/** The website's settings, as the panel shows them. Secrets are never included, only whether one is stored. */
export interface Website {
  /** When off, the Store API answers 404 for its key. */
  enabled: boolean
  /** The store key, which names the shop in the API's address. Not a secret; a new key ends the old address. */
  key: string
  /** The base address for the site's developer: APP_URL, then /api/store/v1/ and the key. Every endpoint is under it. */
  base_url: string
  /** The site's address, without a trailing slash. Its origin may call the API from a browser. */
  url: string | null
  /** Other origins allowed to call it (scheme://host[:port]), such as a site in development on http://localhost:3000. */
  origins: string[]
  telegram: {
    /** Customers can sign in with Telegram. */
    enabled: boolean
    /** The Client ID @BotFather shows for the site (Bot Settings › Login Widget), as digits; null while none is set. */
    client_id: string | null
    /** The shop's bot's own Telegram id, shown as a hint beside the Client ID; null while unknown. */
    bot_id: number | null
    /** A client secret is stored, so the redirect flow can exchange a code. The popup does not need one. */
    has_secret: boolean
  }
  email: {
    /** Customers can sign up with an email and a password. */
    enabled: boolean
    /** Whether the installation can send email (the owner's «تنظیمات پنل › ایمیل»). Without it, email sign-up cannot be switched on and does not work. */
    mail_ready: boolean
  }
  google: {
    /** The site's OAuth client id in Google Cloud (….apps.googleusercontent.com); null when Google sign-in is off. */
    client_id: string | null
  }
  /** The captcha the website's forms use (sign-up, password sign-in, password reset, reviews, and the site's own forms through the Store API's `POST /captcha/verify`): the chosen driver, every captcha driver with its form, and each driver's stored values or defaults (a secret as a SecretState). */
  captcha: {
    /** The chosen captcha (a CaptchaDriver), or `none`. */
    driver: 'none' | 'turnstile' | 'altcha'
    drivers: DriverDescription[]
    /** Each captcha driver's values, keyed by driver. */
    values: Record<string, DriverValues>
  }
  reviews: {
    /** The website shows approved reviews and accepts new ones (the Store API's `GET` and `POST /reviews`, which answer 404 while off). Guests can write only while a captcha is set. Off by default. */
    enabled: boolean
  }
  /** Access for the shop's admins (customers whose role is admin) to the website's admin API, `/api/store/v1/{store}/admin/*`: the panels' daily-work operations. */
  staff: {
    /** Admins can use the admin API. Off by default. */
    enabled: boolean
    /** Admins must sign in strongly: Telegram, Google, or a password with its second step. On by default. */
    strong_sign_in: boolean
    /** What admins may do beyond the daily work, in the grants' order. None by default. */
    grants: StaffGrant[]
  }
}

/** What each grant lets the website's admins do beyond the daily work: `catalog` (plans and categories), `wallet` (credit or debit a customer's wallet), `refunds` (refund a payment), `extend` (add days and traffic to a service), `delete` (delete a service permanently, or hand it to another customer), `account_security` (turn off a customer's two-factor sign-in, sign them out of every device). */
export type StaffGrant = 'catalog' | 'wallet' | 'refunds' | 'extend' | 'delete' | 'account_security'

export interface WebsiteResponse {
  website: Website
}

/** A partial update of the website's settings; each panel card sends its own fields. Every field is optional: a field sent changes, one left out keeps its value, and an empty object changes nothing. Requirements are checked against the settings as they would be after the change (for example, switched on needs an address, sent now or already stored), with every refusal returned at once under its field. A secret sent blank keeps the stored one; `clear_<secret>` empties it. */
export interface WebsiteRequest {
  /** Switching on needs an address, sent now or already stored. */
  enabled?: boolean
  /** The site's address: http(s) with a host, no ? or #, at most 255 characters. Blank for none, which is refused while the website is on. */
  url?: string
  /** Other allowed origins: a list, or text as the admin types it (one per line, or comma-separated). At most 10, each http(s) with a host and optionally a port, nothing after it. Empty for none. */
  origins?: string[] | string
  /** Switching on needs a Client ID, sent now or already stored. */
  telegram_login?: boolean
  /** Digits, as @BotFather shows them (Persian digits accepted). Blank for none, which is refused while Telegram sign-in is on. */
  telegram_client_id?: string
  /** Blank: the stored secret stays. */
  telegram_client_secret?: string
  /** True empties the stored secret. */
  clear_telegram_client_secret?: boolean
  /** Customers can sign up with an email. Cannot be switched on while the installation cannot send email. */
  email_signup?: boolean
  /** The OAuth client id Google Cloud shows (<digits>-<letters and digits>.apps.googleusercontent.com). Blank turns Google sign-in off. */
  google_client_id?: string
  captcha?: WebsiteCaptchaRequest
  /** Whether the website shows approved reviews and accepts new ones. */
  reviews_enabled?: boolean
  /** Whether the shop's admins can use the admin API. */
  staff_enabled?: boolean
  /** Whether admins must sign in strongly: Telegram, Google, or a password with its second step. */
  staff_strong_sign_in?: boolean
  /** The full list of grants beyond the daily work; any grant left out is not given. */
  staff_grants?: StaffGrant[]
}

/** The website's captcha, as its settings card sends it: the driver (`none` for none) and the driver's whole form, validated by the driver. A secret left blank or out keeps the stored one while the driver and the fields it belongs with stay the same; `clear_<name>: true` empties it. Refusals are reported under `captcha.<field>`. One closed shape per driver. */
export type WebsiteCaptchaRequest = WebsiteCaptchaOffRequest | WebsiteTurnstileRequest | WebsiteAltchaRequest

/** No captcha. */
export interface WebsiteCaptchaOffRequest {
  driver: 'none'
}

/** Cloudflare Turnstile: the widget's keys from the Cloudflare dashboard. */
export interface WebsiteTurnstileRequest {
  driver: 'turnstile'
  /** Public; the widget is drawn with it. At most 64 printable Latin characters. */
  site_key: string
  /** Blank: the stored secret stays, as long as the site key stays the same. */
  secret_key?: string
  /** True empties the stored secret. */
  clear_secret_key?: boolean
}

/** ALTCHA: no keys; the shop issues and checks the challenges itself. */
export interface WebsiteAltchaRequest {
  driver: 'altcha'
}

/** The Store API's `GET /`: the shop's public profile, which a website asks for first. */
export interface StoreResponse {
  shop: {
    /** The main shop's name (APP_NAME); for an agent's shop, their bot's title (else its username). */
    name: string
    /** Whether the shop takes orders now (its bot is switched on). When false, purchases, renewals, top-ups and receipts are refused (503); the catalogue can still be read and customers can still sign in. */
    taking_orders: boolean
    /** Whether a customer can add a service to their account by its link now (`POST /subscriptions/claim`, «افزودن سرویس با لینک»): the main shop's alone, while its admin switches it on (off by default). Always false in an agent's shop. */
    service_claims: boolean
    /** Whether a customer can hand one of their services to another customer of the shop now (`POST /subscriptions/{id}/transfer`, «انتقال سرویس»): while the shop's admin switches it on (off by default), in any shop. */
    service_transfers: boolean
    /** Whether a customer can build a service of the traffic and days they choose now («سرویس دلخواه», `GET /custom-services`): the main shop's alone, while its admin switches it on (off by default), both price lists sell and one of its servers can take a new client. Always false in an agent's shop. */
    custom_services: boolean
    /** The ways a buyer may name their new service (`name_mode` of `POST /orders`), `sequential` (the shop's pattern) first and always; `random` and `custom` while the shop's admin offers them. Just `sequential`: ask nothing. */
    name_modes: ClientNameMode[]
    /** The shop's Telegram bot; null while its username is unknown. */
    bot: {
      /** Without the @. */
      username: string
      /** https://t.me/ and the username. */
      url: string
    } | null
  }
  /** How to reach support: the bot's «پشتیبانی» contact as a link (an @handle's t.me address, a web address, or a tel: number). Null when none is set or it is not a link. */
  support: {
    url: string
  } | null
  sign_in: {
    /** Telegram sign-in while it is on; null when it is off. */
    telegram: {
      /** The Client ID for the site's sign-in (telegram-login.js's client_id). */
      client_id: string
      /** The redirect flow is also available (a client secret is stored), so `POST /auth/telegram/authorize` works. */
      redirect: boolean
    } | null
    /** Google sign-in while it is on; null when it is off. */
    google: {
      /** The OAuth client id for the site's sign-in (Google Identity Services' client_id). */
      client_id: string
    } | null
    /** Email sign-up is on and the shop can send email (so the codes reach the customer). Customers who already signed up can sign in with their password either way. */
    email: boolean
  }
  /** The website's captcha, for drawing the widget; null when none is set. Sign-up, password sign-in, password reset and reviews send its token in `captcha`, solved for the operation's action (`x-captcha`: Turnstile's data-action, or ALTCHA's challenge requested with ?action=). For the site's own forms, its backend checks the token with `POST /captcha/verify`. */
  captcha: {
    driver: CaptchaDriver
    /** The widget's public key (Turnstile's site key); null for ALTCHA, which has none. */
    site_key: string | null
    /** Where the widget fetches its challenge: ALTCHA's challengeurl (`GET /captcha/challenge`, with ?action= naming the form's action). Null for Turnstile, which makes its own. */
    challenge_url: string | null
  } | null
  /** The referral program's terms: a new customer who signs in with an invite code (`referral_code`) earns the code's owner a share of their payments. */
  referral: {
    enabled: boolean
    /** The share of a referred customer's payment, in percent. */
    rate: number
    /** Only each referred customer's first payment earns a share. */
    first_only: boolean
  }
}

/** A captcha a website can use: `turnstile`, Cloudflare Turnstile (its widget is drawn with the site key and its token is checked with Cloudflare); or `altcha`, ALTCHA (open source, with no third party and no keys: the visitor's browser solves a challenge the shop issues and checks itself). */
export type CaptchaDriver = 'turnstile' | 'altcha'

/** The action a captcha widget names for a form: the shop's `sign_up`, `sign_in`, `password_reset` and `review` (each operation's `x-captcha`), or the site's own (such as `contact`). A token solved for one action does not pass a form of another. 1 to 32 of A-Z, a-z, 0-9, - and _ (Turnstile's data-action). */
export type CaptchaAction = string

/** An ALTCHA challenge, in the widget's own format: a salt that carries its expiry (and the requested action), the SHA-256 of the salt plus a number up to `maxnumber`, and the shop's signature. The widget finds the number and gives the form the payload (base64 JSON), which the form sends as `captcha`. */
export interface StoreCaptchaChallengeResponse {
  algorithm: 'SHA-256'
  challenge: string
  maxnumber: number
  salt: string
  signature: string
}

/** A captcha token from one of the site's own forms, which the site's backend asks the shop to check, as the visitor's browser sent it. */
export interface StoreCaptchaVerifyRequest {
  /** The token the widget gave the form: Turnstile's cf-turnstile-response, or ALTCHA's payload. At most 4096 characters. */
  token: string
  action?: CaptchaAction
  /** The visitor's IPv4 or IPv6 address, as the site's backend saw it. Refused tokens then count against the visitor's network instead of the backend's (which all visitors share); it is also passed to the captcha provider (Turnstile's remoteip). Optional: when blank or left out, the backend's own network is counted. */
  remoteip?: string
}

/** The result of a token check: whether it passed, the action and host it was solved for (as far as the captcha reports), when it was checked, and why it failed. */
export interface StoreCaptchaVerdict {
  passed: boolean
  /** The action it was solved for, as the widget named it; null for none. */
  action: string | null
  /** The host it was solved on (Turnstile reports it); null when the captcha does not report it (ALTCHA). */
  hostname: string | null
  verified_at: string
  /** Why it failed; empty when it passed. `hostname-mismatch` (not solved on one of the website's hosts: its address and origins), `action-mismatch` (solved for another action than `action`), `timeout-or-duplicate` (expired, or already used), `invalid-input-response` (not a valid token, or tampered with), `missing-input-response`, and Cloudflare's own error codes. */
  reasons: string[]
}

export interface StoreNonceResponse {
  /** Pass it to the provider's sign-in (telegram-login.js's nonce), then send it back once, with the id_token. */
  nonce: string
  /** Seconds until it expires. */
  expires_in: number
}

/** Start a redirect sign-in in this browser: where Telegram sends the browser back (with the code and state), and the PKCE challenge of the verifier the site made and keeps (in sessionStorage). The shop keeps no verifier, so a code and state brought back to another browser sign nobody in. */
export interface StoreTelegramAuthorizeRequest {
  /** An http(s) address on the website's origin or one of its listed origins, without #. */
  redirect_uri: string
  /** S256: the SHA-256 of the verifier (43 to 128 random characters of A-Z, a-z, 0-9 and -._~), in base64url without padding. */
  code_challenge: string
}

export interface StoreAuthorizeResponse {
  /** Telegram's authorization address to send the browser to, with client_id, redirect_uri, scope, state, the PKCE challenge (S256) and a nonce. */
  url: string
}

/** A Telegram sign-in: the popup's id_token with its nonce, or the redirect's code with its state and verifier. */
export type StoreTelegramSignInRequest = StoreTelegramPopupRequest | StoreTelegramCodeRequest

export interface StoreTelegramPopupRequest {
  /** The id_token telegram-login.js gave the page. */
  id_token: string
  /** The nonce from `POST /auth/nonce` that the popup was opened with. */
  nonce: string
  /** The invite code the visitor came with: a new customer becomes its owner's referral. Ignored for an existing customer. */
  referral_code?: string
}

export interface StoreTelegramCodeRequest {
  /** The code Telegram sent the browser back with. */
  code: string
  /** The state it came back with, from `POST /auth/telegram/authorize` (a signed-in customer's state signs nobody in). */
  state: string
  /** The PKCE verifier this browser made and kept when it started the sign-in, whose challenge it sent. Any other verifier signs nobody in. */
  code_verifier: string
  /** The invite code the visitor came with: a new customer becomes its owner's referral. Ignored for an existing customer. */
  referral_code?: string
}

/** A Google sign-in: the id_token Google Identity Services gave the page (its `credential`), with the nonce it was requested with. */
export interface StoreGoogleSignInRequest {
  /** The id_token Google gave the page. */
  id_token: string
  /** The nonce from `POST /auth/nonce` that the sign-in was requested with. */
  nonce: string
  /** The invite code the visitor came with: a new customer becomes its owner's referral. Ignored for an existing customer. */
  referral_code?: string
}

/** The first step of signing up. Every refusal is returned at once, under its field. */
export interface StoreRegisterRequest {
  /** 1 to 64 characters. */
  first_name: string
  /** At most 64 characters; blank for none. */
  last_name?: string
  /** An email address, at most 191 characters; stored in lower case. */
  email: string
  /** At least 8 characters and at most 72 bytes (a Persian letter is two bytes), taken exactly as typed, spaces included. */
  password: string
  /** The invite code the visitor came with: a new customer becomes its owner's referral. Ignored for an existing customer. */
  referral_code?: string
  /** The captcha widget's token, while the website uses a captcha (`GET /` says which and how to draw it), solved for the operation's action (its `x-captcha`). */
  captcha?: string
}

/** The last step of signing up: the emailed code. */
export interface StoreVerifyRequest {
  email: string
  /** Six digits (Persian digits accepted). */
  code: string
}

export interface StoreLoginRequest {
  email: string
  password: string
  /** The captcha widget's token, while the website uses a captcha (`GET /` says which and how to draw it), solved for the operation's action (its `x-captcha`). */
  captcha?: string
}

export interface StoreForgotRequest {
  email: string
  /** The captcha widget's token, while the website uses a captcha (`GET /` says which and how to draw it), solved for the operation's action (its `x-captcha`). */
  captcha?: string
}

export interface StoreResetRequest {
  email: string
  /** The six-digit emailed code (Persian digits accepted). */
  code: string
  /** The new password: at least 8 characters and at most 72 bytes. */
  password: string
}

export interface StoreCodeSentResponse {
  /** Seconds until the code expires (it allows five tries). */
  expires_in: number
}

export interface StoreSignInResponse {
  /** The customer's bearer token for this device, shown only this once. The site keeps it and sends it as `Authorization: Bearer …`. */
  token: string
  customer: StoreCustomer
}

/** The signed-in customer's account. */
export interface StoreCustomer {
  id: number
  first_name: string | null
  last_name: string | null
  /** Their Telegram account: its numeric id and handle (without the @; null if none). Null for a customer who signed up on the website. */
  telegram: {
    id: number
    username: string | null
  } | null
  /** Their verified email (confirmed with an emailed code, or vouched for by Google); null if none. */
  email: string | null
  /** They can sign in with a Google account. */
  google: boolean
  /** They can sign in with their email and a password. */
  has_password: boolean
  /** Password sign-in also asks for their authenticator app's code (or a recovery code). */
  two_factor: boolean
  /** The number they shared with the bot, in E.164 form (+98912…). */
  phone: string | null
  /** Their wallet balance in Toman, as a decimal string ('120000.00'). Below zero is an agent's debt. */
  balance: string
  /** When they became a customer of the shop. */
  created_at: string
}

export interface StoreMeResponse {
  customer: StoreCustomer
}

/** The answer of `GET /me` and `PATCH /me`: the account, the unread notification count (for a bell icon), what the admin API allows them (for an admin of the shop), and their loyalty standing. */
export interface StoreAccountResponse {
  customer: StoreCustomer
  /** How many of their notifications are unread (`GET /notifications`). */
  unread_notifications: number
  /** Null for a customer who is not an admin of the shop, and while the website lets no admins in. */
  staff: StoreStaff | null
  /** Their loyalty tier («سطح وفاداری»), what they spent and the next tier. The tier's discount applies at checkout and its cashback adds to the shop's. In a shop with no tiers, `tier` and `next` are null. */
  loyalty: LoyaltyStanding
}

/** What the website's admin API (`/admin/*`, the panel operations marked `x-staff`) allows this customer as an admin of the shop. */
export interface StoreStaff {
  /** What the website lets admins do beyond the daily work. An operation that needs a grant works only while the grant is listed here. */
  grants: StaffGrant[]
  /** The website requires admins to sign in strongly: Telegram, Google, or a password with its second step. */
  strong_sign_in: boolean
  /** This session was signed in strongly, or a strong way in was proven on it since (`POST /me/reauthenticate`). */
  signed_in_strongly: boolean
  /** Until when this session may use the admin API at all: 12 hours after a way in was last proven on it. Null: prove a way in again first (`POST /me/reauthenticate`). */
  signed_in_until: string | null
  /** Until when this session's sign-in counts as recent, which granted operations and payment approval (`x-staff-recent`) also need. Null: prove a way in again first (`POST /me/reauthenticate`). */
  recent_until: string | null
}

/** A device the customer is signed in on. */
export interface StoreSession {
  id: number
  /** The device as its browser describes it, in words: «Chrome در Windows». */
  device: string | null
  /** The IP address it signed in from. */
  ip: string | null
  /** When it signed in. */
  created_at: string
  /** Its last request (updated every few minutes, not on every request). */
  last_used_at: string | null
  /** The session this request was made with. */
  current: boolean
}

export interface StoreSessionsResponse {
  sessions: StoreSession[]
}

/** The password is right, but the account needs a second step: the challenge to send with the code (`POST /auth/login/2fa`). No session is opened yet. */
export interface StoreTwoFactorResponse {
  two_factor: {
    /** Send it back with the code; it allows five wrong codes. */
    challenge: string
    /** Seconds until it expires. */
    expires_in: number
  }
}

/** The second step of a password sign-in. */
export interface StoreTwoFactorRequest {
  /** The challenge from `POST /auth/login` (or `/auth/password/reset`). */
  challenge: string
  /** The current six-digit code from the account's authenticator app (Persian digits and spaces accepted), or one of its recovery codes (any case, dashes and spaces accepted). */
  code: string
}

/** The customer's names. Only the fields sent change. An account with Telegram takes its names from Telegram and cannot change them. */
export interface StoreProfileRequest {
  /** 1 to 64 characters. */
  first_name?: string
  /** At most 64 characters; blank for none. */
  last_name?: string
}

export interface StorePasswordRequest {
  /** The current password; required when the account has one. */
  current_password?: string
  /** The new password: at least 8 characters and at most 72 bytes (a Persian letter is two bytes), taken exactly as typed. */
  password: string
}

/** A new secret for the customer's authenticator app, waiting for the app's first code. */
export interface StoreTwoFactorSetupResponse {
  /** Base32 (160 bits), for typing into the app by hand. */
  secret: string
  /** The otpauth:// address for the app, as a QR code or a link on the phone. Its issuer is the shop's name and its account name is the email. */
  uri: string
}

export interface StoreTwoFactorEnableRequest {
  /** The app's current six-digit code for the secret from `POST /me/2fa/setup` (Persian digits accepted). */
  code: string
}

/** Two-factor sign-in is on. Its recovery codes, each usable once instead of the app's code, are shown only this once (the shop stores only their hashes). */
export interface StoreRecoveryCodesResponse {
  /** Ten codes, each 10 upper-case letters and digits without look-alikes (no 0/O, 1/I/L). */
  recovery_codes: string[]
}

export interface StoreTwoFactorDisableRequest {
  /** The account's password. */
  password: string
}

/** Proof of a Telegram account, as for a sign-in: the popup's id_token with its nonce, or the redirect's code with its state and verifier. */
export type StoreTelegramLinkRequest = StoreTelegramLinkPopupRequest | StoreTelegramLinkCodeRequest

export interface StoreTelegramLinkPopupRequest {
  /** The id_token telegram-login.js gave the page. */
  id_token: string
  /** The nonce from `POST /auth/nonce` that the popup was opened with. */
  nonce: string
}

export interface StoreTelegramLinkCodeRequest {
  /** The code Telegram sent the browser back with. */
  code: string
  /** The state it came back with, from `POST /me/telegram/authorize` for this customer (a sign-in's or another customer's state proves nothing). */
  state: string
  /** The PKCE verifier this browser made and kept when it started the sign-in, whose challenge it sent. Any other verifier signs nobody in. */
  code_verifier: string
}

/** Proof of a Google account, as for a sign-in: the id_token Google Identity Services gave the page, with the nonce it was requested with. */
export interface StoreGoogleLinkRequest {
  /** The id_token Google gave the page. */
  id_token: string
  /** The nonce from `POST /auth/nonce`. */
  nonce: string
}

/** An email to add to the account, with the password to sign in with. Every refusal is returned at once, under its field. */
export interface StoreEmailLinkRequest {
  /** An email address, at most 191 characters; stored in lower case. */
  email: string
  /** At least 8 characters and at most 72 bytes, taken exactly as typed. */
  password: string
}

export interface StoreEmailLinkVerifyRequest {
  email: string
  /** The six-digit emailed code (Persian digits accepted). */
  code: string
}

/** A way to sign in: a Telegram account, a Google account, or an email (with its password). */
export type StoreIdentityKind = 'telegram' | 'google' | 'email'

/** The way in being added belongs to another account of the shop. Shows that account (so the customer can confirm it is theirs too), which of the two would remain, and the ticket that merges them (`POST /me/merge`). */
export interface StoreMergeOfferResponse {
  merge: {
    /** The merge ticket, usable once and only by this account. */
    token: string
    /** Seconds until it expires. */
    expires_in: number
    /** The other account. */
    account: {
      /** "first last"; null if none. */
      name: string | null
      /** When it became a customer of the shop. */
      created_at: string
      /** Its active services. */
      services: number
      orders: number
      /** Its wallet balance, in Toman. */
      balance: string
      /** It can sign in with Telegram. */
      telegram: boolean
      /** It can sign in with an email. */
      email: boolean
      /** It can sign in with Google. */
      google: boolean
    }
    /** The account that remains, the older one: `this` or the `other`. It takes over everything the other owns, and the other's ways in fill its empty slots. */
    keeps: 'this' | 'other'
  }
}

export interface StoreMergeRequest {
  /** The ticket from the merge offer. */
  token: string
}

/** Proof of a way into this account: its password (with the second step's code while two-factor sign-in is on), its Telegram account (popup or redirect), or its Google account. */
export type StoreReauthenticateRequest = StoreReauthenticatePasswordRequest | StoreReauthenticateTelegramPopupRequest | StoreReauthenticateTelegramCodeRequest | StoreReauthenticateGoogleRequest

export interface StoreReauthenticatePasswordRequest {
  method: 'password'
  /** The account's password. */
  password: string
  /** While two-factor sign-in is on: the current six-digit code from the account's authenticator app, or one of its recovery codes (which is used up), as `POST /auth/login/2fa` accepts them. */
  code?: string
}

export interface StoreReauthenticateTelegramPopupRequest {
  method: 'telegram'
  /** The id_token telegram-login.js gave the page. */
  id_token: string
  /** The nonce from `POST /auth/nonce` that the popup was opened with. */
  nonce: string
}

export interface StoreReauthenticateTelegramCodeRequest {
  method: 'telegram'
  /** The code Telegram sent the browser back with. */
  code: string
  /** The state it came back with, from `POST /me/telegram/authorize` for this customer. */
  state: string
  /** The PKCE verifier this browser made and kept when it started the sign-in, whose challenge it sent. Any other verifier signs nobody in. */
  code_verifier: string
}

export interface StoreReauthenticateGoogleRequest {
  method: 'google'
  /** The id_token Google gave the page. */
  id_token: string
  /** The nonce from `POST /auth/nonce`. */
  nonce: string
}

export interface StoreReauthenticatedResponse {
  /** Seconds this session may change how the account signs in before it must prove a way in again (15 minutes). */
  expires_in: number
}

/** A plan on sale now: what it gives and costs, its category, and the servers a customer can pick today. */
export interface StorePlan {
  id: number
  name: string
  description: string | null
  /** Toman, as a decimal string ('120000.00'). */
  price: string
  /** The price of a customer's first purchase in the shop («قیمت خرید اول»), in Toman, below `price` ('0.00' makes it free). `POST /orders` charges it while the customer has bought no service. Null when there is none, or (with a customer's token) when that customer has already bought. Renewals are at `price`. */
  first_purchase_price: string | null
  /** The price for the shop's agents and members of groups with agent prices («قیمت نماینده»), in Toman, below `price`, for purchases and renewals. Null when there is none, or when the reader is a guest or a customer who gets no agent price. The checkout charges the customer's lowest price. */
  agent_price: string | null
  /** What the reader pays today, in Toman: the lowest price that applies to them (`price`, `first_purchase_price`, `agent_price`) minus their largest automatic discount (`discount`). Equal to `price` when nothing applies. A discount code is applied on top of this at checkout. */
  offer_price: string
  /** The automatic discount in `offer_price`: a sale on the plan, the reader's personal percent or their loyalty tier's, whichever is largest (never two together). Null for none. */
  discount: StorePlanDiscount | null
  /** A note to show at checkout («یادداشت پیش‌فاکتور»: apps it was tested with, a caveat), as plain text the site must escape. Null for none. */
  checkout_note: string | null
  /** Gigabytes, decimals allowed; 0 for unlimited. */
  traffic_gb: number
  /** The term in whole days, rounded up (a 6-hour plan says 1), counted from the first connection; 0 never ends. `duration_hours` is exact. */
  duration_days: number
  /** The term in hours from the first connection (a daily plan's is days × 24); 0 never ends. */
  duration_hours: number
  /** Devices at once; 0 for no limit. */
  devices: number
  /** The category it is listed under; null for the group without a category (no category, or a switched-off one). */
  category_id: number | null
  /** The servers a customer can pick today («لوکیشن» in the bot), in the plan's order; at least one. */
  locations: NamedRef[]
}

/** The automatic discount on a plan for this reader: its source, its label («فروش ویژه نوروز ۲۰٪», «تخفیف اختصاصی شما ۱۰٪», «سطح طلایی ۵٪»), its percent and, for a sale, when it ends. */
export interface StorePlanDiscount {
  source: 'sale' | 'personal' | 'tier'
  label: string
  percent: number | null
  /** When the sale ends; null for no end, or another source. */
  ends_at: string | null
}

export interface StorePlansResponse {
  /** Every active category in order (empty ones too), then last the plans without a category (or in a switched-off one). A shop without categories has only this last group, or no group when it sells nothing now. */
  groups: {
    /** Null for the group without a category («سایر پلن‌ها» in the bot). */
    category: NamedRef | null
    plans: StorePlan[]
  }[]
}

export interface StorePlanResponse {
  plan: StorePlan
}

export interface StoreStatusResponse {
  servers: {
    id: number
    name: string
    /** It takes new customers now: switched on, serving subscription links, and not full. */
    available: boolean
    /** When the shop last reached it; null if never. */
    checked_at: string | null
  }[]
}

/** One of the customer's services, as last read from its panel (`synced_at`), with what the customer can do with it now. */
export interface StoreSubscription {
  id: number
  /** Its name on the panel ("amir_2", "USER_7"). */
  name: string
  status: SubscriptionStatus
  /** A free test account («اکانت تست»), taken in the bot: it has no plan and can be neither renewed nor bought extras (`extras`); the shop may remove it some time after it ends, or some days after it was made while never connected (`removes_at`). */
  is_test: boolean
  /** When the customer added it to their account by its link (`POST /subscriptions/claim`, or in the bot): a service support made on the panel by hand, not sold by the shop. It has no plan until a renewal on a plan the customer picks (`GET …/renewal-plans`; a renewal with a package built leaves it without). Null for every other service. */
  claimed_at: string | null
  /** The plan it was sold or last renewed on; null for a service without one — a test account, a service added by its link (`claimed_at`) or built by the customer (`POST /custom-services/orders`), until a renewal on a plan (a renewal with a package built leaves it null) — or once that plan is deleted. */
  plan: NamedRef | null
  /** The server it is on («لوکیشن» in the bot). */
  server: NamedRef
  /** The subscription link; null once the panel no longer has the service (deleted). */
  link: string | null
  traffic: StoreServiceTraffic
  term: StoreServiceTerm
  /** When it ended: its end date passed, its traffic ran out, or its panel no longer has it. Null while it runs, and after its end date until the shop next reads its panel (`term.expires_at` says when). */
  ended_at: string | null
  /** When the shop will remove it by itself — from its panel and from the customer's services —, by the shop's rules now; null when it will not. Set only for a service that ended (or a test account never connected) in a shop that removes such services: renewing it or adding to it before then keeps it, and the moment can move when the shop changes its rules. A moment already past: it goes at the shop's next check (every 15 minutes). A service switched off by support, or with an order still open (a renewal waiting for its receipt), is not removed. */
  removes_at: string | null
  /** Auto-renewal («تمدید خودکار»): the service is renewed from the wallet before it ends, on its plan at the plan's price. */
  auto_renew: {
    /** The customer's switch. */
    on: boolean
    /** The switch can be set (`PATCH`): the service is active, has an end date and still has its plan, and the wallet payment method is on. */
    offered: boolean
    /** How many days before its end it is renewed when the switch is on — or, where the shop renews automatically by «ریست زمان» (which throws away the term left), how many days before its end a wallet short of the price is told, the renewal itself made shortly before the end. A shop-wide setting, 1 to 30. */
    days_before: number
  }
  /** The customer can renew it now on its own plan: it is active or ended, its plan still exists and the renewal adds something (in an agent's shop, covered by the agent's traffic). False for a service without a plan, which `picks_plan` covers. Whether the shop lets it be renewed yet is `renewal_window`. */
  renewable: boolean
  /** The customer can renew it now on what they pick: it has no plan and is no test account (added by its link, `claimed_at`, or built), it is active or ended, and a plan on sale is sold on its server or the shop's builder is offered. List the choices with `GET …/renewal-plans` and send the plan picked as `plan_id` to `…/renewal` — that renewal makes it the service's plan, after which `renewable` is what holds —, or a package built as `gb` and `days`, after which it still picks. */
  picks_plan: boolean
  /** Whether the shop lets the customer renew it yet («آستانه مجاز تمدید»), beside `renewable` or `picks_plan`. A shop may hold a running service back until its end date is a few days away, or until little of its traffic is left; an ended service may always be renewed. While closed, the renewal's preview and the renewal are a 422 on `status` saying from when, and the fields say what opens it — any that is set, whichever comes first. Auto-renewal goes by its own days. */
  renewal_window: {
    /** It may be renewed now (as far as the window goes). */
    open: boolean
    /** While closed: the moment its end date comes within the window (that end date less the window's days); null otherwise. */
    opens_at: string | null
    /** While closed and its clock waits for its first connection (no end date yet): it opens this many days before the end date it will have; null otherwise. */
    days_before: number | null
    /** While closed: it opens once at most this percent of its traffic is left; null otherwise (unlimited traffic, or the shop holds nothing back by traffic). With no field set, only its end opens it. */
    traffic_percent: number | null
  }
  /** How many devices may use it at once (its IP limit, raised by extra devices); 0 for no limit. */
  devices: number
  /** Whether extra traffic, extra time and extra devices («کاربر اضافه») can be bought for it now (`GET …/extras` gives the amounts and prices). All need the shop to sell them (at its prices or the plan's) and the service to be active or ended (not switched off by support, nor gone from its panel) and not a free test account. Traffic needs a quota and time not run out (a service whose traffic ran out runs again with it). Time needs a term and traffic not run out (a service whose time ran out starts again from its next connection). Devices need a device limit (an unlimited one takes none), neither time nor traffic run out (a renewal then would replace the period they are for at once), and a server whose panel enforces the limit. In an agent's shop, traffic also needs the agent's bot to have traffic left to sell. */
  extras: {
    traffic: boolean
    time: boolean
    devices: boolean
  }
  /** Its link can be changed now (`POST …/rotate-link`): it is active and its server can rotate links. */
  link_rotation: boolean
  /** A renewal's traffic queued after the current period. Null while none waits for the period's end: nothing renewed ahead, or a renewal whose traffic counted at once (the shop carries unused traffic over, `reset_traffic`, or unlimited traffic) — its device limit may still wait (`next_devices`). */
  next_period: StoreNextPeriod | null
  /** A renewal's device limit queued after the current period: a running service renewed by `extend` or `reset_traffic` keeps the devices it has (extra devices bought included) until the period in use ends, and has the renewal's from then. Null while the limit stays as `devices` says. */
  next_devices: NextDevices | null
  /** Whether it is connected. Only set right after the panel was read (`POST …/refresh`) and still has it; null otherwise. */
  presence: {
    /** Connected right now; null when the panel cannot tell. */
    online: boolean | null
    /** Its last connection; null if never, or when the panel cannot tell. */
    last_online_at: string | null
  } | null
  /** When the panel was last read for it; the numbers are from then. */
  synced_at: string | null
  created_at: string
}

/** A service's quota, and how much is used and left, in bytes. */
export interface StoreServiceTraffic {
  /** 0 for unlimited. */
  limit_bytes: number
  used_bytes: number
  /** Null when unlimited. */
  remaining_bytes: number | null
}

/** A service's term: its length, when it started and when it ends, or that it waits for the first connection. */
export interface StoreServiceTerm {
  /** The term in whole days, rounded up, counted from the first connection; 0 never ends. `duration_hours` is exact. */
  duration_days: number
  /** The term in hours, counted from the first connection; 0 never ends. */
  duration_hours: number
  /** When the first connection started the term; null until then. */
  starts_at: string | null
  /** Null while it waits for the first connection, or without a term. */
  expires_at: string | null
  /** The term has not started yet; it starts at the first connection. */
  awaits_first_use: boolean
}

/** A renewal queued after the current period (whose unused traffic is not carried over): when the current period ends, and the most traffic left then, in bytes. */
export interface StoreNextPeriod {
  ends_at: string
  bytes: number
}

/** A device limit a renewal queued after the current period: from when (the current period's end) and how many devices at once then. */
export interface NextDevices {
  from: string
  /** 0 for no limit. */
  devices: number
}

export interface StoreSubscriptionsResponse {
  subscriptions: StoreSubscription[]
  meta: PageMeta
}

export interface StoreSubscriptionResponse {
  subscription: StoreSubscription
}

/** «انتقال سرویس»: who the service goes (or went) to — their Telegram name and handle, nothing else of their account. */
export interface StoreTransferRecipientResponse {
  recipient: {
    /** Their name in Telegram; null when they show none. */
    name: string | null
    /** Their Telegram handle, without the @; null when they have none. */
    username: string | null
  }
}

export interface StoreSubscriptionRequest {
  /** Auto-renewal («تمدید خودکار») on or off. */
  auto_renew: boolean
}

/** A service's link, as the customer sent it, to add the service to their account. */
export interface StoreClaimRequest {
  /** A subscription link or one of the service's configs, alone or among other words (its links are read together, five at most). */
  link: string
}

/** A payment attempt for an order. */
export interface StorePayment {
  id: number
  /** The payment method. */
  method: StorePaymentMethod
  status: PaymentStatus
  /** Toman. */
  amount: string
  created_at: string
  paid_at: string | null
  /** The receipt the customer sent for it, in the bot or on the website; null if none was sent. */
  receipt: {
    sent_at: string
  } | null
  /** The note on its latest decision, as the bot shows it to the customer: support's (a rejection's reason, or a cancellation or refund note) or the shop's own (the order paid another way, left unpaid, or its service handed to another customer); null if none. */
  note: string | null
}

/** One of the customer's orders: what it was for, its status, and its payments (newest first). */
export interface StoreOrder {
  id: number
  type: OrderType
  status: OrderStatus
  /** Toman. */
  amount: string
  pricing: OrderPricing
  /** The total its discounts took off (the automatic one and the code's), in Toman; 0 for none. `amount` is after discounts. */
  discount_amount: string
  /** The discounts applied, the automatic one first. */
  discounts: DiscountLine[]
  /** The discount code used; null for none. */
  discount_code: OrderDiscountCode | null
  created_at: string
  fulfilled_at: string | null
  /** The plan of a purchase or renewal (for extras, the service's plan); null for a wallet top-up, a service the customer built, or once the plan is deleted. */
  plan: NamedRef | null
  package_name: OrderPackageName
  /** Where a purchase was bought; for a renewal or extras, the service's server. Null for none. */
  server: NamedRef | null
  /** The service it is about, by its panel name (for a renewal or extras from the start, for a purchase once delivered); null for none, or once the service is deleted. */
  subscription: NamedRef | null
  /** The traffic it adds, in bytes: a service's extra traffic, a built service's traffic, or an agent's traffic for their bot (`traffic` orders); null otherwise. */
  traffic_bytes: number | null
  /** The extra days it adds to its service, or a built service's term; null otherwise. */
  days: number | null
  /** The devices it adds to its service's IP limit (extra devices), or a built service's limit; null otherwise. */
  devices: number | null
  payments: StorePayment[]
}

export interface StoreOrdersResponse {
  orders: StoreOrder[]
  meta: PageMeta
}

export interface StoreOrderResponse {
  order: StoreOrder
}

/** A payment method, as the checkout shows it. */
export interface StorePaymentMethod {
  id: number
  /** Its button text ("کارت به کارت (ملت)"). */
  label: string
  /** `instant`: the wallet, paid at once. `manual`: a card transfer with a receipt. Null for a method whose driver is no longer installed. */
  kind: GatewayKind | null
}

export interface StorePaymentMethodsResponse {
  /** In checkout order; empty when no method can pay this kind of order. */
  methods: StorePaymentMethod[]
}

/** A purchase: the plan, the location to buy it on, and the payment method. */
export interface StoreOrderRequest {
  /** A plan on sale now (`GET /plans`). */
  plan_id: number
  /** One of the plan's `locations`. */
  server_id: number
  /** A payment method for purchases (`GET /payment-methods?for=purchase`). */
  method_id: number
  name_mode?: StoreNameModeInput
  client_name?: StoreClientNameInput
  /** A discount code the customer typed (any case, Persian digits accepted), applied after the automatic discount. A 422 on `discount_code` when it does not apply to this order. Blank, null or left out for none. Each code that does not apply uses one of the customer's 20 tries an hour (then a 429). A repeated request with the same key returns its order, whatever the code says now. */
  discount_code?: string | null
}

/** How the new service is named on its panel: `sequential` (left out: the default) by the shop's pattern; `random`, a name drawn now; `custom`, `client_name`. Only a way `shop.name_modes` of `GET /` offers; another is a 422 on `name_mode`. A random name is worked out from the `Idempotency-Key`, so two requests sent with one key at once ask the same; a request repeated with its key asks the name its order was made with, and is not refused for what took that name since. */
export type StoreNameModeInput = ClientNameMode

/** For `custom`: the name the customer typed, 3 to 32 English letters, digits, `_` and `-`. A 422 on `client_name` for another character or length, for one that server's panel does not take (a PasarGuard server: two of `_` and `-` side by side), or for a name a service on that server already has. Should a service (or a client made by hand on the panel) take it before the order is delivered, the service is named by the shop's pattern instead. Ignored for the other ways. */
export type StoreClientNameInput = string

/** A discount code and what the customer wants to buy with it, named as in the checkout's own request. */
export interface StoreDiscountCheckRequest {
  /** As the customer typed it (any case, Persian digits accepted). */
  code: string
  for: OwnOrderType
  /** For a purchase: a plan on sale now (`GET /plans`). For a renewal of a service without a plan: the plan picked (`GET …/renewal-plans`). */
  plan_id?: number
  /** For a purchase: one of the plan's `locations`. A code limited to certain servers is refused until one is picked. */
  server_id?: number
  /** For a renewal or extra: the customer's service. */
  subscription_id?: number
  /** For a renewal: the renewal method, as the renewal would send it (a 422 on `method` for one the shop does not offer). */
  method?: RenewalMethod
  /** For extra traffic: how many GB. For a renewal with a package built (instead of `plan_id`): its GB. */
  gb?: WholeNumberInput
  /** For extra time: how many days. For a renewal with a package built: its days. */
  days?: WholeNumberInput
  /** For extra devices: how many. */
  devices?: WholeNumberInput
}

/** A price before anything is ordered, as the order would be made at it: the base, each discount taken off it, and what is left to pay. */
export interface StoreQuote {
  /** Toman before discounts: the customer's price for what they mean to buy — a plan's price, first-purchase price or agent price; an extra amount's cost; a built service's two prices added. */
  base: string
  /** The automatic discount, if any, then the code. */
  discounts: DiscountLine[]
  /** Toman left to pay, never below zero; 0 means free. */
  amount: string
}

export interface StoreDiscountQuoteResponse {
  quote: StoreQuote
}

export interface StoreCustomServicesResponse {
  custom_services: StoreCustomServices
}

/** «سرویس دلخواه» as a customer meets it: each quantity's tiers, bounds and buttons, the devices and the servers. While nothing is `offered`, each quantity's `tiers` and `options` are empty. */
export interface StoreCustomServices {
  /** Whether a service may be built now (`shop.custom_services` of `GET /`). */
  offered: boolean
  /** The GB: `from` and the bounds in GB, a tier's `price` per GB. */
  traffic: StoreCustomQuantity
  /** The days: `from` and the bounds in days, a tier's `price` per day. */
  days: StoreCustomQuantity
  /** Devices at once on a built service; 0 — unlimited. */
  devices: number
  /** The servers a service may be built on now, in order; empty while nothing is offered. */
  servers: NamedRef[]
}

/** One quantity of a built service. Any whole amount from `min` to `max` may be chosen; it is priced whole at the `price` of the last tier whose `from` it reaches (50 with tiers from 1 at 3000 and from 50 at 2500 costs 50 × 2500). The tiers start at `min`: none prices an amount outside the bounds. */
export interface StoreCustomQuantity {
  /** The smallest `from` first; empty while it is not priced, or nothing is offered. */
  tiers: PriceTier[]
  min: number
  max: number
  /** The amounts offered as buttons, smallest first, each within the bounds; empty while nothing is offered. */
  options: number[]
}

/** A service the customer would build. */
export interface StoreCustomQuoteRequest {
  /** How many GB, within the bounds of `GET /custom-services`. */
  gb: WholeNumberInput
  /** How many days, within the bounds of `GET /custom-services`. */
  days: WholeNumberInput
  /** One of `servers` of `GET /custom-services`, once picked: a code limited to certain servers holds only then. */
  server_id?: number
  /** A discount code to check with it (any case, Persian digits accepted). Blank, null or left out for none. */
  discount_code?: string | null
}

export interface StoreCustomQuoteResponse {
  /** What the service would give and cost: its GB, days and devices, each quantity's price, and the price as a `StoreQuote` (`base` is the two prices added). */
  quote: {
    gb: number
    days: number
    /** Devices at once on the service; 0 — unlimited. */
    devices: number
    /** The server named; null for none. */
    server_id: number | null
    /** Toman: the GB at their tier's rate. */
    traffic_price: string
    /** Toman: the days at their tier's rate. */
    days_price: string
    /** Toman before discounts: the customer's price for what they mean to buy — a plan's price, first-purchase price or agent price; an extra amount's cost; a built service's two prices added. */
    base: string
    /** The automatic discount, if any, then the code. */
    discounts: DiscountLine[]
    /** Toman left to pay, never below zero; 0 means free. */
    amount: string
  }
}

/** A service the customer builds, the server to build it on, how to name it, and the payment method. */
export interface StoreCustomOrderRequest {
  /** How many GB, within the bounds of `GET /custom-services`. */
  gb: WholeNumberInput
  /** How many days, within the bounds of `GET /custom-services`. */
  days: WholeNumberInput
  /** One of `servers` of `GET /custom-services`; it may be left out while there is only one. */
  server_id?: number
  /** A payment method for purchases (`GET /payment-methods?for=purchase`). */
  method_id: number
  name_mode?: StoreNameModeInput
  client_name?: StoreClientNameInput
  /** A discount code the customer typed (any case, Persian digits accepted), applied after the automatic discount. A 422 on `discount_code` when it does not apply to this order. Blank, null or left out for none. Each code that does not apply uses one of the customer's 20 tries an hour (then a 429). A repeated request with the same key returns its order, whatever the code says now. */
  discount_code?: string | null
}

/** A service's renewal: the payment method, the plan (or a package built) for a service without one, and the renewal method. */
export interface StoreRenewalRequest {
  /** A payment method for renewals (`GET /payment-methods?for=renewal`). */
  method_id: number
  /** How the renewal adds its plan, one of the preview's `methods`; left out, the first of them. One the shop does not offer is a 422 on `method`. With an `Idempotency-Key`, a retry by another method than the key's order is a 422 on `idempotency_key`. */
  method?: RenewalMethod
  /** For a service without a plan (`picks_plan`): the plan to renew it on, one of `GET …/renewal-plans` (a 422 on `plan_id` when missing or not among them); it becomes the service's plan. For a service with its own plan it may be left out; sent, it must be that plan (a 422 on `plan_id` otherwise), so a page drawn before the service's first renewal never pays for a plan it did not show. With an `Idempotency-Key`, a retry naming another plan than the key's order is a 422 on `idempotency_key`. */
  plan_id?: number
  /** For a service without a plan, instead of `plan_id` while the builder is offered for it (`custom_services` of `GET …/renewal-plans`): the GB of a package the customer builds, within the bounds of `GET /custom-services`, with `days`. The renewal is priced by the builder's tiers, gives the builder's devices, and leaves the service without a plan. A 422 on `status` while the builder is not offered; on `gb` or `days` as the builder refuses them, or for a service renewed on its own plan; on `plan_id` sent with them. */
  gb?: WholeNumberInput
  /** With `gb`: the days of that package, within the bounds of `GET /custom-services`. */
  days?: WholeNumberInput
  /** A discount code the customer typed (any case, Persian digits accepted), applied after the automatic discount. A 422 on `discount_code` when it does not apply to this order. Blank, null or left out for none. Each code that does not apply uses one of the customer's 20 tries an hour (then a 429). A repeated request with the same key returns its order, whatever the code says now. */
  discount_code?: string | null
}

/** Extra traffic for a service: how much, and the payment method. */
export interface StoreExtraTrafficRequest {
  /** Whole GB, as `GET …/extras` offers: one of its amounts, or within its `custom` range. */
  gb: WholeNumberInput
  /** A payment method for extra traffic (`GET /payment-methods?for=extra_traffic`). */
  method_id: number
  /** A discount code the customer typed (any case, Persian digits accepted), applied after the automatic discount. A 422 on `discount_code` when it does not apply to this order. Blank, null or left out for none. Each code that does not apply uses one of the customer's 20 tries an hour (then a 429). A repeated request with the same key returns its order, whatever the code says now. */
  discount_code?: string | null
}

/** Extra time for a service: how many days, and the payment method. */
export interface StoreExtraTimeRequest {
  /** Whole days, as `GET …/extras` offers: one of its amounts, or within its `custom` range. */
  days: WholeNumberInput
  /** A payment method for extra time (`GET /payment-methods?for=extra_time`). */
  method_id: number
  /** A discount code the customer typed (any case, Persian digits accepted), applied after the automatic discount. A 422 on `discount_code` when it does not apply to this order. Blank, null or left out for none. Each code that does not apply uses one of the customer's 20 tries an hour (then a 429). A repeated request with the same key returns its order, whatever the code says now. */
  discount_code?: string | null
}

/** Extra devices for a service: how many, and the payment method. */
export interface StoreExtraDevicesRequest {
  /** Whole devices, as `GET …/extras` offers: one of its amounts, or within its `custom` range. */
  devices: WholeNumberInput
  /** A payment method for extra devices (`GET /payment-methods?for=extra_devices`). */
  method_id: number
  /** A discount code the customer typed (any case, Persian digits accepted), applied after the automatic discount. A 422 on `discount_code` when it does not apply to this order. Blank, null or left out for none. Each code that does not apply uses one of the customer's 20 tries an hour (then a 429). A repeated request with the same key returns its order, whatever the code says now. */
  discount_code?: string | null
}

export interface StoreExtrasResponse {
  extras: {
    /** Extra traffic in GB; null when none can be bought now. */
    traffic: StoreExtraOffer | null
    /** Extra time in days; null when none can be bought now. */
    time: StoreExtraOffer | null
    /** Extra devices (the IP limit); null when none can be bought now. Their prices are for the days left of the period in use (`days`), which they last. */
    devices: StoreExtraOffer | null
  }
}

/** What a service can buy of one extra: the preset amounts with their prices and, when there is a unit price, any amount within `custom`. Devices are priced for 30 days and charged for the days left of the period in use (`days`), which they last: every price here is already that share, rounded up to whole Toman. */
export interface StoreExtraOffer {
  /** Toman per GB, per day or per device (for the days left of the period in use); null when only the preset amounts are sold. A custom count of devices is prorated as a whole, so it may cost a few Toman less than this times the count. */
  price: string | null
  /** The preset amounts, smallest first. */
  options: {
    /** GB, days or devices. */
    quantity: number
    /** Its price in Toman. */
    price: string
  }[]
  /** Any amount in this range can be bought at `price` per unit; null when only the preset amounts are sold. */
  custom: {
    min: number
    max: number
  } | null
  /** For devices: the days their prices are charged for, which they last — what is left of the period in use (whole days, at least 1) until its end date, or until a renewal bought before then begins; its whole term while it waits for its first connection; or 30 for one that never ends. Null for traffic and time. */
  days: number | null
}

/** A wallet top-up: how much, and the payment method. */
export interface StoreTopUpRequest {
  /** Whole Toman, within the `top_up` limits of `GET /wallet`. As a customer types it (a JSON number or text; Persian digits, thousands separators and «تومان» accepted), or in the API's own amount format ("50000.00", with a zero fraction). */
  amount: WholeNumberInput
  /** A payment method for top-ups, any but the wallet itself (`GET /payment-methods?for=wallet_topup`). */
  method_id: number
}

/** A card transfer's receipt, as a browser form uploads it. */
export interface StoreReceiptRequest {
  /** The picture: a JPEG, PNG or WebP (checked by its content), at most 10 MB. */
  file: Blob
  /** An optional note for support, at most 1024 characters; blank for none. */
  note?: string
}

/** The outcome of a checkout (a purchase, renewal, extra traffic, time or devices, or top-up). A checkout that paid nothing is a refusal instead: a wallet short of the price (a 422 on `method_id`, saying how much is missing), or an order paid or closed at the same moment elsewhere (a 409). */
export interface StoreCheckout {
  /** `settled`: paid at once (by the wallet). The order is `fulfilled` (the service is in `subscription`) or `failed` (the money is taken and support will finish it; the order's internal notes are never shown). Also returned when a repeated request's order was paid since (by card too) and is finished: delivered, failed or `refunded`. `transfer`: transfer to the card in `transfer`, then upload the receipt (`POST /payments/{id}/receipt`); the payment waits for the receipt, or support has it. `processing`: paid, and the delivery is in progress. */
  outcome: 'settled' | 'transfer' | 'processing'
  order: StoreOrder
  /** For `transfer`: where to send the money, and the payment the receipt is for. Null otherwise. */
  transfer: {
    /** The payment to upload the receipt for. */
    payment_id: number
    /** The amount to transfer, in Toman. */
    amount: string
    /** The card number: 16 digits, no separators. */
    card: string
    /** The card holder's name. */
    holder: string
    /** The shop's payment instructions; null if none. */
    instructions: string | null
  } | null
  /** Once delivered: the service a purchase created, a renewal renewed or extras were added to. Null otherwise, and on a repeated request once the service is no longer the customer's (handed to another customer). */
  subscription: StoreSubscription | null
}

export interface StoreCheckoutResponse {
  checkout: StoreCheckout
}

/** A preview of a service's renewal: on its own plan (or, for a service without one, the plan picked — or the package built, `custom`), at its price today, by a renewal method among those the shop offers, and the service as the renewal would leave it by that method. It uses the last numbers read from the panel; the renewal itself reads the panel when it is paid. */
export interface StoreRenewal {
  /** The method this preview — and `after` — is by: the one asked for (`method`), else the first the shop offers. */
  method: RenewalMethod
  /** The methods the shop offers, in this order; the price is the same by each. With more than one, let the customer pick and send it as `method` to the preview and the renewal; with one, there is nothing to pick. */
  methods: RenewalMethod[]
  /** The service's own plan, or the one picked (`plan_id`) for a service without one, which the renewal uses: what a renewal gives, as the catalogue says it (`StoreRenewalPlan` without the prices). Null for a package built (`custom`). */
  plan: {
    id: number
    name: string
    description: string | null
    /** A note to show at checkout, as plain text; null for none. */
    checkout_note: string | null
    /** Toman. */
    price: string
    /** Gigabytes, decimals allowed; 0 for unlimited. */
    traffic_gb: number
    /** Whole days, rounded up; 0 never ends. */
    duration_days: number
    /** Hours; 0 never ends. */
    duration_hours: number
    /** Devices at once the renewal gives; 0 for no limit — whatever the service has now, extra devices bought included. A running service renewed by `extend` or `reset_traffic` keeps its devices until the period in use ends, and has these from then; any other has them at once (one still waiting for its first connection with the extra devices bought for it on top). */
    devices: number
  } | null
  /** The package a service without a plan is renewed with instead (`gb` and `days`): what it gives — its GB, its days and the builder's devices — and each quantity's price by the builder's tiers (`GET /custom-services`). Null for a renewal on a plan. */
  custom: {
    gb: number
    days: number
    /** Devices at once it gives; 0 — unlimited. From when, as a plan's `devices`. */
    devices: number
    /** Toman: the GB at their tier's rate. */
    traffic_price: string
    /** Toman: the days at their tier's rate. */
    days_price: string
  } | null
  /** What the renewal costs the customer, in Toman: the plan's agent price for an agent or a «همکار» group member, else its price — or the package's two prices added (only a sale of every plan reaches it, and a code limited to certain plans never does) —, minus their largest automatic discount (`discounts`). A discount code is applied on top of this when paying (`discount_code`). */
  price: string
  /** The automatic discount in `price`: none, or one. */
  discounts: DiscountLine[]
  /** The service as the renewal would leave it by `method`, in StoreSubscription's shapes. By `extend` remaining days carry over, and a period's unused traffic expires when the period ends unless the shop carries it over (see `next_period`); by `reset_traffic` remaining days carry over and the traffic starts afresh; by `reset_time` the term starts afresh and the traffic left carries over. */
  after: {
    term: StoreServiceTerm
    traffic: StoreServiceTraffic
    /** The current period, whose unused traffic expires when it ends; null when nothing expires. */
    next_period: StoreNextPeriod | null
    /** The renewal's device limit, when it waits for the current period's end; null when the service has it at once, or it is the one it has. */
    next_devices: NextDevices | null
  }
}

export interface StoreRenewalResponse {
  renewal: StoreRenewal
}

/** A plan a service can be renewed on: what a renewal on it gives, its price, and what the renewal costs the customer today. */
export interface StoreRenewalPlan {
  /** The `plan_id` to renew on (`…/renewal`). */
  id: number
  name: string
  description: string | null
  /** A note to show at checkout, as plain text the site must escape; null for none. */
  checkout_note: string | null
  /** The plan's price, in Toman. */
  price: string
  /** What renewing on it costs the customer today, in Toman: the plan's agent price for an agent or a «همکار» group member, else its price, minus their largest automatic discount (`discounts`). A discount code is applied on top of this when paying. The preview (`GET …/renewal?plan_id=`) says the same. */
  renewal_price: string
  /** The automatic discount in `renewal_price`: none, or one. */
  discounts: DiscountLine[]
  /** Gigabytes a renewal adds, decimals allowed; 0 for unlimited. */
  traffic_gb: number
  /** Whole days, rounded up; 0 never ends. `duration_hours` is exact. */
  duration_days: number
  /** Hours; 0 never ends. */
  duration_hours: number
  /** Devices at once once renewed on it; 0 for no limit. */
  devices: number
}

export interface StoreRenewalPlansResponse {
  /** In the catalogue's order; empty while the service cannot be renewed now: not at all, not yet (its renewal window), or a renewal of it under way. */
  plans: StoreRenewalPlan[]
  /** Whether the service may be renewed instead with a package the customer builds («🛠 تمدید با سرویس دلخواه»): it has no plan, is no test account, may be renewed now (as `plans`), and the shop's builder is offered (`shop.custom_services` of `GET /`). Then send `gb` and `days` — within the bounds and tiers of `GET /custom-services` — to `…/renewal` in place of `plan_id`. */
  custom_services: boolean
}

/** The customer's wallet. Amounts in Toman, as decimal strings. */
export interface StoreWalletResponse {
  /** Below zero is an agent's debt. */
  balance: string
  /** How far below zero an agent's wallet may go; '0.00' for everyone else. */
  credit: string
  /** What the wallet can pay now: the balance plus the credit. */
  spendable: string
  /** The top-up limits and presets, as the bot's «افزایش موجودی» offers them. `POST /wallet/top-up` accepts these amounts exactly as written here. */
  top_up: {
    /** The smallest top-up allowed. */
    min: string
    /** The largest top-up allowed. */
    max: string
    /** The amounts offered as buttons, ascending. */
    presets: string[]
  }
}

/** A line of the customer's wallet ledger. */
export interface StoreWalletTransaction {
  id: number
  type: WalletTransactionType
  /** Toman. */
  amount: string
  /** The balance after this line. */
  balance_after: string
  /** What it was for («شارژ کیف پول (سفارش #12)»). */
  description: string | null
  created_at: string
}

export interface StoreWalletTransactionsResponse {
  transactions: StoreWalletTransaction[]
  meta: PageMeta
}

export interface StoreGiftCodeRequest {
  /** The gift code as the customer typed it. */
  code: string
}

export interface StoreGiftCodeResponse {
  /** What the code gave the customer. */
  gift: {
    kind: GiftCodeKind
    /** What it gave, as the customer reads it: «۵۰٬۰۰۰ تومان شارژ کیف پول», «سرویس «یک‌ماهه»». */
    words: string
    /** The new wallet balance in Toman; null for a plan code. */
    balance: string | null
    /** For a plan code: the order that delivers its service (delivered, or failed and waiting for support's retry, like any order). Null for a Toman code. */
    order: StoreOrder | null
    /** For a plan code: the service, once delivered. */
    subscription: StoreSubscription | null
  }
}

/** The customer's referral details. */
export interface StoreReferralResponse {
  /** The program is on: each new customer their code brings earns them a share of that customer's payments. */
  enabled: boolean
  /** The share, in percent. */
  rate: number
  /** The share for renewals, in percent, used instead of `rate`; 0: renewals earn nothing. */
  renewal_rate: number
  /** Only each referred customer's first payment earns a share. */
  first_only: boolean
  /** «هدیه شروع»: Toman added to their wallet for each new customer their code brings, and to the new customer's wallet, once per new customer. Null when there is none. Nothing is given while support has an open alert on their referrals. */
  start_gift: {
    /** Toman for their wallet ("0.00": nothing). */
    referrer: string
    /** Toman for the new customer's wallet ("0.00": nothing). */
    invitee: string
    trigger: ReferralStartTrigger
  } | null
  /** «جایزه دعوت»: each time `every` more of the customers their code brought have bought, they get a free service of `plan`. Null when there is none. */
  reward: {
    every: number
    plan: NamedRef
  } | null
  /** Their invite code, which sign-ins accept as `referral_code` (the site can build its own invite link with it). */
  code: string
  /** The bot's invite link with their code (https://t.me/<bot>?start=ref_<code>); null while the bot's username is unknown. */
  bot_link: string | null
  /** How many customers their code brought. */
  invited: number
  /** The total of the commissions credited to them, in Toman. */
  earned: string
  /** How many free services they got for their invitations («جایزه دعوت»). */
  rewards: number
}

/** The kind of notice; one for each message the bot sends a customer on its own. Payments: approved (the service delivered or the wallet charged, or the delivery failed and support will finish it), receipt rejected, order cancelled by support, payment refunded to the wallet, wallet top-up refunded, payment reminder. Services: switched off, back on, deleted, moved (with its new link), given away by support («انتقال سرویس», to its previous owner) or received from another customer (to its new one), given days or traffic; reminders («یادآوری») of a service ending soon or running low on traffic; auto-renewal («تمدید خودکار») done, short of money, or paid but not delivered. Referrals: a new customer joined through their link, a commission, a start gift («هدیه شروع», for the referrer or the new customer), a free service for invitations («جایزه دعوت»). Agency: request approved or rejected, agency changed or ended, the bot's traffic too low for an order. Account security: two-factor sign-in turned off (by support or by them) or on, a way in added or removed, a password set, another account merged into it, the second step locked after failed codes, emailed codes locked after failures (these go to every channel they have: Telegram and email). Tickets: support's answer, and closing. Rewards: moved up a loyalty tier («سطح وفاداری»), cashback earned («کش‌بک»), won the nightly draw («قرعه‌کشی شبانه»). */
export type StoreNoticeType =
  | 'payment_settled'
  | 'payment_rejected'
  | 'order_cancelled'
  | 'payment_refunded'
  | 'topup_refunded'
  | 'payment_reminder'
  | 'service_disabled'
  | 'service_enabled'
  | 'service_deleted'
  | 'service_moved'
  | 'service_transferred'
  | 'service_received'
  | 'service_granted'
  | 'expiry_reminder'
  | 'traffic_reminder'
  | 'auto_renewed'
  | 'auto_renew_short'
  | 'renewal_failed'
  | 'referral_joined'
  | 'referral_commission'
  | 'referral_start_gift'
  | 'referral_reward'
  | 'agency_approved'
  | 'agency_rejected'
  | 'agency_changed'
  | 'agency_revoked'
  | 'agency_traffic_short'
  | 'two_factor_disabled'
  | 'way_in_added'
  | 'way_in_removed'
  | 'password_changed'
  | 'two_factor_enabled'
  | 'account_merged'
  | 'second_step_locked'
  | 'email_codes_failed'
  | 'ticket_answered'
  | 'ticket_closed'
  | 'loyalty_tier_up'
  | 'cashback_earned'
  | 'lottery_won'

/** A notification the bot sent the customer, with the same text as the Telegram message (sent, or that would have been sent). */
export interface StoreNotification {
  id: number
  type: StoreNoticeType
  /** The text without markup: line breaks kept, a premium emoji as its plain emoji. */
  text: string
  /** The text as safe HTML: only b/strong, i/em, u/ins, s/strike/del, code, pre, blockquote, a spoiler as <span class="tg-spoiler">, links to http(s) addresses (<a href> with no other attribute) and <br> for line breaks; all text is escaped. Safe to insert into a page as is. */
  html: string
  /** What it is about, for linking: one of their orders (`GET /orders/{id}`; a payment notice points to its order), services (`GET /subscriptions/{id}`) or tickets (`GET /tickets/{id}`). Null for notices about their account, a deleted service, or someone else's row. Only a service may be gone since (a 404, when support deleted it); orders and tickets stay (an unpaid order expires as `cancelled`). */
  subject: {
    type: 'order' | 'subscription' | 'ticket'
    id: number
  } | null
  /** Marked as read (`POST /notifications/read`). */
  read: boolean
  created_at: string
}

export interface StoreNotificationsResponse {
  notifications: StoreNotification[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    /** How many of their notifications are unread, regardless of the page. */
    unread: number
  }
}

/** The notifications to mark as read, by id (at most 100). Without `ids` (`{}`), all of them. */
export interface StoreNotificationsReadRequest {
  ids?: number[]
}

export interface StoreUnreadResponse {
  /** How many of their notifications are still unread. */
  unread: number
}

/** One of the customer's support tickets. */
export interface StoreTicket {
  id: number
  subject: string
  status: TicketStatus
  /** Their service it is about, by its name; null for none, or when the service was deleted. */
  subscription: NamedRef | null
  /** When its latest message was written; the list is sorted by it. */
  last_message_at: string
  /** Support wrote since they last read it (`POST /tickets/{id}/read` marks it read, as does viewing it in the bot). */
  unread: boolean
  /** Their rating after it closed, 1 to 5; null for none, and after it is reopened. */
  rating: number | null
  created_at: string
  /** When it was closed; null unless it is closed (reopening clears it). */
  closed_at: string | null
}

/** A message in their ticket, from them or from support (the support person is never named; it shows as «پشتیبانی»). */
export interface StoreTicketMessage {
  id: number
  author: TicketAuthor
  /** Its text as plain text, with line breaks kept. */
  body: string
  /** Its picture (`GET /tickets/{id}/messages/{message}/attachment`); null for none. */
  attachment: TicketAttachment | null
  created_at: string
}

/** One of the customer's support tickets, with its whole conversation, oldest message first. */
export interface StoreTicketDetail {
  id: number
  subject: string
  status: TicketStatus
  /** Their service it is about, by its name; null for none, or when the service was deleted. */
  subscription: NamedRef | null
  /** When its latest message was written; the list is sorted by it. */
  last_message_at: string
  /** Support wrote since they last read it (`POST /tickets/{id}/read` marks it read, as does viewing it in the bot). */
  unread: boolean
  /** Their rating after it closed, 1 to 5; null for none, and after it is reopened. */
  rating: number | null
  created_at: string
  /** When it was closed; null unless it is closed (reopening clears it). */
  closed_at: string | null
  /** The note they left with their rating. */
  rating_note: string | null
  messages: StoreTicketMessage[]
}

export interface StoreTicketsResponse {
  tickets: StoreTicket[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    /** How many of all their tickets have unread messages from support, regardless of the filters (for a badge). */
    unread: number
  }
}

export interface StoreTicketResponse {
  ticket: StoreTicketDetail
}

/** A new ticket: its subject, its first message, and optionally the customer's service it is about. To attach a picture, send a form instead (StoreTicketUploadRequest). */
export interface StoreTicketRequest {
  /** 3 to 120 characters. */
  subject: string
  /** 1 to 4000 characters; line breaks are kept. */
  body: string
  /** One of the customer's services (`GET /subscriptions`). Left out, null or blank: about no service. */
  subscription_id?: WholeNumberInput | null
}

/** A new ticket with a picture in its first message, as a browser form uploads it. A form without a file opens it without a picture. */
export interface StoreTicketUploadRequest {
  /** 3 to 120 characters. */
  subject: string
  /** 1 to 4000 characters; line breaks are kept. */
  body: string
  /** One of the customer's services (`GET /subscriptions`). Left out, null or blank: about no service. */
  subscription_id?: WholeNumberInput | null
  /** The picture: a JPEG, PNG or WebP (checked by its content), at most 10 MB. Optional. */
  file?: Blob
}

/** The customer's rating of a closed ticket. It replaces any earlier rating. */
export interface StoreTicketRatingRequest {
  /** 1 to 5. */
  rating: WholeNumberInput
  /** An optional note, at most 500 characters; blank for none. */
  note?: string
}

/** An approved review, as the website shows it: the writer's text and choices as plain text (the website must escape it), with nothing about who wrote it or who approved it. */
export interface StoreReview {
  id: number
  /** The name it is signed with. */
  name: string
  /** 1 to 5. */
  rating: number
  /** Its text, with line breaks as written. */
  body: string
  /** A line on where the service is used («ایرانسل · اندروید · Happ»); null for none. */
  context: string | null
  /** The key of the avatar they picked from the website's own set; null for none (the website shows its default). */
  avatar: string | null
  /** When it was written. */
  created_at: string
}

export interface StoreReviewsResponse {
  reviews: StoreReview[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
    /** Totals over all approved reviews, regardless of the page. */
    summary: {
      count: number
      /** The average rating, to two decimals; null while there are no reviews. */
      average: number | null
    }
  }
}

/** A review written on the website. Every refusal is returned at once, under its field. */
export interface StoreReviewRequest {
  /** The name to sign it with, shown with the review: 2 to 64 characters, one line. */
  name: string
  /** 1 to 5. */
  rating: WholeNumberInput
  /** 10 to 600 characters; line breaks are kept. */
  body: string
  /** A line on where the service is used, such as «ایرانسل · اندروید · Happ»; at most 100 characters, blank for none. */
  context?: string
  /** The key of one of the website's own avatars (1 to 32 of a-z, 0-9, - and _, never an address); blank for none. */
  avatar?: string
  /** The captcha widget's token, while the website uses a captcha (`GET /` says which and how to draw it), solved for the operation's action (its `x-captcha`). */
  captcha?: string
}

export interface StoreReviewWrittenResponse {
  review: {
    id: number
    /** `pending`: waiting for support's approval. */
    status: ReviewStatus
  }
}

/** Every write of a panel's API (the owner's `/api/admin`, an agent's `/api/agent`, and `/api/{panel}` for what both have): its method, its path under the panel's API (a path parameter typed as its value: `/plans/${number}`), the body it takes (`undefined`: none) and its answer (`void`: none). lib/api types every POST, PUT, PATCH and DELETE with it. */
export type PanelWrite =
  /** Sign the owner in. The session works in the shop named by `X-Shop`; if that shop does not exist, even the right credentials get a 404. */
  | { method: 'POST'; path: '/auth/login'; body: LoginRequest; answer: SessionResponse }
  /** Start recovering a lost login. Writes a one-time key into the host's files, where only someone with access to them can read it. Asking again while the key is still valid returns the same key. */
  | { method: 'POST'; path: '/auth/recovery/key'; body: undefined; answer: RecoveryKeyResponse }
  /** Set a new panel login with the recovery key. This browser is signed in with it, in the shop the request names, and every other session ends. A wrong key counts as a failed sign-in. */
  | { method: 'POST'; path: '/auth/recovery'; body: RecoveryRequest; answer: SessionResponse }
  /** Sign an agent in with the code from their one-time link. The main bot gives the link; the session works in the agent's own shop. If another agent is signed in in this browser, the request needs `replace: true`; without it the answer is a 409 on `replace` and the link stays unused, so the panel can ask first. */
  | { method: 'POST'; path: '/auth/link'; body: AgentLinkRequest; answer: SessionResponse }
  /** Sign out of this panel. A session of the other panel in the same browser stays signed in. */
  | { method: 'POST'; path: '/auth/logout'; body: undefined; answer: void }
  /** Sign the agent out of every other browser. This browser stays signed in. If this session was itself ended meanwhile from another browser, the answer is a 401. */
  | { method: 'POST'; path: '/auth/sessions/end'; body: undefined; answer: void }
  /** Change the panel's login. Every other session ends; this one stays signed in. */
  | { method: 'PUT'; path: '/auth/credentials'; body: CredentialsRequest; answer: SessionResponse }
  /** Report a panel error to the server log. For errors in the panel's own code, such as a page that failed to render or an unhandled error. */
  | { method: 'POST'; path: '/client-errors'; body: ClientErrorRequest; answer: void }
  /** Set the webhook of every bot. Each webhook is under APP_URL, which must be HTTPS (else a 422 and no bot is changed). Same as `bot:webhook:set`: a bot that fails does not stop the others. */
  | { method: 'POST'; path: '/system/webhook'; body: undefined; answer: WebhooksResponse }
  /** Delete the webhook of every bot. The bots go back to polling, so `bot:poll` must run. Same as `bot:webhook:delete`. */
  | { method: 'DELETE'; path: '/system/webhook'; body: undefined; answer: WebhooksResponse }
  /** Check GitHub for the newest release now. A 502 when GitHub does not answer; the release read before is kept. */
  | { method: 'POST'; path: '/system/update/check'; body: undefined; answer: UpdateResponse }
  /** Start updating to the newest release. A 422 on `version` when it is not the newest release or not newer than the running one; a 409 while an update is already running; a 422 naming what blocks a self-update. */
  | { method: 'POST'; path: '/system/update/start'; body: UpdateStartRequest; answer: UpdateResponse }
  /** Run the update's next step. Does as much of the step as one request has time for: download the release and check its signature, unpack it, check the host, then install it in one request while every other request gets a 503 with `Retry-After`. A refusal is kept on the run (`run.error`). A 409 while another request is working on the update or a scheduled run does not finish in time; a 502 when GitHub does not answer. */
  | { method: 'POST'; path: '/system/update/step'; body: undefined; answer: UpdateResponse }
  /** Cancel the update before installing starts (`run.cancel`). The downloaded and unpacked files are deleted. */
  | { method: 'POST'; path: '/system/update/cancel'; body: undefined; answer: UpdateResponse }
  /** Roll back the last update (`run.rollback`). Puts the replaced version back. Refused once the update changed the database: only a backup can undo that. */
  | { method: 'POST'; path: '/system/update/rollback'; body: undefined; answer: UpdateResponse }
  /** Add a server. Its panel is checked right away. */
  | { method: 'POST'; path: '/servers'; body: ServerRequest; answer: ServerCreatedResponse }
  /** Test a connection before saving it. With `id`, blank secrets are taken from that saved server. */
  | { method: 'POST'; path: '/servers/test'; body: ServerTestRequest; answer: ProbeResponse }
  /** Update a server. */
  | { method: 'PUT'; path: `/servers/${number}`; body: ServerRequest; answer: ServerResponse }
  /** Delete a server. Only a server nothing was sold on. */
  | { method: 'DELETE'; path: `/servers/${number}`; body: undefined; answer: void }
  /** Check a saved server's panel. Also records whether it serves subscription links. */
  | { method: 'POST'; path: `/servers/${number}/test`; body: undefined; answer: ServerCheckResponse }
  /** Read the panel's inbounds again. */
  | { method: 'POST'; path: `/servers/${number}/inbounds/sync`; body: undefined; answer: ServerDetailResponse }
  /** Switch whether an inbound is sold. */
  | { method: 'PATCH'; path: `/servers/${number}/inbounds/${number}`; body: InboundSellableRequest; answer: InboundsResponse }
  /** Save a server's test terms. A test already given keeps the terms it got. */
  | { method: 'PUT'; path: `/servers/${number}/test-account`; body: ServerTestAccountRequest; answer: ServerTestAccountResponse }
  /** Give days and traffic to the services on a server. */
  | { method: 'POST'; path: `/servers/${number}/grants`; body: ServerGrantRequest; answer: ServerGrantResponse }
  /** Apply a grant to the next few services. */
  | { method: 'POST'; path: `/servers/${number}/grants/${number}/run`; body: undefined; answer: ServerGrantResponse }
  /** Stop a grant. Services already given it keep it. */
  | { method: 'POST'; path: `/servers/${number}/grants/${number}/cancel`; body: undefined; answer: ServerGrantResponse }
  /** Give days and traffic to running services. On every server, or only the agents' services, one customer group's, or one server's. */
  | { method: 'POST'; path: '/mass-grants'; body: MassGrantRequest; answer: MassGrantResponse }
  /** Apply a mass gift for a few seconds. The screen keeps calling this while it is open. */
  | { method: 'POST'; path: `/mass-grants/${number}/run`; body: undefined; answer: MassGrantResponse }
  /** Stop a mass gift. What was already given stays. */
  | { method: 'POST'; path: `/mass-grants/${number}/cancel`; body: undefined; answer: MassGrantResponse }
  /** Pause a broadcast that is sending. */
  | { method: 'POST'; path: `/broadcasts/${number}/pause`; body: undefined; answer: BroadcastResponse }
  /** Resume a paused broadcast. The scheduler continues sending it. */
  | { method: 'POST'; path: `/broadcasts/${number}/resume`; body: undefined; answer: BroadcastResponse }
  /** Cancel a broadcast. Messages already sent stay. */
  | { method: 'POST'; path: `/broadcasts/${number}/cancel`; body: undefined; answer: BroadcastResponse }
  /** Unpin a finished pinned broadcast. Starts and returns a new unpin («لغو پین») run. */
  | { method: 'POST'; path: `/broadcasts/${number}/unpin`; body: undefined; answer: BroadcastResponse }
  /** Add a plan. */
  | { method: 'POST'; path: '/plans'; body: PlanRequest; answer: PlanResponse }
  /** Reorder the plans. */
  | { method: 'POST'; path: '/plans/reorder'; body: ReorderRequest; answer: PlansResponse }
  /** Preview a bulk price change. Nothing is saved: returns each plan's prices before and after, and why a plan cannot take the change. A 422 lists every invalid field of the change at once. */
  | { method: 'POST'; path: '/plans/prices/preview'; body: PlanPricesRequest; answer: PlanPricesPreviewResponse }
  /** Raise or lower several plans' prices at once. All or nothing: if any plan cannot take the change (a price below zero, or a first-purchase or agent price no longer below the price), nothing changes and a 422 on `plan_ids` names each such plan. */
  | { method: 'POST'; path: '/plans/prices'; body: PlanPricesRequest; answer: PlansResponse }
  /** Update a plan. */
  | { method: 'PUT'; path: `/plans/${number}`; body: PlanRequest; answer: PlanResponse }
  /** Switch a plan on or off. */
  | { method: 'PATCH'; path: `/plans/${number}`; body: ActivationRequest; answer: PlanResponse }
  /** Delete a plan. Only a plan nothing was sold with. */
  | { method: 'DELETE'; path: `/plans/${number}`; body: undefined; answer: void }
  /** Duplicate a plan. The copy starts switched off. */
  | { method: 'POST'; path: `/plans/${number}/duplicate`; body: undefined; answer: PlanResponse }
  /** Add a plan category. */
  | { method: 'POST'; path: '/plans/categories'; body: PlanCategoryRequest; answer: PlanCategoryResponse }
  /** Reorder the categories. */
  | { method: 'POST'; path: '/plans/categories/reorder'; body: ReorderRequest; answer: PlanCategoriesResponse }
  /** Update a category. */
  | { method: 'PUT'; path: `/plans/categories/${number}`; body: PlanCategoryRequest; answer: PlanCategoryResponse }
  /** Switch a category on or off. */
  | { method: 'PATCH'; path: `/plans/categories/${number}`; body: ActivationRequest; answer: PlanCategoryResponse }
  /** Delete a category. Its plans become uncategorised. */
  | { method: 'DELETE'; path: `/plans/categories/${number}`; body: undefined; answer: void }
  /** Ban or unban a customer. On the website's admin API, an admin's account (their own included) or an agent's can only be changed from a panel (403). */
  | { method: 'PATCH'; path: `/users/${number}`; body: UserStatusRequest; answer: UserResponse }
  /** Make a customer a bot admin, or take the role back. An admin can use /broadcast, the report group's buttons and, while the website lets admins in, its admin API. */
  | { method: 'PUT'; path: `/users/${number}/role`; body: UserRoleRequest; answer: UserResponse }
  /** Set a customer's personal discount («تخفیف اختصاصی»). It lasts until changed; 0 removes it. Logged with who set it. Panels only. */
  | { method: 'PUT'; path: `/users/${number}/discount`; body: UserDiscountRequest; answer: UserResponse }
  /** Credit or debit a customer's wallet. */
  | { method: 'POST'; path: `/users/${number}/wallet`; body: WalletAdjustmentRequest; answer: WalletAdjustmentResponse }
  /** Set a customer's groups. Replaces their groups with the ones listed. */
  | { method: 'PUT'; path: `/users/${number}/groups`; body: UserGroupsRequest; answer: UserResponse }
  /** Turn off a customer's two-factor sign-in on the website. For a customer who lost the phone it was on: afterwards the password alone signs them in. The customer is notified and the log records who did it. A 422 when it is not on. */
  | { method: 'POST'; path: `/users/${number}/two-factor/disable`; body: undefined; answer: CustomerAccountResponse }
  /** Sign a customer out of the website on every device. All their sessions end; the log records who did it. */
  | { method: 'POST'; path: `/users/${number}/sessions/end`; body: undefined; answer: void }
  /** Add a customer group. */
  | { method: 'POST'; path: '/customer-groups'; body: CustomerGroupRequest; answer: CustomerGroupResponse }
  /** Reorder the customer groups. */
  | { method: 'POST'; path: '/customer-groups/reorder'; body: ReorderRequest; answer: CustomerGroupsResponse }
  /** Update a customer group. */
  | { method: 'PUT'; path: `/customer-groups/${number}`; body: CustomerGroupRequest; answer: CustomerGroupResponse }
  /** Delete a customer group. Its members just leave it. */
  | { method: 'DELETE'; path: `/customer-groups/${number}`; body: undefined; answer: void }
  /** Retry delivering a paid order whose delivery failed or stalled. */
  | { method: 'POST'; path: `/orders/${number}/retry`; body: undefined; answer: OrderResponse }
  /** Cancel an unpaid order. Its open payments are cancelled with it, including one whose receipt waits for review. */
  | { method: 'POST'; path: `/orders/${number}/cancel`; body: NoteRequest; answer: OrderResponse }
  /** Approve a payment and deliver what it paid for. With a receipt in review, or by hand without one. */
  | { method: 'POST'; path: `/payments/${number}/approve`; body: undefined; answer: PaymentResponse }
  /** Reject a receipt. */
  | { method: 'POST'; path: `/payments/${number}/reject`; body: NoteRequest; answer: PaymentResponse }
  /** Cancel an unpaid payment and its open order. If another payment of the order has a receipt waiting for review, only this payment is cancelled and the order stays open for that review. */
  | { method: 'POST'; path: `/payments/${number}/cancel`; body: NoteRequest; answer: PaymentResponse }
  /** Remind the customer to pay. The answer says whether the reminder reached them. */
  | { method: 'POST'; path: `/payments/${number}/remind`; body: undefined; answer: ReminderResponse }
  /** Retry the delivery of what a paid payment bought. */
  | { method: 'POST'; path: `/payments/${number}/retry`; body: undefined; answer: PaymentResponse }
  /** Refund a paid payment. For a purchase, a renewal or an agent's traffic, the amount goes to the customer's wallet; a wallet top-up is taken back out of the wallet. What the order gave a service is taken back first: a purchase's service removed (its client off its panel, the service off the shop), a renewal's or an extra's term and traffic taken off the service and its device limit put back (an extra's devices while their period lasts), once; in an agent's shop the traffic it drew goes back to the agent. A 422 on `note` over 190 characters (it becomes the wallet line's description) or on `status`, and also when what the order added is gone or cannot be taken back (a top-up already spent, an agent's traffic already sold, a service whose panel fails, a purchase's service another paid or open order holds, a service handed to another customer since): then nothing is refunded. A service switched off stays off. The service's own refusals (busy, too many panel operations) come as they are. */
  | { method: 'POST'; path: `/payments/${number}/refund`; body: RefundRequest; answer: PaymentResponse }
  /** Refresh a service's usage and term from its panel. */
  | { method: 'POST'; path: `/subscriptions/${number}/sync`; body: undefined; answer: SubscriptionResponse }
  /** Add days and traffic to a service, on top of what it has. */
  | { method: 'POST'; path: `/subscriptions/${number}/extend`; body: SubscriptionExtensionRequest; answer: SubscriptionResponse }
  /** Switch a service off on its panel. */
  | { method: 'POST'; path: `/subscriptions/${number}/disable`; body: NoteRequest; answer: SubscriptionResponse }
  /** Switch a service back on. */
  | { method: 'POST'; path: `/subscriptions/${number}/enable`; body: undefined; answer: SubscriptionResponse }
  /** Move a service to another server. It keeps its remaining days and traffic. */
  | { method: 'POST'; path: `/subscriptions/${number}/move`; body: SubscriptionMoveRequest; answer: SubscriptionResponse }
  /** Delete a service from its panel and from the shop. */
  | { method: 'POST'; path: `/subscriptions/${number}/delete`; body: SubscriptionDeleteRequest; answer: void }
  /** Hand a service to another customer of the shop («انتقال سرویس»), found by their numeric Telegram id — support may whatever `transfer_enabled` says. The service keeps its link, term and traffic; its «تمدید خودکار» goes off; both customers are told in the bot — the new one, where its panel can change the link, that the previous one keeps it until they do; a renewal or an extra of it the previous customer left unpaid is cancelled, noted «سرویس این سفارش به حساب دیگری منتقل شد» (one whose receipt waits for review stays, and fails at its delivery), and the previous customer's message names them; the report group's «خریدها» topic hears of it; and its client on the panel takes the new customer's Telegram id and comment, its name unchanged (a panel that fails that is logged, and the transfer stands). A 422 on `status` for a free test account, a service gone from its panel, or one being changed on its panel right then (a delivery under way: try again in a moment); on `telegram_id` for an id that is no number, that names no customer of this shop (they must have started the bot once), the service's own customer, a banned account, or the admin themselves. A 403 for a shop admin's own service. */
  | { method: 'POST'; path: `/subscriptions/${number}/transfer`; body: SubscriptionTransferRequest; answer: SubscriptionResponse }
  /** Answer a ticket as support. Send JSON, or a form to attach a picture. The ticket becomes answered (a closed ticket reopens), the customer is notified (in Telegram with the picture, else by email) and the report group gets the answer. A 422 on `body` (1 to 4000 characters), on `file` (a JPEG, PNG or WebP, at most 10 MB) or on `status` once the ticket has 200 messages. A 503 while the host has less than 200 MB of free disk, or once the shop's uploads for the day reach 1024 MB. A form larger than PHP accepts is a 413; a form field that is not UTF-8 is a 422 on that field. */
  | { method: 'POST'; path: `/tickets/${number}/messages`; body: TicketMessageRequest | TicketMessageUploadRequest; answer: TicketResponse }
  /** Close a ticket. The customer and the report group are notified. A 422 on `status` when it is already closed. */
  | { method: 'POST'; path: `/tickets/${number}/close`; body: undefined; answer: TicketResponse }
  /** Reopen a closed ticket. It waits for support again, and its rating is cleared. A 422 on `status` when it is not closed. */
  | { method: 'POST'; path: `/tickets/${number}/reopen`; body: undefined; answer: TicketResponse }
  /** Approve a pending or rejected review. The website shows it from now on (the Store API's `GET /reviews`). A 403 for an admin's own review; a 422 on `status` when it is already approved. */
  | { method: 'POST'; path: `/reviews/${number}/approve`; body: undefined; answer: ReviewResponse }
  /** Reject a pending or approved review. It is kept but no longer shown on the website. A 403 for an admin's own review; a 422 on `status` when it is already rejected. */
  | { method: 'POST'; path: `/reviews/${number}/reject`; body: undefined; answer: ReviewResponse }
  /** Delete a review, whatever its status. A 403 for an admin's own review. */
  | { method: 'DELETE'; path: `/reviews/${number}`; body: undefined; answer: void }
  /** Add a payment method. Names a driver and fills in that driver's form. */
  | { method: 'POST'; path: '/payment-methods'; body: PaymentMethodCreateRequest; answer: PaymentMethodResponse }
  /** Reorder the payment methods. */
  | { method: 'POST'; path: '/payment-methods/reorder'; body: ReorderRequest; answer: PaymentMethodsResponse }
  /** Update a payment method. Sends its driver's form again. */
  | { method: 'PUT'; path: `/payment-methods/${number}`; body: PaymentMethodUpdateRequest; answer: PaymentMethodResponse }
  /** Switch a payment method on or off. */
  | { method: 'PATCH'; path: `/payment-methods/${number}`; body: PaymentMethodSwitchRequest; answer: PaymentMethodResponse }
  /** Delete a payment method. The wallet cannot be deleted. */
  | { method: 'DELETE'; path: `/payment-methods/${number}`; body: undefined; answer: void }
  /** Save one group of the bot's settings. */
  | { method: 'PUT'; path: `/bot/settings/${BotSettingsGroup}`; body: BotSettingsRequest; answer: BotSettingsSaved }
  /** Upload a custom QR background. */
  | { method: 'POST'; path: '/bot/qr-background'; body: QrBackgroundRequest; answer: QrBackgroundResponse }
  /** Reset the QR background to the default picture. */
  | { method: 'DELETE'; path: '/bot/qr-background'; body: undefined; answer: QrBackgroundResponse }
  /** Add a required channel by its link, @handle or id. */
  | { method: 'POST'; path: '/bot/channels'; body: BotChannelRequest; answer: BotChannelResponse }
  /** Reorder the required channels. */
  | { method: 'POST'; path: '/bot/channels/reorder'; body: ReorderRequest; answer: BotChannelsResponse }
  /** Check a channel with Telegram again. */
  | { method: 'POST'; path: `/bot/channels/${number}/check`; body: undefined; answer: BotChannelResponse }
  /** Remove a required channel. */
  | { method: 'DELETE'; path: `/bot/channels/${number}`; body: undefined; answer: void }
  /** Disconnect the report group. The bot stays in the group. */
  | { method: 'DELETE'; path: '/bot/report-group'; body: undefined; answer: ReportGroupResponse }
  /** Make a one-time link that adds the bot to a group with topics. */
  | { method: 'POST'; path: '/bot/report-group/link'; body: undefined; answer: ReportGroupResponse }
  /** Check the report group with Telegram again. */
  | { method: 'POST'; path: '/bot/report-group/check'; body: undefined; answer: ReportGroupResponse }
  /** Send a test message to every topic. */
  | { method: 'POST'; path: '/bot/report-group/test'; body: undefined; answer: ReportGroupTestResponse }
  /** Save a keyboard. */
  | { method: 'PUT'; path: `/keyboards/${string}`; body: KeyboardRequest; answer: KeyboardResponse }
  /** Reset a keyboard to the default layout. */
  | { method: 'POST'; path: `/keyboards/${string}/reset`; body: undefined; answer: KeyboardResponse }
  /** Clear a referral alert. The referrer earns start gifts and plan rewards again from now on; rewards withheld meanwhile are not paid later. An admin cannot clear an alert on themselves (403). An alert already cleared is a 422 on `status`. */
  | { method: 'POST'; path: `/referrals/alerts/${number}/clear`; body: undefined; answer: void }
  /** Save the agency program's settings. */
  | { method: 'PUT'; path: '/agency/settings'; body: AgencySettingsRequest; answer: AgencySettingsResponse }
  /** Approve an agency request. Sets the level and the starting credit; the customer is notified. */
  | { method: 'POST'; path: `/agency/requests/${number}/approve`; body: AgencyTermsRequest; answer: AgencyRequestResponse }
  /** Reject an agency request. The customer is notified, with the note. */
  | { method: 'POST'; path: `/agency/requests/${number}/reject`; body: NoteRequest; answer: AgencyRequestResponse }
  /** Change an agent's level and credit. The id is the agent's customer id. The agent is notified of a change, and the answer says whether the notice reached them. */
  | { method: 'PUT'; path: `/agency/agents/${number}`; body: AgencyTermsRequest; answer: AgentChangedResponse }
  /** End an agency. The agent's bot stops; the agent is notified, with the note. */
  | { method: 'POST'; path: `/agency/agents/${number}/revoke`; body: NoteRequest; answer: void }
  /** Adjust an agent's traffic. A negative `gb` takes traffic away. */
  | { method: 'POST'; path: `/agency/agents/${number}/traffic`; body: TrafficAdjustmentRequest; answer: AgentResponse }
  /** Add an agency level. */
  | { method: 'POST'; path: '/agency/levels'; body: AgencyLevelRequest; answer: AgencyLevelResponse }
  /** Reorder the agency levels. */
  | { method: 'POST'; path: '/agency/levels/reorder'; body: ReorderRequest; answer: AgencyLevelsResponse }
  /** Update an agency level. */
  | { method: 'PUT'; path: `/agency/levels/${number}`; body: AgencyLevelRequest; answer: AgencyLevelResponse }
  /** Delete an agency level. Refused (409) while agents are on it. */
  | { method: 'DELETE'; path: `/agency/levels/${number}`; body: undefined; answer: void }
  /** Remove a premium emoji from the picker. */
  | { method: 'DELETE'; path: `/bot/custom-emojis/${string}`; body: undefined; answer: void }
  /** Save a text's wording. */
  | { method: 'PUT'; path: `/bot/texts/${string}`; body: BotTextRequest; answer: BotTextResponse }
  /** Reset a text to its default wording. */
  | { method: 'POST'; path: `/bot/texts/${string}/reset`; body: undefined; answer: BotTextResponse }
  /** Update the website's settings. Only the fields sent change. The result is validated as a whole, and every refusal is returned at once under its field. */
  | { method: 'PATCH'; path: '/website'; body: WebsiteRequest; answer: WebsiteResponse }
  /** Make a new store key. The old base address stops working at once. */
  | { method: 'POST'; path: '/website/key'; body: undefined; answer: WebsiteResponse }
  /** Create a discount code. Every refusal is returned at once, under its field. */
  | { method: 'POST'; path: '/discount-codes'; body: DiscountCodeRequest; answer: DiscountCodeResponse }
  /** Update a discount code. Send the whole form; its past uses stay. */
  | { method: 'PUT'; path: `/discount-codes/${number}`; body: DiscountCodeRequest; answer: DiscountCodeResponse }
  /** Switch a discount code on or off. */
  | { method: 'PATCH'; path: `/discount-codes/${number}`; body: DiscountSwitchRequest; answer: DiscountCodeResponse }
  /** Delete an unused discount code. A code with uses (orders made with it and not cancelled) is a 409: switch it off instead. */
  | { method: 'DELETE'; path: `/discount-codes/${number}`; body: undefined; answer: void }
  /** Create a sale. Every refusal is returned at once, under its field. */
  | { method: 'POST'; path: '/sales'; body: SaleRequest; answer: SaleResponse }
  /** Update a sale. Send the whole form. */
  | { method: 'PUT'; path: `/sales/${number}`; body: SaleRequest; answer: SaleResponse }
  /** Switch a sale on or off. */
  | { method: 'PATCH'; path: `/sales/${number}`; body: DiscountSwitchRequest; answer: SaleResponse }
  /** Delete a sale. Orders it discounted keep their discount lines. */
  | { method: 'DELETE'; path: `/sales/${number}`; body: undefined; answer: void }
  /** Create a batch of gift codes. Every refusal is returned at once, under its field. */
  | { method: 'POST'; path: '/gift-codes'; body: GiftCodeBatchRequest; answer: GiftCodeBatchResponse }
  /** Delete a batch's unredeemed codes, including expired ones. Redeemed codes stay, for the record. A 422 on `batch` when none is named. */
  | { method: 'POST'; path: '/gift-codes/discard'; body: GiftCodeDiscardRequest; answer: GiftCodesDeletedResponse }
  /** Delete an unredeemed gift code. A redeemed code stays (a 422 on `status`). */
  | { method: 'DELETE'; path: `/gift-codes/${number}`; body: undefined; answer: void }
  /** Add a wheel slice. */
  | { method: 'POST'; path: '/wheel/prizes'; body: WheelPrizeRequest; answer: WheelPrizeResponse }
  /** Reorder the wheel slices. */
  | { method: 'POST'; path: '/wheel/prizes/reorder'; body: ReorderRequest; answer: WheelPrizesResponse }
  /** Update a wheel slice. */
  | { method: 'PUT'; path: `/wheel/prizes/${number}`; body: WheelPrizeRequest; answer: WheelPrizeResponse }
  /** Switch a wheel slice on or off. */
  | { method: 'PATCH'; path: `/wheel/prizes/${number}`; body: WheelPrizeSwitchRequest; answer: WheelPrizeResponse }
  /** Delete a wheel slice. Spins that landed on it keep a copy of it. */
  | { method: 'DELETE'; path: `/wheel/prizes/${number}`; body: undefined; answer: void }
  /** Add a loyalty tier. Every refusal is returned at once, under its field. */
  | { method: 'POST'; path: '/loyalty/tiers'; body: LoyaltyTierRequest; answer: LoyaltyTierResponse }
  /** Update a loyalty tier. */
  | { method: 'PUT'; path: `/loyalty/tiers/${number}`; body: LoyaltyTierRequest; answer: LoyaltyTierResponse }
  /** Delete a loyalty tier. Its customers drop to the tier below; their past orders do not change. */
  | { method: 'DELETE'; path: `/loyalty/tiers/${number}`; body: undefined; answer: void }
  /** Add a cashback rule. Every refusal is returned at once, under its field. */
  | { method: 'POST'; path: '/cashback/rules'; body: CashbackRuleRequest; answer: CashbackRuleResponse }
  /** Update a cashback rule. */
  | { method: 'PUT'; path: `/cashback/rules/${number}`; body: CashbackRuleRequest; answer: CashbackRuleResponse }
  /** Switch a cashback rule on or off. */
  | { method: 'PATCH'; path: `/cashback/rules/${number}`; body: CashbackRuleSwitchRequest; answer: CashbackRuleResponse }
  /** Delete a cashback rule. Cashback it already credited stays with the customers. */
  | { method: 'DELETE'; path: `/cashback/rules/${number}`; body: undefined; answer: void }
  /** Ask Telegram which bot a token belongs to. Uses the stored token when none is given. */
  | { method: 'POST'; path: '/settings/config/telegram/test'; body: TelegramTestRequest; answer: BotIdentityResponse }
  /** Test database settings without saving them. */
  | { method: 'POST'; path: '/settings/config/database/test'; body: DatabaseRequest; answer: DatabaseCheckResponse }
  /** Send a test email with the saved mail settings. A 422 while no email can go out (no transport or no sender address saved); a 502 with the transport's reason when it refused the email. */
  | { method: 'POST'; path: '/settings/config/mail/test'; body: MailTestRequest; answer: MailTestResponse }
  /** Save one group of the config.php settings. */
  | { method: 'PUT'; path: `/settings/config/${ConfigGroup}`; body: ConfigSettingsRequest; answer: ConfigSettingsResponse }

/** Every write of the web installer's API (`/api/install`), in the same form as PanelWrite. */
export type InstallWrite =
  /** Save the database settings. They are written to config.php only after the driver's probe connects. A blank secret means empty here, since nothing is stored yet. */
  | { method: 'POST'; path: '/database'; body: DatabaseRequest; answer: InstallStatus }
  /** Create the shop's tables. Creates the tables missing from the database the previous step saved. */
  | { method: 'POST'; path: '/tables'; body: undefined; answer: InstallStatus }
  /** Set the panel's login. */
  | { method: 'POST'; path: '/admin'; body: InstallAdminRequest; answer: InstallStatus }
  /** Set the shop's name and address, and the bot's token when one is given. */
  | { method: 'POST'; path: '/site'; body: InstallSiteRequest; answer: InstallStatus }
  /** Finish the installation. */
  | { method: 'POST'; path: '/finish'; body: undefined; answer: InstallFinished }

/** Every write of the Store API, a shop website's API under its store key (`/api/store/v1/{store}`), in the same form as PanelWrite. */
export type StoreWrite =
  /** Get a nonce for an id_token sign-in. For Telegram's popup or Google, when signing in, adding a way in or proving one again: the site opens the provider's sign-in with the nonce and sends it back once, with the token. A 429 once this address network asked for 120 nonces, states and codes in 10 minutes (one address can be many customers on a mobile network). A 503 while the shop already holds its maximum of open sign-ins (5000 nonces, each valid for half an hour). */
  | { method: 'POST'; path: '/auth/nonce'; body: undefined; answer: StoreNonceResponse }
  /** Start a Telegram sign-in by redirect. Returns the address to send the browser to. The site makes the PKCE verifier, keeps it in this browser and sends only its challenge; the shop makes the state (which keeps the challenge) and the nonce. Only `POST /auth/telegram` accepts this state back, together with the verifier (a signed-in customer uses `POST /me/telegram/authorize` instead). A 422 while the website has no client secret, or on `redirect_uri` or `code_challenge`; a 429 once this address network asked for 120 nonces, states and codes in 10 minutes; a 503 while the shop holds its maximum of open sign-ins. */
  | { method: 'POST'; path: '/auth/telegram/authorize'; body: StoreTelegramAuthorizeRequest; answer: StoreAuthorizeResponse }
  /** Sign in with Telegram. Send the popup's id_token, or the redirect's code with the state from `POST /auth/telegram/authorize` and the verifier of its challenge. A signed-in customer's state, or a code and state brought back to another browser, signs nobody in. Signs in the shop's customer with that Telegram account, or registers a new customer as the bot does. A 401 when the proof does not hold; a 403 when banned; a 422 when Telegram sign-in is off or a field is missing; a 429 after too many failures from this address network (30 in 15 minutes); a 502 when Telegram cannot be reached. */
  | { method: 'POST'; path: '/auth/telegram'; body: StoreTelegramSignInRequest; answer: StoreSignInResponse }
  /** Sign in with Google. Send the id_token Google Identity Services gave the page, with the nonce it was requested with. Signs in the customer with that Google account; else the customer with the email address Google vouches for (that account can sign in with Google from then on, and the customer is notified); else registers a new customer. Google vouches for an address only when `email_verified` is true and it is an @gmail.com or Google Workspace (`hd`) address; any other address is ignored. A 401 when the proof does not hold; a 403 when banned; a 409 when the address belongs to an account that cannot be linked this way (two-factor sign-in is on, or another Google account is linked): the customer signs in their usual way and adds Google from their account. A 422 when Google sign-in is off; a 429 after too many failures from this address network; a 502 when Google cannot be reached. */
  | { method: 'POST'; path: '/auth/google'; body: StoreGoogleSignInRequest; answer: StoreSignInResponse }
  /** Sign up with an email. Emails a six-digit code to the address (finish with `POST /auth/register/verify`); if the address already has an account, the email says so instead. The answer is the same either way. Asking again sends a new code that replaces the last one. A 422 when email sign-up is off or a field (the captcha included) is invalid. A 429 when a code went to the address in the last minute, the address got its 10 emails today (counted across all shops), this address network sent its 20 an hour or asked too often, or the address is locked after failed codes (10 wrong an hour, 20 a day). A 502 when the email could not be sent. A 503 when the shop sends no email, the shop's or the installation's email budget is spent (60 an hour and 300 a day per shop), or the captcha could not be checked. */
  | { method: 'POST'; path: '/auth/register'; body: StoreRegisterRequest; answer: StoreCodeSentResponse }
  /** Finish signing up with the emailed code. Creates the account and signs it in; the referrer, if any, is notified. A 409 when the address got an account meanwhile; a 422 when the code is wrong, expired, used or tried five times (or email sign-up is off); a 429 after too many failures, or when the address's budget of wrong codes is spent (10 an hour, 20 a day, each counted before it is checked). */
  | { method: 'POST'; path: '/auth/register/verify'; body: StoreVerifyRequest; answer: StoreSignInResponse }
  /** Sign in with an email and password. If the account has two-factor sign-in on, the answer is a 202 with a challenge instead (finish with `POST /auth/login/2fa`). A 401 for a wrong email or password (one answer for both, so it never reveals which addresses have an account); a 403 when banned; a 422 on an invalid field (the captcha included); a 429 after too many failures from this address network (30 in 15 minutes) or on this account (50, from all addresses); a 503 when the captcha could not be checked. */
  | { method: 'POST'; path: '/auth/login'; body: StoreLoginRequest; answer: StoreSignInResponse | StoreTwoFactorResponse }
  /** Finish a password sign-in with its second step. Send the challenge with the six-digit code from the account's authenticator app (Persian digits accepted; each code works once) or one of its recovery codes (used up). If the challenge came from a password reset, the new password is set now and every other session of the account ends. A 401 when the challenge is no longer valid (expired, five wrong codes, or two-factor turned off since): sign in again. A 403 when banned; a 422 when a field is missing or the code is wrong. A 429 after too many failures from this address network or on this account, or when the account's second-step budget is spent: 10 codes an hour and 20 a day, counted before each is checked, whoever tries them (the customer is notified once a day). */
  | { method: 'POST'; path: '/auth/login/2fa'; body: StoreTwoFactorRequest; answer: StoreSignInResponse }
  /** Start a password reset. Emails a six-digit code when the address has an account; an address without one gets an email saying so, at most once a day (a second request that day sends nothing). The answer is the same either way. A 422 on the address or the captcha. A 429 when a code went to the address in the last minute, the address got its 10 emails today (counted across all shops), this address network sent its 20 an hour or asked too often, or the address is locked after failed codes (10 wrong an hour, 20 a day). A 502 when the email could not be sent. A 503 when the shop sends no email, the shop's or the installation's email budget is spent, or the captcha could not be checked. */
  | { method: 'POST'; path: '/auth/password/forgot'; body: StoreForgotRequest; answer: StoreCodeSentResponse }
  /** Set a new password with the code from `POST /auth/password/forgot`. The password is saved, every session of the account ends, this device is signed in and the customer is notified. If two-factor sign-in is on (a reset does not turn it off), the answer is a 202 with a challenge and nothing changes yet: `POST /auth/login/2fa` sets the new password. A 403 when banned; a 422 on an invalid field, or a code that is wrong, expired, used or tried five times; a 429 after too many failures, or when the address's budget of wrong codes is spent (10 an hour, 20 a day, each counted before it is checked; the account is notified once a day). */
  | { method: 'POST'; path: '/auth/password/reset'; body: StoreResetRequest; answer: StoreSignInResponse | StoreTwoFactorResponse }
  /** Check a captcha token for the site's own backend. For the site's own forms (a contact form): the shop holds the captcha's secret and says whether the token passed for the action the backend names, where it was solved (Turnstile reports the host, which must be the website's address or one of its origins) and why it failed. Call it server to server: a browser's claim that it passed proves nothing. Each token is accepted once (an ALTCHA solution, for the 15 minutes its challenge lives). A 200 whether or not it passed; a 409 while the website asks for no captcha; a 422 on `token`, `action` or `remoteip`. A 429 past the website's 3000 checks in 10 minutes (the backend is one address for all its visitors), or past 120 refused tokens in that time from the visitor's network: the one `remoteip` names, else the backend's own. So pass the visitor's address, and a bot behind the site's forms uses up only its own network's allowance. This budget is separate from sign-ins. A 503 when the token could not be checked (Cloudflare cannot be reached). */
  | { method: 'POST'; path: '/captcha/verify'; body: StoreCaptchaVerifyRequest; answer: StoreCaptchaVerdict }
  /** Sign this device out. Its token stops working. */
  | { method: 'POST'; path: '/auth/logout'; body: undefined; answer: void }
  /** Change the customer's names. Only the names sent change; the answer is the same as `GET /me`. A 422 on the field: a blank first name, a name over 64 characters, or any name on an account with Telegram (its names come from Telegram). */
  | { method: 'PATCH'; path: '/me'; body: StoreProfileRequest; answer: StoreAccountResponse }
  /** Prove a way into this account again. Changing how the account signs in needs a sign-in from the last 15 minutes; an older session gets a 403 and calls this first. Prove the account's password (with the authenticator app's code or a recovery code while two-factor sign-in is on), its Telegram account (the popup's id_token with a nonce from `POST /auth/nonce`, or the redirect's code with the state from `POST /me/telegram/authorize` for this session and the verifier of its challenge), or its Google account. The session may then change the account's ways in for 15 minutes. A 422 on `method` for anything but password, telegram or google; a 422 (no field) while the website has that sign-in method off; a 422 on `password` for an account without a password (prove another way); a 422 on the field when it is missing, the proof does not hold (`password`, `code` or `id_token`) or it proves another account. A 429 after too many failures (counted like sign-ins; a code also counts against the account's second-step budget); a 502 when the provider cannot be reached. */
  | { method: 'POST'; path: '/me/reauthenticate'; body: StoreReauthenticateRequest; answer: StoreReauthenticatedResponse }
  /** Start a Telegram redirect for the signed-in customer. Used to add their Telegram account (`POST /me/identities/telegram`) or prove it again (`POST /me/reauthenticate`). Returns the address to send the browser to. The site makes the PKCE verifier, keeps it and sends only its challenge. The state belongs to this session alone: a sign-in, another customer or another device of theirs cannot use it, and nothing can without the verifier. A 422 while the website has no client secret, or on `redirect_uri` or `code_challenge`; a 429 once this address network asked for 120 nonces, states and codes in 10 minutes; a 503 while the shop holds its maximum of open sign-ins. */
  | { method: 'POST'; path: '/me/telegram/authorize'; body: StoreTelegramAuthorizeRequest; answer: StoreAuthorizeResponse }
  /** Set or change the customer's password. The password signs in with their email. Every other session ends, this one stays, and the customer is notified. A 403 when the session's sign-in is not recent (call `POST /me/reauthenticate` first); a 422 when the account has no email, the current password (if the account has one) is missing or wrong (a wrong one counts as a failed sign-in), or the new one breaks the password rule; a 429 after too many failures. */
  | { method: 'PUT'; path: '/me/password'; body: StorePasswordRequest; answer: StoreMeResponse }
  /** End one of the customer's sessions, this one included. Another customer's session is a 404. */
  | { method: 'DELETE'; path: `/me/sessions/${number}`; body: undefined; answer: void }
  /** Start setting up two-factor sign-in. Returns a new secret for the customer's authenticator app (RFC 6238). It waits 15 minutes for the app's first code (`POST /me/2fa/enable`) and replaces any earlier one. A 403 when the session's sign-in is not recent; a 422 when the account has no email and password, or two-factor sign-in is already on. */
  | { method: 'POST'; path: '/me/2fa/setup'; body: undefined; answer: StoreTwoFactorSetupResponse }
  /** Turn two-factor sign-in on. Send the app's first code for the secret from `POST /me/2fa/setup` (five tries). Returns ten recovery codes, shown only this once; the customer is notified. A 403 when the session's sign-in is not recent; a 422 when the account has no email and password, two-factor sign-in is already on, or on `code` when it is wrong or no secret is waiting (start the setup again). */
  | { method: 'POST'; path: '/me/2fa/enable'; body: StoreTwoFactorEnableRequest; answer: StoreRecoveryCodesResponse }
  /** Turn two-factor sign-in off. Needs the account's password; the customer is notified. A 403 when the session's sign-in is not recent; a 422 when it is not on, or on `password` when it is missing or wrong (a wrong one counts as a failed sign-in); a 429 after too many failures. */
  | { method: 'POST'; path: '/me/2fa/disable'; body: StoreTwoFactorDisableRequest; answer: StoreMeResponse }
  /** Add a Telegram account to the customer's account. Prove it as a Telegram sign-in does: the popup's id_token with its nonce, or the redirect's code with the state from `POST /me/telegram/authorize` for this customer and the verifier of its challenge (a sign-in's state or another customer's proves nothing here). If the Telegram account is already this account's or nobody's, the answer is the account (a new link takes its handle and names from Telegram, and the customer is notified). If another account of the shop has it, a merge is offered (202). A 403 when the session's sign-in is not recent; a 422 when the proof does not hold (on `id_token` or the redirect's `code`), the account already has another Telegram account, the merge is not allowed, or Telegram sign-in is off; a 429 after too many failures; a 502 when Telegram cannot be reached. */
  | { method: 'POST'; path: '/me/identities/telegram'; body: StoreTelegramLinkRequest; answer: StoreMeResponse | StoreMergeOfferResponse }
  /** Add a Google account to the customer's account. Send the id_token Google Identity Services gave the page, with its nonce. If the Google account is already this account's or nobody's, the answer is the account; a new link also takes the email address Google vouches for (an @gmail.com or Workspace address) when the account has no email and no other account has it, and the customer is notified. If another account of the shop has the Google account or that address, a merge is offered (202). A 403 when the session's sign-in is not recent; a 422 when the proof does not hold (on `id_token`), the account already has another Google account, the merge is not allowed, or Google sign-in is off; a 429 after too many failures; a 502 when Google cannot be reached. */
  | { method: 'POST'; path: '/me/identities/google'; body: StoreGoogleLinkRequest; answer: StoreMeResponse | StoreMergeOfferResponse }
  /** Add an email and password to the customer's account. Emails a six-digit code to the address (finish with `POST /me/identities/email/verify`), whether or not another account has the address: it is proven first. Another customer's pending code for the same address is not affected. A 403 when the session's sign-in is not recent; a 422 when the account already has an email or a field is invalid. A 429 when a code went to the address in the last minute, the address got its 10 emails today (counted across all shops), this address network sent its 20 an hour or asked too often, the address is locked after failed codes (10 wrong an hour, 20 a day), or this account asked for 5 codes today. A 502 when the email could not be sent; a 503 when the shop sends no email or its email budget is spent. */
  | { method: 'POST'; path: '/me/identities/email'; body: StoreEmailLinkRequest; answer: StoreCodeSentResponse }
  /** Finish adding an email with the emailed code. Only the account the code was sent for can use it. The address and its password become this account's and the customer is notified. If another account of the shop has the address, a merge is offered (202); after the merge, the remaining account has the address and the chosen password. A 403 when the session's sign-in is not recent; a 422 when the account already has an email, the merge is not allowed, or on `code` when it is wrong, expired, used, tried five times or another account's; a 429 after too many failures, or when the budget of wrong codes for the address or this account is spent (10 an hour, 20 a day). */
  | { method: 'POST'; path: '/me/identities/email/verify'; body: StoreEmailLinkVerifyRequest; answer: StoreMeResponse | StoreMergeOfferResponse }
  /** Remove a way in from the customer's account. Never the last one. Removing the email also removes the password and two-factor sign-in, and the address gets an email saying so; removing Telegram also removes the handle. The customer is notified. A 403 when the session's sign-in is not recent; a 422 when the account has no way in of that kind, or it is the last one. */
  | { method: 'DELETE'; path: `/me/identities/${StoreIdentityKind}`; body: undefined; answer: StoreMeResponse }
  /** Accept a merge offer (the 202 of a `POST /me/identities/…`). The two accounts become one: the older account stays and takes over everything the other owned, then also gets the way in being added. A password set this way ends every other session of the account. This session is signed in to the remaining account, and the customer is notified. A 403 when the session's sign-in is not recent; a 422 when the ticket is not this account's, is used or expired, the other account no longer has that way in, or the merge is no longer allowed. */
  | { method: 'POST'; path: '/me/merge'; body: StoreMergeRequest; answer: StoreMeResponse }
  /** What a service the customer builds would cost them, nothing ordered: the GB's price and the days' price by their tiers, their sum (`base`), the customer's automatic discount and — with `discount_code` — the code's, as lines, and what is left. Only a sale of every plan reaches a built service, and a code limited to certain plans never does; a code limited to certain servers holds once `server_id` is picked. A 422 on `status` while nothing may be built (`offered` of `GET /custom-services`); on `gb` or `days` for an amount that is no whole number or outside its bounds (the message says them); on `server_id` for a server not on offer now; on `discount_code` when it does not apply. Each code that does not apply uses one of the customer's 20 tries an hour (shared with the checkout's), then a 429 with `Retry-After`. */
  | { method: 'POST'; path: '/custom-services/quote'; body: StoreCustomQuoteRequest; answer: StoreCustomQuoteResponse }
  /** Buy a service the customer builds, paid with `method_id`: the same checkout as `POST /orders`, as the bot's «🛠 ساخت سرویس دلخواه» buys it, at `POST /custom-services/quote`'s price. The order is a purchase without a plan (`plan` null) carrying its own `traffic_bytes`, `days` and `devices`; once delivered, its service has no plan either. `server_id` may be left out while the builder sells on one server. The service is named as `POST /orders` names one (`name_mode`, `client_name`). A 422 on `status` while nothing may be built; on `gb` or `days` for an amount that is no whole number or outside its bounds; on `server_id` for none picked among several servers, or a server not on offer now; on `name_mode` or `client_name` as `POST /orders` refuses them; on `method_id` for a method that cannot pay it, or a wallet short of the price; on `discount_code` when it does not apply; on `idempotency_key` for a key used for a different order or first sent with another `method_id`. The 5 unpaid orders, the 409s, the 429 past 30 ordering requests in 10 minutes and the 503 while the bot is switched off are `POST /orders`' own. Repeated with the same `Idempotency-Key`, it returns the order that key made, as it is now: never a second order or a second charge. */
  | { method: 'POST'; path: '/custom-services/orders'; body: StoreCustomOrderRequest; answer: StoreCheckoutResponse }
  /** Write a review. Send the signed name, 1 to 5 stars, the text, an optional line on where the service is used, and an optional avatar from the website's own set. It waits for support to approve it (then `GET /reviews` shows it) or reject it; the report group is notified. Guests may write while the website asks for a captcha; without one, a guest gets a 403 (sign in first). With a bearer token the review is that customer's; a token that is not valid is a 401 (never a guest review instead), and a banned customer gets a 403. A 404 while the website takes no reviews (`reviews_enabled`, off by default). A 422 lists every invalid field at once: `name` (2 to 64 characters), `rating` (1 to 5), `body` (10 to 600), `context` (at most 100) or `avatar` (1 to 32 of a-z, 0-9, - and _: a key, never an address); then `captcha` while the website asks for one. A 429 with `Retry-After` past 20 reviews an hour from one address network (IPv6 counted by /48; refused captchas included), 3 a day from one customer, or 20 an hour from all guests together (refused captchas not included). A 503 while 200 reviews wait for support (no new review is accepted until support decides some), or when the captcha could not be checked. */
  | { method: 'POST'; path: '/reviews'; body: StoreReviewRequest; answer: StoreReviewWrittenResponse }
  /** Add a service to the customer's account by its link («افزودن سرویس با لینک»), as the bot does. `link` is the text the customer sent: a subscription link or one of the service's configs (vless://, vmess://, trojan://, ss://, hysteria2://, tuic://), alone or among other words; its links are read together, five at most. The shop asks its panels whose client it is and adds it as its panel has it, with no plan (`plan` null, `claimed_at` set) until its first renewal (`GET …/renewal-plans`); the report group is told. The first customer to send it gets it. Only while the shop offers it (`shop.service_claims` of `GET /`): the main shop's alone, off by default. Refusals are on `link`: a 422 while it is off, for text with no link in it (or longer than 2000 characters), for a link no panel has, for a service already the customer's own, or one whose panel serves it no subscription link; a 409 for a service already in another customer's account; a 502 when a panel that might have it did not answer. Each link sent asks every panel: at most 10 links in 10 minutes per customer, then a 429 with `Retry-After`. */
  | { method: 'POST'; path: '/subscriptions/claim'; body: StoreClaimRequest; answer: StoreSubscriptionResponse }
  /** Switch the service's auto-renewal («تمدید خودکار») on or off. When on, the service is renewed from the wallet before it ends. A 422 on `auto_renew` while auto-renewal is not offered (`auto_renew.offered`), or for anything but a boolean. */
  | { method: 'PATCH'; path: `/subscriptions/${number}`; body: StoreSubscriptionRequest; answer: StoreSubscriptionResponse }
  /** Read the service from its panel now, with whether it is connected (`presence`). A client the panel no longer has comes back as deleted. A 502 when the panel cannot be read now (unreachable, or skipped for a while after a failure); the shop's stored copy is not returned instead. At most 10 reads a minute per customer, then a 429 with `Retry-After`. */
  | { method: 'POST'; path: `/subscriptions/${number}/refresh`; body: undefined; answer: StoreSubscriptionResponse }
  /** Change the service's subscription link (`link_rotation`). Every device on the old link is disconnected; the bot sends no message about it. A 422 on `status` when the service is not active or its server cannot rotate links; a 409 while another change to it is running; a 502 when the server failed. At most 5 an hour per customer, then a 429 with `Retry-After`. */
  | { method: 'POST'; path: `/subscriptions/${number}/rotate-link`; body: undefined; answer: StoreSubscriptionResponse }
  /** «انتقال سرویس»: hand the service to another customer of the shop, found by their numeric Telegram id, for free — as the bot does it. Only while the shop lets customers (`shop.service_transfers` of `GET /`, off by default). The service keeps its link, term and traffic and is the recipient's from now on (this customer's `GET /subscriptions/{id}` is a 404 after it); its «تمدید خودکار» goes off; the link goes with it, so this customer's devices stay connected until the recipient changes it (`POST /subscriptions/{id}/rotate-link`); the recipient is told in the bot and among their notices (`service_received`, which says so where the link can change) — this customer is not: the answer is theirs; the report group hears of it. A 403 when the session's sign-in is not recent (call `POST /me/reauthenticate` first): a bearer token alone gives no service away. A 422 on `status` while the shop does not offer it, for a free test account, a service gone from its panel or being changed on it right then (try again in a moment), or one with an order this customer made not finished with (a renewal or an extra awaiting its payment or receipt, or being delivered; an unpaid one is dropped by itself after 48 hours; one whose delivery failed waits for support, and its message says to ask them); on `telegram_id` for an id that is no number, that names no customer of this shop (they must have started the bot once), the customer themselves, or a banned account. It looks the recipient up as `GET` does (one of its 10 in 10 minutes); at most 5 services handed on an hour per customer, then a 429 with `Retry-After`. */
  | { method: 'POST'; path: `/subscriptions/${number}/transfer`; body: SubscriptionTransferRequest; answer: StoreTransferRecipientResponse }
  /** Renew a service, paid with `method_id`, as the GET previews it: by the renewal method `method`, and a service without a plan on the plan `plan_id` picks, which becomes its plan once the renewal is delivered — or with the package `gb` and `days` build, an order without a plan carrying its own `traffic_bytes`, `days` and `devices`, after which the service still has none (so «تمدید خودکار» never renews it). The wallet renews it at once; a card waits for its receipt (`POST /payments/{id}/receipt`). Refused for the same reasons as the GET, and as `POST /orders` (a 422 on `method_id` when the wallet is short; a 422 with too many unpaid orders; a 409 when the order was paid or closed at the same moment elsewhere; a 429 past the checkout's rate limit; a 503 while the shop takes no orders). Repeated with the same `Idempotency-Key` (and the same `plan_id`, or `gb` and `days`, and `method`), it returns the renewal that key made, as it is now. */
  | { method: 'POST'; path: `/subscriptions/${number}/renewal`; body: StoreRenewalRequest; answer: StoreCheckoutResponse }
  /** Buy extra traffic for a service. `gb` paid with `method_id`, through the same checkout as every purchase. The wallet adds it at once (a service whose traffic ran out runs again); a card waits for its receipt (`POST /payments/{id}/receipt`). A 422 on `status` while the service cannot take it (switched off by support, gone from its panel, a free test account, unlimited, or its time ran out); on `gb` for an amount not on offer, or when none is sold (see `GET …/extras`); on `method_id` and `idempotency_key` as for `POST /orders`. The 422 for five unpaid orders, and the 409, 429 and 503, as for `POST /orders`. Repeated with the same `Idempotency-Key`, it returns the order that key made, as it is now. */
  | { method: 'POST'; path: `/subscriptions/${number}/extra-traffic`; body: StoreExtraTrafficRequest; answer: StoreCheckoutResponse }
  /** Buy extra time for a service. `days` paid with `method_id`, through the same checkout as every purchase. The wallet adds it at once: onto the service's end, or, if its time ran out, as a new term starting at its next connection. A card waits for its receipt. A 422 on `status` while the service cannot take it (switched off by support, gone from its panel, a free test account, never expiring, or its traffic ran out); on `days` for an amount not on offer, or when none is sold (see `GET …/extras`); on `method_id` and `idempotency_key` as for `POST /orders`. The 422 for five unpaid orders, and the 409, 429 and 503, as for `POST /orders`. Repeated with the same `Idempotency-Key`, it returns the order that key made, as it is now. */
  | { method: 'POST'; path: `/subscriptions/${number}/extra-time`; body: StoreExtraTimeRequest; answer: StoreCheckoutResponse }
  /** Buy extra devices for a service («کاربر اضافه»): `devices` more on its IP limit, paid with `method_id`, through the same checkout as every purchase. Priced for 30 days and charged for the days left of the period in use (whole days, at least 1: to its end date, or to the start of a renewal bought before then; its whole term while it waits for its first connection; 30 for one that never ends), rounded up to whole Toman — as `GET …/extras` shows. The devices last until that period ends: a renewal brings its own device limit (`StoreRenewal`), and they are bought again for the new period. The wallet adds them at once; a card waits for its receipt. A 422 on `status` while the service cannot take them (switched off by support, gone from its panel, a free test account, an unlimited device limit, its time or traffic ran out, or a server whose panel does not enforce the limit); on `devices` for an amount not on offer, or when none is sold (see `GET …/extras`); on `method_id` and `idempotency_key` as for `POST /orders`. The 422 for five unpaid orders, and the 409, 429 and 503, as for `POST /orders`. Repeated with the same `Idempotency-Key`, it returns the order that key made, as it is now. */
  | { method: 'POST'; path: `/subscriptions/${number}/extra-devices`; body: StoreExtraDevicesRequest; answer: StoreCheckoutResponse }
  /** Buy a plan on one of its locations, paid with `method_id`. The same checkout as the bot's purchases, at the customer's price: the lowest of the price, the first-purchase price (`first_purchase_price`, until they have bought a service) and the agent price (`agent_price`, for an agent or a member of a group with agent prices). The order is created only with the payment method. The service is named on its panel by the shop's pattern, or — while the shop offers it (`shop.name_modes` of `GET /`) — by a random name or the one the customer typed (`name_mode`, `client_name`). The wallet pays at once (the service is delivered, or the order fails and waits for support); a card waits for its receipt (`POST /payments/{id}/receipt`). A 422 on `plan_id` for a plan not on sale now (switched off, on no location that can deliver it now, or in an agent's shop more traffic than the agent has left to sell); on `server_id` for a server it is not sold on now while another location is; on `name_mode` for a way of naming the shop does not offer; on `client_name` for a typed name that is refused; on `method_id` for a method that cannot pay it, or a wallet short of the price (the message says how much is missing); on `idempotency_key` for a key used for a different order or first sent with another `method_id`. A 422 (no field) while the customer has 5 unpaid orders; paid or expired orders free the room. A 409 when the order was paid or closed at the same moment elsewhere, or, for a repeated request, when the order was cancelled since (with its own message). A 429 with `Retry-After` past 30 ordering requests in 10 minutes (purchases, renewals and top-ups together, repeats included). A 503 while the shop takes no orders because its bot is switched off (`shop.taking_orders` of `GET /`), repeats included. Repeated with the same `Idempotency-Key`, it returns the order that key made, as it is now: never a second order or a second charge. */
  | { method: 'POST'; path: '/orders'; body: StoreOrderRequest; answer: StoreCheckoutResponse }
  /** Upload the receipt of a card transfer. For the payment the checkout returned (`transfer.payment_id`): a picture, with an optional note from the customer. Support always reviews it (in the panel or the report group): a card method's automatic approval window applies only to receipts sent in the bot, never to uploads. Returns the order, with the payment awaiting review. A 404 for another customer's payment; a 422 on `status` while the payment awaits no receipt (one was already sent, or it was rejected, paid, cancelled, or is not a card transfer), on `file` when it is not a JPEG, PNG or WebP of at most 10 MB, or on `note` when it is too long. A 429 with `Retry-After` past 10 pictures in an hour (receipts and ticket pictures share this budget). A 503 while the host has less than 200 MB of free disk, once the shop's uploads for the day reach 1024 MB, or while the shop takes no orders (its bot is switched off). A form larger than PHP accepts is a 413; a form field that is not UTF-8 is a 422 on that field. */
  | { method: 'POST'; path: `/payments/${number}/receipt`; body: StoreReceiptRequest; answer: StoreOrderResponse }
  /** Top up the wallet by `amount`, paid with `method_id`. The amount must be within the `top_up` limits of `GET /wallet` (else a 422 on `amount` stating them). Any method but the wallet itself (else a 422 on `method_id`). A card waits for its receipt (`POST /payments/{id}/receipt`); once paid, the amount is added to the balance. Refused like `POST /orders` for too many unpaid orders (422), past the checkout's rate limit (429) and while the shop takes no orders (503). Repeated with the same `Idempotency-Key`, it returns the top-up that key made, as it is now. */
  | { method: 'POST'; path: '/wallet/top-up'; body: StoreTopUpRequest; answer: StoreCheckoutResponse }
  /** Check a discount code before paying. Returns the quote the order would be made at: the base price (the customer's price for the plan, or the cost of that much extra), the automatic discount and the code's discount as lines, and the total. Nothing is ordered. A 422 on `code` when it does not apply: unknown in this shop, switched off, outside its dates, for another type of order, plan or server, only for a first purchase, for a larger order, or used up. Each code that does not apply uses one of the customer's 20 tries an hour (shared with the checkout's `discount_code`), then a 429 with `Retry-After`. */
  | { method: 'POST'; path: '/discount-codes/check'; body: StoreDiscountCheckRequest; answer: StoreDiscountQuoteResponse }
  /** Redeem a gift code, as the bot's «🎁 کد هدیه» does. The code may be typed in any case, with dashes and spaces anywhere and Persian digits. Toman are added to the wallet; a plan code delivers a free service (the answer has its order and, once delivered, the service; nothing is sent in Telegram). A 422 on `code` when the shop takes no codes now, the code does not exist, is already redeemed (by them or someone else) or expired, or its service cannot be delivered now (the code stays theirs to try again). Every try that names no code of the shop counts against the customer and their address network: 10 wrong tries an hour per customer and 30 per network, then a 429 with `Retry-After`. */
  | { method: 'POST'; path: '/gift-codes/redeem'; body: StoreGiftCodeRequest; answer: StoreGiftCodeResponse }
  /** Mark notifications as read. Those listed in `ids` (an id that is not the customer's is ignored, not an error), or all of them when `ids` is left out (`{}`). A notice read before keeps its first read time. Returns how many are still unread. A 422 on `ids` for anything but a list of at most 100 numbers. */
  | { method: 'POST'; path: '/notifications/read'; body: StoreNotificationsReadRequest; answer: StoreUnreadResponse }
  /** Open a support ticket. Send the subject, the first message, optionally the customer's service it is about, and optionally a picture (as a form). It waits for support, and the report group is notified at once. A 422 on `subject` (3 to 120 characters), `body` (1 to 4000), `subscription_id` (not the customer's) or `file` (a JPEG, PNG or WebP of at most 10 MB). A 429 with `Retry-After` past 10 tickets in an hour, 30 messages in 10 minutes, or 10 pictures in an hour (receipts and ticket pictures together). A 503 while the host has less than 200 MB of free disk, or once the shop's uploads for the day reach 1024 MB. A form larger than PHP accepts is a 413; a form field that is not UTF-8 is a 422 on that field. */
  | { method: 'POST'; path: '/tickets'; body: StoreTicketRequest | StoreTicketUploadRequest; answer: StoreTicketResponse }
  /** Mark a ticket as read. Call it once the website has shown the ticket: support's latest messages become read (`unread` false), and so do the notifications about it. Returns the ticket. Another customer's ticket is a 404. */
  | { method: 'POST'; path: `/tickets/${number}/read`; body: undefined; answer: StoreTicketResponse }
  /** Write a message in a ticket. Send the text, and optionally a picture (as a form). The ticket waits for support again (a closed ticket reopens and its rating is cleared), and the report group gets the message. A 422 on `body` or `file`; on `file` also once the ticket has 20 pictures the customer uploaded; on `status` once it has 200 messages (open a new ticket instead). A 429 with `Retry-After` past 30 messages in 10 minutes, or 10 pictures in an hour (receipts and ticket pictures together). A 503 while the host has less than 200 MB of free disk, or once the shop's uploads for the day reach 1024 MB. A form larger than PHP accepts is a 413; a form field that is not UTF-8 is a 422 on that field. */
  | { method: 'POST'; path: `/tickets/${number}/messages`; body: TicketMessageRequest | TicketMessageUploadRequest; answer: StoreTicketResponse }
  /** Close a ticket. The report group is notified. A 422 on `status` when it is already closed. */
  | { method: 'POST'; path: `/tickets/${number}/close`; body: undefined; answer: StoreTicketResponse }
  /** Rate a closed ticket, 1 to 5 with an optional note. A new rating replaces the previous one; the report group hears of a first or changed rating. A 422 on `rating` or `note` (at most 500 characters), then on `status` while the ticket is not closed. A 429 with `Retry-After`: each rating counts as one of the customer's 30 messages per 10 minutes. */
  | { method: 'POST'; path: `/tickets/${number}/rating`; body: StoreTicketRatingRequest; answer: StoreTicketResponse }
