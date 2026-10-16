import type {
  AgencyRequestStatus,
  AgentBot,
  BotMode,
  BroadcastStatus,
  Delivery,
  DiscountState,
  DiscountType,
  GiftCodeKind,
  GiftCodeStatus,
  ManualPayment,
  OrderPricing,
  OrderStatus,
  OrderType,
  OwnOrderType,
  PaymentStatus,
  RenewalMethod,
  ReviewStatus,
  SellingSuspension,
  ServerGrantStatus,
  ServerMigrationMode,
  ServerMigrationStatus,
  ServerRow,
  SubscriptionStatus,
  TestAccountState,
  TicketChannel,
  TicketStatus,
  UserStatus,
  WheelPrizeKind,
} from '@/lib/api-types'

/*
 * The words and the colour family of every state the panels show — one vocabulary per domain, keyed by the API's own
 * values, so a state the API adds is a type error until it is worded here. A badge, a tab and a filter of one domain
 * use the same words.
 */

/** The status families of the design (index.css): neutral ink, blue for information, green, amber, red. */
export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger'

export interface Status {
  label: string
  tone: Tone
}

export const ORDER_STATUS: Record<OrderStatus, Status> = {
  pending: { label: 'در انتظار پرداخت', tone: 'warning' },
  paid: { label: 'پرداخت‌شده', tone: 'info' },
  processing: { label: 'در حال ساخت', tone: 'info' },
  fulfilled: { label: 'تکمیل‌شده', tone: 'success' },
  failed: { label: 'ناموفق', tone: 'danger' },
  cancelled: { label: 'لغوشده', tone: 'neutral' },
  refunded: { label: 'بازپرداخت‌شده', tone: 'neutral' },
}

/**
 * The orders' queue that waits on support — not a status of its own: paid and not delivered, the delivery failed or
 * nobody is delivering it (the orders list's `stuck`; the dashboard's and the sidebar's count).
 */
export const ORDER_STUCK: Status = { label: 'نیازمند رسیدگی', tone: 'danger' }

/**
 * What an order was for — a plan, then what is bought for a service (its renewal, extra traffic, time or devices), a
 * wallet's top-up, an agent's traffic —, in the order a list's «نوع» filter offers them.
 */
export const ORDER_TYPE: Record<OrderType, string> = {
  purchase: 'خرید',
  renewal: 'تمدید',
  extra_traffic: 'حجم اضافه',
  extra_time: 'زمان اضافه',
  extra_devices: 'کاربر اضافه',
  wallet_topup: 'شارژ کیف پول',
  traffic: 'خرید حجم نمایندگی',
}

/**
 * The price an order was made at, when it is not its plan's own (`regular`, which says nothing): a customer's first
 * purchase at its plan's first-purchase price, or its agent price — an agent's, or a customer of a «همکار» group —, said
 * beside or under its amount.
 */
export const ORDER_PRICING: Record<OrderPricing, string | null> = {
  regular: null,
  first_purchase: 'قیمت خرید اول',
  agent: 'قیمت نماینده',
  gift: 'هدیه',
}

/** How an order support made by hand was paid (ManualPayment::label()): said beside its amount and in its dialog. */
export const MANUAL_PAYMENT: Record<ManualPayment, string> = {
  free: 'رایگان',
  wallet: 'از کیف پول مشتری',
  outside: 'پرداخت خارج از فروشگاه',
}

/** How a renewal adds its package to a service, as the bot and the website name each method (RenewalMethod::label()), in the bot's order. */
export const RENEWAL_METHOD: Record<RenewalMethod, string> = {
  extend: 'تمدید پلن فعلی',
  reset_traffic: 'ریست حجم + افزایش روز',
  reset_time: 'ریست زمان + انتقال حجم',
}

/**
 * Whether a message the bot wrote someone of its own accord reached them (the API's `delivery`): Telegram took it
 * (`told`), or — they have no Telegram account — the shop's email did (`emailed`).
 */
export const isDelivered = (delivery: Delivery): delivery is 'told' | 'emailed' => delivery === 'told' || delivery === 'emailed'

/** How a message reached someone who has no Telegram account, said after what it was: «یادآوری با ایمیل فرستاده شد». */
export const EMAILED = 'با ایمیل فرستاده شد'

/**
 * Why a message the bot wrote someone of its own accord did not reach them (the API's `delivery`, but the two that did),
 * told of whom it was for — and whether they have a Telegram account (`telegram`: without one, it was to go by email):
 * «مشتری ربات را مسدود کرده یا حسابش دیگر نیست».
 */
export const NOT_DELIVERED: Record<Exclude<Delivery, 'told' | 'emailed'>, (who: string, telegram: boolean) => string> = {
  turned_away: (who) => `${who} ربات را مسدود کرده یا حسابش دیگر نیست`,
  unreachable: (_who, telegram) => (telegram ? 'تلگرام در دسترس نبود' : 'سرور ایمیل در دسترس نبود'),
  refused: () => 'تلگرام پیام را نپذیرفت',
  no_telegram: (who) => `این ${who} تلگرام ندارد، و ایمیلی هم برایش نرفت: ایمیلی ندارد، یا ارسال ایمیل راه نیفتاده است`,
}

