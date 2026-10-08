/*
 * The shapes of the API's answers and of the bodies its requests carry, and each API's writes by method and path,
 * generated from resources/api/openapi.yaml by `pnpm api:types` — do not edit by hand: change the description, then run
 * it again. The PHP tests check every request and every response against the same file, and so do the panels' tests.
 */

/** Every refusal and failure — a controller's, a middleware's, the error handler's, the front controller's last resort. A 429 says its wait in `Retry-After` (seconds) too. */
export interface ErrorResponse {
  /** In the reader's words: the admin's on the panels, the customer's on the Store API. */
  message: string
  /** What to fix, under the field it is about */
  errors?: Record<string, string[]>
  /** The request's id, as its `X-Request-Id` header says it and every log line of it carries it — what the panel shows on a failure of the server's own («کد پیگیری»), to find the request in the log. */
  request_id: string
  /** Only for a failure of the server's (a 500, never a refusal), with APP_DEBUG on, and only to a request straight from the server's own machine — never one a proxy or a tunnel forwarded. Paths are told from the app's folder; no call's arguments. */
  debug?: {
    exception: string
    detail: string
    trace: string[]
  }
}

/** Where a paged list is. A sorted list's meta says its order beside these (`sort`, `dir`). */
export interface PageMeta {
  page: number
  per_page: number
  total: number
  last_page: number
}

/** Which way a sorted list runs — asc (the oldest, the smallest, the soonest first) or desc. */
export type SortDirection = 'asc' | 'desc'

/** The customer as every screen points at them — the three Telegram identifiers, kept apart (a customer who signed up on the website has none but a name), their email, plus our id. */
export interface UserRef {
  id: number
  /** "first last" as Telegram has it, or as they signed up with; null when the account shows none. */
  name: string | null
  /** Bare Telegram handle without "@"; null when the account has none. */
  username: string | null
  /** Their Telegram account; null for one who signed up on the website — the bot cannot write to them. */
  telegram_id: number | null
  /** Their email (lower case, proven); null while they have none. */
  email: string | null
}

/** A whole number as a form sends it: a JSON number, or its text — Persian or Arabic digits too. */
export type WholeNumberInput = number | string

/** An amount as a form sends it: a JSON number, or its text — Persian or Arabic digits and thousands separators too. Toman are whole (the form this API answers them in, "120000.00" — a fraction of zero —, is taken; any other fraction is refused); gigabytes may have up to two decimals. */
export type AmountInput = number | string

/** A short list of whole numbers — the amounts a screen offers as buttons —: a list, or the text an admin types ("50, 100, 200", or a space between; a comma between groups of three digits sets a number's thousands apart: "50,000, 100,000" is two numbers); kept without repeats, smallest first. */
export type NumbersInput = WholeNumberInput[] | string

/** Support's word to the customer that comes with a decision — a cancel, a rejection, a service switched off, an agency refused or ended: its own line of the message they get; 300 characters at most, blank for none. */
export interface NoteRequest {
  note?: string
}

/** Support's word on a refund: its own line of the message the customer gets, and the note of the wallet line the refund writes — 190 characters at most, a ledger line's (Ledger::NOTE_MAX); blank for none. */
export interface RefundRequest {
  note?: string
}

/** An admin-ordered list's rows in their new order, by id — 1000 at most (Sorting::MAX_IDS), as many as a list may have. */
export interface ReorderRequest {
  ids: number[]
}

/** The on/off switch of a row in its list (a plan, a category): true or false, nothing else. */
export interface ActivationRequest {
  is_active: boolean
}

export interface Health {
  status: 'ok' | 'degraded'
  version: string
  installed: boolean
  /** Never why: the log has that. */
  database: 'ok' | 'unavailable'
}

/** GET /api/app — asked before anything else. */
export interface AppInfo {
  /** The shop's name (APP_NAME), as the brand shows it. */
  name: string
  /** False until the web installer has finished: the panel then opens it. */
  installed: boolean
  /** The shop's time zone (APP_TIMEZONE, an IANA name such as Asia/Tehran): the panels show every date and time in it, as the bot words its dates. */
  timezone: string
}

/** What a field holds, as a generic form draws it: a line of text, a whole number, an amount of Toman, a short list of numbers typed as text, an on/off switch, one of a few values, a secret, an email address, a web address, a file's path, a few lines of text, or a bank card's number (drawn in groups of four, as it is printed, and sent as its digits). */
export type FieldType = 'text' | 'number' | 'amount' | 'list' | 'toggle' | 'choice' | 'secret' | 'email' | 'url' | 'path' | 'textarea' | 'card'

/** One field of a driver's form as a generic form draws it (Field::describe()): the screens hold no driver's code. */
export interface FieldDescription {
  /** The key it is sent under, and the one a 422 names. */
  name: string
  type: FieldType
  label: string
  /** The line under it. */
  hint: string | null
  placeholder: string | null
  /** A form that leaves it empty is refused — a secret's: one must end up kept, the stored one staying while it is left blank. */
  required: boolean
  /** Never sent back — its value is a SecretState —: left blank or left out it keeps the stored one, `clear_<name>: true` empties it. */
  secret: boolean
  /** A secret's: the fields it belongs with. While one of them moves a blank one is refused (in `moved`'s words), to be typed again or cleared. */
  bound_to: string[]
  /** What the server says of a blank secret while a field it belongs with moved — the hint to show before it is asked. */
  moved: string | null
  /** Drawn under «تنظیمات پیشرفته». */
  advanced: boolean
  /** A choice's values and their words, in order. */
  options: {
    value: string
    label: string
  }[]
  /** Shown — and read, and required when it is — only while each named field (one before it) holds one of these values: a switch as "true" or "false", a number as its digits. Hidden, what is kept of it stays. */
  when: Record<string, string[]>
  /** Said after a number: «ثانیه». */
  unit: string | null
  /** The least a number may be — each of a list's, an amount's. */
  min: number | null
  /** The most. */
  max: number | null
  /** Latin content (a host, a username): drawn left to right, as a number, a secret, an email, a web address and a path are. */
  ltr: boolean
  /** What it holds while nothing is kept; null for a secret. */
  default: string | number | boolean | number[] | null
}

/** A driver — one way of doing a thing through someone else: a database, a way email goes out — as the panels show it (Descriptor::toArray()): its name and words, and its form, every field described. */
export interface DriverDescription {
  /** What config.php, a row or a request names it by. */
  key: string
  label: string
  description: string
  /** Short lines under the description: the versions it needs, its caveats. */
  notes: string[]
  fields: FieldDescription[]
  /** What its family says of it beyond this: a database driver's `installable` (the installer offers it); a mail driver's `sender` (who it can send from, in words); a payment gateway's `kind` (instant — it settles at once, the wallet —, or manual — a card transfer whose receipt a person accepts) and `builtin` (part of the shop itself: never added nor deleted); a panel connector's `mark` (the two letters its card draws), `vendor`, `docs` (its project's address) and what its panel can do — `inbounds` (services sold on inbounds the owner picks; without, a server is sold whole) and `link_rotation` («تغییر لینک»). A captcha driver has none. */
  traits: Record<string, string | number | boolean | (string | number | boolean)[] | null>
}

/** A driver's fields by name as a screen shows them (Form::present()): as config.php holds them, else their defaults — a secret as its SecretState, never the secret. */
export type DriverValues = Record<string, string | number | boolean | number[] | SecretState>

/** The database part of config.php: the driver DB_CONNECTION names, the drivers the admin may pick — each described, its form —, and the driver's fields as config.php holds them. */
export interface DatabaseSettings {
  /** The DriverDescription.key config.php names. */
  driver: string
  /** The installable drivers, and the one the shop runs on */
  drivers: DriverDescription[]
  values: DriverValues
}

/** The database part of config.php as a form sends it — the installer's step, the settings screen's save and its test: the driver (one of `drivers`) and its form's fields under their names, as typed. A secret left blank or left out keeps the stored one while the address it was given for stays — in the installer there is none: it is empty —, and `clear_<name>: true` empties it. A closed shape per driver. */
export type DatabaseRequest = DatabaseMysqlRequest | DatabaseSqliteRequest

/** MySQL or MariaDB: where the database is, the account on it, its tables' prefix. */
export interface DatabaseMysqlRequest {
  driver: 'mysql'
  host: string
  port: WholeNumberInput
  database: string
  username: string
  /** Left out or blank: the one kept stays — while the host, the port and the socket stay. */
  password?: string
  /** True: the kept password is emptied. */
  clear_password?: boolean
  /** Blank for none. */
  socket?: string
  /** Blank for none. */
  prefix?: string
}

/** SQLite: a file of the shop's own. */
export interface DatabaseSqliteRequest {
  driver: 'sqlite'
  /** The file, relative to the application or absolute; never under public/. */
  path: string
}

