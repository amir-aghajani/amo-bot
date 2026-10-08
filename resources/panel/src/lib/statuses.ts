import type {
  AgencyRequestStatus,
  AgentBot,
  BotMode,
  BroadcastStatus,
  Delivery,
  OrderStatus,
  OrderType,
  PaymentStatus,
  ReviewStatus,
  ServerGrantStatus,
  ServerRow,
  SubscriptionStatus,
  TicketChannel,
  TicketStatus,
  UserStatus,
} from '@/lib/api-types'

/*
 * What the panel calls each state of each thing, and in which status family it is drawn — one vocabulary per domain,
 * keyed by the API's own values, so a status the API adds is a type error until it is worded here. A badge, a tab and a
 * filter of the same domain say the same words.
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

/** What an order was for. */
export const ORDER_TYPE: Record<OrderType, string> = {
  purchase: 'خرید',
  renewal: 'تمدید',
  wallet_topup: 'شارژ کیف پول',
  traffic: 'خرید حجم نمایندگی',
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

export const USER_STATUS: Record<UserStatus, Status> = {
  active: { label: 'فعال', tone: 'success' },
  banned: { label: 'مسدود', tone: 'danger' },
}

/** A grant (a server's «افزودن زمان و حجم», a mass gift): cancelled is the admin's «توقف». */
export const GRANT_STATUS: Record<ServerGrantStatus, Status> = {
  running: { label: 'در حال انجام', tone: 'info' },
  done: { label: 'انجام‌شده', tone: 'success' },
  cancelled: { label: 'متوقف‌شده', tone: 'neutral' },
}

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

/** An agent's bot in a word: switched off (the agency ended), waiting for its token, kept from running, or running. */
export function agentBotStatus(bot: AgentBot): Status {
  if (bot.status === 'disabled') return BOT_STATUS.disabled
  if (!bot.connected) return { label: 'وصل نشده', tone: 'warning' }
  return bot.problem !== null ? { label: 'مشکل دارد', tone: 'warning' } : BOT_STATUS.active
}
