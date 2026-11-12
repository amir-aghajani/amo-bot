import type { ComponentType } from 'react'
import {
  ArrowLeftRight,
  Ban,
  Bell,
  Briefcase,
  CalendarClock,
  CircleCheck,
  CircleX,
  Clock,
  Coins,
  Crown,
  Gift,
  MessageCircle,
  PartyPopper,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
  Trash2,
  UserPlus,
  Wifi,
} from 'lucide-react'
import type { StoreNotification } from '@/lib/api-types'

/*
 * A notice of the feed as the app draws it: an icon and a colour by its type, and where a tap leads — what it is about
 * (an order, a service, a ticket), else the place of the app its type belongs to. A type the app does not know yet is
 * drawn plainly and leads by its subject alone.
 */

export interface NoticeLook {
  icon: ComponentType<{ className?: string }>
  /** A CSS colour. */
  tint: string
}

const GOOD = 'var(--app-success)'
const BAD = 'var(--app-danger)'
const WAIT = 'var(--app-warning)'
const CALM = 'var(--app-accent)'

const LOOKS: Record<string, NoticeLook> = {
  payment_settled: { icon: CircleCheck, tint: GOOD },
  payment_credited: { icon: Coins, tint: GOOD },
  payment_refunded: { icon: Coins, tint: GOOD },
  cashback_earned: { icon: Coins, tint: GOOD },
  wallet_received: { icon: Coins, tint: GOOD },
  payment_rejected: { icon: CircleX, tint: BAD },
  order_cancelled: { icon: CircleX, tint: BAD },
  renewal_failed: { icon: CircleX, tint: BAD },
  topup_refunded: { icon: ArrowLeftRight, tint: WAIT },
  wallet_sent: { icon: ArrowLeftRight, tint: CALM },
  payment_reminder: { icon: Clock, tint: WAIT },
  auto_renew_short: { icon: Clock, tint: WAIT },
  expiry_reminder: { icon: CalendarClock, tint: WAIT },
  traffic_reminder: { icon: CalendarClock, tint: WAIT },
  auto_renewed: { icon: RefreshCw, tint: GOOD },
  service_disabled: { icon: Ban, tint: BAD },
  service_deleted: { icon: Trash2, tint: BAD },
  service_enabled: { icon: Wifi, tint: GOOD },
  service_moved: { icon: Wifi, tint: CALM },
  service_transferred: { icon: ArrowLeftRight, tint: CALM },
  service_received: { icon: Wifi, tint: GOOD },
  service_granted: { icon: Gift, tint: GOOD },
  referral_joined: { icon: UserPlus, tint: GOOD },
  referral_commission: { icon: Coins, tint: GOOD },
  referral_commission_back: { icon: ArrowLeftRight, tint: WAIT },
  referral_start_gift: { icon: Gift, tint: GOOD },
  referral_reward: { icon: Gift, tint: GOOD },
  way_in_added: { icon: ShieldCheck, tint: CALM },
  way_in_removed: { icon: ShieldCheck, tint: CALM },
  password_changed: { icon: ShieldCheck, tint: CALM },
  two_factor_enabled: { icon: ShieldCheck, tint: CALM },
  two_factor_disabled: { icon: ShieldCheck, tint: WAIT },
  account_merged: { icon: ShieldCheck, tint: CALM },
  second_step_locked: { icon: ShieldAlert, tint: BAD },
  email_codes_failed: { icon: ShieldAlert, tint: BAD },
  ticket_answered: { icon: MessageCircle, tint: CALM },
  ticket_closed: { icon: MessageCircle, tint: 'var(--app-hint)' },
  loyalty_tier_up: { icon: Crown, tint: '#e6a100' },
  lottery_won: { icon: PartyPopper, tint: '#ff2d55' },
}

/** The icon and colour of a notice's type. */
export function noticeLook(type: string): NoticeLook {
  return LOOKS[type] ?? (type.startsWith('agency_') ? { icon: Briefcase, tint: '#5856d6' } : { icon: Bell, tint: CALM })
}

/** Where the notices without a subject lead, by their type. */
const PLACES: Record<string, string> = {
  wallet_sent: '/wallet',
  wallet_received: '/wallet',
  service_deleted: '/services',
  service_transferred: '/services',
  referral_joined: '/club/referral',
  referral_commission: '/club/referral',
  referral_commission_back: '/club/referral',
  referral_start_gift: '/wallet',
  referral_reward: '/club/referral',
  way_in_added: '/account',
  way_in_removed: '/account',
  password_changed: '/account/security',
  two_factor_enabled: '/account/security',
  two_factor_disabled: '/account/security',
  account_merged: '/account',
  second_step_locked: '/account/security',
  email_codes_failed: '/account/security',
  loyalty_tier_up: '/club',
  lottery_won: '/club/lottery',
}

/** Where a tap on the notice leads: what it is about, else its type's place; null for nowhere. */
export function noticeLink(notice: StoreNotification): string | null {
  const subject = notice.subject
  if (subject !== null) {
    if (subject.type === 'order') return `/orders/${subject.id}`
    if (subject.type === 'subscription') return `/services/${subject.id}`
    if (subject.type === 'ticket') return `/support/${subject.id}`
  }

  return PLACES[notice.type] ?? null
}

/** A notice's words split for its row: the first line as its heading, the rest under it. */
export function noticeLines(text: string): { head: string; rest: string } {
  const trimmed = text.trim()
  const end = trimmed.indexOf('\n')

  return end === -1 ? { head: trimmed, rest: '' } : { head: trimmed.slice(0, end).trim(), rest: trimmed.slice(end + 1).trim() }
}
