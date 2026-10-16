import type {
  AgencyLevelRow,
  AgencyStanding,
  BotSettingsData,
  BotSettingsResponse,
  CubePayMethodRow,
  CustomerAccount,
  CustomerGroupRow,
  DashboardResponse,
  DiscountCodeRow,
  DriverDescription,
  DriverValues,
  FieldDescription,
  GiftCodeRow,
  LoyaltyStanding,
  ManualMethodRow,
  OrderRow,
  PaymentCardRow,
  PaymentRow,
  PlanCategoryRow,
  PlanRow,
  QrBackgroundInfo,
  ReviewRow,
  SaleRow,
  ServerMigrationRow,
  ServerRow,
  SubscriptionRow,
  TestAccountRow,
  TestServerRow,
  TicketDetail,
  TicketMessageRow,
  TicketRow,
  UserRow,
  Website,
  WheelPrizeRow,
  WheelSpinRow,
} from '@/lib/api-types'
import { MEGABYTE } from '@/lib/format'

/*
 * Rows as the API hands them to the screens, for the tests that need a whole one — each with `overrides` merged last.
 */

/**
 * A bot's settings as its page reads them — the main shop's: the bot on, top-ups from 10٬000 Toman offered at 50٬000 and
 * 100٬000, no extra traffic, time or devices sold (nothing priced, what may be typed at the server's defaults), every
 * report topic on, the rest at the server's defaults (services added by their link and the custom builder off).
 */
export function botSettingsRow(overrides: Partial<BotSettingsData> = {}): BotSettingsData {
  return {
    enabled: true,
    phone_required: false,
    join_required: false,
    support_contact: '',
    topup_min: 10000,
    topup_presets: [50000, 100000],
    topup_random_count: 0,
    topup_random_min: 50000,
    topup_random_max: 500000,
    topup_random_round: 1000,
    topup_unique_max: 0,
    wallet_transfer_enabled: false,
    wallet_transfer_min: 10000,
    wallet_transfer_max: 5000000,
    wallet_transfer_daily: 10000000,
    card_protect: true,
    card_to_support: false,
    receipt_single: true,
    qr_enabled: true,
    carry_traffic: false,
    renew_window_days: 0,
    renew_window_percent: 0,
    renew_extend: true,
    renew_reset_traffic: false,
    renew_reset_time: false,
    renew_default_method: 'extend',
    auto_renew_days: 2,
    auto_renew_default: false,
    extras_enabled: false,
    extra_traffic_price: null,
    extra_traffic_options: [],
    extra_traffic_min: 1,
    extra_traffic_max: 100,
    extra_time_price: null,
    extra_time_options: [],
    extra_time_min: 1,
    extra_time_max: 30,
    extra_devices_price: null,
    extra_devices_options: [],
    extra_devices_min: 1,
    extra_devices_max: 5,
    expiry_reminder: false,
    expiry_reminder_days: 3,
    traffic_reminder: false,
    traffic_reminder_percent: 80,
    expired_cleanup: false,
    expired_cleanup_hours: 24,
    test_enabled: false,
    test_per_user: 1,
    test_period_days: 0,
    test_daily_limit: 0,
    test_newcomers_only: false,
    test_cleanup: true,
    test_cleanup_hours: 24,
    test_unused_days: 7,
    claim_enabled: false,
    transfer_enabled: false,
    name_pattern: '{username}_{seq}',
    test_name_pattern: 'test_{username}_{seq}',
    name_random: false,
    name_custom: false,
    custom_enabled: false,
    custom_traffic_tiers: [],
    custom_traffic_min: 1,
    custom_traffic_max: 500,
    custom_traffic_options: [10, 20, 50, 100],
    custom_days_tiers: [],
    custom_days_min: 1,
    custom_days_max: 90,
    custom_days_options: [30, 60, 90],
    custom_devices: 2,
    custom_servers: [],
    referral_enabled: false,
    referral_rate: 10,
    referral_renewal_rate: 10,
    referral_first_only: false,
    referral_start_referrer: '0.00',
    referral_start_invitee: '0.00',
    referral_start_when: 'purchase',
    referral_reward_every: 0,
    referral_reward_plan_id: null,
    referral_alert_burst: 10,
    referral_alert_minutes: 60,
    referral_alert_idle: 20,
    report_purchases: true,
    report_renewals: true,
    report_wallet: true,
    report_receipts: true,
    report_users: true,
    report_errors: true,
    report_tickets: true,
    report_reviews: true,
    gift_codes_enabled: false,
    wheel_enabled: false,
    wheel_cooldown_hours: 24,
    wheel_buyers_only: false,
    wheel_cost: 0,
    cashback_enabled: false,
    lottery_enabled: false,
    lottery_hour: 22,
    lottery_winners: 1,
    lottery_prize: 'wallet',
    lottery_wallet: '50000.00',
    lottery_traffic_gb: 10,
    lottery_days: 7,
    lottery_plan_id: null,
    lottery_entries: 'order',
    lottery_exclude_agents: true,
    hold_minutes: 60,
    ...overrides,
  }
}