/** Under a paid payment that went to the wallet (`to_wallet`): it came after its order had closed, and paid nothing of it. */
export const TO_WALLET = 'به کیف پول مشتری رفت'

/**
 * Under an online payment held for support (`held`): its gateway took money on it that does not fit it, or kept money
 * on it unsettled for 48 hours, so it is never asked about again and, while it waits, its order is paid no other way —
 * support settles it with the customer at the gateway.
 */
export const HELD = 'نگه داشته شده برای پشتیبانی'

export const PAYMENT_STATUS: Record<PaymentStatus, Status> = {
  pending: { label: 'در انتظار پرداخت', tone: 'warning' },
  awaiting_review: { label: 'در انتظار بررسی', tone: 'warning' },
  paid: { label: 'پرداخت‌شده', tone: 'success' },
  failed: { label: 'ناموفق', tone: 'danger' },
  cancelled: { label: 'لغوشده', tone: 'neutral' },
  refunded: { label: 'بازپرداخت‌شده', tone: 'neutral' },
}

export const SUBSCRIPTION_STATUS: Record<SubscriptionStatus, Status> = {
  active: { label: 'فعال', tone: 'success' },
  expired: { label: 'منقضی‌شده', tone: 'neutral' },
  disabled: { label: 'غیرفعال', tone: 'neutral' },
  deleted: { label: 'حذف‌شده', tone: 'neutral' },
}

/** A free test account: its service's state, or that it is still being made or was removed (still counted in the quota). */
export const TEST_STATE: Record<TestAccountState, Status> = {
  making: { label: 'در حال ساخت', tone: 'info' },
  waiting: { label: 'در انتظار اولین اتصال', tone: 'warning' },
  running: { label: 'فعال', tone: 'success' },
  disabled: { label: 'غیرفعال', tone: 'neutral' },
  ended: { label: 'تمام‌شده', tone: 'neutral' },
  removed: { label: 'حذف‌شده', tone: 'neutral' },
}

export const USER_STATUS: Record<UserStatus, Status> = {
  active: { label: 'فعال', tone: 'success' },
  banned: { label: 'مسدود', tone: 'danger' },
}

/**
 * A grant (a server's «افزودن زمان و حجم», a mass gift): cancelled is the admin's «لغو». As for a move and a broadcast,
 * «متوقف‌شده» is only ever a pause, «لغوشده» the owner's end.
 */
export const GRANT_STATUS: Record<ServerGrantStatus, Status> = {
  running: { label: 'در حال انجام', tone: 'info' },
  done: { label: 'انجام‌شده', tone: 'success' },
  cancelled: { label: 'لغوشده', tone: 'neutral' },
}

/**
 * A move of a server's services («انتقال سرویس‌ها»): paused by the owner, cancelled by the owner, or failed — left
 * unfinished because the target could take no more (the report group says «ناتمام ماند» too).
 */
export const MIGRATION_STATUS: Record<ServerMigrationStatus, Status> = {
  queued: { label: 'در صف', tone: 'info' },
  running: { label: 'در حال انجام', tone: 'info' },
  paused: { label: 'متوقف‌شده', tone: 'warning' },
  done: { label: 'انجام‌شده', tone: 'success' },
  cancelled: { label: 'لغوشده', tone: 'neutral' },
  failed: { label: 'ناتمام', tone: 'danger' },
}

/** The two ways a server's services move, as the card and its dialog name them (MigrationMode::label() in the report). */
export const MIGRATION_MODE: Record<ServerMigrationMode, string> = { migrate: 'مهاجرت', rescue: 'نجات' }

export const BROADCAST_STATUS: Record<BroadcastStatus, Status> = {
  sending: { label: 'در حال ارسال', tone: 'info' },
  paused: { label: 'متوقف‌شده', tone: 'warning' },
  done: { label: 'انجام‌شده', tone: 'success' },
  cancelled: { label: 'لغوشده', tone: 'neutral' },
}

/** A support ticket: one waiting on support is the queue (the sidebar's count, the dashboard's); one answered waits on the customer. */
export const TICKET_STATUS: Record<TicketStatus, Status> = {
  open: { label: 'در انتظار پاسخ', tone: 'warning' },
  answered: { label: 'پاسخ‌داده‌شده', tone: 'info' },
  closed: { label: 'بسته‌شده', tone: 'neutral' },
}

/**
 * A customer's review: one waiting on support is the queue (the sidebar's count, the dashboard's); an approved one the
 * website shows, a rejected one it does not.
 */
export const REVIEW_STATUS: Record<ReviewStatus, Status> = {
  pending: { label: 'در انتظار بررسی', tone: 'warning' },
  approved: { label: 'تاییدشده', tone: 'success' },
  rejected: { label: 'ردشده', tone: 'neutral' },
}

/** A gift code: still to be taken, taken by a customer, or past its end with nobody taking it. */
export const GIFT_CODE_STATUS: Record<GiftCodeStatus, Status> = {
  unused: { label: 'استفاده‌نشده', tone: 'info' },
  redeemed: { label: 'استفاده‌شده', tone: 'success' },
  expired: { label: 'منقضی‌شده', tone: 'neutral' },
}

