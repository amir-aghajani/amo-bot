import type { ImportPhase, ImportProblemReason, ImportStage, ImportState } from '@/lib/api-types'
import type { Status } from '@/lib/statuses'

/* «انتقال از ربات دیگر»'s words: an import's phases, its stages, the kinds of things it brings and why some did not come. */

/** An import's phase, as its badge says it. */
export const PHASE: Record<ImportPhase, Status> = {
  reading: { label: 'در حال خواندن', tone: 'info' },
  review: { label: 'آماده بررسی', tone: 'warning' },
  importing: { label: 'در حال انتقال', tone: 'info' },
  done: { label: 'انجام‌شده', tone: 'success' },
  failed: { label: 'ناموفق', tone: 'danger' },
  cancelled: { label: 'لغوشده', tone: 'neutral' },
}

/** Still under way: the installation's one import (another cannot start beside it). */
export const isOpen = (state: Pick<ImportState, 'phase'>) => state.phase === 'reading' || state.phase === 'review' || state.phase === 'importing'

/** Going on a step at a time — reading or importing —: the panel asks for the next step while it is on screen. */
export const isStepping = (state: Pick<ImportState, 'phase'>) => state.phase === 'reading' || state.phase === 'importing'

/** The stages an import writes in, in their order, as ImportStage::label() names them (a new stage is a type error until it is worded). */
const STAGE_LABELS: Record<ImportStage, string> = {
  servers: 'سرورها',
  groups: 'گروه‌های مشتری',
  customers: 'مشتری‌ها',
  referrals: 'معرفی‌ها',
  plans: 'پلن‌ها',
  services: 'سرویس‌ها',
  discount_codes: 'کدهای تخفیف',
  gift_codes: 'کدهای هدیه',
  channels: 'کانال‌های اجباری',
  cards: 'کارت‌های بانکی',
}

export const STAGES = Object.entries(STAGE_LABELS).map(([stage, label]) => ({ stage, label }))

/** The kinds an import counts what it did with (`counts`), in the order its report lists them. */
export const COUNT_KINDS: { kind: string; label: string }[] = [
  { kind: 'customers', label: 'مشتری‌ها' },
  { kind: 'balances', label: 'موجودی کیف پول‌ها' },
  { kind: 'groups', label: 'گروه‌های مشتری' },
  { kind: 'servers', label: 'سرورها' },
  { kind: 'categories', label: 'دسته‌بندی‌ها' },
  { kind: 'plans', label: 'پلن‌ها' },
  { kind: 'services', label: 'سرویس‌ها' },
  { kind: 'discount_codes', label: 'کدهای تخفیف' },
  { kind: 'gift_codes', label: 'کدهای هدیه' },
  { kind: 'channels', label: 'کانال‌های اجباری' },
  { kind: 'cards', label: 'کارت‌های بانکی' },
]

/** A thing of the other bot's, by the kind a problem names it (the kinds `counts` has) — the kind as it is when the panel has no word for it. */
const THING: Record<string, string> = {
  customers: 'مشتری',
  balances: 'موجودی',
  groups: 'گروه',
  servers: 'پنل',
  categories: 'دسته‌بندی',
  plans: 'پلن',
  services: 'سرویس',
  discount_codes: 'کد تخفیف',
  gift_codes: 'کد هدیه',
  channels: 'کانال',
  cards: 'کارت',
}

export function thingOf(kind: string): string {
  return THING[kind] ?? kind
}

/** The reasons only counted (ProblemReason::counted()): the owner's own choice, or nothing to act on — no row to list. */
export const COUNTED_REASONS: ReadonlySet<ImportProblemReason> = new Set(['reseller', 'panel_skipped', 'not_chosen', 'used_up', 'expired', 'ended', 'removed'])