/** The QR code's background as the shop ships it: a 1024×1024 JPEG, uploads up to 5 MB. */
export function qrBackgroundRow(overrides: Partial<QrBackgroundInfo> = {}): QrBackgroundInfo {
  return { custom: false, mime: 'image/jpeg', width: 1024, height: 1024, size: 182_000, updated_at: 1_759_000_000, max_bytes: 5 * 1024 * 1024, ...overrides }
}

/** The bot settings as their page reads them (GET /bot/settings): `settings`, the shipped QR background, and the name variables `more` gives (none). */
export function botSettingsResponse(settings: BotSettingsData = botSettingsRow(), more: Partial<Omit<BotSettingsResponse, 'settings'>> = {}): BotSettingsResponse {
  return { settings, qr_background: qrBackgroundRow(), name_variables: [], ...more }
}

/** A term's whole days as the API says them beside its hours: rounded up — 0 only for none. */
const daysOf = (hours: number): number => Math.ceil(hours / 24)

/**
 * A service as the subscriptions screen lists it: active on server 1 «آلمان», its 30-day term running (`duration_hours`,
 * its days beside them as the API says them), what an active one may do allowed.
 */
export function subscriptionRow(overrides: Partial<SubscriptionRow> = {}): SubscriptionRow {
  const hours = overrides.duration_hours ?? 30 * 24
  return {
    id: 1,
    status: 'active',
    name: 'amir_1',
    link: 'https://sub.example.com/s/Zx81',
    is_test: false,
    claimed_at: null,
    user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    plan: { id: 3, name: 'طلایی' },
    server: { id: 1, name: 'آلمان' },
    traffic: { limit: 30 * 1024 ** 3, used: 5 * 1024 ** 3 },
    ip_limit: 2,
    duration_days: daysOf(hours),
    duration_hours: hours,
    auto_renew: false,
    next_period: null,
    next_devices: null,
    starts_at: '2026-09-20T10:00:00Z',
    expires_at: '2026-10-20T10:00:00Z',
    expiring_soon: false,
    last_synced_at: '2026-10-06T09:00:00Z',
    disabled_at: null,
    ended_at: null,
    removes_at: null,
    created_at: '2026-09-19T10:00:00Z',
    actions: { sync: true, disable: true, enable: false, move: true, transfer: true, delete: true, extend: true },
    ...overrides,
  }
}

/** When claimedServiceRow()'s customer added it. */
export const CLAIMED_AT = '2026-10-01T10:00:00Z'

/** A service a customer added by its link («افزودن سرویس با لینک»), a client made by hand on its panel: no plan until a renewal gives it one. */
export function claimedServiceRow(overrides: Partial<SubscriptionRow> = {}): SubscriptionRow {
  return subscriptionRow({ plan: null, claimed_at: CLAIMED_AT, ...overrides })
}

/** A card payment as the payments screen lists it: a wallet top-up's receipt waiting for review, what such a one allows. */
export function paymentRow(overrides: Partial<PaymentRow> = {}): PaymentRow {
  return {
    id: 7,
    status: 'awaiting_review',
    amount: '50000.00',
    gateway: 'manual',
    gateway_label: 'کارت به کارت',
    kind: 'manual',
    method: 'کارت به کارت (ملت)',
    summary: '6037 •••• •••• 1119 · امیر رضایی',
    reference: null,
    description: null,
    user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    order: {
      id: 12,
      type: 'wallet_topup',
      status: 'pending',
      plan: null,
      server: null,
      subscription_id: null,
      traffic_bytes: null,
      days: null,
      devices: null,
      notes: null,
      discount_amount: '0.00',
      discounts: [],
      discount_code: null,
    },
    receipt: { name: null, note: null, sent_at: '2026-10-06T09:00:00Z' },
    auto_approved: false,
    reviewer: null,
    refund_note: null,
    paid_at: null,
    link_expires_at: null,
    checked_at: null,
    to_wallet: false,
    held: false,
    created_at: '2026-10-06T08:55:00Z',
    actions: { approve: true, reject: true, cancel: true, remind: false, retry: false, refund: false, check: false },
    ...overrides,
  }
}