/** How a discount code — or a sale — stands now. */
export const DISCOUNT_STATE: Record<DiscountState, Status> = {
  active: { label: 'فعال', tone: 'success' },
  scheduled: { label: 'شروع نشده', tone: 'info' },
  ended: { label: 'تمام‌شده', tone: 'neutral' },
  used_up: { label: 'ظرفیت پر', tone: 'warning' },
  off: { label: 'غیرفعال', tone: 'neutral' },
}

/** What a discount code takes off. */
export const DISCOUNT_TYPE: Record<DiscountType, string> = {
  percent: 'درصدی',
  fixed: 'مبلغ ثابت',
  free: 'رایگان',
}

/**
 * The orders a customer places for themselves (`OwnOrderType`, OrderType::FOR_THEMSELVES) as ORDER_TYPE words them —
 * the choices of a discount's and a cashback rule's kinds of order —, in the order a form offers them.
 */
export const OWN_ORDER_TYPE: Record<OwnOrderType, string> = {
  purchase: ORDER_TYPE.purchase,
  renewal: ORDER_TYPE.renewal,
  extra_traffic: ORDER_TYPE.extra_traffic,
  extra_time: ORDER_TYPE.extra_time,
  extra_devices: ORDER_TYPE.extra_devices,
}

/** What a gift code is worth. */
export const GIFT_CODE_KIND: Record<GiftCodeKind, string> = {
  wallet: 'شارژ کیف پول',
  plan: 'سرویس رایگان',
}

/** What a slice of the lucky wheel gives, in the order its form offers them. */
export const WHEEL_PRIZE_KIND: Record<WheelPrizeKind, string> = {
  wallet: 'شارژ کیف پول',
  traffic: 'حجم',
  days: 'زمان',
  plan: 'سرویس رایگان',
  nothing: 'پوچ',
}

/**
 * Where a ticket's message was written: the customer's on the shop's website or in its bot, support's on a panel, on the
 * website's admin side (one of the shop's admins) or in the report group.
 */
export const TICKET_CHANNEL: Record<TicketChannel, string> = {
  web: 'وب‌سایت',
  bot: 'ربات',
  panel: 'پنل',
  staff: 'مدیریت وب‌سایت',
  group: 'گروه گزارش‌ها',
}

/** A request to become an agent: one waiting is «در انتظار بررسی», as a receipt waiting is. */
export const AGENCY_REQUEST_STATUS: Record<AgencyRequestStatus, Status> = {
  pending: { label: 'در انتظار بررسی', tone: 'warning' },
  approved: { label: 'تاییدشده', tone: 'success' },
  rejected: { label: 'ردشده', tone: 'danger' },
}

/** How a bot gets its updates (GET /system): Telegram calls its webhook, bot:poll asks for them, or nothing does. */
export const BOT_MODE: Record<BotMode, Status> = {
  webhook: { label: 'Webhook', tone: 'success' },
  polling: { label: 'bot:poll', tone: 'success' },
  offline: { label: 'خاموش', tone: 'warning' },
}

/** A server's connection in a word: switched off, its panel failing, answering, or never asked. */
export function serverStatus(server: Pick<ServerRow, 'is_active' | 'last_error' | 'last_checked_at'>): Status {
  if (!server.is_active) return { label: 'غیرفعال', tone: 'neutral' }
  if (server.last_error !== null) return { label: 'خطا در اتصال', tone: 'danger' }
  return server.last_checked_at !== null ? { label: 'متصل', tone: 'success' } : { label: 'بررسی نشده', tone: 'warning' }
}

/** Whether a bot — a shop — is switched on: an agent's goes off when their agency ends (its shop is kept). */
export const BOT_STATUS: Record<AgentBot['status'], Status> = {
  active: { label: 'فعال', tone: 'success' },
  disabled: { label: 'غیرفعال', tone: 'neutral' },
}

/**
 * Why an agent's shop sells nothing new (its bot and services run on): its agency's end passed, a month below its
 * minimum purchase, or the main shop's owner stopped it. Word for word the server's App\Modules\Bots\Enums\
 * SellingSuspension::words() — what the agent's bot and the owner's report group say —: change both together.
 */
export const SELLING_SUSPENSION: Record<SellingSuspension, Status> = {
  expired: { label: 'تاریخ نمایندگی گذشته است', tone: 'danger' },
  minimum: { label: 'خرید حجم ماه قبل به کف خرید ماهانه نرسید', tone: 'danger' },
  manual: { label: 'فروشگاه اصلی فروش را متوقف کرده است', tone: 'danger' },
}

/** An agent's bot in a word: switched off (the agency ended), waiting for its token, kept from running, or running. */
export function agentBotStatus(bot: AgentBot): Status {
  if (bot.status === 'disabled') return BOT_STATUS.disabled
  if (!bot.connected) return { label: 'وصل نشده', tone: 'warning' }
  return bot.problem !== null ? { label: 'مشکل دارد', tone: 'warning' } : BOT_STATUS.active
}
