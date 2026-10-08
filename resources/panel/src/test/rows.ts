import type {
  AgencyLevelRow,
  BotSettingsData,
  CustomerAccount,
  DriverDescription,
  DriverValues,
  FieldDescription,
  ManualMethodRow,
  OrderRow,
  PaymentRow,
  PlanCategoryRow,
  PlanRow,
  ReviewRow,
  ServerRow,
  SubscriptionRow,
  TicketDetail,
  TicketMessageRow,
  TicketRow,
  UserRow,
  Website,
} from '@/lib/api-types'

/*
 * Rows as the API hands them to the screens, for the tests that need a whole one — each with `overrides` merged last.
 */

/** A bot's settings as its page reads them: the bot on, top-ups from 10٬000 Toman offered at 50٬000 and 100٬000, every report topic on. */
export function botSettingsRow(overrides: Partial<BotSettingsData> = {}): BotSettingsData {
  return {
    enabled: true,
    phone_required: false,
    join_required: false,
    support_contact: '',
    topup_min: 10000,
    topup_presets: [50000, 100000],
    qr_enabled: true,
    carry_traffic: false,
    auto_renew_days: 2,
    auto_renew_default: false,
    expiry_reminder: false,
    expiry_reminder_days: 3,
    traffic_reminder: false,
    traffic_reminder_percent: 80,
    referral_enabled: false,
    referral_rate: 10,
    referral_first_only: false,
    report_purchases: true,
    report_renewals: true,
    report_wallet: true,
    report_receipts: true,
    report_users: true,
    report_errors: true,
    report_tickets: true,
    report_reviews: true,
    ...overrides,
  }
}

/** A service as the subscriptions screen lists it: active on server 1 «آلمان», what an active one may do allowed. */
export function subscriptionRow(overrides: Partial<SubscriptionRow> = {}): SubscriptionRow {
  return {
    id: 1,
    status: 'active',
    name: 'amir_1',
    link: 'https://sub.example.com/s/Zx81',
    user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    plan: { id: 3, name: 'طلایی' },
    server: { id: 1, name: 'آلمان' },
    traffic: { limit: 30 * 1024 ** 3, used: 5 * 1024 ** 3 },
    ip_limit: 2,
    duration_days: 30,
    auto_renew: false,
    next_period: null,
    starts_at: '2026-09-20T10:00:00Z',
    expires_at: '2026-10-20T10:00:00Z',
    expiring_soon: false,
    last_synced_at: '2026-10-06T09:00:00Z',
    disabled_at: null,
    created_at: '2026-09-19T10:00:00Z',
    actions: { sync: true, disable: true, enable: false, move: true, delete: true, extend: true },
    ...overrides,
  }
}

/** A card payment as the payments screen lists it: a wallet top-up's receipt waiting for review, what such a one allows. */
export function paymentRow(overrides: Partial<PaymentRow> = {}): PaymentRow {
  return {
    id: 7,
    status: 'awaiting_review',
    amount: '50000.00',
    gateway: 'manual',
    kind: 'manual',
    method: 'کارت به کارت (ملت)',
    summary: '6037 •••• •••• 1119 · امیر رضایی',
    reference: null,
    description: null,
    user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    order: { id: 12, type: 'wallet_topup', status: 'pending', plan: null, server: null, subscription_id: null, notes: null },
    receipt: { name: null, note: null, sent_at: '2026-10-06T09:00:00Z' },
    auto_approved: false,
    reviewer: null,
    refund_note: null,
    paid_at: null,
    created_at: '2026-10-06T08:55:00Z',
    actions: { approve: true, reject: true, cancel: true, remind: false, retry: false, refund: false },
    ...overrides,
  }
}

/** An order as the orders screen lists it: the wallet top-up payment 7 pays, still to be paid. */
export function orderRow(overrides: Partial<OrderRow> = {}): OrderRow {
  return {
    id: 12,
    type: 'wallet_topup',
    status: 'pending',
    amount: '50000.00',
    user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
    plan: null,
    server: null,
    subscription: null,
    notes: null,
    payments: [{ id: 7, status: 'awaiting_review', amount: '50000.00', gateway: 'manual', method: 'کارت به کارت (ملت)', paid_at: null, created_at: '2026-10-06T08:55:00Z' }],
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

/** Plan 3 «طلایی», sold whole on server 1 and shown in the bot — with history, so it is not deleted. */
export function planRow(overrides: Partial<PlanRow> = {}): PlanRow {
  return {
    id: 3,
    category: null,
    name: 'طلایی',
    description: null,
    price: '120000.00',
    duration_days: 30,
    traffic_gb: 30,
    ip_limit: 1,
    servers: [{ server: { id: 1, name: 'آلمان', is_active: true, serves_subscriptions: true }, all_inbounds: true, inbounds: [], unsellable_reason: null }],
    unsellable_reason: null,
    is_active: true,
    sort: 1,
    counts: { active_subscriptions: 4, sales: 6, orders: 7, subscriptions: 5 },
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}

/** Category 1 «ماهانه», on, two plans under it. */
export function categoryRow(overrides: Partial<PlanCategoryRow> = {}): PlanCategoryRow {
  return { id: 1, name: 'ماهانه', is_active: true, sort: 1, counts: { plans: 2 }, created_at: '2026-09-01T10:00:00Z', updated_at: '2026-10-06T09:00:00Z', ...overrides }
}

/** Agency level 1 «طلایی», 3٬000 Toman a GB, no agent on it. */
export function levelRow(overrides: Partial<AgencyLevelRow> = {}): AgencyLevelRow {
  return { id: 1, name: 'طلایی', price_per_gb: '3000.00', sort: 1, counts: { agents: 0 }, created_at: '2026-09-01T10:00:00Z', updated_at: '2026-10-06T09:00:00Z', ...overrides }
}

/** Card-to-card method 5, the masked card and holder its summary, no payment made with it yet. */
export function cardMethodRow(overrides: Partial<ManualMethodRow> = {}): ManualMethodRow {
  return {
    id: 5,
    driver: 'manual',
    driver_label: 'کارت به کارت',
    kind: 'manual',
    builtin: false,
    label: 'کارت به کارت (ملت)',
    summary: '6037 •••• •••• 1119 · امیر رضایی',
    config: { card_number: '6037997700001119', card_holder: 'امیر رضایی', instructions: '', auto_approve_after: 0 },
    enabled: true,
    sort: 2,
    counts: { payments: 0 },
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
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