/**
 * Payment 7 on CubePay, an online gateway, as the payments screen lists it: Ali's wallet top-up opened there and not
 * paid yet — its authority its id at CubePay, its page open until `link_expires_at` —, what such a one allows.
 */
export function onlinePaymentRow(overrides: Partial<PaymentRow> = {}): PaymentRow {
  return paymentRow({
    status: 'pending',
    gateway: 'cubepay',
    gateway_label: 'CubePay',
    kind: 'online',
    method: 'پرداخت آنلاین',
    summary: null,
    reference: 'A1b2C3d4E5f6',
    receipt: null,
    link_expires_at: '2026-10-06T09:25:00Z',
    actions: { approve: false, reject: false, cancel: true, remind: false, retry: false, refund: false, check: true },
    ...overrides,
  })
}

/** An order as the orders screen lists it: the wallet top-up payment 7 pays, still to be paid. */
export function orderRow(overrides: Partial<OrderRow> = {}): OrderRow {
  return {
    id: 12,
    type: 'wallet_topup',
    status: 'pending',
    amount: '50000.00',
    pricing: 'regular',
    discount_amount: '0.00',
    discounts: [],
    discount_code: null,
    user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    plan: null,
    package_name: null,
    renewal_method: null,
    server: null,
    subscription: null,
    traffic_bytes: null,
    days: null,
    devices: null,
    notes: null,
    manual: null,
    payments: [
      { id: 7, status: 'awaiting_review', amount: '50000.00', gateway: 'manual', method: 'کارت به کارت (ملت)', paid_at: null, to_wallet: false, held: false, created_at: '2026-10-06T08:55:00Z' },
    ],
    paid_at: null,
    fulfilled_at: null,
    created_at: '2026-10-06T08:50:00Z',
    actions: { retry: false, cancel: true },
    ...overrides,
  }
}