/** GET /api/install, and every installer step's answer — where the installation stands. */
export interface InstallStatus {
  requirements: {
    name: string
    /** In the installer's words */
    label: string
    ok: boolean
    /** What the shop runs without — the owner's panel updating it itself needs it: shown, and holding nothing up. */
    optional: boolean
  }[]
  /** Every requirement that is not optional is met. */
  ready: boolean
  /** The database step's settings, and whether a database answers to what config.php names (not asked before the step wrote one). */
  database: {
    /** The DriverDescription.key config.php names. */
    driver: string
    /** The installable drivers, and the one the shop runs on */
    drivers: DriverDescription[]
    values: DriverValues
    connected: boolean
    /** Every table of database/schema.php is there. */
    tables: boolean
    /** Why it does not answer, in the admin's words */
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

/** The panel's login by its one rule (Auth's Credentials): a username of 2 to 64 letters, digits, `.`, `_`, `@` or `-`, and a password of 8 characters at least and 72 bytes at most, typed twice alike. */
export interface InstallAdminRequest {
  username: string
  /** As typed — a space at either end is part of it. */
  password: string
  password_confirmation: string
}

/** The shop's name and the address it is reached at (APP_NAME, APP_URL), and the main bot's token when the owner has it now — written once Telegram says whose it is. */
export interface InstallSiteRequest {
  name: string
  /** http(s), its sub-folder included. */
  url: string
  /** From @BotFather; blank or left out — later, from the settings screen. */
  token?: string
}

/** Who is signed in to a panel and the shop the request was worked in: on the owner's panel the one login config.php keeps and the shop the request named (X-Shop), on an agent's their bot and its shop. */
export interface Session {
  /** The owner's login, or the agent's bot (@username) — what their decisions are recorded under. */
  name: string
  shop: ShopRef
}

export interface SessionResponse {
  session: Session
}

export interface ShopsResponse {
  /** Every shop the owner may open — the main bot's first. */
  shops: ShopRef[]
}

/** A bot's shop: the main bot's, or an agent's. */
export interface ShopRef {
  /** The bot's id — 1 is the main bot. */
  id: number
  /** The main bot's «فروشگاه اصلی»; an agent's bot's title, else — not handed over yet — «نماینده: » and the agent's name. */
  name: string
  /** The bot's @username, without the @. */
  username: string | null
  /** disabled: an agent whose agency ended — the shop is kept, its bot does not run. */
  status: 'active' | 'disabled'
}

/** The owner signs in with the panel's login (config.php keeps it). */
export interface LoginRequest {
  username: string
  /** As typed — a space at either end is part of it. */
  password: string
}

/** The owner changes the panel's login: their current password first, then the new login by the installer's rule (InstallAdminRequest) — a password left blank, its confirmation too, keeps the one kept; a change that changes nothing is refused. */
export interface CredentialsRequest {
  current_password: string
  username: string
  /** As typed — a space at either end is part of it. */
  password?: string
  password_confirmation?: string
}

/** A new panel login set without the old one: the key the panel wrote to the host's files (RecoveryKeyResponse.file), then the login by the installer's rule (InstallAdminRequest). */
export interface RecoveryRequest {
  /** As the file holds it; case and the spaces around it do not matter. */
  key: string
  username: string
  /** As typed — a space at either end is part of it. */
  password: string
  password_confirmation: string
}

export interface RecoveryKeyResponse {
  /** Where the key is, from the shop's own folder: storage/recovery-key.txt. */
  file: string
  /** When it stops opening anything — an hour after it was made. */
  expires_at: string
}

/** The code of the one-time link the main bot gave the agent («ورود به پنل»), read off the address's fragment (`#code=`). */
export interface AgentLinkRequest {
  code: string
  /** Sign in even though another agent's session is open in this browser — it ends. Left out: refused with a 409 on `replace`, the link not spent. */
  replace?: boolean
}

/** Each area's number (Core\Database\ChangeFeed::AREAS); an area nothing was written to yet is absent. */
export interface ChangesResponse {
  versions: {
    /** The customers, their wallets and their groups. */
    users?: number
    /** The servers and their inbounds. */
    servers?: number
    /** The grants and their parts on each server — a running one's cursor moves every second. */
    grants?: number
    /** The plans, their categories and their server entries. */
    plans?: number
    payment_methods?: number
    orders?: number
    payments?: number
    subscriptions?: number
    /** The commissions paid out. */
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
  }
}

/** A failure of the panel's own code, as its page reports it (ClientErrors): one error line of the shop's log with the shop's version, never kept anywhere else. Each part is cut to its length and taken out of any secret, the body is refused past 16 KB (413), and a principal or an address sends 20 in ten minutes and 100 in a day (429, `Retry-After`). */
export interface ClientErrorRequest {
  /** `render` a page it could not draw (an error boundary, the router's error screen); `unhandled` an error a press or a promise left unhandled. */
  kind: 'render' | 'unhandled'
  /** What was thrown: the error's name and its message. */
  message: string
  /** The top of the error's stack. */
  stack?: string | null
  /** Where in the page's components it was drawing (a render failure's). */
  component_stack?: string | null
  /** The panel's address it happened at — its path and query, never the fragment (a sign-in link's code). */
  address: string
  /** The panel build the page runs: when `vite build` made it. */
  build: string
}

/** How many wait on a human in the shop — the sidebar's numbers, the first rows of the dashboard's attention card. */
export interface QueueCounts {
  /** Receipts waiting for a review. */
  payments_to_review: number
  /** Orders paid and not delivered: the delivery failed, or nobody is delivering it (the orders list's stuck tab). */
  stuck_orders: number
  /** Support tickets waiting on an answer (the tickets list's open tab). */
  open_tickets: number
  /** Customers' reviews waiting on support (the reviews list's pending tab). */
  pending_reviews: number
}

export interface QueuesResponse {
  queues: QueueCounts
}

export type UserStatus = 'active' | 'banned'

/** admin = the shop's people inside the bot — /broadcast, exempt from the bot's rules — and on its website (its admin API, while the website lets them in). Not the panel login. */
export type UserRole = 'customer' | 'admin'

/** One row of the users table: a customer of the bot (the panel's own login is not one). */
export interface UserRow {
  id: number
  /** "first last" as Telegram has it, or as they signed up with; null when the account shows none. */
  name: string | null
  /** Bare Telegram handle without "@"; null when the account has none. */
  username: string | null
  /** Their Telegram account; null for one who signed up on the website — the bot cannot write to them. */
  telegram_id: number | null
  /** Their email (lower case, proven); null while they have none. */
  email: string | null
  /** E.164, once shared with the bot. */
  phone: string | null
  status: UserStatus
  role: UserRole
  balance: string
  counts: {
    orders: number
    subscriptions: number
    active_subscriptions: number
  }
  /** The admin's groups the customer is in, in the admin's order. */
  groups: CustomerGroupRef[]
  created_at: string
  last_seen_at: string | null
}

/** What the users table sorts by: when each joined (the list's own order), was last seen, the balance (no ledger is 0), the orders, the running services. */
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

/** GET /users/{id} — one customer, as their page shows them; their services, orders and payments are those lists' own, narrowed to them (`user=`). */
export interface UserDetailResponse {
  user: UserRow
  referral: CustomerReferral
  /** The customer as an agent of the main bot, as the agents list shows them — their level and credit, their bot and what it sold; null for anyone who is not one (so in every agent's shop). */
  agency: AgentRow | null
  account: CustomerAccount
}

/** The customer's account on the shop's website, as support reads it: its ways in, whether its password sign-in asks a second step, the devices signed in, and the accounts merged into it. */
export interface CustomerAccount {
  /** Their email — proven; null without one. */
  email: string | null
  /** A Google account signs them in. */
  google: boolean
  /** Their email and a password sign them in. */
  has_password: boolean
  /** Their password sign-in asks the code of their authenticator app. */
  two_factor: boolean
  /** The devices signed in on the website — the sessions that have not ended. */
  sessions: number
  /** The accounts merged into this one, newest first. */
  merges: CustomerMerge[]
}

/** An account of the shop merged into this customer's — its row gone since —: how it was signed in and called, and when the customer merged them (from their website: the one way two accounts are made one). */
export interface CustomerMerge {
  /** Its former number in the shop. */
  merged_user_id: number
  merged: {
    /** The Telegram account it had; null for none. */
    telegram_id: number | null
    /** Its Telegram handle, without the @. */
    username: string | null
    email: string | null
    /** A Google account signed it in. */
    google: boolean
    /** "first last", as it was; null without one. */
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

/** Make a customer the bot's admin, or take it back. */
export interface UserRoleRequest {
  role: UserRole
}

/** The admin's groups the customer is in: every one listed, no other — an empty list takes them out of all. */
export interface UserGroupsRequest {
  /** CustomerGroupRow ids. */
  group_ids: number[]
}

/** The referral program's facts about a customer: whose link brought them, how many their own link brought and what those earned them. */
export interface CustomerReferral {
  /** The customer whose link brought them; null when none did. */
  referrer: UserRef | null
  /** The customers their link brought. */
  referrals: number
  /** What the commissions credited to them add up to, in Toman — each kept as theirs when it was earned, two accounts merged since or not. */
  earned: string
}

/** One of the admin's groups of customers, as a customer's row carries it. */
export interface CustomerGroupRef {
  id: number
  name: string
}

/** One of the admin's groups of customers («گروه»): a name, in the admin's order; a broadcast can go to one. */
export interface CustomerGroupRow {
  id: number
  name: string
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

/** A group added or renamed. */
export interface CustomerGroupRequest {
  /** Unique in the shop. */
  name: string
}

export type WalletTransactionType = 'credit' | 'debit'

/** One line of a customer's wallet ledger. */
export interface WalletTransactionRow {
  id: number
  type: WalletTransactionType
  amount: string
  balance_after: string
  description: string | null
  /** Who wrote it by hand — a panel's principal, or one of the shop's admins on its website — as its reader may see it (anyone but the owner reads the owner's login as «پشتیبانی»); null for the lines the shop writes itself. */
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

/** A credit or a debit by hand — a debit no further than the balance —, with the line the customer reads in their ledger. */
export interface WalletAdjustmentRequest {
  type: WalletTransactionType
  /** Whole Toman, above zero. */
  amount: AmountInput
  /** The ledger line, 190 characters at most; blank for support's default wording. */
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
    /** Money that came in (Toman): paid payments not made from the wallet, and refunded ones but a wallet top-up's. */
    revenue: {
      value: string
      previous: string
    }
    orders: CountKpi
    new_users: CountKpi
    active_subscriptions: number
    users_total: number
  }
  series: SeriesPoint[]
  attention: {
    /** Receipts waiting for a review. */
    payments_to_review: number
    /** Orders paid and not delivered: the delivery failed, or nobody is delivering it (the orders list's stuck tab). */
    stuck_orders: number
    /** Support tickets waiting on an answer (the tickets list's open tab). */
    open_tickets: number
    /** Customers' reviews waiting on support (the reviews list's pending tab). */
    pending_reviews: number
    pending_orders: number
    /** The shop's servers whose panel failed its last read — 0 in an agent's shop. */
    servers_with_errors: number
    /** Active services ending within `expiring_days`. */
    expiring_soon: number
  }
  /** An agent's shop whose traffic sells nothing; null while it sells, and in the main bot's shop. */
  traffic_shortage: TrafficShortage | null
  /** The one "ending soon" window, in days — the subscriptions screen's too. */
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

export type OrderType = 'purchase' | 'renewal' | 'wallet_topup' | 'traffic'

export interface RecentOrder {
  id: number
  type: OrderType
  status: OrderStatus
  amount: string
  plan: string | null
  user: UserRef
  created_at: string
}

export interface RecentUser {
  id: number
  /** "first last" as Telegram has it, or as they signed up with; null when the account shows none. */
  name: string | null
  /** Bare Telegram handle without "@"; null when the account has none. */
  username: string | null
  /** Their Telegram account; null for one who signed up on the website — the bot cannot write to them. */
  telegram_id: number | null
  /** Their email (lower case, proven); null while they have none. */
  email: string | null
  balance: string
  created_at: string
}

/** How a bot runs. The main bot's is the installation's: every bot gets its updates its way (on webhooks, or polling — they are put on webhooks and taken off together), and its token is config.php's. */
export interface BotRuntime {
  /** It has a token to run on. */
  configured: boolean
  /** Its shop's master switch (تنظیمات ربات), independent of whether the process is running. */
  enabled: boolean
  /** Its @username, without the @; null while unknown. */
  username: string | null
  mode: BotMode
  last_update_at: string | null
}

/** How a bot gets its updates: a webhook Telegram calls, a poller (bot:poll) whose heartbeat is fresh, or none of them. */
export type BotMode = 'webhook' | 'polling' | 'offline'

/** Whether the machinery behind the shop is alive — the owner's alone. */
export interface SystemStatus {
  version: string
  php: string
  /** The bot of the shop the request names (the dashboard's): the main bot, or an agent's. */
  bot: {
    /** The bot's id — 1 is the main bot. */
    id: number
    /** It has a token to run on. */
    configured: boolean
    /** Its shop's master switch (تنظیمات ربات), independent of whether the process is running. */
    enabled: boolean
    /** Its @username, without the @; null while unknown. */
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

/** What putting the bots on webhooks, or taking them off, did for one of them — in the owner's words, never a token nor the secret a webhook's address ends with. */
export interface WebhookOutcome {
  bot: {
    /** The bot's id — 1 is the main bot. */
    id: number
    /** Its @username, without the @; null while unknown. */
    username: string | null
  }
  done: boolean
  /** What happened, or why it did not. */
  message: string
}

export interface WebhooksResponse {
  /** Every bot the shop runs, the main one first; none while no bot has a token. */
  bots: WebhookOutcome[]
}

/** AmoBot's own update: the version the shop runs, the newest release GitHub publishes, and the update under way or the last one. The shop installs only a release the key built into it signed. */
export interface UpdateStatus {
  /** The version the shop runs. */
  current: string
  /** The newest release, as last read; null while GitHub publishes none, or before it was first read. */
  latest: ReleaseInfo | null
  /** When GitHub was last read; null before it ever was. */
  checked_at: string | null
  /** The newest release is newer than the version the shop runs, and no update is under way — nor has just installed it. */
  available: boolean
  /** What keeps the shop from updating itself — no release key to check a release with, PHP without its sodium or zip extension, PHP that may not change the app's files, a release without its signed files — in the owner's words, with the way out (an update by hand: docs/Upgrading.md); null when nothing does. */
  blocker: string | null
  /** The update under way, or the last one; null when there is none. */
  run: UpdateRun | null
}

/** A release as GitHub lists it. */
export interface ReleaseInfo {
  /** Its version (its tag, without the v). */
  version: string
  published_at: string | null
  /** Its notes, as plain text: shown as they are, line breaks kept — never as HTML. */
  notes: string
  /** Its page on GitHub. */
  url: string
}

/** Where an update stands: its files fetched and checked (download), unpacked (extract), the host checked (preflight), installed (install) — then done, or taken back once installed (rolled_back). An install that failed was taken back by itself and stays at install, its error said. */
export type UpdateStep = 'download' | 'extract' | 'preflight' | 'install' | 'done' | 'rolled_back'

export interface UpdateRun {
  /** The version it installs. */
  version: string
  /** The version the shop ran when it began — the one a rollback puts back. */
  from: string
  step: UpdateStep
  /** How far into its step, in percent. */
  progress: number
  /** Why its step was last refused, in the owner's words; null while nothing stands in its way. */
  error: string | null
  /** It may still be given up: nothing of the app's was replaced yet. */
  cancel: boolean
  /** It may be taken back: installed, the version it replaced kept, and the database not changed by it. */
  rollback: boolean
  /** When it was installed or taken back; null while under way. */
  finished_at: string | null
}

export interface UpdateResponse {
  update: UpdateStatus
}

export interface UpdateStartRequest {
  /** The newest release — `latest.version`: an update is to it, and nothing else. */
  version: string
}

export type PaymentStatus = 'pending' | 'awaiting_review' | 'paid' | 'failed' | 'cancelled' | 'refunded'

/** How a gateway settles: at once (the wallet), or once a person — or the method's review window — accepts the receipt (a card transfer). */
export type GatewayKind = 'instant' | 'manual'

/** One row of the payments screen. */
export interface PaymentRow {
  id: number
  status: PaymentStatus
  amount: string
  /** Driver key ("manual", "wallet"). */
  gateway: string
  /** How the driver settles; null once the driver is no longer registered. */
  kind: GatewayKind | null
  /** The method row's label ("کارت به کارت (ملت)") — a method payments were made with is never deleted. */
  method: string
  /** What the customer was told to pay to, as the driver words it (the masked card and its holder); null for a driver without one (the wallet). */
  summary: string | null
  /** The gateway's own id of the payment (the wallet's ledger line); null when it has none (a card transfer). */
  reference: string | null
  /** The word on its latest verdict: why it failed or was cancelled — the admin's reason or note, the gateway's refusal, the order paid another way or left unpaid — or a refund's note (refund_note too). */
  description: string | null
  user: UserRef
  order: {
    id: number
    type: OrderType
    status: OrderStatus
    plan: string | null
    server: string | null
    subscription_id: number | null
    /** Why its delivery failed (null unless it did) — the owner reads the diagnosis of a panel that failed it, anyone else that panel's failure in a word. */
    notes: string | null
  }
  /** The customer's receipt — always a picture: sent in the bot (it takes nothing else), or uploaded from the shop's website; null before it is sent. GET …/receipt serves either. */
  receipt: {
    name: string | null
    note: string | null
    sent_at: string | null
  } | null
  auto_approved: boolean
  /** Who decided it by hand: the agent's @bot, a bot admin's @username / tg:<id> / user#<id> (in the report group or on the shop's website), or the owner's login — «پشتیبانی» to anyone but the owner (Auth\Services\Reviewers). */
  reviewer: string | null
  refund_note: string | null
  paid_at: string | null
  created_at: string
  /** What the modal may do with it right now (the server's rules). */
  actions: {
    approve: boolean
    reject: boolean
    cancel: boolean
    remind: boolean
    retry: boolean
    refund: boolean
  }
}

/** What the payments list sorts by: when each was made (the list's own order), the amount. */
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
    /** The review queue, whatever the list shows. */
    awaiting_review: number
    /** The payments of the list (its tab, search and days) that are paid. */
    paid: number
    /** What those add up to (Toman). */
    paid_amount: string
  }
}

export interface PaymentResponse {
  payment: PaymentRow
}

/** What came of a message the bot wrote a customer of its own accord: told; emailed — Telegram could not take it to them (no Telegram account, or they turned the bot away), and the shop's email took it to their address; they turned the bot away (blocked it, or their account is gone) and no email went; Telegram (or, for an email, the mail server) was out of reach; Telegram refused it; they have no Telegram account (they signed up on the website) and no email went to them — nothing was sent. Whichever, the notice is kept for their website. */
export type Delivery = 'told' | 'emailed' | 'turned_away' | 'unreachable' | 'refused' | 'no_telegram'

export interface ReminderResponse {
  payment: PaymentRow
  delivery: Delivery
}

/** One row of the orders screen: what the customer asked for, and how far it got. */
export interface OrderRow {
  id: number
  type: OrderType
  status: OrderStatus
  amount: string
  user: UserRef
  plan: NamedRef | null
  /** The server the customer chose — for a renewal, where its service is. */
  server: NamedRef | null
  /** The service it bought or renewed; null for a top-up, or once the service was deleted. */
  subscription: {
    id: number
    name: string
    status: SubscriptionStatus
  } | null
  /** Why it failed, or why it was cancelled — a failed delivery as its reader may read it: the owner the diagnosis of a panel that failed it, anyone else (an agent, the shop's admins on its website) that panel's failure in a word. */
  notes: string | null
  /** The attempts to pay it, the newest first. */
  payments: OrderPayment[]
  paid_at: string | null
  fulfilled_at: string | null
  created_at: string
  actions: {
    retry: boolean
    cancel: boolean
  }
}

/** Something pointed at by its id and name (a plan, a server). */
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
  /** The method row's label — a method payments were made with is never deleted. */
  method: string
  paid_at: string | null
  created_at: string
}

/** What the orders list sorts by: when each was placed (the list's own order), the amount. */
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
    /** The stuck tab — paid and not delivered —, whatever the list shows. */
    stuck: number
    /** The orders of the list (its tab, type, search and days) that are sales: paid, being delivered or delivered. */
    sold: number
    /** What those add up to (Toman). */
    sold_amount: string
  }
}

export interface OrderResponse {
  order: OrderRow
}

export type SubscriptionStatus = 'active' | 'expired' | 'disabled' | 'deleted'

/** One row of the subscriptions screen: a client on a panel, as the shop last saw it there. */
export interface SubscriptionRow {
  id: number
  status: SubscriptionStatus
  /** The client's name on the panel ("amir_2", "USER_7"). */
  name: string
  /** The one link the customer got. */
  link: string
  user: UserRef
  /** Null once the plan was deleted. */
  plan: NamedRef | null
  server: NamedRef
  /** Bytes; a limit of 0 is no limit. */
  traffic: {
    limit: number
    used: number
  }
  /** Devices at once; 0 is no limit. */
  ip_limit: number
  /** The term in days, counted from the first connection; 0 never expires. */
  duration_days: number
  /** The customer's «تمدید خودکار»: renewed from their wallet before the deadline. */
  auto_renew: boolean
  /** A renewal queued behind the period in use (what that period leaves unused is not carried): when it begins, and the most it leaves then (bytes). */
  next_period: {
    starts_at: string
    traffic: number
  } | null
  /** When the first connection started the clock; null until then. */
  starts_at: string | null
  /** Null while waiting for the first connection, or without a term. */
  expires_at: string | null
  /** Active, its deadline within the expiring window (the list's `meta.expiring_days`). */
  expiring_soon: boolean
  /** The moment the panel was last read for it — what the numbers are from. */
  last_synced_at: string | null
  disabled_at: string | null
  created_at: string
  /** What the modal may do with it right now (the server's rules). */
  actions: {
    sync: boolean
    disable: boolean
    enable: boolean
    move: boolean
    delete: boolean
    /** Days and traffic on top: an active service that ends some day or has a quota. */
    extend: boolean
  }
}

/** What «افزایش زمان و حجم» gives one service on top of what it has — what a grant gives it —: at least one amount above zero, days only to a service that ends some day, traffic only to one with a quota. In an agent's shop the traffic comes out of the bot's (refused under `traffic_gb` when it is short); the days cost nothing. */
export interface SubscriptionExtensionRequest {
  /** Whole days, 0 to 365; blank is none. */
  days?: WholeNumberInput
  /** Gigabytes, 0 to 10000; blank is none. */
  traffic_gb?: AmountInput
  /** Support's word to the customer, on its own line of the message; 300 characters at most. */
  note?: string
  /** Whether the customer is told; they are unless it is false. */
  notify?: boolean
}

/** A running service moved to another server with what is left of it. `leave_previous` moves it without asking its previous panel, out of reach — the old client stays there for the owner to remove (in an agent's shop only while that panel is out of reach). */
export interface SubscriptionMoveRequest {
  server_id: number
  leave_previous?: boolean
}

/** A service deleted from its panel and the shop for good; the note goes to the customer when they hear at all (a service still active). `leave_panel` deletes it without asking its panel, out of reach — the client stays there (in an agent's shop only while that panel is out of reach). */
export interface SubscriptionDeleteRequest {
  note?: string
  leave_panel?: boolean
}

/** What the services list sorts by: when each was bought (the list's own order), when it ends — one that never does, or has not started, after every deadline —, the traffic used. */
export type SubscriptionSort = 'created' | 'expires' | 'used'

export interface SubscriptionsResponse {
  subscriptions: SubscriptionRow[]
  /** `expiring`: active services ending within `expiring_days` (the tab's count; the dashboard's window too). */
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

/** Where a support ticket stands: open — waiting on support —, answered — support wrote last, waiting on the customer —, closed. A customer's message opens a ticket (again), support's answers it; either side closes it, support reopens it. */
export type TicketStatus = 'open' | 'answered' | 'closed'

/** Who wrote a ticket's message: the customer, or support. */
export type TicketAuthor = 'customer' | 'support'

/** Where a ticket's message was written: the shop's website or its bot (the customer's), a panel, the website's admin side (one of the shop's admins on it) or the report group (support's). */
export type TicketChannel = 'web' | 'bot' | 'panel' | 'staff' | 'group'

/** A message's picture — its bytes are the message's attachment address (…/messages/{message}/attachment). */
export interface TicketAttachment {
  /** Its name, as its sender's device gave it or one made up. */
  name: string
  /** Whether the shop keeps it still: a picture uploaded to a ticket goes 30 days after the ticket closed (one opened again keeps the pictures it still has; one already gone does not come back) — false then, the message saying it had one, its address a 404. */
  kept: boolean
}

/** A support ticket, as the list shows it. */
export interface TicketRow {
  id: number
  subject: string
  status: TicketStatus
  customer: UserRef
  /** The customer's service it is about, by its name on the panel; null for none — or one deleted since. */
  subscription: NamedRef | null
  /** When its latest message was written: the list's order. */
  last_message_at: string
  messages_count: number
  /** 1 to 5: the customer's word on it once it was closed; null for none. */
  rating: number | null
  created_at: string
  /** When it was closed; null while it is not — a ticket opened again leaves its end behind. */
  closed_at: string | null
}

/** One message of a ticket. */
export interface TicketMessageRow {
  id: number
  author: TicketAuthor
  /** Who of support wrote it: a panel's principal, a bot admin's @username, tg:<id> or user#<id> (in the report group or on the shop's website) — the owner's login reads «پشتیبانی» to anyone but the owner; null for the customer's. */
  reviewer: string | null
  body: string
  /** Its picture; null for none. */
  attachment: TicketAttachment | null
  channel: TicketChannel
  created_at: string
}

/** A support ticket with its whole conversation, the first message first. */
export interface TicketDetail {
  id: number
  subject: string
  status: TicketStatus
  customer: UserRef
  /** The customer's service it is about, by its name on the panel; null for none — or one deleted since. */
  subscription: NamedRef | null
  /** When its latest message was written: the list's order. */
  last_message_at: string
  messages_count: number
  /** 1 to 5: the customer's word on it once it was closed; null for none. */
  rating: number | null
  created_at: string
  /** When it was closed; null while it is not — a ticket opened again leaves its end behind. */
  closed_at: string | null
  /** What the customer said with their rating. */
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
    /** The tickets waiting on an answer, whatever the list shows (the queue). */
    open: number
  }
}

export interface TicketResponse {
  ticket: TicketDetail
}

/** A ticket's message — support's answer on a panel, the customer's on their website —: its words. One with a picture comes as a form (TicketMessageUploadRequest). */
export interface TicketMessageRequest {
  /** 1 to 4000 characters, its line breaks kept. */
  body: string
}

/** A ticket's message with its picture, as a browser form uploads it — a form without one is a message without a picture. */
export interface TicketMessageUploadRequest {
  /** 1 to 4000 characters, its line breaks kept. */
  body: string
  /** The picture: a JPEG, a PNG or a WebP, judged by its bytes — 10 MB at most; left out, none. */
  file?: Blob
}

/** Where a customer's review stands: pending — waiting on support —, approved — the website shows it —, rejected — kept, never shown. Support moves it either way, at any time. */
export type ReviewStatus = 'pending' | 'approved' | 'rejected'

/** A review written on the shop's website, as the moderation screen lists it — the writer's own words. */
export interface ReviewRow {
  id: number
  /** The name it is signed with. */
  name: string
  /** 1 to 5. */
  rating: number
  /** Its words, line breaks as written. */
  body: string
  /** A line of where they use the service from («ایرانسل · اندروید · Happ»); null for none. */
  context: string | null
  status: ReviewStatus
  /** The customer who wrote it, signed in on the website; null for a guest's — or one whose account is gone. */
  customer: UserRef | null
  /** Who of support decided it last: a panel's principal, or one of the shop's admins on its website (@username, tg:<id> or user#<id>) — the owner's login reads «پشتیبانی» to anyone but the owner; null while it waits. */
  reviewer: string | null
  /** When it was last approved or rejected; null while it waits. */
  decided_at: string | null
  created_at: string
  /** What its state allows support to decide (a delete, always). */
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
    /** The reviews waiting on support, whatever the list shows (the queue). */
    pending: number
  }
}

export interface ReviewResponse {
  review: ReviewRow
}

/** The panel connectors a server is added with, in their order (each a PanelDriver): each one's words and the versions it needs (`notes`), what the add-server picker draws it by (`traits`: `mark`, two letters; `vendor`; `docs_url`) and what its panels can do (`inbounds`, `link_rotation`) — and a server's form on it: its name, the connector's connection, then the server's own columns. */
export interface ServerDriversResponse {
  drivers: DriverDescription[]
}

export interface ServerRow {
  id: number
  name: string
  driver: string
  driver_label: string
  base_url: string
  /** Its form — the fields its connector's description (ServerDriversResponse) lists — as the server keeps it: how the shop signs in to the panel (`auth_mode`, and each secret as its SecretState: whether one is kept, never the secret), the connection's options, the server's own columns; null while this installation has not its connector. */
  form: DriverValues | null
  is_active: boolean
  capacity: number | null
  sort: number
  notes: string | null
  last_checked_at: string | null
  last_error: string | null
  /** Whether the panel hands out subscription links as of the last check; null = never asked. A server without them cannot be sold. */
  serves_subscriptions: boolean | null
  /** Why nothing can be sold or moved onto the server now (switched off, without subscription links, full, its connector missing…); null when it can. */
  unsellable_reason: string | null
  counts: {
    inbounds: number
    selectable_inbounds: number
    /** Its services running now, every shop's — the main bot's and the agents' bots': what its capacity counts. */
    active_subscriptions: number
    /** Of them, the shop's the panel has open — the ones its subscriptions screen lists (`?server=`); the rest are other shops'. */
    shop_active_subscriptions: number
  }
  created_at: string
  updated_at: string
}

/** An inbound as the panel reports it — from a probe, or the cached copy in server_inbounds. */
export interface ProbeInbound {
  /** The panel's own identifier, opaque. */
  remote_key: string
  tag: string
  /** Null for what is not one protocol (a PasarGuard group). */
  protocol: string | null
  /** Null for what has no port of its own (a PasarGuard group). */
  port: number | null
  remark: string
  enabled: boolean
  network: string | null
  security: string | null
  client_count: number
}

export interface ServerInboundRow {
  /** The panel's own identifier, opaque. */
  remote_key: string
  tag: string
  /** Null for what is not one protocol (a PasarGuard group). */
  protocol: string | null
  /** Null for what has no port of its own (a PasarGuard group). */
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
  /** What the panel calls its core ("Xray"); null when it has no named one. */
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

/** Outcome of talking to a panel. */
export interface Probe {
  ok: boolean
  /** Persian sentence: what went wrong and what to change. */
  error: string | null
  /** Technical detail behind it (panel wording, cURL text), Latin. */
  error_detail: string | null
  status: PanelStatusInfo | null
  /** Null when the panel could not list them — a check then leaves the stored ones as they were. */
  inbounds: ProbeInbound[] | null
  /** Whether the panel serves subscription links; null when that could not be asked. */
  serves_subscriptions: boolean | null
  /** False when the connection worked but the subscription question failed. */
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

/** A server added or changed with its form, whole: its connector (`driver` — a saved server's own: a server's connector does not change) and the fields its description lists (ServerDriversResponse), by name, as typed — its name; the panel's address and the way in, an API token or an admin's username and password, one at a time (the other way's credentials are emptied); the connection's options; its own columns. A secret left blank or left out keeps the stored one while the panel's address stays on its host — moved to another, it is typed again —, and `clear_<name>: true` empties it. A closed shape per connector: tests/Unit/Drivers/DriverFormsTest holds each to its connector's form. */
export type ServerRequest = ServerThreeXuiRequest | ServerPasarGuardRequest

/** A 3X-UI panel's server: its API token, or an admin's username and password with the secret of the two-factor login the panel may ask a code of. */
export interface ServerThreeXuiRequest {
  driver: '3x-ui'
  /** Seen in the panels alone; 100 characters at most. */
  name: string
  /** The panel's address, its path included — http(s), no credentials, query or fragment in it, and none of the panel's own pages (3X-UI's /panel, PasarGuard's /dashboard). */
  base_url: string
  /** The way in: an API token, or an admin's username and password. */
  auth_mode: 'token' | 'password'
  /** With a token: the panel's API token (PasarGuard's whole `pg_key_…` key). Left out or blank: the one kept stays. */
  api_token?: string
  /** True: the kept token is emptied. */
  clear_api_token?: boolean
  /** With a username and password: an admin of the panel. */
  username?: string
  /** With a username and password. Left out or blank: the one kept stays. */
  password?: string
  /** True: the kept password is emptied. */
  clear_password?: boolean
  /** Whether the panel's TLS certificate is checked — off for a self-signed one. */
  verify_tls: boolean
  /** Seconds a request may take, 5 to 120. */
  timeout: WholeNumberInput
  /** What stands before the token of a subscription link instead of the panel's own — a full address, no credentials, query or fragment; blank: the panel's. */
  subscription_url?: string
  /** The running services it takes; blank — no limit, 0 — full. */
  capacity?: WholeNumberInput
  /** The owner's own, 1000 characters at most. */
  notes?: string
  /** Off: no new service goes on it. */
  is_active: boolean
  /** With a username and password: the two-factor login's secret (Base32), whose codes the shop signs in with — as an authenticator app shows it, spaces, dashes and lower case taken. Left out or blank: the one kept stays. */
  totp_secret?: string
  /** True: the kept two-factor secret is emptied — the login asks no code any more. */
  clear_totp_secret?: boolean
}

/** A PasarGuard panel's server: its API key, or an admin's username and password. */
export interface ServerPasarGuardRequest {
  driver: 'pasarguard'
  /** Seen in the panels alone; 100 characters at most. */
  name: string
  /** The panel's address, its path included — http(s), no credentials, query or fragment in it, and none of the panel's own pages (3X-UI's /panel, PasarGuard's /dashboard). */
  base_url: string
  /** The way in: an API token, or an admin's username and password. */
  auth_mode: 'token' | 'password'
  /** With a token: the panel's API token (PasarGuard's whole `pg_key_…` key). Left out or blank: the one kept stays. */
  api_token?: string
  /** True: the kept token is emptied. */
  clear_api_token?: boolean
  /** With a username and password: an admin of the panel. */
  username?: string
  /** With a username and password. Left out or blank: the one kept stays. */
  password?: string
  /** True: the kept password is emptied. */
  clear_password?: boolean
  /** Whether the panel's TLS certificate is checked — off for a self-signed one. */
  verify_tls: boolean
  /** Seconds a request may take, 5 to 120. */
  timeout: WholeNumberInput
  /** What stands before the token of a subscription link instead of the panel's own — a full address, no credentials, query or fragment; blank: the panel's. */
  subscription_url?: string
  /** The running services it takes; blank — no limit, 0 — full. */
  capacity?: WholeNumberInput
  /** The owner's own, 1000 characters at most. */
  notes?: string
  /** Off: no new service goes on it. */
  is_active: boolean
}

/** A connection tried before it is saved: a new server's form (ServerRequest), or a saved server's under its `id` — whose stored secrets the blank ones stand for, while the address stays on its host. A closed shape per connector. */
export type ServerTestRequest = ServerThreeXuiTestRequest | ServerPasarGuardTestRequest

/** A 3X-UI panel's server, tried. */
export interface ServerThreeXuiTestRequest {
  /** The saved server whose form it is. */
  id?: number
  driver: '3x-ui'
  /** Seen in the panels alone; 100 characters at most. */
  name: string
  /** The panel's address, its path included — http(s), no credentials, query or fragment in it, and none of the panel's own pages (3X-UI's /panel, PasarGuard's /dashboard). */
  base_url: string
  /** The way in: an API token, or an admin's username and password. */
  auth_mode: 'token' | 'password'
  /** With a token: the panel's API token (PasarGuard's whole `pg_key_…` key). Left out or blank: the one kept stays. */
  api_token?: string
  /** True: the kept token is emptied. */
  clear_api_token?: boolean
  /** With a username and password: an admin of the panel. */
  username?: string
  /** With a username and password. Left out or blank: the one kept stays. */
  password?: string
  /** True: the kept password is emptied. */
  clear_password?: boolean
  /** Whether the panel's TLS certificate is checked — off for a self-signed one. */
  verify_tls: boolean
  /** Seconds a request may take, 5 to 120. */
  timeout: WholeNumberInput
  /** What stands before the token of a subscription link instead of the panel's own — a full address, no credentials, query or fragment; blank: the panel's. */
  subscription_url?: string
  /** The running services it takes; blank — no limit, 0 — full. */
  capacity?: WholeNumberInput
  /** The owner's own, 1000 characters at most. */
  notes?: string
  /** Off: no new service goes on it. */
  is_active: boolean
  /** With a username and password: the two-factor login's secret (Base32), whose codes the shop signs in with — as an authenticator app shows it, spaces, dashes and lower case taken. Left out or blank: the one kept stays. */
  totp_secret?: string
  /** True: the kept two-factor secret is emptied — the login asks no code any more. */
  clear_totp_secret?: boolean
}

/** A PasarGuard panel's server, tried. */
export interface ServerPasarGuardTestRequest {
  /** The saved server whose form it is. */
  id?: number
  driver: 'pasarguard'
  /** Seen in the panels alone; 100 characters at most. */
  name: string
  /** The panel's address, its path included — http(s), no credentials, query or fragment in it, and none of the panel's own pages (3X-UI's /panel, PasarGuard's /dashboard). */
  base_url: string
  /** The way in: an API token, or an admin's username and password. */
  auth_mode: 'token' | 'password'
  /** With a token: the panel's API token (PasarGuard's whole `pg_key_…` key). Left out or blank: the one kept stays. */
  api_token?: string
  /** True: the kept token is emptied. */
  clear_api_token?: boolean
  /** With a username and password: an admin of the panel. */
  username?: string
  /** With a username and password. Left out or blank: the one kept stays. */
  password?: string
  /** True: the kept password is emptied. */
  clear_password?: boolean
  /** Whether the panel's TLS certificate is checked — off for a self-signed one. */
  verify_tls: boolean
  /** Seconds a request may take, 5 to 120. */
  timeout: WholeNumberInput
  /** What stands before the token of a subscription link instead of the panel's own — a full address, no credentials, query or fragment; blank: the panel's. */
  subscription_url?: string
  /** The running services it takes; blank — no limit, 0 — full. */
  capacity?: WholeNumberInput
  /** The owner's own, 1000 characters at most. */
  notes?: string
  /** Off: no new service goes on it. */
  is_active: boolean
}

/** Whether an inbound is sold — on the plans that sell the whole server. */
export interface InboundSellableRequest {
  is_selectable: boolean
}

export interface InboundsResponse {
  inbounds: ServerInboundRow[]
}

export type ServerGrantStatus = 'running' | 'done' | 'cancelled'

/** «افزودن زمان و حجم»: a grant's part on a server — the grant's terms, and how far it got there, worked through a batch at a time. */
export interface ServerGrantRow {
  /** The part's: what /servers/{id}/grants/{grant} names. */
  id: number
  /** The grant (a «هدیه همگانی») it is this server's part of, when it reaches beyond this server; null for a grant for this server alone. */
  mass_grant_id: number | null
  /** Only the agents' services (a mass gift for the agents). */
  agents_only: boolean
  days: number
  traffic_bytes: number
  /** What the customers read with it. */
  reason: string | null
  /** Whether the customers are told in the bot. */
  notify: boolean
  /** The admin's tick: services still waiting for their first connection get it too, not only running ones. */
  include_unstarted: boolean
  status: ServerGrantStatus
  /** Every active service it goes through, as issued — which of them takes it is the panel's word as each one's turn comes; granted + skipped + failed is how far it got. */
  total: number
  granted: number
  /** Services it was not for when their turn came (switched off, gone, ended beyond what it gives, still waiting for their first connection without the tick). */
  skipped: number
  failed: number
  /** Why it waits at the service it was on, in the owner's words — what keeps the shop from working with the panel (ProviderErrorPresenter::describe(): out of reach, a credential refused or missing…); tried again by itself. Null while it moves on. */
  waiting_reason: string | null
  /** The latest service the panel refused, and why. */
  last_failure: string | null
  reviewer: string | null
  created_at: string
  finished_at: string | null
}

/** A server's grants card: its latest grants, and whom a new one would reach by what the shop last knew. */
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

/** Days and traffic for the services on a server — an outage made good, a gift —: at least one amount above zero. It reaches the services running when it is issued, and those still waiting for their first connection only with `include_unstarted`; the reason goes to the customers, who hear it unless `notify` is false. */
export interface ServerGrantRequest {
  /** Whole days, 0 to 365; blank is none. */
  days?: WholeNumberInput
  /** Gigabytes, 0 to 10000; blank is none. */
  traffic_gb?: AmountInput
  /** Its own line of the message; 300 characters at most. */
  reason?: string
  notify?: boolean
  include_unstarted?: boolean
}

/** Whom a grant would reach by what the shop last knew: running services, and those still waiting for their first connection. */
export interface GrantReach {
  running: number
  unstarted: number
}

/** Every service, the agents' only, or one server's. */
export type MassGrantAudience = 'all' | 'agents' | 'server'

/** A mass gift's part on one server: a server grant, worked through on its own. */
export interface MassGrantPart {
  id: number
  server: NamedRef
  status: ServerGrantStatus
  total: number
  granted: number
  skipped: number
  failed: number
  /** As a server grant's (ServerGrantRow.waiting_reason). */
  waiting_reason: string | null
  last_failure: string | null
}

/** A grant: days and traffic for the running services on every server (or the agents' only, or one server's — a server page's too), a part per server; its numbers are the parts' added up, its status and end its parts'. */
export interface MassGrantRow {
  id: number
  days: number
  traffic_bytes: number
  reason: string | null
  notify: boolean
  include_unstarted: boolean
  audience: MassGrantAudience
  /** The one server, for audience server. */
  server: NamedRef | null
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

/** The mass gift card: the latest grants — the servers' pages' too —, and whom a new one would reach — everyone, the agents, on each server. */
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
  }
}

export interface MassGrantResponse {
  grant: MassGrantRow
}

/** A server grant's terms (ServerGrantRequest) for every server at once — or the agents' services alone (what the bots of agents whose agency stands sold), or one server's. */
export interface MassGrantRequest {
  /** Whole days, 0 to 365; blank is none. */
  days?: WholeNumberInput
  /** Gigabytes, 0 to 10000; blank is none. */
  traffic_gb?: AmountInput
  /** Its own line of the message; 300 characters at most. */
  reason?: string
  notify?: boolean
  include_unstarted?: boolean
  audience: MassGrantAudience
  /** For audience server, the server (a ServerRow.id); blank for the others. */
  server_id?: WholeNumberInput
}

export type BroadcastStatus = 'sending' | 'paused' | 'done' | 'cancelled'

/** Whom it goes to: everyone, buyers, non-buyers, the inactive (no service running), a customer group, agents, one server's customers. */
export type BroadcastAudience = 'all' | 'buyers' | 'non_buyers' | 'inactive' | 'group' | 'agents' | 'server'

export interface BroadcastButton {
  text: string
  url: string
}

/** «ارسال همگانی»: a message a bot admin sent with /broadcast — copied or forwarded to an audience — or (kind unpin) the pins of one taken off again. */
export interface BroadcastRow {
  id: number
  kind: 'message' | 'unpin'
  /** An unpin run: the broadcast whose pins it takes off. */
  source_id: number | null
  /** Copied from the bot (with the link buttons), or forwarded from its first sender. */
  mode: 'copy' | 'forward'
  audience: {
    key: BroadcastAudience
    /** The group or the server the audience names. */
    id: number | null
    label: string
  }
  /** Each message pinned in its chat. */
  pin: boolean
  /** Chats where its message is pinned still (what «لغو پین» would take off). */
  pinned: number
  /** The admin's link buttons under a copy, rows in reading order. */
  buttons: BroadcastButton[][]
  /** What the message is: text, photo, video, animation, document, audio, voice, video_note, sticker, poll, other. */
  content: string | null
  /** Its text or caption, cut short. */
  excerpt: string | null
  status: BroadcastStatus
  /** Recipients when it started; sent + blocked + failed is how far it got. */
  total: number
  sent: number
  /** Recipients who blocked the bot. */
  blocked: number
  failed: number
  /** The bot admin who sent it; null for a run started from the panel. */
  admin: UserRef | null
  /** Who started it — from a panel or the shop's website: the agent's @bot, a bot admin's @username / tg:<id> / user#<id>, or the owner's login — «پشتیبانی» to anyone but the owner. */
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

/** A secret as the settings screen sees it: never the value, only whether one is stored and a recognisable hint. */
export interface SecretState {
  set: boolean
  hint: string
}

export type ConfigGroup = 'app' | 'database' | 'telegram' | 'mail' | 'advanced'

export interface ConfigAppSettings {
  name: string
  /** APP_URL: the address the shop is reached at, its sub-folder included — every address the shop hands out is built on it. */
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

/** How the shop's email goes out: none, or a mail driver's key (DriverDescription.key) — smtp, an account on a mail server; native, the host's own mail, as PHP's mail() sends it (php.ini's sendmail_path); resend, Resend's API (resend.com), with its API key and the sender's domain verified there. */
export type MailTransport = 'none' | 'smtp' | 'native' | 'resend'

/** An SMTP connection: tls — STARTTLS, required; ssl — TLS from the first byte; none. */
export type MailEncryption = 'tls' | 'ssl' | 'none'

/** The shop's email (MAIL_* in config.php) — a website's sign-up codes and password resets, and the notices to a customer Telegram cannot reach, go out by it: how, every mail driver described with its settings, and who the emails come from. */
export interface ConfigMailSettings {
  transport: MailTransport
  /** The address the shop's emails come from; empty: no email goes out. */
  from_address: string
  /** The name they come from; empty: the shop's name. */
  from_name: string
  /** Every mail driver, its form described; its `sender` trait says which address its emails may come from. */
  drivers: DriverDescription[]
  /** Each driver's settings by its key — as config.php holds them, else their defaults; a secret as its SecretState. */
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

/** The panel's own configuration, read from config.php. */
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
    /** The cron trigger with its token masked, like the token; null without one. The whole address is GET /api/admin/settings/config/cron-url. */
    cron_url: string | null
    /** The main bot's webhook, its secret masked. */
    webhook_url: string
  }
}

export interface CronUrlResponse {
  /** The cron trigger, token and all — for the screen's copy action; null without a token. */
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

/** What answered a database probe. */
export interface DatabaseCheck {
  /** The server and its version: "MariaDB 10.4.32", "SQLite 3.45.1" */
  version: string
  /** How many tables its database holds */
  tables: number
}

export interface DatabaseCheckResponse {
  database: DatabaseCheck
}

/** One group of the config.php settings, saved whole (PUT /settings/config/{group}) — the group's fields as ConfigSettingsResponse.groups names them. A secret left blank or left out keeps the stored one, `clear_<name>: true` empties it. */
export type ConfigSettingsRequest = ConfigAppRequest | ConfigTelegramRequest | ConfigMailRequest | ConfigAdvancedRequest | DatabaseRequest

export interface ConfigAppRequest {
  name: string
  url: string
  debug: boolean
  /** An IANA zone id (one of meta.timezones). */
  timezone: string
}

export interface ConfigTelegramRequest {
  /** Left out or blank: the one kept stays — while the Bot API's address (`api_url`) stays on its host. */
  token?: string
  clear_token?: boolean
  /** The bot's @username, the @ optional; blank for none. */
  username?: string
  /** The Bot API's address — http(s), a host, no credentials, ? or #. Moved to another origin (scheme, host or port), every bot's token goes there, agents' too: the save asks `current_password`. */
  api_url: string
  /** The owner's current panel password, asked only while `api_url` moves to another origin (else not read): blank, or a wrong one — a failed sign-in, counted with the login's —, is a 422 on it and nothing is saved. */
  current_password?: string
  /** Seconds, 1 to 60. */
  poll_timeout: WholeNumberInput
  webhook_secret?: string
  clear_webhook_secret?: boolean
}

/** The shop's email, saved whole: the way out, and — for a mail driver — its settings by its form and who the emails come from. A closed shape per way out; what the other drivers keep stays as it is. */
export type ConfigMailRequest = MailNoneRequest | MailSmtpRequest | MailNativeRequest | MailResendRequest

/** No email at all: what the drivers keep stays, for a way back. */
export interface MailNoneRequest {
  transport: 'none'
}

/** An account on a mail server, over SMTP. */
export interface MailSmtpRequest {
  transport: 'smtp'
  /** mail.example.com — no scheme, no port. */
  host: string
  /** 1 to 65535: 587 with tls, 465 with ssl, 25 without. */
  port: WholeNumberInput
  encryption: MailEncryption
  /** Blank for none. */
  username?: string
  /** Left out or blank: the one kept stays — while the server, its port, the encryption and the username stay. */
  password?: string
  /** True: the kept password is emptied. */
  clear_password?: boolean
  /** An email address (kept in lower case). */
  from_address: string
  /** 64 characters at most; blank: the shop's name. */
  from_name?: string
}

/** The host's own mail, as PHP's mail() sends it. */
export interface MailNativeRequest {
  transport: 'native'
  /** An email address (kept in lower case). */
  from_address: string
  /** 64 characters at most; blank: the shop's name. */
  from_name?: string
}

/** Resend's API. */
export interface MailResendRequest {
  transport: 'resend'
  /** Resend's API key, re_… as resend.com › API Keys shows it; left out or blank: the one kept stays — one is needed. */
  resend_key?: string
  /** True: the kept key is emptied. */
  clear_resend_key?: boolean
  /** An email address (kept in lower case). */
  from_address: string
  /** 64 characters at most; blank: the shop's name. */
  from_name?: string
}

/** Where the test email goes. */
export interface MailTestRequest {
  /** An email address. */
  to: string
}

export interface MailTestResponse {
  /** True: the transport took it — whether it lands in the inbox is the mail server's. */
  sent: boolean
}

export interface ConfigAdvancedRequest {
  /** One of meta.log_levels — any other is refused, as a time zone is. */
  log_level: string
  /** Minutes, 5 to 43200. */
  session_lifetime: WholeNumberInput
  session_secure_cookie: boolean
  /** Seconds, 5 to 120. */
  http_timeout: WholeNumberInput
  cron_token?: string
  clear_cron_token?: boolean
}

/** A token to ask Telegram about; blank or left out — the stored one. */
export interface TelegramTestRequest {
  token?: string
}

/** The server as the plan form and the plans table know it. */
export interface PlanServerRef {
  id: number
  name: string
  is_active: boolean
  /** As on the servers screen: null = never checked, false = no subscription links — either way the customer is not shown the entry. */
  serves_subscriptions: boolean | null
}

export interface PlanInboundRef {
  id: number
  remark: string | null
  /** Null for what is not one protocol (a PasarGuard group). */
  protocol: string | null
  /** Null for what has no port of its own (a PasarGuard group). */
  port: number | null
  enabled: boolean
  is_selectable: boolean
}

/** One server a plan is sold on; `inbounds` is what a purchase gets today (resolved for whole-server entries). */
export interface PlanServerEntry {
  server: PlanServerRef
  all_inbounds: boolean
  inbounds: PlanInboundRef[]
  /** Why customers are not offered this entry now (the server switched off, without subscription links, full, without a sellable inbound…); null when they are. */
  unsellable_reason: string | null
}

export interface PlanCategoryRef {
  id: number
  name: string
  is_active: boolean
}

/** A group plans are filed under. */
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

/** A plan as the list shows it. */
export interface PlanRow {
  id: number
  /** Null = uncategorised (sold under "سایر پلن‌ها" when the shop has categories). */
  category: PlanCategoryRef | null
  name: string
  description: string | null
  price: string
  /** 0 = no time limit */
  duration_days: number
  /** Gigabytes, decimals allowed; 0 = unlimited */
  traffic_gb: number
  /** 0 = unlimited */
  ip_limit: number
  servers: PlanServerEntry[]
  /** Why the bot does not show the plan now, its switch aside (ServerSelector::judge(), the bot's own judgement): no server on it, none that can sell (each entry says why), or traffic of an agent's shop that does not cover it; null when it does. */
  unsellable_reason: string | null
  is_active: boolean
  sort: number
  counts: {
    /** Its services running now. */
    active_subscriptions: number
    /** Its orders sold: paid, being delivered or delivered. */
    sales: number
    /** Every order placed for it, whatever came of it; a plan that has any — or any service — is not deleted (switched off instead). */
    orders: number
    /** Every service sold on it, ended ones too. */
    subscriptions: number
  }
  created_at: string
  updated_at: string
}

export interface PlanOptionServer {
  id: number
  name: string
  is_active: boolean
  /** As on the servers screen: null = never checked, false = no subscription links — either way the customer is not shown the entry. */
  serves_subscriptions: boolean | null
  driver_label: string
  inbounds: PlanInboundRef[]
  /** Why nothing can be sold on the server now (switched off, without subscription links, full…); null when it can. */
  unsellable_reason: string | null
}

/** The servers and inbounds the plan form can build entries from, and the categories. */
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

/** A plan as its form sends it, added or changed whole: its words, its price and what it gives — 0 is no limit for the term, the traffic and the devices —, its category, and the servers it is sold on (the set is replaced). */
export interface PlanRequest {
  name: string
  /** Shown with the plan in the bot; blank for none. */
  description?: string
  /** Whole Toman; 0 for a free plan. */
  price: AmountInput
  /** The term in days, from the first connection; 0 — it never ends. */
  duration_days: WholeNumberInput
  /** Gigabytes; 0 — unlimited, which an agent's shop refuses (its traffic is prepaid). */
  traffic_gb: AmountInput
  /** Devices at once; 0 — unlimited. */
  ip_limit: WholeNumberInput
  /** A PlanCategoryRow.id; blank or 0 — none. */
  category_id?: WholeNumberInput
  /** At least one, each server once. */
  servers: PlanServerEntryInput[]
  /** Absent: a new plan starts on, a changed one keeps its switch. */
  is_active?: boolean
}

/** A server the plan is sold on: the whole of it — every inbound the server marks sellable, at purchase time — or the inbounds picked; a driver without inbounds sells the whole server. */
export interface PlanServerEntryInput {
  server_id: number
  /** Absent: the inbounds picked. */
  all_inbounds?: boolean
  /** The server's own enabled inbounds (PlanInboundRef.id); empty for the whole server. */
  inbound_ids?: number[]
}

/** A category added or changed. */
export interface PlanCategoryRequest {
  /** Unique in the shop. */
  name: string
  /** Absent: a new category starts on, a changed one keeps its switch. */
  is_active?: boolean
}

/** The gateway drivers a method is made from, in the order they are registered: each described with its form — a method's settings — and its traits: `kind`, how it settles (a GatewayKind), and `builtin`, part of the shop itself (the wallet: one row in every shop, never added or deleted, nothing to set). */
export interface PaymentDriversResponse {
  drivers: DriverDescription[]
}

/** One row per method the customer may pick, in checkout order — as its driver describes it: `config` is its driver's form, each field as the row keeps it (a closed shape per driver). */
export type PaymentMethodRow = ManualMethodRow | WalletMethodRow | UnregisteredMethodRow

/** A card-to-card method. */
export interface ManualMethodRow {
  id: number
  driver: 'manual'
  driver_label: string
  kind: GatewayKind
  builtin: boolean
  /** What the customer sees on the button: "کارت به کارت (ملت)". */
  label: string
  /** The driver's one line about the method (its masked card and holder), as the payments screen shows it; null when it has none. */
  summary: string | null
  config: ManualGatewayConfig
  enabled: boolean
  sort: number
  counts: {
    /** The payments made with it; a method that has any is not deleted. */
    payments: number
  }
  created_at: string
  updated_at: string
}

/** The wallet, the shop's own method. */
export interface WalletMethodRow {
  id: number
  driver: 'wallet'
  driver_label: string
  kind: GatewayKind
  builtin: boolean
  /** What the customer sees on the button: "کارت به کارت (ملت)". */
  label: string
  /** The driver's one line about the method (its masked card and holder), as the payments screen shows it; null when it has none. */
  summary: string | null
  /** Nothing to configure. */
  config: Record<string, never>
  enabled: boolean
  sort: number
  counts: {
    /** The payments made with it; a method that has any is not deleted. */
    payments: number
  }
  created_at: string
  updated_at: string
}

/** A method whose driver is no longer installed — no checkout offers it, switched on or not —; it can only be switched off or deleted. */
export interface UnregisteredMethodRow {
  id: number
  driver: string
  driver_label: string
  /** No driver says how it settles. */
  kind: null
  builtin: boolean
  /** What the customer sees on the button: "کارت به کارت (ملت)". */
  label: string
  /** The driver's one line about the method (its masked card and holder), as the payments screen shows it; null when it has none. */
  summary: string | null
  /** Not shown — no driver says which of its settings are secrets. */
  config: Record<string, never>
  enabled: boolean
  sort: number
  counts: {
    /** The payments made with it; a method that has any is not deleted. */
    payments: number
  }
  created_at: string
  updated_at: string
}

/** A card-to-card method's settings (ManualMethodRow.config): the manual driver's form, each field as the row keeps it — one kept before the field existed at its default. */
export interface ManualGatewayConfig {
  /** Bare 16 digits. */
  card_number: string
  card_holder: string
  instructions: string
  /** Minutes a receipt may wait for an admin before it is accepted on its own; 0 = only by hand. */
  auto_approve_after: number
}

export interface PaymentMethodsResponse {
  methods: PaymentMethodRow[]
}

export interface PaymentMethodResponse {
  method: PaymentMethodRow
}

/** A card-to-card method's form: the label the customer picks it by, its switch, and the manual driver's form — the card and its holder the bot shows, the note under them, and how long a receipt may wait for a review. */
export interface ManualMethodRequest {
  /** What the checkout's button says: «کارت به کارت (ملت)». Absent on a change: the one it has. */
  label?: string
  /** Absent: a new method starts on, a changed one keeps its switch. */
  enabled?: boolean
  /** 16 digits; Persian ones, and spaces or dashes between them, too. */
  card_number: string
  card_holder: string
  /** Shown to the customer with the card; blank for none. */
  instructions?: string
  /** Minutes a receipt may wait for a review before it is accepted on its own; blank or 0 — only by hand. */
  auto_approve_after?: WholeNumberInput
}

/** The wallet, the shop's own method: nothing to configure but its label and its switch. */
export interface WalletMethodRequest {
  label?: string
  enabled?: boolean
}

/** A new card-to-card method: its driver, and its form (ManualMethodRequest) with the label required. */
export interface ManualMethodCreateRequest {
  /** A DriverDescription.key of GET /payment-methods/drivers. */
  driver: 'manual'
  /** What the checkout's button says: «کارت به کارت (ملت)». Absent on a change: the one it has. */
  label: string
  /** Absent: a new method starts on, a changed one keeps its switch. */
  enabled?: boolean
  /** 16 digits; Persian ones, and spaces or dashes between them, too. */
  card_number: string
  card_holder: string
  /** Shown to the customer with the card; blank for none. */
  instructions?: string
  /** Minutes a receipt may wait for a review before it is accepted on its own; blank or 0 — only by hand. */
  auto_approve_after?: WholeNumberInput
}

/** A new method of a driver that is not built in: the driver's key, the label the customer picks it by, the switch and the driver's form (DriverDescription.fields) — a closed shape per driver, held to its form by tests/Unit/Drivers/DriverFormsTest. */
export type PaymentMethodCreateRequest = ManualMethodCreateRequest

/** A method's form again, as its driver takes it — a closed shape per driver, held to its form by tests/Unit/Drivers/DriverFormsTest; a method whose driver is gone takes none (switch it off or delete it). */
export type PaymentMethodUpdateRequest = ManualMethodRequest | WalletMethodRequest

/** A method's switch in the list: true or false, nothing else. */
export interface PaymentMethodSwitchRequest {
  enabled: boolean
}

/** Telegram's button styles (`style` on KeyboardButton / InlineKeyboardButton); null = the client's default look. */
export type ButtonStyle = 'primary' | 'success' | 'danger'

export interface KeyboardButtonSpec {
  /** An action of the bot (see KeyboardAction.key). */
  action: string
  /** What the button says; for a reply keyboard also what the tap sends. */
  label: string
  style: ButtonStyle | null
  /** A premium emoji's id (see CustomEmojiRow), drawn before the label — Telegram's `icon_custom_emoji_id`, shown only while the bot's owner has Telegram Premium; null = none. */
  icon: string | null
}

export type KeyboardType = 'reply' | 'inline'

/** One of the bot's keyboards as the admin laid it out; rows are in reading order (first = rightmost). */
export interface KeyboardLayoutData {
  name: string
  title: string
  is_default: boolean
  type: KeyboardType
  rows: KeyboardButtonSpec[][]
}

/** Something a menu button can stand for. */
export interface KeyboardAction {
  key: string
  title: string
  /** The default label (emoji + title). */
  label: string
  /** The `menu:*` callback an inline button carries. */
  screen: string
}

export interface KeyboardsResponse {
  keyboards: KeyboardLayoutData[]
  /** What a button of this shop's menu can do — an agent's bot has no «نمایندگی», which its layouts never show and a save refuses. */
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

/** A keyboard laid out anew: its type and its rows in reading order (first = rightmost) — a row left empty is dropped. */
export interface KeyboardRequest {
  type: KeyboardType
  rows: KeyboardButtonInput[][]
}

/** A button as the editor sends it back (a KeyboardButtonSpec): each action once a keyboard, each label once. */
export interface KeyboardButtonInput {
  /** A KeyboardAction.key. */
  action: string
  /** Plain text — a premium emoji goes in `icon`. */
  label: string
  style?: ButtonStyle | null
  /** A premium emoji's id; null — none. */
  icon?: string | null
}

/** Where a text ends up: a message or the QR card's caption (Telegram HTML), a callback popup or a button (shown as typed), or a part another text takes in. */
export type BotTextKind = 'message' | 'caption' | 'popup' | 'button' | 'part'

/** A `%name%` the bot fills in when it sends the text. */
export interface BotTextVariable {
  name: string
  description: string
  /** What the screen's preview puts in its place. */
  sample: string
  /** The text cannot do without it (a delivery without its link). */
  required: boolean
}

/** One text the bot sends a customer, with the shop's wording and the admin's. */
export interface BotTextRow {
  key: string
  group: string
  kind: BotTextKind
  /** Telegram reads it as HTML (a message, a caption, a part). */
  html: boolean
  /** The longest the wording may be, in characters. */
  limit: number
  title: string
  /** Where the customer meets it. */
  description: string
  variables: BotTextVariable[]
  default: string
  /** The wording in use: the admin's, or the default. */
  value: string
  customized: boolean
}

export interface BotTextGroup {
  key: string
  title: string
  texts: BotTextRow[]
}

export interface BotTextsResponse {
  /** The groups the shop's bot says, in the screen's order — an agent's has no «نمایندگی». */
  groups: BotTextGroup[]
}

export interface BotTextResponse {
  text: BotTextRow
}

/** A text reworded: Telegram HTML for a message, a caption or a part, plain text for a popup or a button, with its required variables; the shop's own wording puts the default back. */
export interface BotTextRequest {
  value: string
}

/** A premium emoji an admin showed the bot (/emoji), offered by the bot texts and keyboards editors. */
export interface CustomEmojiRow {
  /** Telegram's custom emoji id — digits, kept as a string. */
  id: string
  /** The plain emoji it stands for: what goes inside its <tg-emoji>. */
  emoji: string
  /** How Telegram draws it: a still picture, a Lottie animation or a WebM video (see …/animation); null = not known yet. */
  format: 'static' | 'animated' | 'video' | null
  /** Telegram paints it in the colour of the text around it (its needs_repainting). */
  repaint: boolean
  updated_at: string
}

export interface CustomEmojisResponse {
  /** The latest seen first. */
  emojis: CustomEmojiRow[]
  /** Whether the bot's own premium emoji came through, as the last /emoji found; null before the first. */
  status: {
    ok: boolean
    checked_at: string
  } | null
}

export interface BotSettingsData {
  enabled: boolean
  phone_required: boolean
  join_required: boolean
  /** The Telegram id or link the bot's "پشتیبانی" screen shows; may be empty. */
  support_contact: string
  /** Smallest top-up, Toman. */
  topup_min: number
  /** The amounts offered as buttons, ascending. */
  topup_presets: number[]
  /** Whether a delivered service comes with a QR code of its link. */
  qr_enabled: boolean
  /** «تمدید سرویس»: whether the traffic a period leaves unused is added to the renewed one (else it goes when the period ends). */
  carry_traffic: boolean
  /** «تمدید خودکار»: how many days before its deadline a service is renewed from the wallet. */
  auto_renew_days: number
  /** Whether a new service starts with the customer's switch on. */
  auto_renew_default: boolean
  /** «یادآوری»: the customer is told a service ends soon — this many days before its deadline. */
  expiry_reminder: boolean
  expiry_reminder_days: number
  /** …and that its traffic runs low — once this share of its quota is used. */
  traffic_reminder: boolean
  traffic_reminder_percent: number
  /** «زیرمجموعه‌گیری»: links bring referrals and payments earn their referrers this percent… */
  referral_enabled: boolean
  referral_rate: number
  /** …of only the first payment of each referred customer. */
  referral_first_only: boolean
  /** «گروه گزارش‌ها»: which topics of the admins' report group get reports — every one on until switched off. */
  report_purchases: boolean
  report_renewals: boolean
  report_wallet: boolean
  report_receipts: boolean
  report_users: boolean
  report_errors: boolean
  /** The main bot's shop only: agency requests and traffic purchases are reported to the shop's own group, so an agent's bot has no such switch. */
  report_agency?: boolean
  /** The support tickets and their conversation — every shop's. */
  report_tickets: boolean
  /** The reviews written on the shop's website — every shop's. */
  report_reviews: boolean
}

/** The picture QR codes are drawn on. */
export interface QrBackgroundInfo {
  /** False = the shipped picture. */
  custom: boolean
  mime: string
  width: number
  height: number
  size: number
  /** File mtime (unix seconds) — a cache-buster for the preview. */
  updated_at: number
  /** The largest upload taken. */
  max_bytes: number
}

export type BotSettingsGroup = 'general' | 'channels' | 'wallet' | 'renewal' | 'auto_renew' | 'reminders' | 'referral' | 'reports' | 'qr'

export interface BotSettingsResponse {
  settings: BotSettingsData
  qr_background: QrBackgroundInfo
}

export interface BotSettingsSaved {
  settings: BotSettingsData
}

export interface QrBackgroundResponse {
  qr_background: QrBackgroundInfo
}

/** The admin's own picture for the QR card, as a browser form uploads it: PNG, JPEG or WebP, judged by its bytes, up to QrBackgroundInfo.max_bytes. */
export interface QrBackgroundRequest {
  file: Blob
}

/** One group of the bot's settings, saved whole (PUT /bot/settings/{group}) — the group's fields as BotSettingsData names them: every switch and number is sent (one left out is refused), a text or a list left out is blank. */
export type BotSettingsRequest =
  | BotGeneralSettingsRequest
  | BotChannelsSettingsRequest
  | BotWalletSettingsRequest
  | BotRenewalSettingsRequest
  | BotAutoRenewSettingsRequest
  | BotRemindersSettingsRequest
  | BotReferralSettingsRequest
  | BotReportsSettingsRequest
  | BotQrSettingsRequest

export interface BotGeneralSettingsRequest {
  enabled: boolean
  phone_required: boolean
  /** 190 characters at most. */
  support_contact?: string
}

export interface BotChannelsSettingsRequest {
  join_required: boolean
}

export interface BotWalletSettingsRequest {
  /** Toman — its thousands set apart as typed too, only between groups of three ("10,000"). */
  topup_min: WholeNumberInput
  /** Toman, none below topup_min, 8 at most. */
  topup_presets?: NumbersInput
}

export interface BotRenewalSettingsRequest {
  carry_traffic: boolean
}

export interface BotAutoRenewSettingsRequest {
  /** 1 to 30. */
  auto_renew_days: WholeNumberInput
  auto_renew_default: boolean
}

export interface BotRemindersSettingsRequest {
  expiry_reminder: boolean
  /** 1 to 30. */
  expiry_reminder_days: WholeNumberInput
  traffic_reminder: boolean
  /** 50 to 99. */
  traffic_reminder_percent: WholeNumberInput
}

export interface BotReferralSettingsRequest {
  referral_enabled: boolean
  /** Percent, 1 to 100. */
  referral_rate: WholeNumberInput
  referral_first_only: boolean
}

export interface BotReportsSettingsRequest {
  report_purchases: boolean
  report_renewals: boolean
  report_wallet: boolean
  report_receipts: boolean
  report_users: boolean
  report_errors: boolean
  /** The main bot's shop only, where it is required; an agent's bot has no such switch. */
  report_agency?: boolean
  report_tickets: boolean
  report_reviews: boolean
}

export interface BotQrSettingsRequest {
  qr_enabled: boolean
}

/** A channel or group the customer must join first. */
export interface BotChannelRow {
  id: number
  chat_id: number
  type: 'channel' | 'supergroup'
  title: string
  /** Public handle without "@"; null for private ones. */
  username: string | null
  /** Where the customer is sent to join. */
  link: string
  /** What the last check found; the bot needs admin rights to see the members. */
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

/** A channel or group the customers must join, as the admin pasted it: t.me/name, @name, or a private chat's numeric id (-100…) — the bot an admin there already. */
export interface BotChannelRequest {
  link: string
}

export type ReportTopicKey = 'purchases' | 'renewals' | 'wallet' | 'receipts' | 'users' | 'errors' | 'agency' | 'tickets' | 'reviews'

/** One topic of the report group, in the group's order. */
export interface ReportTopicInfo {
  key: ReportTopicKey
  /** Its name in the group, and its switch's label. */
  title: string
  /** What comes to it. */
  about: string
  /** Whether the bot has made it in the connected group. */
  ready: boolean
  /** Whether the admin wants its reports (`report_<key>` of the bot settings). */
  enabled: boolean
}

/** The admins' report group. */
export interface ReportGroupData {
  connected: boolean
  /** -100… — a Telegram id, shown as is. */
  chat_id: number | null
  title: string | null
  connected_at: string | null
  /** What keeps reports from reaching the group, in the admin's words; null when nothing does. */
  problem: string | null
  /** Sending waits until then: a flood limit, an unreachable Telegram, the group's problem. */
  paused_until: string | null
  /** Reports queued and not sent yet. */
  waiting: number
  topics: ReportTopicInfo[]
  /** The connect link outstanding: Telegram's add-to-group link, the command that does the same typed into the group, when both stop working. */
  link: {
    url: string
    command: string
    expires_at: string
  } | null
  /** The last group offered with that link and refused, and why. */
  attempt: {
    title: string
    message: string
    at: string
  } | null
  /** The bot's @username without "@"; null until the bot has run once. */
  bot_username: string | null
}

export interface ReportGroupResponse {
  group: ReportGroupData
}

/** Messages queued, and how many went out right away. */
export interface ReportGroupTestResponse {
  group: ReportGroupData
  queued: number
  sent: number
}

/** The program's rules and numbers. */
export interface ReferralSummary {
  enabled: boolean
  rate: number
  first_only: boolean
  /** Customers whose link brought someone. */
  referrers: number
  /** Customers who came through a link. */
  referred: number
  commissions: number
  /** Everything paid out, in Toman. */
  paid: string
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
  /** What the commissions credited to them add up to. */
  earned: string
  last_referral_at: string | null
}

/** A customer who came through someone's link. */
export interface InviteeRow {
  /** The customer's id. */
  id: number
  user: UserRef
  status: UserStatus
  referrer: UserRef | null
  /** What this customer's payments earned the referrer they have — the commissions credited to that one. */
  earned: string
  joined_at: string
}

/** A commission paid out. */
export interface ReferralCommissionRow {
  id: number
  /** Whom it was credited to, kept as it was earned. */
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
  /** The percent then in force. */
  rate: number
  commission: string
  created_at: string
}

/** What the referrers list sorts by: how many each brought (the list's own order), what it earned them, when the last one came. */
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

/** What the invitees list sorts by: when each joined (the list's own order), what their payments earned their referrer. */
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

/** What the commissions list sorts by: when each was paid out (the list's own order), the amount. */
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

/** The agency program's rules — the shop's own (the main bot's settings), on the owner's agents page. */
export interface AgencySettings {
  /** Requests are taken and customers see the menu's button (agents keep their account and bots either way). */
  enabled: boolean
  /** The credit an approved agent starts with, in Toman. */
  default_credit: string
  /** The GB an agent may buy with one tap, smallest first. */
  traffic_presets: number[]
  /** The least GB an agent may type. */
  traffic_min: number
}

export interface AgencySettingsResponse {
  settings: AgencySettings
}

/** The program's rules saved whole, as AgencySettings names them: the switch and the numbers are sent (one left out is refused), the presets left out are none. */
export interface AgencySettingsRequest {
  enabled: boolean
  /** Whole Toman. */
  default_credit: AmountInput
  /** Gigabytes, none below traffic_min, 8 at most. */
  traffic_presets?: NumbersInput
  /** Gigabytes. */
  traffic_min: WholeNumberInput
}

/** The program's numbers, above the lists (its rules are AgencySettings, on their own section). */
export interface AgencySummary {
  levels: number
  agents: number
  /** Agents' bots that run (handed over and switched on). */
  bots: number
  /** Requests waiting for a decision. */
  pending: number
}

export interface AgencySummaryResponse {
  summary: AgencySummary
}

/** A level as another list carries it. */
export interface AgencyLevelRef {
  id: number
  name: string
  /** What a GB of the traffic their bots sell costs its agents, in Toman. */
  price_per_gb: string
}

/** A level of the shop's agents. */
export interface AgencyLevelRow {
  id: number
  name: string
  /** What a GB of the traffic their bots sell costs its agents, in Toman. */
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

/** A level added or changed. */
export interface AgencyLevelRequest {
  /** Unique. */
  name: string
  /** Whole Toman: what its agents pay for a gigabyte of their bot's traffic. */
  price_per_gb: AmountInput
}

export type AgencyRequestStatus = 'pending' | 'approved' | 'rejected'

/** A customer asking to become an agent. */
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
  /** Who decided: the panel login, or a bot admin (@username / tg:<id>). */
  reviewer: string | null
  decided_at: string | null
  created_at: string
  actions: {
    approve: boolean
    reject: boolean
  }
}

/** What the agency requests sort by: when each was sent (the list's own order). */
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

/** An agent's terms — approving a request, or changing an agent's: their level, and how far below zero their wallet may go. */
export interface AgencyTermsRequest {
  /** An AgencyLevelRow.id. */
  level_id: WholeNumberInput
  /** Whole Toman; 0 — none. */
  credit_limit: AmountInput
}

/** An agent — a customer of the main bot on a level — with their bot. */
export interface AgentRow {
  /** The customer's id. */
  id: number
  user: UserRef
  status: UserStatus
  level: AgencyLevelRef
  /** How far below zero their wallet may go, in Toman. */
  credit_limit: string
  /** The wallet, in Toman — below zero while their credit is in use. */
  balance: string
  /** Their bot — its shop opened when they were approved. */
  bot: AgentBot | null
  counts: {
    /** Their bot's customers. */
    customers: number
    /** Services their bot sold (purchases delivered). */
    sold: number
    /** Their bot's services that run. */
    active: number
  }
}

/** An agent's bot: who it is, whether it runs, what keeps it from running, the traffic it may still sell. */
export interface AgentBot {
  id: number
  /** Its @username, without the @ — null until its token is handed over. */
  username: string | null
  /** The name Telegram shows for it. */
  title: string | null
  /** disabled: the agency ended. */
  status: 'active' | 'disabled'
  /** Whether it has a token to run on: handed over, and still readable. */
  connected: boolean
  /** What keeps it from running (Telegram refused its token…). */
  problem: string | null
  /** The traffic it may still sell, in bytes. */
  traffic_balance: number
  /** When its token was first handed over — a new token handed over later keeps it; null before. */
  connected_at: string | null
}

/** An agent's bot whose traffic cannot cover even its smallest plan on sale — or is used up: the bot offers no plan and renews no service until the agent buys traffic in the main bot. */
export interface TrafficShortage {
  /** The traffic it may still sell, in bytes. */
  balance: number
  /** What its smallest plan on sale needs, in bytes; null while none is on sale. */
  smallest_plan: number | null
}

export interface AccountResponse {
  /** Whether their traffic sells nothing; null while it sells. */
  traffic_shortage: TrafficShortage | null
  account: {
    bot: AgentBot
    agent: UserRef
    /** Their level; null once the agency ended. */
    level: AgencyLevelRef | null
    /** How far below zero their wallet with the shop may go, in Toman. */
    credit_limit: string
    /** Their wallet with the shop (in the main bot), in Toman. */
    balance: string
  }
}

/** One line of an agent's traffic. */
export interface TrafficLine {
  id: number
  /** Bought, drawn by a sale or a renewal of their bot or by the traffic the shop gave one of its services, given back (a failed delivery or extension), or set right by the shop. */
  type: 'purchase' | 'sale' | 'renewal' | 'extension' | 'refund' | 'adjust'
  /** Signed: in (+) or out (-). */
  bytes: number
  balance_after: number
  description: string | null
  order_id: number | null
  /** Who moved it — an adjustment, an extension: the agent's bot, the owner's login (which anyone but the owner reads as «پشتیبانی»), or one of the shop's admins on its website. */
  reviewer: string | null
  created_at: string | null
}

export interface TrafficLinesResponse {
  lines: TrafficLine[]
  meta: PageMeta
}

/** What the agents list sorts by: when each joined the bot (the list's own order), the wallet, the bot's traffic, what it sold (none is 0). */
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

/** An agent's traffic set right by hand, never below zero — the note on their ledger. */
export interface TrafficAdjustmentRequest {
  /** Gigabytes added; a negative number (or text after a minus sign) takes them away. */
  gb: AmountInput
  /** The ledger line's note, 190 characters at most (Ledger::NOTE_MAX); blank for none. */
  note?: string
}

export interface AgentChangedResponse {
  agent: AgentRow
  /** Whether the agent was told their new terms; null when the terms sent are the ones they had — nothing changed, nobody told. */
  delivery: Delivery | null
}

/** The shop's website as its panel shows it — never a secret, only whether one is kept. */
export interface Website {
  /** Off: the Store API answers its key with a 404. */
  enabled: boolean
  /** The store key, which names the shop in the API's address — no secret; a new one ends the old address. */
  key: string
  /** What the site's developer is handed: APP_URL, then /api/store/v1/ and the key — every endpoint under it. */
  base_url: string
  /** The site's address, without a trailing slash: its origin may call the API from a browser. */
  url: string | null
  /** The other origins that may (scheme://host[:port]) — a site being built on http://localhost:3000. */
  origins: string[]
  telegram: {
    /** Customers sign in with their Telegram account. */
    enabled: boolean
    /** The Client ID @BotFather shows for the site (Bot Settings › Login Widget), digits; null while none is set. */
    client_id: string | null
    /** The shop's bot's own Telegram id, a hint beside the Client ID; null while the bot has none. */
    bot_id: number | null
    /** A client secret is kept: the redirect flow can exchange a code. The popup needs none. */
    has_secret: boolean
  }
  email: {
    /** Customers sign up with their email and a password. */
    enabled: boolean
    /** Whether the installation's email goes out (the owner's «تنظیمات پنل › ایمیل»): email sign-up is not switched on, and answers nobody, without it. */
    mail_ready: boolean
  }
  google: {
    /** The site's OAuth client id in Google Cloud (….apps.googleusercontent.com); null: Google sign-in off. */
    client_id: string | null
  }
  /** The captcha the website's forms ask — a sign-up, a sign-in with a password, a password reset, a review, and any of the site's own (the Store API's POST /captcha/verify) —: its driver, every captcha described (its form), and each one's fields as the website keeps them, else their defaults (a secret as its SecretState). */
  captcha: {
    /** The captcha asked — a CaptchaDriver —, or none. */
    driver: 'none' | 'turnstile' | 'altcha'
    drivers: DriverDescription[]
    /** Each captcha driver's fields by its key. */
    values: Record<string, DriverValues>
  }
  reviews: {
    /** The website shows the reviews support approved and takes new ones (the Store API's GET and POST /reviews, a 404 while off) — a guest's only while it asks a captcha. Off at first. */
    enabled: boolean
  }
  /** The door the website opens to the shop's admins (its customers whose role is admin): its admin API, /api/store/v1/{store}/admin/* — the panels' operations of the shop's daily work. */
  staff: {
    /** The shop's admins work the shop from the website. Off at first. */
    enabled: boolean
    /** They must have signed in strongly — Telegram, Google, or a password with its second step. On at first. */
    strong_sign_in: boolean
    /** What it lets them do beyond the shop's daily work, in the grants' order. None at first. */
    grants: StaffGrant[]
  }
}

/** What a shop lets its admins do on its website beyond its daily work: the catalogue's plans and categories (catalog), a customer's wallet credited or debited (wallet), a payment given back (refunds), a service given days and traffic (extend), a service deleted for good (delete), a customer's website account taken in hand — two-factor sign-in turned off, every device signed out (account_security). */
export type StaffGrant = 'catalog' | 'wallet' | 'refunds' | 'extend' | 'delete' | 'account_security'

export interface WebsiteResponse {
  website: Website
}

/** A change of the website as its panel makes it — each card sends its own fields. Every field is optional: one sent is changed, one left out stays as it is kept; an empty object changes nothing. What a website needs is asked of the website as it would stand after the change (switched on, an address — the one sent or the one kept), every refusal at once, under its field. A secret sent blank keeps the one kept too; `clear_<secret>` empties it. */
export interface WebsiteRequest {
  /** On needs an address: the one sent, or the one kept. */
  enabled?: boolean
  /** The site's address — http(s), a host, no ? or #, 255 characters at most. Blank: none — refused while the website is on. */
  url?: string
  /** The other origins: a list, or the text an admin types (one a line, or apart by commas) — 10 at most, each http(s) with a host and a port at most, nothing after it. Empty: none. */
  origins?: string[] | string
  /** On needs a Client ID: the one sent, or the one kept. */
  telegram_login?: boolean
  /** Digits, as @BotFather shows them (Persian digits too). Blank: none — refused while Telegram sign-in is on. */
  telegram_client_id?: string
  /** Blank: the one kept stays. */
  telegram_client_secret?: string
  /** True: the kept secret is emptied. */
  clear_telegram_client_secret?: boolean
  /** Customers sign up with their email — not switched on while the installation's email does not go out. */
  email_signup?: boolean
  /** The OAuth client id Google Cloud shows (<digits>-<letters and digits>.apps.googleusercontent.com). Blank: Google sign-in off. */
  google_client_id?: string
  captcha?: WebsiteCaptchaRequest
  /** Whether it shows the reviews support approved and takes new ones. */
  reviews_enabled?: boolean
  /** Whether the shop's admins work it from the website. */
  staff_enabled?: boolean
  /** Whether they must have signed in strongly: Telegram, Google, or a password with its second step. */
  staff_strong_sign_in?: boolean
  /** What it lets them do beyond the shop's daily work — the whole list: what is left out is not granted. */
  staff_grants?: StaffGrant[]
}

/** The captcha the website's forms ask, as its card sends it: its driver — `none` for none — and the driver's form whole, checked by it; a secret left blank or left out keeps the one kept while the driver and the fields it belongs with stay (`clear_<name>: true` empties it). Refused under `captcha.<field>`. A closed shape per driver. */
export type WebsiteCaptchaRequest = WebsiteCaptchaOffRequest | WebsiteTurnstileRequest | WebsiteAltchaRequest

/** No captcha: the forms ask none. */
export interface WebsiteCaptchaOffRequest {
  driver: 'none'
}

/** Cloudflare Turnstile: the widget's keys, as the Cloudflare dashboard shows them. */
export interface WebsiteTurnstileRequest {
  driver: 'turnstile'
  /** Public — the widget is drawn with it. 64 characters at most, printable Latin. */
  site_key: string
  /** Blank: the one kept stays — while the site key stays. */
  secret_key?: string
  /** True: the kept secret is emptied. */
  clear_secret_key?: boolean
}

/** ALTCHA: no keys — the shop issues and checks its challenges itself. */
export interface WebsiteAltchaRequest {
  driver: 'altcha'
}

/** GET / of the Store API: the shop as its website introduces it — what the site asks first. */
export interface StoreResponse {
  shop: {
    /** The main shop's name (APP_NAME); an agent's, their bot's title (else its @username). */
    name: string
    /** Whether the shop takes orders now — its bot switched on. False: a purchase, a renewal, a top-up and a receipt are refused (503); what it sells is still read, and customers still sign in. */
    taking_orders: boolean
    /** The shop's Telegram bot; null while its @username is not known. */
    bot: {
      /** Without the @. */
      username: string
      /** https://t.me/ and the username. */
      url: string
    } | null
  }
  /** How to reach support — the bot's «پشتیبانی» contact as a link: an @handle's t.me address, a web address, a tel: number. Null when none is set, or it is no link. */
  support: {
    url: string
  } | null
  sign_in: {
    /** Telegram sign-in while it is on; null when it is off. */
    telegram: {
      /** The Client ID the site signs in under — telegram-login.js's client_id. */
      client_id: string
      /** The redirect flow is set up too (a client secret is kept): POST /auth/telegram/authorize answers. */
      redirect: boolean
    } | null
    /** Google sign-in while it is on; null when it is off. */
    google: {
      /** The OAuth client id the site signs in under — Google Identity Services' client_id. */
      client_id: string
    } | null
    /** Signing up with an email and a password is on — and the shop's email goes out (its codes reach the customer). A customer who signed up before signs in with their password whatever this says. */
    email: boolean
  }
  /** The captcha the website's forms ask, while it asks one (null: none): what a page draws its widget with. A sign-up, a sign-in with a password, a password reset and a review carry its token (`captcha`), the widget naming the operation's action (`x-captcha`: Turnstile's data-action, ALTCHA's challenge asked with ?action=); a form of the site's own is judged by its backend (POST /captcha/verify). */
  captcha: {
    driver: CaptchaDriver
    /** The widget's public key — Turnstile's site key; null for ALTCHA, which has none. */
    site_key: string | null
    /** Where the widget fetches its challenge — ALTCHA's challengeurl (GET /captcha/challenge, ?action= naming the form's action); null for Turnstile, which draws its own. */
    challenge_url: string | null
  } | null
  /** The referral program's terms: a newcomer who signs in with an invite code (`referral_code`) earns its owner a share of what they pay. */
  referral: {
    enabled: boolean
    /** The share, in percent, of a referred customer's payment. */
    rate: number
    /** Only each referred customer's first payment earns it. */
    first_only: boolean
  }
}

/** A captcha a website's forms may ask: turnstile — Cloudflare Turnstile, its widget drawn with the site's key, its token checked with Cloudflare; altcha — ALTCHA, open source, no third party and no keys: the visitor's browser solves a challenge the shop issues, which the shop checks itself. */
export type CaptchaDriver = 'turnstile' | 'altcha'

/** The action a captcha's widget names for a form — the shop's own sign_up, sign_in, password_reset and review (each operation's `x-captcha`), or a site's own (`contact`): a token solved for one passes no form of another. 1 to 32 of A-Z, a-z, 0-9, - and _ (Turnstile's data-action). */
export type CaptchaAction = string

/** ALTCHA's challenge, in its widget's own shape: a salt carrying when it expires (and the action it was asked for), the SHA-256 of the salt and a number up to `maxnumber`, and the shop's signature on it — the widget finds the number and hands the form the payload (as base64 JSON), which the form sends as its `captcha`. */
export interface StoreCaptchaChallengeResponse {
  algorithm: 'SHA-256'
  challenge: string
  maxnumber: number
  salt: string
  signature: string
}

/** A token a form of the website's own carried, which its backend asks the shop to judge — as the visitor's browser posted it to the site. */
export interface StoreCaptchaVerifyRequest {
  /** What the widget handed the form: Turnstile's cf-turnstile-response, ALTCHA's payload. 4096 characters at most. */
  token: string
  action?: CaptchaAction
  /** The visitor's address (IPv4 or IPv6) as the site's backend saw it — their tokens refused are counted against their network, not against the backend's, which is every visitor's —, handed to the captcha's provider too (Turnstile's remoteip). Optional: blank or left out, the backend's own network is counted. */
  remoteip?: string
}

/** What came of a token: whether it passed, the action and the host it was solved for — as far as the captcha says —, when the shop judged it, and why it did not pass. */
export interface StoreCaptchaVerdict {
  passed: boolean
  /** The action it was solved for, as the widget named it; null: none. */
  action: string | null
  /** The host it was solved on (Turnstile says it); null when the captcha does not say (ALTCHA). */
  hostname: string | null
  verified_at: string
  /** Why it did not pass, empty when it did: hostname-mismatch (solved on none of the website's hosts — its address and its origins), action-mismatch (for another action than `action`), timeout-or-duplicate (expired, or taken before), invalid-input-response (no token of the captcha's, or tampered with), missing-input-response — and Cloudflare's own codes as it says them. */
  reasons: string[]
}

export interface StoreNonceResponse {
  /** Handed to the provider's sign-in (telegram-login.js's nonce), and posted back with its id_token — once. */
  nonce: string
  /** Seconds it waits for its token. */
  expires_in: number
}

/** A redirect sign-in begun in this browser: where Telegram sends it back to with its code and state, and the PKCE challenge of the verifier the site made for it and keeps (sessionStorage) — the shop keeps no verifier, so a code and state another browser brings back open nothing. */
export interface StoreTelegramAuthorizeRequest {
  /** An http(s) address on the website's own origin or one it lists, without #. */
  redirect_uri: string
  /** S256: the SHA-256 of the verifier (43 to 128 of A-Z, a-z, 0-9 and -._~, random), in base64url without padding. */
  code_challenge: string
}

export interface StoreAuthorizeResponse {
  /** Telegram's authorization address, to send the browser to: client_id, redirect_uri, scope, state, the PKCE challenge (S256) and a nonce on it. */
  url: string
}

/** A Telegram sign-in: the popup's id_token with the nonce it was opened with, or the redirect's code with its state. */
export type StoreTelegramSignInRequest = StoreTelegramPopupRequest | StoreTelegramCodeRequest

export interface StoreTelegramPopupRequest {
  /** What telegram-login.js handed the page. */
  id_token: string
  /** The nonce POST /auth/nonce answered, which the popup was opened with. */
  nonce: string
  /** An invite code the visitor came with: a newcomer becomes its owner's referral. Nothing for a customer the shop knows already. */
  referral_code?: string
}

export interface StoreTelegramCodeRequest {
  /** What Telegram sent the browser back with. */
  code: string
  /** The state it came back with: the one POST /auth/telegram/authorize put on the address — a sign-in's (a signed-in customer's own signs nobody in). */
  state: string
  /** The PKCE verifier this browser made and kept when it began the sign-in — the one whose challenge it sent: another's opens nothing. */
  code_verifier: string
  /** An invite code the visitor came with: a newcomer becomes its owner's referral. Nothing for a customer the shop knows already. */
  referral_code?: string
}

/** A Google sign-in: the id_token Google Identity Services handed the page (its `credential`), with the nonce it was asked for. */
export interface StoreGoogleSignInRequest {
  /** What Google handed the page. */
  id_token: string
  /** The nonce POST /auth/nonce answered, which the sign-in was asked for with. */
  nonce: string
  /** An invite code the visitor came with: a newcomer becomes its owner's referral. Nothing for a customer the shop knows already. */
  referral_code?: string
}

/** A sign-up's first step. Every refusal at once, under its field. */
export interface StoreRegisterRequest {
  /** 1 to 64 characters. */
  first_name: string
  /** 64 characters at most; blank for none. */
  last_name?: string
  /** An email address, 191 characters at most — kept in lower case. */
  email: string
  /** 8 characters at least, 72 bytes at most (a Persian letter is two) — taken as typed, spaces and all. */
  password: string
  /** An invite code the visitor came with: a newcomer becomes its owner's referral. Nothing for a customer the shop knows already. */
  referral_code?: string
  /** The captcha widget's token, while the website asks one (GET / says which, and how to draw it), solved for the operation's action (its `x-captcha`). */
  captcha?: string
}

/** A sign-up's last step: the code the email carried. */
export interface StoreVerifyRequest {
  email: string
  /** Six digits (Persian digits too). */
  code: string
}

export interface StoreLoginRequest {
  email: string
  password: string
  /** The captcha widget's token, while the website asks one (GET / says which, and how to draw it), solved for the operation's action (its `x-captcha`). */
  captcha?: string
}

export interface StoreForgotRequest {
  email: string
  /** The captcha widget's token, while the website asks one (GET / says which, and how to draw it), solved for the operation's action (its `x-captcha`). */
  captcha?: string
}

export interface StoreResetRequest {
  email: string
  /** Six digits (Persian digits too): what the email carried. */
  code: string
  /** The new one: 8 characters at least, 72 bytes at most. */
  password: string
}

export interface StoreCodeSentResponse {
  /** Seconds the code waits to be typed back (it takes five tries). */
  expires_in: number
}

export interface StoreSignInResponse {
  /** The customer's bearer token on this device, shown this once — the site keeps it (`Authorization: Bearer …`). */
  token: string
  customer: StoreCustomer
}

/** The signed-in customer's account as their website shows it. */
export interface StoreCustomer {
  id: number
  first_name: string | null
  last_name: string | null
  /** Their Telegram account: its numeric id, and its handle (without the @; null without one). Null for one who signed up on the website. */
  telegram: {
    id: number
    username: string | null
  } | null
  /** Their email — proven: a code sent to it typed back, or Google vouching for it; null without one. */
  email: string | null
  /** A Google account signs them in. */
  google: boolean
  /** Their email and a password sign them in. */
  has_password: boolean
  /** Their password sign-in asks a second step: the code of their authenticator app (or a recovery code). */
  two_factor: boolean
  /** E.164 (+98912…), the number they shared with the bot. */
  phone: string | null
  /** The wallet, in Toman (a decimal string, '120000.00'); below zero an agent's debt. */
  balance: string
  /** Since when they are the shop's customer. */
  created_at: string
}

export interface StoreMeResponse {
  customer: StoreCustomer
}

/** GET /me and PATCH /me: the account, the count a site's bell shows, and — one of the shop's admins — what its admin API lets them do. */
export interface StoreAccountResponse {
  customer: StoreCustomer
  /** How many of the notices the shop told them they have not read (GET /notifications). */
  unread_notifications: number
  /** Null for a customer who is no admin of the shop, and while the website lets no admin in. */
  staff: StoreStaff | null
}

/** The customer as one of the shop's admins on its website: what its admin API (/admin/*: the panels' operations this file marks x-staff) answers them. */
export interface StoreStaff {
  /** What the website lets its admins do beyond the shop's daily work: an operation marked with a grant answers only while it is here. */
  grants: StaffGrant[]
  /** The website asks its admins a strong sign-in: Telegram, Google, or a password with its second step. */
  strong_sign_in: boolean
  /** This session was signed in so — or a strong way was proven on it since (POST /me/reauthenticate). */
  signed_in_strongly: boolean
  /** Until when this session's sign-in lets it work the admin API at all — 12 hours after a way in was last proven on it; null: prove a way in again first (POST /me/reauthenticate). */
  signed_in_until: string | null
  /** Until when this session's sign-in counts as recent — what a granted operation, and approving a payment (x-staff-recent), asks too; null: prove a way in again first (POST /me/reauthenticate). */
  recent_until: string | null
}

/** A device the customer is signed in on. */
export interface StoreSession {
  id: number
  /** What its browser said it is, in words: «Chrome در Windows». */
  device: string | null
  /** The address it signed in from. */
  ip: string | null
  /** When it signed in. */
  created_at: string
  /** Its last request — written every few minutes, not on each. */
  last_used_at: string | null
  /** The one this request came with. */
  current: boolean
}

export interface StoreSessionsResponse {
  sessions: StoreSession[]
}

/** The password was right for an account whose sign-in asks a second step: the challenge its code is posted with (POST /auth/login/2fa) — no session opened yet. */
export interface StoreTwoFactorResponse {
  two_factor: {
    /** Posted back with the code; it takes five wrong codes. */
    challenge: string
    /** Seconds it waits for the code. */
    expires_in: number
  }
}

/** A password sign-in's second step. */
export interface StoreTwoFactorRequest {
  /** The challenge POST /auth/login (or /auth/password/reset) answered. */
  challenge: string
  /** The six-digit code the account's authenticator app shows now (Persian digits and spaces too), or one of its recovery codes (dashes and spaces, any case). */
  code: string
}

/** The customer's names: a field sent is changed, one left out stays. A Telegram account's names are Telegram's. */
export interface StoreProfileRequest {
  /** 1 to 64 characters. */
  first_name?: string
  /** 64 characters at most; blank for none. */
  last_name?: string
}

export interface StorePasswordRequest {
  /** The password now — required while the account has one. */
  current_password?: string
  /** The new one: 8 characters at least, 72 bytes at most (a Persian letter is two) — taken as typed. */
  password: string
}

/** A new secret for the customer's authenticator app, waiting for its first code. */
export interface StoreTwoFactorSetupResponse {
  /** Base32 (160 bits): what the app takes when it is typed in by hand. */
  secret: string
  /** The otpauth:// address the app takes it by — for a QR code, or a tap on the phone: the shop's name its issuer, the account's email its name. */
  uri: string
}

export interface StoreTwoFactorEnableRequest {
  /** The six digits the app shows now for the secret POST /me/2fa/setup gave (Persian digits too). */
  code: string
}

/** Two-factor sign-in on: its recovery codes — each signs in once in place of the app's code —, shown this once (the shop keeps their keyed hashes only). */
export interface StoreRecoveryCodesResponse {
  /** Ten codes, each 10 capital letters and digits without look-alikes (no 0/O, 1/I/L). */
  recovery_codes: string[]
}

export interface StoreTwoFactorDisableRequest {
  /** The account's password. */
  password: string
}

/** A Telegram account proven as its sign-in proves one: the popup's id_token with the nonce it was opened with, or the redirect's code with its state. */
export type StoreTelegramLinkRequest = StoreTelegramLinkPopupRequest | StoreTelegramLinkCodeRequest

export interface StoreTelegramLinkPopupRequest {
  /** What telegram-login.js handed the page. */
  id_token: string
  /** The nonce POST /auth/nonce answered, which the popup was opened with. */
  nonce: string
}

export interface StoreTelegramLinkCodeRequest {
  /** What Telegram sent the browser back with. */
  code: string
  /** The state it came back with: the one POST /me/telegram/authorize gave this customer (a sign-in's, or another customer's, proves nothing). */
  state: string
  /** The PKCE verifier this browser made and kept when it began the sign-in — the one whose challenge it sent: another's opens nothing. */
  code_verifier: string
}

/** A Google account proven as its sign-in proves one: the id_token Google Identity Services handed the page, with the nonce it was asked for. */
export interface StoreGoogleLinkRequest {
  /** What Google handed the page. */
  id_token: string
  /** The nonce POST /auth/nonce answered. */
  nonce: string
}

/** An email for the account, and the password chosen to sign in by it. Every refusal at once, under its field. */
export interface StoreEmailLinkRequest {
  /** An email address, 191 characters at most — kept in lower case. */
  email: string
  /** 8 characters at least, 72 bytes at most — taken as typed. */
  password: string
}

export interface StoreEmailLinkVerifyRequest {
  email: string
  /** Six digits (Persian digits too): what the email carried. */
  code: string
}

/** A kind of way in: a Telegram account, a Google account, an email (with its password). */
export type StoreIdentityKind = 'telegram' | 'google' | 'email'

/** The way in being added is another account's of the shop: that account, for the customer to judge it is theirs too, which of the two stays, and the ticket that makes them one (POST /me/merge). */
export interface StoreMergeOfferResponse {
  merge: {
    /** The merge ticket — this account's alone, once. */
    token: string
    /** Seconds it waits for the customer. */
    expires_in: number
    /** The other account. */
    account: {
      /** "first last"; null without one. */
      name: string | null
      /** Since when it is the shop's customer. */
      created_at: string
      /** Its running services. */
      services: number
      orders: number
      /** Its wallet, in Toman. */
      balance: string
      /** A Telegram account signs it in. */
      telegram: boolean
      /** An email signs it in. */
      email: boolean
      /** A Google account signs it in. */
      google: boolean
    }
    /** The account that stays — the older: this one, or the other. Everything the other owns becomes its own; its ways in fill the empty slots. */
    keeps: 'this' | 'other'
  }
}

export interface StoreMergeRequest {
  /** The ticket the merge offer carried. */
  token: string
}

/** A way into this account proven again: its password (and its second step's code while it asks one), its Telegram account — the popup's or the redirect's —, or its Google account. */
export type StoreReauthenticateRequest = StoreReauthenticatePasswordRequest | StoreReauthenticateTelegramPopupRequest | StoreReauthenticateTelegramCodeRequest | StoreReauthenticateGoogleRequest

export interface StoreReauthenticatePasswordRequest {
  method: 'password'
  /** The account's password. */
  password: string
  /** While two-factor sign-in is on: the six-digit code the account's authenticator app shows now, or one of its recovery codes (spent) — as POST /auth/login/2fa takes it. */
  code?: string
}

export interface StoreReauthenticateTelegramPopupRequest {
  method: 'telegram'
  /** What telegram-login.js handed the page. */
  id_token: string
  /** The nonce POST /auth/nonce answered, which the popup was opened with. */
  nonce: string
}

export interface StoreReauthenticateTelegramCodeRequest {
  method: 'telegram'
  /** What Telegram sent the browser back with. */
  code: string
  /** The state it came back with: the one POST /me/telegram/authorize gave this customer. */
  state: string
  /** The PKCE verifier this browser made and kept when it began the sign-in — the one whose challenge it sent: another's opens nothing. */
  code_verifier: string
}

export interface StoreReauthenticateGoogleRequest {
  method: 'google'
  /** What Google handed the page. */
  id_token: string
  /** The nonce POST /auth/nonce answered. */
  nonce: string
}

export interface StoreReauthenticatedResponse {
  /** Seconds this session may change how the account is signed in to before it is asked again (15 minutes). */
  expires_in: number
}

/** A plan as the shop offers it now: what it gives and costs, the category it is filed under, the servers a customer may pick it on today. */
export interface StorePlan {
  id: number
  name: string
  description: string | null
  /** Toman, a decimal string ('120000.00'). */
  price: string
  /** Gigabytes, decimals allowed; 0 = unlimited. */
  traffic_gb: number
  /** The term, counted from the first connection; 0 = it never ends. */
  duration_days: number
  /** Devices at once; 0 = no limit. */
  devices: number
  /** The category it is offered under (its group's); null for the group of the rest — no category, or one switched off. */
  category_id: number | null
  /** The servers a customer may pick it on today («لوکیشن» in the bot), in the plan's order — at least one. */
  locations: NamedRef[]
}

export interface StorePlansResponse {
  /** Every active category in its order — an empty one too —, then, last, the plans of none (or of one switched off): without a category the shop has but this one group, or none when it sells nothing now. */
  groups: {
    /** Null for the group of the rest («سایر پلن‌ها» in the bot). */
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
    /** It takes a new customer now: switched on, serving subscription links, with room. */
    available: boolean
    /** When the shop last talked to it; null — never. */
    checked_at: string | null
  }[]
}

/** One of the customer's services, as the shop last saw it on its panel (`synced_at`), with what they may do with it now. */
export interface StoreSubscription {
  id: number
  /** Its name on the panel ("amir_2", "USER_7"). */
  name: string
  status: SubscriptionStatus
  /** The plan it was sold or last renewed on; null once that plan was deleted. */
  plan: NamedRef | null
  /** The server it is on («لوکیشن» in the bot). */
  server: NamedRef
  /** The one link the customer subscribes to; null once the panel no longer has the service (deleted). */
  link: string | null
  traffic: StoreServiceTraffic
  term: StoreServiceTerm
  /** «تمدید خودکار»: renewed from the wallet before its end, on its plan at its price. */
  auto_renew: {
    /** The customer's switch. */
    on: boolean
    /** The switch may be set (PATCH): it runs, it ends some day, it has its plan, and the wallet is on. */
    offered: boolean
    /** How many days before its end it is renewed, its switch on — the shop's rule, one for every service (1 to 30). */
    days_before: number
  }
  /** Its customer may renew it now, on its plan: it is active or ended, its plan still there, renewing something — and in an agent's shop covered by their traffic. */
  renewable: boolean
  /** Its link may be changed now (POST …/rotate-link): it runs, and its server can give it a new one. */
  link_rotation: boolean
  /** Null while no renewal is queued behind the period in use. */
  next_period: StoreNextPeriod | null
  /** Whether it is connected — only when its panel was just asked (POST …/refresh) and has it; null otherwise. */
  presence: {
    /** Connected right now; null when the panel cannot tell. */
    online: boolean | null
    /** Its last connection; null — never, or the panel cannot tell. */
    last_online_at: string | null
  } | null
  /** When its panel was last read for it — what the numbers are from. */
  synced_at: string | null
  created_at: string
}

/** A service's quota and what of it is used and left, in bytes. */
export interface StoreServiceTraffic {
  /** 0 = unlimited. */
  limit_bytes: number
  used_bytes: number
  /** Null when unlimited. */
  remaining_bytes: number | null
}

/** A service's term: how long, when its clock started and when it ends — or that it waits for the first connection. */
export interface StoreServiceTerm {
  /** The term in days, counted from the first connection; 0 = it never ends. */
  duration_days: number
  /** When the first connection started the clock; null until then. */
  starts_at: string | null
  /** Null while it waits for the first connection, or without a term. */
  expires_at: string | null
  /** Its term has not started: it starts at the first connection. */
  awaits_first_use: boolean
}

/** A renewal queued behind the period in use, what that period leaves unused not carried: when the period ends, and the most that is left then (bytes). */
export interface StoreNextPeriod {
  ends_at: string
  bytes: number
}

export interface StoreSubscriptionsResponse {
  subscriptions: StoreSubscription[]
  meta: PageMeta
}

export interface StoreSubscriptionResponse {
  subscription: StoreSubscription
}

export interface StoreSubscriptionRequest {
  /** Its «تمدید خودکار», on or off. */
  auto_renew: boolean
}

/** One attempt to pay an order. */
export interface StorePayment {
  id: number
  /** The way it was paid. */
  method: StorePaymentMethod
  status: PaymentStatus
  /** Toman. */
  amount: string
  created_at: string
  paid_at: string | null
  /** The receipt the customer sent for it — in the bot, or from the website; null — none sent. */
  receipt: {
    sent_at: string
  } | null
  /** Support's word on its latest verdict — a rejection's reason, a cancellation's or a refund's note — as the bot tells the customer; null without one. */
  note: string | null
}

/** An order of the customer's: what it was for, how far it got, its payments (the newest first). */
export interface StoreOrder {
  id: number
  type: OrderType
  status: OrderStatus
  /** Toman. */
  amount: string
  created_at: string
  fulfilled_at: string | null
  /** A purchase's or a renewal's plan; null for a wallet top-up, or once the plan was deleted. */
  plan: NamedRef | null
  /** Where a purchase was bought — a renewal's, where its service is; null for none. */
  server: NamedRef | null
  /** The service it is about, by its name on the panel — a renewal's from the order's start, a purchase's once it is delivered — as a ticket names the service it is about; null for none, or once it was deleted. */
  subscription: NamedRef | null
  payments: StorePayment[]
}

export interface StoreOrdersResponse {
  orders: StoreOrder[]
  meta: PageMeta
}

export interface StoreOrderResponse {
  order: StoreOrder
}

/** A way to pay, as the checkout names it. */
export interface StorePaymentMethod {
  id: number
  /** What its button says ("کارت به کارت (ملت)"). */
  label: string
  /** instant — the wallet, paid at once; manual — a card transfer and its receipt; null for a way of paying no longer installed. */
  kind: GatewayKind | null
}

export interface StorePaymentMethodsResponse {
  /** In checkout order; none while the shop takes no way to pay that kind of order. */
  methods: StorePaymentMethod[]
}

/** A purchase: the plan, the location it is bought on, and the way to pay it. */
export interface StoreOrderRequest {
  /** A plan the shop sells now (GET /plans). */
  plan_id: number
  /** One of the plan's `locations`. */
  server_id: number
  /** A way to pay a purchase (GET /payment-methods?for=purchase). */
  method_id: number
}

/** A service's renewal: the way to pay it. */
export interface StoreRenewalRequest {
  /** A way to pay a renewal (GET /payment-methods?for=renewal). */
  method_id: number
}

/** A wallet top-up: how much, and the way to pay it. */
export interface StoreTopUpRequest {
  /** Toman, whole, as a customer types it — a JSON number or its text: Persian digits, thousands separators and «تومان» too — or as this API answers amounts (GET /wallet's "50000.00": a fraction of zero), within GET /wallet's `top_up` bounds. */
  amount: WholeNumberInput
  /** A way to pay a top-up — any but the wallet itself (GET /payment-methods?for=wallet_topup). */
  method_id: number
}

/** A card transfer's receipt, as a browser form uploads it. */
export interface StoreReceiptRequest {
  /** The picture: a JPEG, a PNG or a WebP, judged by its bytes — 10 MB at most. */
  file: Blob
  /** The customer's words with it, as support reads them — 1024 characters at most; blank for none. */
  note?: string
}

/** What a checkout came to — a purchase, a renewal, a top-up. One that paid nothing is no answer but a refusal: the wallet short of the price (422 on `method_id`, what is missing), the order paid or closed elsewhere in the same moment (409). */
export interface StoreCheckout {
  /** settled — paid at once (the wallet): the order as it ended, `fulfilled` (a purchase's or a renewal's service in `subscription`) or `failed` (support finishes it, the money in: the order's own notes are support's, never shown) — and a request made again with its key whose order was paid since (by card too) and is done with: delivered, failed, or `refunded`; transfer — a card to transfer to (`transfer`), then the receipt (POST /payments/{id}/receipt): the payment awaits it, or has it with support; processing — paid, its delivery under way. */
  outcome: 'settled' | 'transfer' | 'processing'
  order: StoreOrder
  /** Where to transfer the money, and the payment the receipt is for — a transfer's; null for the rest. */
  transfer: {
    /** The payment its receipt is sent for. */
    payment_id: number
    /** Toman: what to transfer. */
    amount: string
    /** The card's number, its 16 digits alone. */
    card: string
    /** Whose card it is. */
    holder: string
    /** The shop's note on paying with it; null without one. */
    instructions: string | null
  } | null
  /** The service a purchase delivered or a renewal renewed, once delivered; null for the rest. */
  subscription: StoreSubscription | null
}

export interface StoreCheckoutResponse {
  checkout: StoreCheckout
}

/** A service's renewal before it is paid: on its own plan, at that plan's price today, and the service as the renewal would leave it — by the shop's last copy of its panel's numbers (the renewal reads the panel as it is paid). */
export interface StoreRenewal {
  /** The plan it is renewed on — its own: what a renewal on it gives. */
  plan: {
    id: number
    name: string
    /** Toman. */
    price: string
    /** Gigabytes, decimals allowed; 0 = unlimited. */
    traffic_gb: number
    /** 0 = it never ends. */
    duration_days: number
  }
  /** What the renewal costs: Toman. */
  price: string
  /** The service as the renewal would leave it — as StoreSubscription has them: the days left always carry; the traffic a period leaves unused goes when it ends unless the shop carries it (`next_period`). */
  after: {
    term: StoreServiceTerm
    traffic: StoreServiceTraffic
    /** The period in use, its unused traffic going when it ends; null when nothing goes. */
    next_period: StoreNextPeriod | null
  }
}

export interface StoreRenewalResponse {
  renewal: StoreRenewal
}

/** The customer's wallet. Amounts in Toman, as decimal strings. */
export interface StoreWalletResponse {
  /** Below zero an agent's debt. */
  balance: string
  /** How far below zero an agent's wallet may go; '0.00' for anyone else. */
  credit: string
  /** What the wallet can pay now: the balance and the credit. */
  spendable: string
  /** What a top-up may be, as the bot's «افزایش موجودی» offers it — POST /wallet/top-up takes these amounts as they are written here. */
  top_up: {
    /** The smallest top-up the shop takes. */
    min: string
    /** The largest top-up the shop takes. */
    max: string
    /** The amounts offered as buttons, ascending. */
    presets: string[]
  }
}

/** One line of the customer's wallet ledger. */
export interface StoreWalletTransaction {
  id: number
  type: WalletTransactionType
  /** Toman. */
  amount: string
  /** The balance from then on. */
  balance_after: string
  /** What it was for, in words («شارژ کیف پول (سفارش #12)»). */
  description: string | null
  created_at: string
}

export interface StoreWalletTransactionsResponse {
  transactions: StoreWalletTransaction[]
  meta: PageMeta
}

/** The customer's part in the referral program. */
export interface StoreReferralResponse {
  /** The program runs: a newcomer their code brings earns them a share of what that newcomer pays. */
  enabled: boolean
  /** The share, in percent. */
  rate: number
  /** Only each newcomer's first payment earns it. */
  first_only: boolean
  /** Their invite code — what a sign-in takes as `referral_code` (the site makes its own invite link with it). */
  code: string
  /** The bot's invite link with their code (https://t.me/<bot>?start=ref_<code>); null while the bot's @username is not known. */
  bot_link: string | null
  /** The customers their code brought. */
  invited: number
  /** What the commissions credited to them add up to, in Toman. */
  earned: string
}

/** What a notice was — one for each thing the shop's bot tells a customer on its own: a payment approved (the service delivered, the wallet charged — or the delivery failed and support finishes it), a receipt refused, an order cancelled by support, a payment back in the wallet, a wallet top-up given back, a payment reminder; a service switched off, back on, deleted, moved (its new link), given days or traffic; «یادآوری» — a service ending soon, its traffic running low; «تمدید خودکار» done, short of money, a renewal paid but not delivered; a newcomer their invite link brought, a referral's commission; their request to become an agent approved or rejected, their agency changed or ended, their bot's traffic short of an order; how their account is signed in to changed — two-factor sign-in turned off (by support, or by them) or on, a way in added or taken away, a password set, another account of theirs made one with it, its second step failed until it waits, the codes emailed to its address failed until none goes for a while (told on every door they have: Telegram and email both); support's answer to their ticket, and its closing. */
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
  | 'service_granted'
  | 'expiry_reminder'
  | 'traffic_reminder'
  | 'auto_renewed'
  | 'auto_renew_short'
  | 'renewal_failed'
  | 'referral_joined'
  | 'referral_commission'
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

/** A notice the shop told the customer — the words its bot wrote them (the admin's, as Telegram got them, or would have). */
export interface StoreNotification {
  id: number
  type: StoreNoticeType
  /** Its words as plain text: no markup, line breaks as they are, a premium emoji its plain emoji. */
  text: string
  /** Its words as safe HTML — only b/strong, i/em, u/ins, s/strike/del, code, pre, blockquote, a spoiler as <span class="tg-spoiler">, a link to an http(s) address (<a href> and no other attribute) and <br> for a line break; every text escaped. Safe to put in a page as it is. */
  html: string
  /** What it is about, for a link: one of their orders (GET /orders/{id} — a notice about a payment is about its order), of their services (GET /subscriptions/{id}) or of their tickets (GET /tickets/{id}); null for none — their account, a service deleted, someone else's row. Only a service may be gone since (a 404): support deleted it. An order stays — one nobody paid expires as `cancelled` —, and so does a ticket. */
  subject: {
    type: 'order' | 'subscription' | 'ticket'
    id: number
  } | null
  /** They marked it read (POST /notifications/read). */
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
    /** How many of their notices they have not read, whatever the page shows. */
    unread: number
  }
}

/** The notices to mark read, by their numbers (at most 100); none sent (`{}`): every one. */
export interface StoreNotificationsReadRequest {
  ids?: number[]
}

export interface StoreUnreadResponse {
  /** How many of their notices they have not read now. */
  unread: number
}

/** One of the customer's support tickets. */
export interface StoreTicket {
  id: number
  subject: string
  status: TicketStatus
  /** The service of theirs it is about, by its name; null for none — or one deleted since. */
  subscription: NamedRef | null
  /** When its latest message was written: the list's order. */
  last_message_at: string
  /** Support wrote since they last read it (POST /tickets/{id}/read reads it, as the bot reads it once shown there). */
  unread: boolean
  /** Their rating of it, 1 to 5, once it was closed; null for none — and once it opened again. */
  rating: number | null
  created_at: string
  /** When it was closed; null unless it is closed — a ticket opened again leaves its end behind. */
  closed_at: string | null
}

/** One message of their ticket — theirs, or support's (who of support wrote it is never said: «پشتیبانی»). */
export interface StoreTicketMessage {
  id: number
  author: TicketAuthor
  /** Its words, line breaks as they are — plain text. */
  body: string
  /** Its picture (GET /tickets/{id}/messages/{message}/attachment); null for none. */
  attachment: TicketAttachment | null
  created_at: string
}

/** One of the customer's support tickets with its whole conversation, the first message first. */
export interface StoreTicketDetail {
  id: number
  subject: string
  status: TicketStatus
  /** The service of theirs it is about, by its name; null for none — or one deleted since. */
  subscription: NamedRef | null
  /** When its latest message was written: the list's order. */
  last_message_at: string
  /** Support wrote since they last read it (POST /tickets/{id}/read reads it, as the bot reads it once shown there). */
  unread: boolean
  /** Their rating of it, 1 to 5, once it was closed; null for none — and once it opened again. */
  rating: number | null
  created_at: string
  /** When it was closed; null unless it is closed — a ticket opened again leaves its end behind. */
  closed_at: string | null
  /** What they said with their rating. */
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
    /** How many of all of their tickets hold support's words they have not read, whatever the list shows (a badge). */
    unread: number
  }
}

export interface StoreTicketResponse {
  ticket: StoreTicketDetail
}

/** A ticket opened: what it is about, its first message, and the service of theirs it is about. One with a picture comes as a form (StoreTicketUploadRequest). */
export interface StoreTicketRequest {
  /** 3 to 120 characters. */
  subject: string
  /** 1 to 4000 characters, its line breaks kept. */
  body: string
  /** One of their services (GET /subscriptions); left out, null or blank — about none. */
  subscription_id?: WholeNumberInput | null
}

/** A ticket opened with a picture in its first message, as a browser form uploads it — a form without one opens it without a picture. */
export interface StoreTicketUploadRequest {
  /** 3 to 120 characters. */
  subject: string
  /** 1 to 4000 characters, its line breaks kept. */
  body: string
  /** One of their services (GET /subscriptions); left out, null or blank — about none. */
  subscription_id?: WholeNumberInput | null
  /** The picture: a JPEG, a PNG or a WebP, judged by its bytes — 10 MB at most; left out, none. */
  file?: Blob
}

/** Their rating of a closed ticket — a rating given before is replaced. */
export interface StoreTicketRatingRequest {
  /** 1 to 5. */
  rating: WholeNumberInput
  /** What they say with it — 500 characters at most; blank for none. */
  note?: string
}

/** A review the shop shows: approved by support. Its writer's own words and choices — plain text, which the website escapes —, nothing of who wrote it nor of support's. */
export interface StoreReview {
  id: number
  /** The name it is signed with. */
  name: string
  /** 1 to 5. */
  rating: number
  /** Its words, line breaks as written. */
  body: string
  /** A line of where they use the service from («ایرانسل · اندروید · Happ»); null for none. */
  context: string | null
  /** The key of the website's own avatar they picked; null for none — the website draws its own default. */
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
    /** Every review the shop shows, as one figure — whatever page this is. */
    summary: {
      count: number
      /** Their mean rating, to two decimals; null while there is none. */
      average: number | null
    }
  }
}

/** A review written on the website. Every refusal at once, under its field. */
export interface StoreReviewRequest {
  /** 2 to 64 characters, on one line: the name it is signed with, shown with it. */
  name: string
  /** 1 to 5. */
  rating: WholeNumberInput
  /** 10 to 600 characters, its line breaks kept. */
  body: string
  /** A line of where they use the service from — «ایرانسل · اندروید · Happ» —, 100 characters at most; blank for none. */
  context?: string
  /** The key of one of the website's own avatars — 1 to 32 of a-z, 0-9, - and _, never an address —; blank for none. */
  avatar?: string
  /** The captcha widget's token, while the website asks one (GET / says which, and how to draw it), solved for the operation's action (its `x-captcha`). */
  captcha?: string
}

export interface StoreReviewWrittenResponse {
  review: {
    id: number
    /** pending: waiting on support until it is approved. */
    status: ReviewStatus
  }
}

/** Every write of a panel's own API — the owner's `/api/admin`, an agent's `/api/agent`; what both have is described under `/api/{panel}` —: its method, its path under the panel's API (a path parameter as the type of its value: `/plans/${number}`), the body it takes (`undefined`: none) and its answer (`void`: none). lib/api types each POST, PUT, PATCH and DELETE by it. */
export type PanelWrite =
  /** The owner signs in with the panel's login, in the shop the request names (`X-Shop`): the right credentials at the address of a shop that is not there open nothing (404) */
  | { method: 'POST'; path: '/auth/login'; body: LoginRequest; answer: SessionResponse }
  /** A lost login's way back in begins: a one-time key written to the host's files, where only someone who can read them finds it — the same key while it still opens */
  | { method: 'POST'; path: '/auth/recovery/key'; body: undefined; answer: RecoveryKeyResponse }
  /** A new panel login, set with that key: signed in under it, in the shop the request names, every other session out (a wrong key counts as a failed sign-in) */
  | { method: 'POST'; path: '/auth/recovery'; body: RecoveryRequest; answer: SessionResponse }
  /** An agent signs in with the code of the one-time link the main bot gave them, in their bot's shop — in place of another agent's session open in this browser only with `replace` (else a 409 on `replace`, the link not spent: the panel asks first) */
  | { method: 'POST'; path: '/auth/link'; body: AgentLinkRequest; answer: SessionResponse }
  /** Sign out of this panel (the other's session in the same browser stays) */
  | { method: 'POST'; path: '/auth/logout'; body: undefined; answer: void }
  /** The agent signs out of every other browser signed in to their panel — this one stays (its sign-in held under the new epoch); a session ended meanwhile by another of theirs is a 401 */
  | { method: 'POST'; path: '/auth/sessions/end'; body: undefined; answer: void }
  /** The owner changes the panel's login: every other session ends, this one stays */
  | { method: 'PUT'; path: '/auth/credentials'; body: CredentialsRequest; answer: SessionResponse }
  /** Report a failure of the panel's own code — a page it could not draw, an error left unhandled — to the shop's log */
  | { method: 'POST'; path: '/client-errors'; body: ClientErrorRequest; answer: void }
  /** Put every bot the shop runs on its webhook under APP_URL — which Telegram calls only over HTTPS, else a 422 and no bot is touched — as bot:webhook:set does: one bot failing keeps it from none of the others */
  | { method: 'POST'; path: '/system/webhook'; body: undefined; answer: WebhooksResponse }
  /** Take every bot the shop runs off its webhook, back to polling — bot:poll must run then —, as bot:webhook:delete does */
  | { method: 'DELETE'; path: '/system/webhook'; body: undefined; answer: WebhooksResponse }
  /** Read the newest release from GitHub now (a 502 while GitHub does not answer: the release read last stands) */
  | { method: 'POST'; path: '/system/update/check'; body: undefined; answer: UpdateResponse }
  /** Begin updating to the newest release — a 422 on `version` when it is not that, or not newer than the shop's; a 409 while an update is under way; a 422 with what keeps the shop from updating itself */
  | { method: 'POST'; path: '/system/update/start'; body: UpdateStartRequest; answer: UpdateResponse }
  /** Take the update's next step, or as much of it as a request has time for — the release's files fetched and checked against the release key, unpacked, the host checked, then installed in one request while every other request is told to come back (a 503 with Retry-After). A refusal is kept with the run (`run.error`); a 409 while another request works the update, or a scheduled run does not end in time; a 502 while GitHub does not answer */
  | { method: 'POST'; path: '/system/update/step'; body: undefined; answer: UpdateResponse }
  /** Give the update up before its install began (`run.cancel`): what it fetched and unpacked goes */
  | { method: 'POST'; path: '/system/update/cancel'; body: undefined; answer: UpdateResponse }
  /** Take the update installed last back (`run.rollback`): the version it replaced in its place again — refused once it changed the database, which only a backup takes back */
  | { method: 'POST'; path: '/system/update/rollback'; body: undefined; answer: UpdateResponse }
  /** Add a server; its panel is asked at once */
  | { method: 'POST'; path: '/servers'; body: ServerRequest; answer: ServerCreatedResponse }
  /** Try a connection before it is saved (`id` reuses a saved server's secrets) */
  | { method: 'POST'; path: '/servers/test'; body: ServerTestRequest; answer: ProbeResponse }
  /** Change a server */
  | { method: 'PUT'; path: `/servers/${number}`; body: ServerRequest; answer: ServerResponse }
  /** Delete a server nothing was sold on */
  | { method: 'DELETE'; path: `/servers/${number}`; body: undefined; answer: void }
  /** Check a saved server's panel (and learn whether it serves subscription links) */
  | { method: 'POST'; path: `/servers/${number}/test`; body: undefined; answer: ServerCheckResponse }
  /** Read the panel's inbounds again */
  | { method: 'POST'; path: `/servers/${number}/inbounds/sync`; body: undefined; answer: ServerDetailResponse }
  /** Whether an inbound is sold */
  | { method: 'PATCH'; path: `/servers/${number}/inbounds/${number}`; body: InboundSellableRequest; answer: InboundsResponse }
  /** Give days and traffic to the services on a server */
  | { method: 'POST'; path: `/servers/${number}/grants`; body: ServerGrantRequest; answer: ServerGrantResponse }
  /** Work a grant through a few services */
  | { method: 'POST'; path: `/servers/${number}/grants/${number}/run`; body: undefined; answer: ServerGrantResponse }
  /** Stop a grant; the services reached keep what they got */
  | { method: 'POST'; path: `/servers/${number}/grants/${number}/cancel`; body: undefined; answer: ServerGrantResponse }
  /** Give days and traffic to the running services on every server — or the agents', or one server's */
  | { method: 'POST'; path: '/mass-grants'; body: MassGrantRequest; answer: MassGrantResponse }
  /** Work on a mass gift's parts for a few seconds (the screen keeps asking while it is open) */
  | { method: 'POST'; path: `/mass-grants/${number}/run`; body: undefined; answer: MassGrantResponse }
  /** Stop a mass gift; what was given stays */
  | { method: 'POST'; path: `/mass-grants/${number}/cancel`; body: undefined; answer: MassGrantResponse }
  /** Pause a run that is sending */
  | { method: 'POST'; path: `/broadcasts/${number}/pause`; body: undefined; answer: BroadcastResponse }
  /** Let a paused run go on (the scheduler carries on with it) */
  | { method: 'POST'; path: `/broadcasts/${number}/resume`; body: undefined; answer: BroadcastResponse }
  /** Stop a run for good; those who got it keep it */
  | { method: 'POST'; path: `/broadcasts/${number}/cancel`; body: undefined; answer: BroadcastResponse }
  /** Take a finished pinned run's pins off again — answers with the new «لغو پین» run */
  | { method: 'POST'; path: `/broadcasts/${number}/unpin`; body: undefined; answer: BroadcastResponse }
  /** Add a plan */
  | { method: 'POST'; path: '/plans'; body: PlanRequest; answer: PlanResponse }
  /** Put the plans in a new order */
  | { method: 'POST'; path: '/plans/reorder'; body: ReorderRequest; answer: PlansResponse }
  /** Change a plan */
  | { method: 'PUT'; path: `/plans/${number}`; body: PlanRequest; answer: PlanResponse }
  /** Switch a plan on or off */
  | { method: 'PATCH'; path: `/plans/${number}`; body: ActivationRequest; answer: PlanResponse }
  /** Delete a plan nothing was sold with */
  | { method: 'DELETE'; path: `/plans/${number}`; body: undefined; answer: void }
  /** A copy of a plan, switched off */
  | { method: 'POST'; path: `/plans/${number}/duplicate`; body: undefined; answer: PlanResponse }
  /** Add a category */
  | { method: 'POST'; path: '/plans/categories'; body: PlanCategoryRequest; answer: PlanCategoryResponse }
  /** Put the categories in a new order */
  | { method: 'POST'; path: '/plans/categories/reorder'; body: ReorderRequest; answer: PlanCategoriesResponse }
  /** Change a category */
  | { method: 'PUT'; path: `/plans/categories/${number}`; body: PlanCategoryRequest; answer: PlanCategoryResponse }
  /** Switch a category on or off */
  | { method: 'PATCH'; path: `/plans/categories/${number}`; body: ActivationRequest; answer: PlanCategoryResponse }
  /** Delete a category; its plans become uncategorised */
  | { method: 'DELETE'; path: `/plans/categories/${number}`; body: undefined; answer: void }
  /** Ban or unban a customer. On the shop's website, an admin's account — another's or their own — and an agent's are the panels' (403). */
  | { method: 'PATCH'; path: `/users/${number}`; body: UserStatusRequest; answer: UserResponse }
  /** Make a customer the bot's admin — /broadcast, the report group's buttons, the shop's website's admin side while it lets its admins in — or take it back */
  | { method: 'PUT'; path: `/users/${number}/role`; body: UserRoleRequest; answer: UserResponse }
  /** Credit or debit a customer's wallet */
  | { method: 'POST'; path: `/users/${number}/wallet`; body: WalletAdjustmentRequest; answer: WalletAdjustmentResponse }
  /** The admin's groups a customer is in: every one listed, no other */
  | { method: 'PUT'; path: `/users/${number}/groups`; body: UserGroupsRequest; answer: UserResponse }
  /** Turn off the two-factor sign-in of the customer's account on the website — the phone it was on lost: the password alone signs them in. The customer is told (in Telegram, when they have it), the log says who did it. 422 when it is not on. */
  | { method: 'POST'; path: `/users/${number}/two-factor/disable`; body: undefined; answer: CustomerAccountResponse }
  /** Sign the customer out of the website on every device: every session of theirs ends (the log says who did it) */
  | { method: 'POST'; path: `/users/${number}/sessions/end`; body: undefined; answer: void }
  /** Add a group */
  | { method: 'POST'; path: '/customer-groups'; body: CustomerGroupRequest; answer: CustomerGroupResponse }
  /** Put the groups in a new order */
  | { method: 'POST'; path: '/customer-groups/reorder'; body: ReorderRequest; answer: CustomerGroupsResponse }
  /** Rename a group */
  | { method: 'PUT'; path: `/customer-groups/${number}`; body: CustomerGroupRequest; answer: CustomerGroupResponse }
  /** Delete a group — its customers just leave it */
  | { method: 'DELETE'; path: `/customer-groups/${number}`; body: undefined; answer: void }
  /** Deliver a paid order whose delivery failed */
  | { method: 'POST'; path: `/orders/${number}/retry`; body: undefined; answer: OrderResponse }
  /** Cancel an order nobody paid, with its open payments — a receipt waiting for review among them */
  | { method: 'POST'; path: `/orders/${number}/cancel`; body: NoteRequest; answer: OrderResponse }
  /** Accept a payment (a receipt, or by hand) and deliver what it paid for */
  | { method: 'POST'; path: `/payments/${number}/approve`; body: undefined; answer: PaymentResponse }
  /** Refuse a receipt */
  | { method: 'POST'; path: `/payments/${number}/reject`; body: NoteRequest; answer: PaymentResponse }
  /** Cancel an unpaid payment and its open order — the payment alone while another of the order's payments waits on its receipt's review: that one stays support's to decide */
  | { method: 'POST'; path: `/payments/${number}/cancel`; body: NoteRequest; answer: PaymentResponse }
  /** Remind the customer in the bot — the answer says whether they were told */
  | { method: 'POST'; path: `/payments/${number}/remind`; body: undefined; answer: ReminderResponse }
  /** Deliver again what a paid payment bought */
  | { method: 'POST'; path: `/payments/${number}/retry`; body: undefined; answer: PaymentResponse }
  /** Give a paid payment back, by what it bought: a purchase's, a renewal's or an agent's traffic's amount to the customer's wallet, a top-up's back out of it. 422 on `note` past the 190 characters of the wallet line it is written on, or on `status`; 422 too once what the order put in the shop is no longer there (a top-up spent, an agent's traffic sold). */
  | { method: 'POST'; path: `/payments/${number}/refund`; body: RefundRequest; answer: PaymentResponse }
  /** Read the service's numbers from its panel again */
  | { method: 'POST'; path: `/subscriptions/${number}/sync`; body: undefined; answer: SubscriptionResponse }
  /** Give the service days and traffic on top of what it has */
  | { method: 'POST'; path: `/subscriptions/${number}/extend`; body: SubscriptionExtensionRequest; answer: SubscriptionResponse }
  /** Switch a service off on its panel */
  | { method: 'POST'; path: `/subscriptions/${number}/disable`; body: NoteRequest; answer: SubscriptionResponse }
  /** Switch a service back on */
  | { method: 'POST'; path: `/subscriptions/${number}/enable`; body: undefined; answer: SubscriptionResponse }
  /** Move a service to another server with what is left of it */
  | { method: 'POST'; path: `/subscriptions/${number}/move`; body: SubscriptionMoveRequest; answer: SubscriptionResponse }
  /** Delete a service from its panel and the shop */
  | { method: 'POST'; path: `/subscriptions/${number}/delete`; body: SubscriptionDeleteRequest; answer: void }
  /** Support's answer — its words, and a picture as a form — under the panel's principal: the ticket answered, waiting on the customer (a closed one opens again so), the customer told on every door they have (in Telegram, the picture with it), the report group under the ticket. 422 on `body` (1 to 4000 characters) or `file` (a JPEG, a PNG or a WebP by its bytes, 10 MB at most), or on `status` once the ticket holds 200 messages; 503 while the host's free disk space is under the 200 MB the shop keeps in reserve, or once the pictures uploaded to the shop in a day — its customers' and support's together — took 1024 MB; a form larger than the host's PHP takes is a 413, and a form field that is not UTF-8 a 422 on it. */
  | { method: 'POST'; path: `/tickets/${number}/messages`; body: TicketMessageRequest | TicketMessageUploadRequest; answer: TicketResponse }
  /** Close the ticket — the customer told, the report group under the ticket; 422 on `status` when it is closed already */
  | { method: 'POST'; path: `/tickets/${number}/close`; body: undefined; answer: TicketResponse }
  /** Open a closed ticket again, waiting on support (its rating left behind); 422 on `status` when it is not closed */
  | { method: 'POST'; path: `/tickets/${number}/reopen`; body: undefined; answer: TicketResponse }
  /** Approve the review — waiting, or rejected before —: the website shows it from now on (GET /reviews of the Store API), under the request's principal; 403 the shop's admin's own review, 422 on `status` when it is approved already */
  | { method: 'POST'; path: `/reviews/${number}/approve`; body: undefined; answer: ReviewResponse }
  /** Reject the review — waiting, or shown before (hidden again) —: kept, never shown on the website; 403 the shop's admin's own review, 422 on `status` when it is rejected already */
  | { method: 'POST'; path: `/reviews/${number}/reject`; body: undefined; answer: ReviewResponse }
  /** Delete the review, whatever it stood at — shown on the website no more; 403 the shop's admin's own review */
  | { method: 'DELETE'; path: `/reviews/${number}`; body: undefined; answer: void }
  /** Add a method from a driver, with that driver's form */
  | { method: 'POST'; path: '/payment-methods'; body: PaymentMethodCreateRequest; answer: PaymentMethodResponse }
  /** Put the methods in a new checkout order */
  | { method: 'POST'; path: '/payment-methods/reorder'; body: ReorderRequest; answer: PaymentMethodsResponse }
  /** Change a method — its driver's form again */
  | { method: 'PUT'; path: `/payment-methods/${number}`; body: PaymentMethodUpdateRequest; answer: PaymentMethodResponse }
  /** Switch a method on or off */
  | { method: 'PATCH'; path: `/payment-methods/${number}`; body: PaymentMethodSwitchRequest; answer: PaymentMethodResponse }
  /** Delete a method (never the wallet) */
  | { method: 'DELETE'; path: `/payment-methods/${number}`; body: undefined; answer: void }
  /** Save one group of the bot's settings */
  | { method: 'PUT'; path: `/bot/settings/${BotSettingsGroup}`; body: BotSettingsRequest; answer: BotSettingsSaved }
  /** Upload a picture of the admin's own */
  | { method: 'POST'; path: '/bot/qr-background'; body: QrBackgroundRequest; answer: QrBackgroundResponse }
  /** Go back to the shipped picture */
  | { method: 'DELETE'; path: '/bot/qr-background'; body: undefined; answer: QrBackgroundResponse }
  /** Add a channel by its link, @handle or id */
  | { method: 'POST'; path: '/bot/channels'; body: BotChannelRequest; answer: BotChannelResponse }
  /** Put the channels in a new order */
  | { method: 'POST'; path: '/bot/channels/reorder'; body: ReorderRequest; answer: BotChannelsResponse }
  /** Ask Telegram about a channel again */
  | { method: 'POST'; path: `/bot/channels/${number}/check`; body: undefined; answer: BotChannelResponse }
  /** Stop asking customers to join a channel */
  | { method: 'DELETE'; path: `/bot/channels/${number}`; body: undefined; answer: void }
  /** Disconnect the group (the bot stays in it) */
  | { method: 'DELETE'; path: '/bot/report-group'; body: undefined; answer: ReportGroupResponse }
  /** A one-time link that adds the bot to a group with topics */
  | { method: 'POST'; path: '/bot/report-group/link'; body: undefined; answer: ReportGroupResponse }
  /** Ask Telegram about the group again */
  | { method: 'POST'; path: '/bot/report-group/check'; body: undefined; answer: ReportGroupResponse }
  /** A test message in every topic */
  | { method: 'POST'; path: '/bot/report-group/test'; body: undefined; answer: ReportGroupTestResponse }
  /** Save a keyboard */
  | { method: 'PUT'; path: `/keyboards/${string}`; body: KeyboardRequest; answer: KeyboardResponse }
  /** Back to the built-in layout */
  | { method: 'POST'; path: `/keyboards/${string}/reset`; body: undefined; answer: KeyboardResponse }
  /** Save the rules */
  | { method: 'PUT'; path: '/agency/settings'; body: AgencySettingsRequest; answer: AgencySettingsResponse }
  /** Approve a request on a level, with the credit the agent starts with; the customer is told */
  | { method: 'POST'; path: `/agency/requests/${number}/approve`; body: AgencyTermsRequest; answer: AgencyRequestResponse }
  /** Reject a request; the customer is told, with the note */
  | { method: 'POST'; path: `/agency/requests/${number}/reject`; body: NoteRequest; answer: AgencyRequestResponse }
  /** An agent's level and credit (the id is the customer's); the agent is told of a change, and the answer says whether they were */
  | { method: 'PUT'; path: `/agency/agents/${number}`; body: AgencyTermsRequest; answer: AgentChangedResponse }
  /** End the agency: their bot goes off; the customer is told, with the note */
  | { method: 'POST'; path: `/agency/agents/${number}/revoke`; body: NoteRequest; answer: void }
  /** Set an agent's traffic right — a negative gb takes it away */
  | { method: 'POST'; path: `/agency/agents/${number}/traffic`; body: TrafficAdjustmentRequest; answer: AgentResponse }
  /** Add a level */
  | { method: 'POST'; path: '/agency/levels'; body: AgencyLevelRequest; answer: AgencyLevelResponse }
  /** Put the levels in a new order */
  | { method: 'POST'; path: '/agency/levels/reorder'; body: ReorderRequest; answer: AgencyLevelsResponse }
  /** Change a level */
  | { method: 'PUT'; path: `/agency/levels/${number}`; body: AgencyLevelRequest; answer: AgencyLevelResponse }
  /** Delete a level — refused (409) while agents are on it */
  | { method: 'DELETE'; path: `/agency/levels/${number}`; body: undefined; answer: void }
  /** Take a premium emoji off the picker */
  | { method: 'DELETE'; path: `/bot/custom-emojis/${string}`; body: undefined; answer: void }
  /** Reword a text */
  | { method: 'PUT'; path: `/bot/texts/${string}`; body: BotTextRequest; answer: BotTextResponse }
  /** Back to the shop's wording */
  | { method: 'POST'; path: `/bot/texts/${string}/reset`; body: undefined; answer: BotTextResponse }
  /** Change the website — the fields sent, the rest as kept: judged as the website would stand after it, every refusal at once, under its field */
  | { method: 'PATCH'; path: '/website'; body: WebsiteRequest; answer: WebsiteResponse }
  /** A new store key — the old base address stops working at once */
  | { method: 'POST'; path: '/website/key'; body: undefined; answer: WebsiteResponse }
  /** Ask Telegram who a token belongs to — the stored one when none is typed */
  | { method: 'POST'; path: '/settings/config/telegram/test'; body: TelegramTestRequest; answer: BotIdentityResponse }
  /** Try database settings without saving them */
  | { method: 'POST'; path: '/settings/config/database/test'; body: DatabaseRequest; answer: DatabaseCheckResponse }
  /** A short email by the mail settings as saved — 422 while none goes out (no transport, or no address to send from, saved), 502 with the transport's reason when it did not take it */
  | { method: 'POST'; path: '/settings/config/mail/test'; body: MailTestRequest; answer: MailTestResponse }
  /** Save one group of the config.php settings */
  | { method: 'PUT'; path: `/settings/config/${ConfigGroup}`; body: ConfigSettingsRequest; answer: ConfigSettingsResponse }

/** Every write of the web installer's API (`/api/install`), as PanelWrite has the panel's. */
export type InstallWrite =
  /** The database, written to config.php once its driver's probe answers (a blank secret is empty: nothing is stored yet) */
  | { method: 'POST'; path: '/database'; body: DatabaseRequest; answer: InstallStatus }
  /** The shop's tables, on the database the previous step wrote — the ones it does not have */
  | { method: 'POST'; path: '/tables'; body: undefined; answer: InstallStatus }
  /** The panel's login */
  | { method: 'POST'; path: '/admin'; body: InstallAdminRequest; answer: InstallStatus }
  /** The shop's name and address, and the bot's token if given */
  | { method: 'POST'; path: '/site'; body: InstallSiteRequest; answer: InstallStatus }
  /** End the installation */
  | { method: 'POST'; path: '/finish'; body: undefined; answer: InstallFinished }

/** Every write of the Store API — a shop's website's, under its store key (`/api/store/v1/{store}`) —, as PanelWrite has the panel's. */
export type StoreWrite =
  /** A nonce for an id_token sign-in (Telegram's popup, Google) — a sign-in's, or a signed-in customer's adding or proving a way in: the site opens the provider's sign-in with it, and posts it back with the token — once. 429 once this address network asked for 120 nonces, states and codes in 10 minutes (one address is many customers on a mobile network); 503 while the shop keeps as many sign-ins begun as it takes (5000 nonces in their half hour). */
  | { method: 'POST'; path: '/auth/nonce'; body: undefined; answer: StoreNonceResponse }
  /** Telegram's sign-in by redirect, begun in this browser: where to send it — the site makes the PKCE verifier and keeps it, sending the challenge; the state (keeping the challenge) and the nonce are the shop's — the state a sign-in's: POST /auth/telegram alone takes it back, with the verifier (a signed-in customer's own is POST /me/telegram/authorize's). 422 while the website keeps no client secret, or on `redirect_uri` or `code_challenge`; 429 once this address network asked for 120 nonces, states and codes in 10 minutes; 503 while the shop keeps as many sign-ins begun as it takes. */
  | { method: 'POST'; path: '/auth/telegram/authorize'; body: StoreTelegramAuthorizeRequest; answer: StoreAuthorizeResponse }
  /** Sign in with Telegram — the popup's id_token, or the redirect's code with the state POST /auth/telegram/authorize gave and the verifier of the challenge it was given (a signed-in customer's state, or a code and state brought back to another browser, signs nobody in): the shop's customer with that Telegram account, or a newcomer made as the bot makes one. 401 when it does not sign anyone in, 403 banned, 422 Telegram sign-in off or a field missing, 429 too many failures from this address network (30 in 15 minutes), 502 Telegram out of reach. */
  | { method: 'POST'; path: '/auth/telegram'; body: StoreTelegramSignInRequest; answer: StoreSignInResponse }
  /** Sign in with Google — the id_token Google Identity Services handed the page, with the nonce it was asked for: the shop's customer with that Google account, else the one with the address Google speaks for (which signs in with Google from then on, its customer told), else a newcomer. The address counts only where Google is its own authority — `email_verified`, and an @gmail.com address or a Google Workspace account's (`hd`); any other address finds, takes on and keeps no account. 401 when it does not sign anyone in, 403 banned, 409 the address is an account's that takes no Google account on by it — one with two-factor sign-in on, or another Google account's: signed in its own way, the customer adds Google from their account —, 422 Google sign-in off, 429 too many failures from this address network, 502 Google out of reach. */
  | { method: 'POST'; path: '/auth/google'; body: StoreGoogleSignInRequest; answer: StoreSignInResponse }
  /** Sign up with an email: a six-digit code to the address (POST /auth/register/verify takes it back) — or, when the address has an account, an email saying so; the answer is the same either way. Again: a new code in the last one's place. 422 email sign-up off, or a field (the captcha's too), 429 a code went to the address a minute ago, the address had its ten emails today (from every shop), this address network its twenty an hour (or asked too much), or the codes sent to the address were failed until it waits (ten wrong an hour, twenty a day); 502 the email did not go; 503 the shop's email does not go out, or the shop's (or every shop's) budget of emails is spent — sixty an hour, three hundred a day a shop —, or the captcha could not be judged. */
  | { method: 'POST'; path: '/auth/register'; body: StoreRegisterRequest; answer: StoreCodeSentResponse }
  /** Finish signing up with the code the email carried: the account made — reported, its invite code's owner told — and signed in. 409 the address took an account meanwhile, 422 the code is wrong, expired, spent or tried five times (or email sign-up off), 429 too many failures — or the address's budget of wrong codes spent: ten an hour, twenty a day, each counted before it is checked, whatever codes were sent. */
  | { method: 'POST'; path: '/auth/register/verify'; body: StoreVerifyRequest; answer: StoreSignInResponse }
  /** Sign in with an email and its password — an account with two-factor sign-in on answers its second step's challenge instead (202; POST /auth/login/2fa finishes it). 401 for either wrong (one answer: which addresses have an account is told nobody), 403 banned, 422 a field (the captcha's too), 429 too many failures from this address network (30 in 15 minutes) or for this account (50, from every address), 503 the captcha could not be judged. */
  | { method: 'POST'; path: '/auth/login'; body: StoreLoginRequest; answer: StoreSignInResponse | StoreTwoFactorResponse }
  /** A password sign-in's second step: the challenge it answered, with the six-digit code of the account's authenticator app (Persian digits too; a code opens once) or one of its recovery codes (spent). A reset's new password the challenge keeps is set now, every other session of the account ended. 401 the challenge opens nothing any more (expired, five wrong codes, two-factor turned off since — sign in again), 403 banned, 422 a field missing or the code wrong, 429 too many failures from this address network or for this account — or the account's second-step codes spent: 10 an hour and 20 a day, counted before each is checked, whoever tries them (the customer is told once a day). */
  | { method: 'POST'; path: '/auth/login/2fa'; body: StoreTwoFactorRequest; answer: StoreSignInResponse }
  /** A forgotten password: a six-digit code to the address when it has an account — and to one without, an email saying it has none, once a day (asked again that day, nothing goes); the answer the same either way. 422 the address or the captcha, 429 a code went to the address a minute ago, the address had its ten emails today (from every shop), this address network its twenty an hour (or asked too much), or the codes sent to the address were failed until it waits (ten wrong an hour, twenty a day); 502 the email did not go; 503 the shop's email does not go out, or the shop's (or every shop's) budget of emails is spent, or the captcha could not be judged. */
  | { method: 'POST'; path: '/auth/password/forgot'; body: StoreForgotRequest; answer: StoreCodeSentResponse }
  /** A new password with the code POST /auth/password/forgot emailed: kept, every session of the account ended, this device signed in, the customer told — or, two-factor sign-in on (a reset leaves it on), its second step's challenge (202), nothing changed yet: the new password waits in it, and POST /auth/login/2fa sets it. 403 banned, 422 a field — the code wrong, expired, spent or tried five times —, 429 too many failures — or the address's budget of wrong codes spent: ten an hour, twenty a day, each counted before it is checked (its account told once a day). */
  | { method: 'POST'; path: '/auth/password/reset'; body: StoreResetRequest; answer: StoreSignInResponse | StoreTwoFactorResponse }
  /** A token of the website's captcha judged for its own backend — a form of the site's own (a review, a contact form), the shop holding the captcha's secret: whether it passed, for the action the backend names (the widget's), solved where (Turnstile says the host; the website's address and origins are held to), and why not. Server to server: a browser's word that it passed proves nothing. A token is taken once (an ALTCHA solution, for the quarter of an hour its challenge lives). 200 either way; 409 while the website asks no captcha; 422 on `token`, `action` or `remoteip`; 429 past the website's 3000 checks in 10 minutes — a backend is one address for all its visitors —, or past 120 refused tokens in them from the visitor's network, the one `remoteip` names (else the backend's own: name the visitor, so a bot behind the site's forms spends its own network's refusals, not everyone's), its next token not judged; never the sign-ins' budget; 503 while it could not be judged (Cloudflare out of reach). */
  | { method: 'POST'; path: '/captcha/verify'; body: StoreCaptchaVerifyRequest; answer: StoreCaptchaVerdict }
  /** Sign this device out — its token opens nothing from now on */
  | { method: 'POST'; path: '/auth/logout'; body: undefined; answer: void }
  /** The customer's names — the ones sent, the rest as they are; answered as GET /me answers. 422 under the field: a first name blank or past 64 characters, a last name past 64 — and an account with Telegram, whose names are Telegram's. */
  | { method: 'PATCH'; path: '/me'; body: StoreProfileRequest; answer: StoreAccountResponse }
  /** A way into this account proven again — what changing how it is signed in to asks of a session whose sign-in is older than 15 minutes (their 403): the account's password — with its authenticator app's code or a recovery code while two-factor sign-in is on —, its Telegram account (the popup's id_token with a nonce of POST /auth/nonce, or the redirect's code with the state POST /me/telegram/authorize gave this session and the verifier of its challenge), or its Google account. This session may change the account's ways in for the next 15 minutes. 422 on `method` for none of password, telegram and google; 422 (no field) while the website has that way's sign-in off — Telegram's, or Google's; 422 on `password` for an account without a password (prove another way); 422 under the fields: missing, the proof does not hold (on `password`, `code` or `id_token`), or it proves another account; 429 too many failures — counted as sign-ins are, a code against the account's second-step budget too; 502 the provider out of reach. */
  | { method: 'POST'; path: '/me/reauthenticate'; body: StoreReauthenticateRequest; answer: StoreReauthenticatedResponse }
  /** Telegram's redirect sign-in begun for the signed-in customer themselves — to add their Telegram account (POST /me/identities/telegram) or prove it again (POST /me/reauthenticate): where to send the browser — the site makes the PKCE verifier and keeps it, sending the challenge —, the state this session's alone — no sign-in, no other customer, and no other device of theirs takes it back, and none without the verifier. 422 while the website keeps no client secret, or on `redirect_uri` or `code_challenge`; 429 once this address network asked for 120 nonces, states and codes in 10 minutes; 503 while the shop keeps as many sign-ins begun as it takes. */
  | { method: 'POST'; path: '/me/telegram/authorize'; body: StoreTelegramAuthorizeRequest; answer: StoreAuthorizeResponse }
  /** The customer's password, set or changed — it signs in with their email: every other session of theirs ends, this one stays, the customer told. 403 the session's sign-in is not recent (POST /me/reauthenticate first); 422 the account has no email, or under the fields: the current password (when there is one) missing or wrong — a wrong one counted as a failed sign-in —, the new one by the password rule; 429 too many failures. */
  | { method: 'PUT'; path: '/me/password'; body: StorePasswordRequest; answer: StoreMeResponse }
  /** End one of the customer's sessions (this one too) — another's is not there (404) */
  | { method: 'DELETE'; path: `/me/sessions/${number}`; body: undefined; answer: void }
  /** A new secret for the customer's authenticator app (two-factor sign-in, RFC 6238): it waits 15 minutes for the app's first code (POST /me/2fa/enable), any earlier one gone. 403 the session's sign-in is not recent; 422 the account does not sign in with an email and a password, or two-factor sign-in is on already. */
  | { method: 'POST'; path: '/me/2fa/setup'; body: undefined; answer: StoreTwoFactorSetupResponse }
  /** Two-factor sign-in turned on by the app's first code for the secret POST /me/2fa/setup gave (five tries): its ten recovery codes, shown this once; the customer told. 403 the session's sign-in is not recent; 422 no email and password, on already, or on `code` — wrong, or no secret waiting for it (set it up again). */
  | { method: 'POST'; path: '/me/2fa/enable'; body: StoreTwoFactorEnableRequest; answer: StoreRecoveryCodesResponse }
  /** Two-factor sign-in turned off, with the account's password; the customer told. 403 the session's sign-in is not recent; 422 it is not on, or on `password` — missing or wrong, a wrong one counted as a failed sign-in; 429 too many failures. */
  | { method: 'POST'; path: '/me/2fa/disable'; body: StoreTwoFactorDisableRequest; answer: StoreMeResponse }
  /** Add a Telegram account to the customer's — proven as Telegram's sign-in proves one: the popup's id_token with its nonce, or the redirect's code with the state POST /me/telegram/authorize gave this customer and the verifier of its challenge (a sign-in's state, or another customer's, proves nothing here). This account's already, or nobody's (now this account's, its handle and names Telegram's; the customer told): the account. Another account's of the shop: a merge offered (202). 403 the session's sign-in is not recent; 422 the proof does not hold (on `id_token`, or the redirect's `code`), the account has another Telegram account (or a merge the rules refuse, or Telegram sign-in off); 429 too many failures; 502 Telegram out of reach. */
  | { method: 'POST'; path: '/me/identities/telegram'; body: StoreTelegramLinkRequest; answer: StoreMeResponse | StoreMergeOfferResponse }
  /** Add a Google account to the customer's — the id_token Google Identity Services handed the page, with its nonce. This account's already, or nobody's (now this account's, with the address Google speaks for — an @gmail.com or Workspace address — when the account has none and no other account has it; the customer told): the account. Another account's of the shop — by the Google account, or by the address Google speaks for —: a merge offered (202). 403 the session's sign-in is not recent; 422 the proof does not hold (on `id_token`), the account has another Google account (or a merge the rules refuse, or Google sign-in off); 429 too many failures; 502 Google out of reach. */
  | { method: 'POST'; path: '/me/identities/google'; body: StoreGoogleLinkRequest; answer: StoreMeResponse | StoreMergeOfferResponse }
  /** Add an email to the customer's account, with the password chosen to sign in by it: a six-digit code to the address (POST /me/identities/email/verify takes it back) — whether or not another account has it: it is proven first. Another customer's code for the same address stays as it is. 403 the session's sign-in is not recent; 422 the account has an email, or a field; 429 a code went to the address a minute ago, the address had its ten emails today (from every shop), this address network its twenty an hour (or asked too much), or the codes sent to the address were failed until it waits (ten wrong an hour, twenty a day), or this account asked five codes today; 502 the email did not go; 503 the shop's email does not go out, or its budget of emails is spent. */
  | { method: 'POST'; path: '/me/identities/email'; body: StoreEmailLinkRequest; answer: StoreCodeSentResponse }
  /** The code the email carried, typed back by the account it was sent for: the address and its password this account's (the customer told) — or, the address another account's of the shop, a merge offered (202; once merged, the account that stays has the address and the password chosen). 403 the session's sign-in is not recent; 422 the account has an email, a merge the rules refuse, or on `code` — wrong, expired, spent, tried five times or another account's —; 429 too many failures, or the budget of wrong codes of the address or of this account spent (ten an hour, twenty a day). */
  | { method: 'POST'; path: '/me/identities/email/verify'; body: StoreEmailLinkVerifyRequest; answer: StoreMeResponse | StoreMergeOfferResponse }
  /** Take a way in off the customer's account — never the last one: an email goes with its password and two-factor sign-in (the address is emailed that it is no way in any more), a Telegram account with its handle; the customer told. 403 the session's sign-in is not recent; 422 the account has none of that kind, or it is its last way in. */
  | { method: 'DELETE'; path: `/me/identities/${StoreIdentityKind}`; body: undefined; answer: StoreMeResponse }
  /** Yes to a merge offered (a 202 of POST /me/identities/…): the two accounts one — the older stays, everything the other owned its own —, then what the offer carried (the way in being added) its own too — a password set by it ends every other session of the account. This session signs in the account that stays; the customer told. 403 the session's sign-in is not recent; 422 the ticket is not this account's, spent, expired, or the other account no longer has that way in; or the merge the rules refuse now. */
  | { method: 'POST'; path: '/me/merge'; body: StoreMergeRequest; answer: StoreMeResponse }
  /** Write a review of the shop — the name it is signed with, 1 to 5 stars, the words, a line of where they use the service from, one of the website's own avatars —: kept waiting on support, which approves it (GET /reviews shows it then) or rejects it; the report group hears of it. Anyone may, while the website asks a captcha — without one, a guest's is a 403 (sign in first) —: a customer's bearer token with it makes it theirs (their account beside it in the panels) — one that opens no session is a 401, never a guest's review in its place, and a banned customer's a 403. A 404 while the website takes no reviews (its `reviews_enabled`, off at first). 422 on `name` (2 to 64 characters), `rating` (1 to 5), `body` (10 to 600), `context` (100 at most) or `avatar` (1 to 32 of a-z, 0-9, - and _: a key of the website's own set, never an address), every one at once — then on `captcha` while the website asks one; 429 (`Retry-After`) past 20 reviews an hour from one address network (an IPv6 one by its /48; a refused captcha among them), 3 a day from one customer, or 20 an hour from every guest together (a refused captcha not among them); 503 while 200 reviews wait on support (the shop takes no new one until it decides some), or while the captcha could not be judged. */
  | { method: 'POST'; path: '/reviews'; body: StoreReviewRequest; answer: StoreReviewWrittenResponse }
  /** Its «تمدید خودکار» on or off: renewed from the wallet before its end. 422 on `auto_renew` while it is not offered (`auto_renew.offered`) — or for anything but a boolean. */
  | { method: 'PATCH'; path: `/subscriptions/${number}`; body: StoreSubscriptionRequest; answer: StoreSubscriptionResponse }
  /** Read from its panel now, with whether it is connected (`presence`) — a client the panel no longer has comes back deleted. 502 when its panel cannot be read now (out of reach, or left alone a while after it failed): the shop's copy is no answer to it. A customer reads 10 a minute at most: then a 429 with `Retry-After`. */
  | { method: 'POST'; path: `/subscriptions/${number}/refresh`; body: undefined; answer: StoreSubscriptionResponse }
  /** A new link for it (`link_rotation`): every device on the old one drops, the bot says nothing of it. 422 on `status` when it does not run or its server cannot give it a new link, 409 another change of it under way, 502 its server failed it. A customer asks for 5 an hour at most: then a 429 with `Retry-After`. */
  | { method: 'POST'; path: `/subscriptions/${number}/rotate-link`; body: undefined; answer: StoreSubscriptionResponse }
  /** Renew it — paid with `method_id`, as GET says: the wallet renews it at once, a card waits for its receipt (POST /payments/{id}/receipt). Refused as GET is, and as POST /orders is (the wallet short: 422 on `method_id`; too many unpaid orders open: 422; 409 paid or closed elsewhere in the same moment; 429 past the checkout's pace; 503 while the shop takes no orders); made again with its Idempotency-Key, the renewal that key came to, as it stands. */
  | { method: 'POST'; path: `/subscriptions/${number}/renewal`; body: StoreRenewalRequest; answer: StoreCheckoutResponse }
  /** Buy a plan — on one of its locations, paid with `method_id` — through the checkout the bot's purchases go through: nothing is ordered until the way to pay is picked; the wallet pays at once (the service delivered, or the order failed and waiting for support), a card waits for its receipt (POST /payments/{id}/receipt). 422 on `plan_id` for a plan the shop does not sell now (switched off, on no location that can deliver it now, or — an agent's shop — more traffic than the agent's bot has left to sell), on `server_id` for a server it is not sold on now while another of its locations is, on `method_id` for a way that may not pay it — or a wallet short of the price (what is missing) —, on `idempotency_key` for a key that came to an order of something else or was first sent with another `method_id`; 422 (no field) while the customer has 5 unpaid orders open — paid, or expired, they make room; 409 when the order was paid or closed elsewhere in the same moment — or, the request made again, was cancelled since (by support, or unpaid in its time: its own words); 429 past 30 ordering requests (purchases, renewals and top-ups together, made again or not) in 10 minutes, with `Retry-After`; 503 while the shop takes no orders — its bot switched off (GET /'s `shop.taking_orders`), a request made again too. Made again with its Idempotency-Key, the order that key came to, as it stands — never a second order or a second charge. */
  | { method: 'POST'; path: '/orders'; body: StoreOrderRequest; answer: StoreCheckoutResponse }
  /** The receipt of a card transfer the checkout answered (`transfer.payment_id`): a picture, with the customer's words — taken as the bot takes one, and reviewed by support (their screen, the report group), always: the method's review window, which accepts a receipt sent in the bot nobody looked at, never takes one uploaded here. Its order is the answer, the payment awaiting review. 404 for a payment that is not theirs; 422 on `status` while it awaits no receipt (one sent already, refused, paid, cancelled; not a card transfer), on `file` for no picture — JPEG, PNG or WebP by its bytes, 10 MB at most —, on `note` past its length; 429 past 10 pictures uploaded in an hour — their receipts and their tickets' pictures together, one budget —, with `Retry-After`; 503 while the host's free disk space is under the 200 MB the shop keeps in reserve, or once the pictures uploaded to the shop in a day — its customers' and support's together — took 1024 MB, or while the shop takes no orders (its bot switched off); a form larger than the host's PHP takes is a 413, and a form field that is not UTF-8 a 422 on it. */
  | { method: 'POST'; path: `/payments/${number}/receipt`; body: StoreReceiptRequest; answer: StoreOrderResponse }
  /** Charge the wallet by `amount` — within GET /wallet's `top_up` bounds (422 on `amount`, saying them) —, paid with `method_id`: any way but the wallet itself (422 on `method_id`); a card waits for its receipt (POST /payments/{id}/receipt), and once paid the amount lands on the balance. Refused as POST /orders is for too many unpaid orders open (422), past the checkout's pace (429) and while the shop takes no orders (503). Made again with its Idempotency-Key, the top-up that key came to, as it stands. */
  | { method: 'POST'; path: '/wallet/top-up'; body: StoreTopUpRequest; answer: StoreCheckoutResponse }
  /** Mark the customer's notices read: those numbered `ids` — a number that is not one of theirs marks nothing, it is no error —, or every one without `ids` (`{}`); one read before keeps when it was. How many are left unread is the answer. 422 on `ids` for anything but a list of at most 100 numbers. */
  | { method: 'POST'; path: '/notifications/read'; body: StoreNotificationsReadRequest; answer: StoreUnreadResponse }
  /** Open a ticket — its subject, its first message, the service of theirs it is about, and a picture as a form —: waiting on support, which the report group hears of at once. 422 on `subject` (3 to 120 characters), `body` (1 to 4000), `subscription_id` (not one of theirs) or `file` (a JPEG, a PNG or a WebP by its bytes, 10 MB at most); 429 (`Retry-After`) past 10 tickets in an hour, 30 messages in 10 minutes, or 10 pictures in an hour (their receipts and their tickets' together); 503 while the host's free disk space is under the 200 MB the shop keeps in reserve, or once the pictures uploaded to the shop in a day — its customers' and support's together — took 1024 MB; a form larger than the host's PHP takes is a 413, and a form field that is not UTF-8 a 422 on it. */
  | { method: 'POST'; path: '/tickets'; body: StoreTicketRequest | StoreTicketUploadRequest; answer: StoreTicketResponse }
  /** The website showed the customer the ticket: support's latest words read (`unread` false), and the notices about it in their feed read too — the ticket as it stands. Another customer's is not there (404). */
  | { method: 'POST'; path: `/tickets/${number}/read`; body: undefined; answer: StoreTicketResponse }
  /** Write in the ticket — its words, and a picture as a form —: waiting on support again (a closed one opens again, its rating left behind), the report group under the ticket. 422 on `body` or `file`, on `file` too once the ticket keeps 20 pictures they uploaded, or on `status` once it holds 200 messages (a new ticket is the way on); 429 (`Retry-After`) past 30 messages in 10 minutes, or 10 pictures in an hour (their receipts and their tickets' together); 503 while the host's free disk space is under the 200 MB the shop keeps in reserve, or once the pictures uploaded to the shop in a day — its customers' and support's together — took 1024 MB; a form larger than the host's PHP takes is a 413, and a form field that is not UTF-8 a 422 on it. */
  | { method: 'POST'; path: `/tickets/${number}/messages`; body: TicketMessageRequest | TicketMessageUploadRequest; answer: StoreTicketResponse }
  /** Close the ticket (the report group hears it); 422 on `status` when it is closed already */
  | { method: 'POST'; path: `/tickets/${number}/close`; body: undefined; answer: StoreTicketResponse }
  /** Rate a closed ticket — 1 to 5, and a note —, a rating given before replaced; the report group hears a first rating, or one that changed. 422 on `rating` or `note` (500 characters at most), then on `status` while it is not closed; 429 (`Retry-After`): each rating is one of their 30 messages in 10 minutes. */
  | { method: 'POST'; path: `/tickets/${number}/rating`; body: StoreTicketRatingRequest; answer: StoreTicketResponse }