/** A customer as the users table lists them: the one the other rows belong to, an active customer with a service running. */
export function userRow(overrides: Partial<UserRow> = {}): UserRow {
  return {
    id: 10,
    name: 'امیر',
    username: 'amir',
    telegram_id: 123456789,
    email: null,
    phone: '+989120000001',
    status: 'active',
    role: 'customer',
    balance: '25000.00',
    discount_percent: 0,
    counts: { orders: 1, subscriptions: 1, active_subscriptions: 1 },
    groups: [{ id: 3, name: 'VIP' }],
    created_at: '2026-09-01T10:00:00Z',
    last_seen_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}

/** A customer's account on the shop's website as their page reads it: a bot customer's, never signed in there. */
export function customerAccount(overrides: Partial<CustomerAccount> = {}): CustomerAccount {
  return {
    email: null,
    google: false,
    has_password: false,
    two_factor: false,
    sessions: 0,
    merges: [],
    ...overrides,
  }
}

/** Where a customer stands among the shop's loyalty tiers as their page reads it: a shop with no tier, nothing spent. */
export function loyaltyStanding(overrides: Partial<LoyaltyStanding> = {}): LoyaltyStanding {
  return { tier: null, spent: '0.00', next: null, ...overrides }
}

/** Server 1 «آلمان», a 3x-ui panel signed into with a token, checked and selling. */
export function serverRow(overrides: Partial<ServerRow> = {}): ServerRow {
  return {
    id: 1,
    name: 'آلمان',
    driver: '3x-ui',
    driver_label: '3X-UI',
    base_url: 'https://de.example.com:2053/panel-path',
    form: {
      name: 'آلمان',
      base_url: 'https://de.example.com:2053/panel-path',
      auth_mode: 'token',
      api_token: { set: true, hint: '••••••ok-1' },
      username: '',
      password: { set: false, hint: '' },
      totp_secret: { set: false, hint: '' },
      verify_tls: true,
      timeout: 30,
      subscription_url: '',
      proxy_enabled: false,
      proxy_host: '',
      proxy_port: 1080,
      proxy_username: '',
      proxy_password: { set: false, hint: '' },
      capacity: '',
      notes: '',
      is_active: true,
    },
    is_active: true,
    capacity: null,
    sort: 1,
    notes: null,
    last_checked_at: '2026-10-06T09:00:00Z',
    last_error: null,
    serves_subscriptions: true,
    unsellable_reason: null,
    counts: { inbounds: 2, selectable_inbounds: 1, active_subscriptions: 4, shop_active_subscriptions: 4 },
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}

/** Move 5 of server 1's services to «هلند» (server 3), running: four services to go through, none yet. */
export function serverMigrationRow(overrides: Partial<ServerMigrationRow> = {}): ServerMigrationRow {
  return {
    id: 5,
    source: { id: 1, name: 'آلمان' },
    target: { id: 3, name: 'هلند' },
    mode: 'migrate',
    include_disabled: false,
    notify: true,
    plans: null,
    status: 'running',
    total: 4,
    moved: 0,
    failed: 0,
    skipped: 0,
    left_behind: 0,
    waiting_reason: null,
    reason: null,
    last_failure: null,
    reviewer: 'root',
    created_at: '2026-10-14T09:00:00Z',
    started_at: '2026-10-14T09:00:01Z',
    finished_at: null,
    ...overrides,
  }
}

/**
 * The 3x-ui connector as GET /servers/drivers describes it: its card's words and traits, and a server's form on it — the
 * name, the panel's address, the way in (a token, or a username and password with the two-factor secret), each secret
 * bound to the address, then under «تنظیمات پیشرفته» the connection's options and the server's own columns.
 */
export function serverDriverRow(): DriverDescription {
  return {
    key: '3x-ui',
    label: '3X-UI',
    description: 'پنل مدیریت Xray.',
    notes: ['فقط نسخه 3 و بالاتر پشتیبانی می‌شود.'],
    traits: { mark: '3X', vendor: 'MHSanaei', docs_url: 'https://github.com/MHSanaei/3x-ui', inbounds: true, link_rotation: true },
    fields: [
      serverField('name', { label: 'نام سرور', required: true }),
      serverField('base_url', { type: 'url', label: 'آدرس پنل', required: true }),
      serverField('auth_mode', {
        type: 'choice',
        label: 'احراز هویت',
        required: true,
        default: 'token',
        options: [
          { value: 'token', label: 'توکن API' },
          { value: 'password', label: 'نام کاربری و رمز' },
        ],
      }),
      serverSecret('api_token', 'توکن API', { when: { auth_mode: ['token'] } }),
      serverField('username', { label: 'نام کاربری', required: true, when: { auth_mode: ['password'] }, ltr: true }),
      serverSecret('password', 'رمز عبور', { when: { auth_mode: ['password'] } }),
      serverSecret('totp_secret', 'کلید TOTP', { required: false, when: { auth_mode: ['password'] }, moved: 'آدرس پنل عوض شده است؛ کلید TOTP را دوباره وارد کنید یا پاکش کنید.' }),
      serverField('verify_tls', { type: 'toggle', label: 'بررسی گواهی TLS', required: true, advanced: true, default: true }),
      serverField('timeout', { type: 'number', label: 'تایم‌اوت اتصال', required: true, advanced: true, unit: 'ثانیه', min: 5, max: 120, default: 30 }),
      serverField('subscription_url', { type: 'url', label: 'آدرس اشتراک', advanced: true }),
      serverField('proxy_enabled', { type: 'toggle', label: 'اتصال از طریق پراکسی SOCKS5', required: true, advanced: true, default: false }),
      serverField('proxy_host', { label: 'آدرس پراکسی', required: true, advanced: true, when: { proxy_enabled: ['true'] }, ltr: true }),
      serverField('proxy_port', { type: 'number', label: 'پورت پراکسی', required: true, advanced: true, when: { proxy_enabled: ['true'] }, min: 1, max: 65535, default: 1080 }),
      serverField('proxy_username', { label: 'نام کاربری پراکسی', advanced: true, when: { proxy_enabled: ['true'] }, ltr: true }),
      serverSecret('proxy_password', 'رمز پراکسی', {
        required: false,
        advanced: true,
        when: { proxy_enabled: ['true'] },
        bound_to: ['proxy_host', 'proxy_port'],
        moved: 'آدرس یا پورت پراکسی عوض شده است؛ رمز پراکسی را دوباره وارد کنید.',
      }),
      serverField('capacity', { type: 'number', label: 'ظرفیت', advanced: true, min: 0 }),
      serverField('notes', { type: 'textarea', label: 'یادداشت', advanced: true }),
      serverField('is_active', { type: 'toggle', label: 'فعال', required: true, advanced: true, default: true }),
    ],
  }
}

/** A secret of a server's way in as its connector describes it: required, bound to the panel's address. */
function serverSecret(name: string, label: string, overrides: Partial<FieldDescription>): FieldDescription {
  return serverField(name, { type: 'secret', label, required: true, secret: true, bound_to: ['base_url'], moved: `آدرس پنل عوض شده است؛ ${label} را دوباره وارد کنید.`, default: null, ...overrides })
}

/** A field of a server's form as its connector's description has it: a line of text, optional, unless `overrides` say. */
function serverField(name: string, overrides: Partial<FieldDescription>): FieldDescription {
  return {
    name,
    type: 'text',
    label: name,
    hint: null,
    placeholder: null,
    required: false,
    secret: false,
    bound_to: [],
    moved: null,
    advanced: false,
    options: [],
    when: {},
    unit: null,
    min: null,
    max: null,
    ltr: false,
    default: '',
    ...overrides,
  }
}

/**
 * Plan 3 «طلایی», 120٬000 Toman for everyone — no first-purchase price, no agent price —, 30 days (`duration_hours`, its
 * days beside them as the API says them), sold whole on server 1 and shown in the bot — with history, so it is not
 * deleted —, its extras at the shop's prices; no checkout note, its button in the bot the client's own look, a row of
 * its own.
 */
export function planRow(overrides: Partial<PlanRow> = {}): PlanRow {
  const hours = overrides.duration_hours ?? 30 * 24
  return {
    id: 3,
    category: null,
    name: 'طلایی',
    description: null,
    price: '120000.00',
    first_purchase_price: null,
    agent_price: null,
    checkout_note: null,
    button_style: null,
    button_row: 1,
    duration_days: daysOf(hours),
    duration_hours: hours,
    traffic_gb: 30,
    ip_limit: 1,
    extras: null,
    servers: [{ server: { id: 1, name: 'آلمان', is_active: true, serves_subscriptions: true }, all_inbounds: true, inbounds: [], unsellable_reason: null }],
    unsellable_reason: null,
    is_active: true,
    hidden_by_owner: false,
    sort: 1,
    counts: { active_subscriptions: 4, sales: 6, orders: 7, subscriptions: 5 },
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}

/** One of the admin's groups of customers: group 1 «VIP», its customers paying plans' own prices, three of them in it. */
export function groupRow(overrides: Partial<CustomerGroupRow> = {}): CustomerGroupRow {
  return { id: 1, name: 'VIP', agent_pricing: false, sort: 1, counts: { users: 3 }, created_at: '2026-10-01T10:00:00Z', ...overrides }
}

/** Category 1 «ماهانه», on, two plans under it. */
export function categoryRow(overrides: Partial<PlanCategoryRow> = {}): PlanCategoryRow {
  return { id: 1, name: 'ماهانه', is_active: true, sort: 1, counts: { plans: 2 }, created_at: '2026-09-01T10:00:00Z', updated_at: '2026-10-06T09:00:00Z', ...overrides }
}

/** Agency level 1 «طلایی», 3٬000 Toman a GB, no agent on it. */
export function levelRow(overrides: Partial<AgencyLevelRow> = {}): AgencyLevelRow {
  return { id: 1, name: 'طلایی', price_per_gb: '3000.00', sort: 1, counts: { agents: 0 }, created_at: '2026-09-01T10:00:00Z', updated_at: '2026-10-06T09:00:00Z', ...overrides }
}

/** An agent's terms of selling: no end, no monthly minimum, selling — in Mehr 1405, nothing bought yet, warned three days ahead. */
export function agencyStanding(overrides: Partial<AgencyStanding> = {}): AgencyStanding {
  return {
    expires_at: null,
    monthly_minimum: null,
    month: { name: 'مهر ۱۴۰۵', purchased: '0.00', ends_at: '2026-10-22T20:30:00Z', judged: false },
    suspension: null,
    warn_days: 3,
    ...overrides,
  }
}

/** A dashboard of the last 30 days with nothing in them: no sale, no customer, nothing waiting, no agent's concern. */
export function dashboardResponse(overrides: Partial<DashboardResponse> = {}): DashboardResponse {
  const none = { value: 0, previous: 0 }
  return {
    range: { days: 30, from: '2026-09-07', to: '2026-10-06' },
    generated_at: '2026-10-06T09:00:00Z',
    kpis: { revenue: { value: '0.00', previous: '0.00' }, orders: none, new_users: none, active_subscriptions: 0, users_total: 0 },
    series: [],
    attention: { payments_to_review: 0, stuck_orders: 0, open_tickets: 0, pending_reviews: 0, pending_orders: 0, servers_with_errors: 0, expiring_soon: 0 },
    traffic_shortage: null,
    agency_standing: null,
    expiring_days: 3,
    recent_orders: [],
    recent_users: [],
    ...overrides,
  }
}

/**
 * Card-to-card method 5 for any amount, its one card 11 (امیر رضایی's, no cap), no payment made with it yet; the shop
 * shows its cards (not support's private chat).
 */
export function cardMethodRow(overrides: Partial<ManualMethodRow> = {}): ManualMethodRow {
  return {
    id: 5,
    driver: 'manual',
    driver_label: 'کارت به کارت',
    kind: 'manual',
    builtin: false,
    label: 'کارت به کارت (ملت)',
    summary: null,
    config: { instructions: '', auto_approve_after: 0 },
    min_amount: null,
    max_amount: null,
    enabled: true,
    sort: 2,
    counts: { payments: 0 },
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-10-06T09:00:00Z',
    cards: [paymentCardRow()],
    to_support: false,
    ...overrides,
  }
}

/** CubePay method 6, an online gateway, for any amount: its token kept (never shown), no payment made with it yet. */
export function cubePayMethodRow(overrides: Partial<CubePayMethodRow> = {}): CubePayMethodRow {
  return {
    id: 6,
    driver: 'cubepay',
    driver_label: 'CubePay',
    kind: 'online',
    builtin: false,
    label: 'پرداخت آنلاین',
    summary: null,
    config: { token: { set: true, hint: '••••••1234' }, fee_percent: '', fee_fixed: 0 },
    min_amount: null,
    max_amount: null,
    enabled: true,
    sort: 3,
    counts: { payments: 0 },
    created_at: '2026-10-15T10:00:00Z',
    updated_at: '2026-10-15T10:00:00Z',
    ...overrides,
  }
}

/**
 * The CubePay driver as GET /payment-methods/drivers describes it (Payments\Drivers\CubePay\CubePayDriver): the token, a
 * secret it cannot go without, and the fee passed on to customers — a percent and a fixed sum.
 */
export function cubePayDriverRow(): DriverDescription {
  return {
    key: 'cubepay',
    label: 'CubePay',
    description: 'مشتری در صفحه CubePay پرداخت می‌کند و پرداخت بدون رسید تایید می‌شود.',
    notes: ['آدرس سایت فروشگاه باید با https شروع شود: CubePay نتیجه هر پرداخت را به آن خبر می‌دهد'],
    traits: { kind: 'online', builtin: false },
    fields: [
      serverField('token', { type: 'secret', label: 'توکن API', hint: 'از ربات ‎@cubepy_bot، بخش «🔗 پنل من».', required: true, secret: true, ltr: true, default: null }),
      serverField('fee_percent', { label: 'کارمزد درصدی بر عهده مشتری', placeholder: '0', ltr: true }),
      serverField('fee_fixed', { type: 'number', label: 'کارمزد ثابت بر عهده مشتری', required: true, unit: 'تومان', min: 0, max: 1000000, default: 0 }),
    ],
  }
}

/**
 * Card 11 of a card method: امیر رضایی's, switched on, no bank, no cap, nothing taken today, no payment shown it — named
 * in one line as the server names it (PaymentCard::summary()), of its number, holder and bank as overridden.
 */
export function paymentCardRow(overrides: Partial<PaymentCardRow> = {}): PaymentCardRow {
  const card = { id: 11, number: '6037997700001119', holder: 'امیر رضایی', bank: null, enabled: true, daily_cap: null, today: '0.00', payments: 0, ...overrides }
  return { summary: [`${card.number.slice(0, 4)} •••• •••• ${card.number.slice(-4)}`, card.holder, card.bank ?? ''].filter((part) => part !== '').join(' · '), ...card }
}

/**
 * The shop's website as its settings page reads it: on at https://shop.example, a site being built on localhost besides,
 * Telegram sign-in on under a Client ID that is the bot's own id, a Client Secret kept, no captcha asked — the captchas
 * described as the server describes them (websiteCaptcha()) —, the shop's admins not let in yet.
 */
export function websiteRow(overrides: Partial<Website> = {}): Website {
  return {
    enabled: true,
    key: '0123456789abcdef01234567',
    base_url: 'https://shop.example/api/store/v1/0123456789abcdef01234567',
    url: 'https://shop.example',
    origins: ['http://localhost:3000'],
    telegram: { enabled: true, client_id: '7123456789', bot_id: 7123456789, has_secret: true },
    email: { enabled: false, mail_ready: true },
    google: { client_id: null },
    captcha: websiteCaptcha(),
    reviews: { enabled: false },
    staff: { enabled: false, strong_sign_in: true, grants: [] },
    ...overrides,
  }
}

/**
 * The website's captcha as its settings page reads it — `driver` asked (none by default), Cloudflare Turnstile and ALTCHA
 * described, Turnstile's keys `turnstile` as kept (at their defaults: none).
 */
export function websiteCaptcha(driver: Website['captcha']['driver'] = 'none', turnstile: DriverValues = { site_key: '', secret_key: { set: false, hint: '' } }): Website['captcha'] {
  return {
    driver,
    drivers: [
      {
        key: 'turnstile',
        label: 'Cloudflare Turnstile',
        description: 'ویجت تایید امنیتی Cloudflare.',
        notes: [],
        traits: {},
        fields: [
          turnstileKey('site_key', 'Site Key'),
          turnstileKey('secret_key', 'Secret Key', { type: 'secret', secret: true, bound_to: ['site_key'], moved: 'با Site Key تازه، Secret Key همان ویجت را هم وارد کنید.', default: null }),
        ],
      },
      { key: 'altcha', label: 'ALTCHA', description: 'تایید امنیتی متن‌باز و بدون سرویس بیرونی.', notes: [], traits: {}, fields: [] },
    ],
    values: { turnstile, altcha: {} },
  }
}

/** One of Cloudflare Turnstile's keys as its form describes it: required, Latin, left to right. */
function turnstileKey(name: string, label: string, overrides: Partial<FieldDescription> = {}): FieldDescription {
  return {
    name,
    type: 'text',
    label,
    hint: null,
    placeholder: null,
    required: true,
    secret: false,
    bound_to: [],
    moved: null,
    advanced: false,
    options: [],
    when: {},
    unit: null,
    min: null,
    max: null,
    ltr: true,
    default: '',
    ...overrides,
  }
}

/** Ticket 12 as the tickets screen lists it: customer 10's, about their service 1, waiting on support, two messages in. */
export function ticketRow(overrides: Partial<TicketRow> = {}): TicketRow {
  return {
    id: 12,
    subject: 'سرعت سرویس پایین است',
    status: 'open',
    customer: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    subscription: { id: 1, name: 'amir_1' },
    last_message_at: '2026-10-06T09:30:00Z',
    messages_count: 2,
    rating: null,
    created_at: '2026-10-06T09:00:00Z',
    closed_at: null,
    ...overrides,
  }
}

/** A message of ticket 12: the customer's first one, from the website, without a picture. */
export function ticketMessage(overrides: Partial<TicketMessageRow> = {}): TicketMessageRow {
  return { id: 30, author: 'customer', reviewer: null, body: 'از دیشب سرعت خیلی پایین است.', attachment: null, channel: 'web', created_at: '2026-10-06T09:00:00Z', ...overrides }
}

/** Ticket 12 with its conversation: the customer wrote twice, the second time with a picture — waiting on support. */
export function ticketDetail(overrides: Partial<TicketDetail> = {}): TicketDetail {
  return {
    ...ticketRow(),
    rating_note: null,
    messages: [ticketMessage(), ticketMessage({ id: 31, body: 'این هم تصویر تست سرعت.', attachment: { name: 'speedtest.png', kept: true }, created_at: '2026-10-06T09:30:00Z' })],
    ...overrides,
  }
}

/** Review 21 as the reviews screen lists it: customer 10's, signed «امیر ر.», four stars from Irancell on Android, waiting on support. */
export function reviewRow(overrides: Partial<ReviewRow> = {}): ReviewRow {
  return {
    id: 21,
    name: 'امیر ر.',
    rating: 4,
    body: 'سرعت خوب است و قطعی ندارد.',
    context: 'ایرانسل · اندروید · Happ',
    status: 'pending',
    customer: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    reviewer: null,
    decided_at: null,
    created_at: '2026-10-06T09:00:00Z',
    actions: { approve: true, reject: true },
    ...overrides,
  }
}

/** Gift code 31 as its screen lists it: «NOWRUZ7K3M9QXAPT» of the batch «نوروز», 50٬000 Toman for the wallet, nobody's yet, for ever. */
export function giftCodeRow(overrides: Partial<GiftCodeRow> = {}): GiftCodeRow {
  return {
    id: 31,
    code: 'NOWRUZ7K3M9QXAPT',
    batch: 'نوروز',
    kind: 'wallet',
    amount: '50000.00',
    plan: null,
    expires_at: null,
    status: 'unused',
    redeemed_by: null,
    redeemed_at: null,
    order: null,
    created_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}

/** Slice 5 of the lucky wheel: «۱۰ هزار تومان», 10٬000 Toman for the wallet, weight 1, on. */
export function wheelPrizeRow(overrides: Partial<WheelPrizeRow> = {}): WheelPrizeRow {
  return { id: 5, label: '۱۰ هزار تومان', kind: 'wallet', amount: '10000.00', plan: null, weight: 1, enabled: true, sort: 1, ...overrides }
}

/** Spin 41 of customer 10's, landed on «۱۰ هزار تومان» and given. */
export function wheelSpinRow(overrides: Partial<WheelSpinRow> = {}): WheelSpinRow {
  return {
    id: 41,
    customer: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    label: '۱۰ هزار تومان',
    kind: 'wallet',
    amount: '10000.00',
    given: true,
    outcome: null,
    order: null,
    subscription: null,
    created_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}

/**
 * Test 51 of customer 10's on server 1 «آلمان»: 500 MB for a day from the first connection, made and waiting for it, no
 * purchase since.
 */
export function testAccountRow(overrides: Partial<TestAccountRow> = {}): TestAccountRow {
  return {
    id: 51,
    customer: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    server: { id: 1, name: 'آلمان' },
    traffic_bytes: 500 * MEGABYTE,
    duration_hours: 24,
    on_hold: true,
    state: 'waiting',
    subscription: { id: 7, name: 'test_amir_1', status: 'active', traffic: { limit: 500 * MEGABYTE, used: 0 }, starts_at: null, expires_at: null },
    bought_after: false,
    created_at: '2026-10-06T09:00:00Z',
    issued_at: '2026-10-06T09:00:00Z',
    ended_at: null,
    ...overrides,
  }
}

/** Server 1 «آلمان» giving tests now: 500 MB for a day from the first connection. */
export function testServerRow(overrides: Partial<TestServerRow> = {}): TestServerRow {
  return { id: 1, name: 'آلمان', test_enabled: true, test_traffic_mb: 500, test_duration_hours: 24, test_on_hold: true, available: true, ...overrides }
}

/** Code 21 of the shop: NOWRUZ, 20% off anything a code takes, for anyone, no window, used once of a hundred. */
export function discountCodeRow(overrides: Partial<DiscountCodeRow> = {}): DiscountCodeRow {
  return {
    id: 21,
    code: 'NOWRUZ',
    note: null,
    type: 'percent',
    value: '20.00',
    max_discount: null,
    min_amount: null,
    order_types: null,
    plans: null,
    servers: null,
    first_purchase_only: false,
    starts_at: null,
    ends_at: null,
    max_uses: 100,
    per_user: null,
    enabled: true,
    uses: 1,
    state: 'active',
    created_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}

/** Sale 4 of the shop: «فروش ویژه نوروز», 20% off every plan, running with no window. */
export function saleRow(overrides: Partial<SaleRow> = {}): SaleRow {
  return {
    id: 4,
    name: 'فروش ویژه نوروز',
    percent: 20,
    order_types: null,
    plans: null,
    starts_at: null,
    ends_at: null,
    enabled: true,
    state: 'active',
    created_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}
