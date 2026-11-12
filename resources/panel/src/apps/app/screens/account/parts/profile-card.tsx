import { useState } from 'react'
import { ChevronLeft, Copy, Crown } from 'lucide-react'
import { Link } from 'react-router'
import { tap } from '@/apps/app/telegram/haptics'
import { telegram } from '@/apps/app/telegram/webapp'
import { copyText } from '@/apps/app/ui/toaster'
import type { StoreCustomer } from '@/lib/api-types'
import { formatDate, initials } from '@/lib/format'

/*
 * The customer's card at the head of «حساب من»: their Telegram picture (or their initials), their name and handle, their
 * numeric Telegram id to copy (what another customer sends money or a service to), since when they are the shop's
 * customer, and their loyalty tier leading to the club.
 */

/**
 * Their Telegram picture, as the launch handed it — only for the account it signed in, and only from t.me, the one
 * place besides the shop the page's policy loads a picture from (vite.config.ts).
 */
function photoOf(customer: StoreCustomer): string | null {
  const user = telegram()?.initDataUnsafe.user
  const photo = user !== undefined && user.id === customer.telegram?.id ? (user.photo_url ?? null) : null

  return photo !== null && photo.startsWith('https://t.me/') ? photo : null
}

/**
 * Pictures that did not load in this visit (t.me out of reach, or refused by a page policy without it): asked for once,
 * then the initials on every later view.
 */
const unloaded = new Set<string>()

function Avatar({ name, photo }: { name: string; photo: string | null }) {
  const [broken, setBroken] = useState(false)

  if (photo !== null && !broken && !unloaded.has(photo)) {
    return (
      <img
        src={photo}
        alt=""
        onError={() => {
          unloaded.add(photo)
          setBroken(true)
        }}
        className="size-16 shrink-0 rounded-full bg-fill object-cover"
      />
    )
  }

  return (
    <span
      aria-hidden
      className="text-on-accent grid size-16 shrink-0 place-items-center rounded-full text-title leading-none font-bold shadow-[0_10px_24px_-12px_var(--app-accent)]"
      style={{ background: 'linear-gradient(140deg, color-mix(in srgb, var(--app-accent) 70%, white), color-mix(in srgb, var(--app-accent) 72%, black))' }}
    >
      {initials(name) || '؟'}
    </span>
  )
}

interface ProfileCardProps {
  customer: StoreCustomer
  /** Their loyalty tier's name while the shop runs tiers; null for none. */
  tier: string | null
}

export function ProfileCard({ customer, tier }: ProfileCardProps) {
  const name = [customer.first_name, customer.last_name].filter(Boolean).join(' ') || 'کاربر بدون نام'
  const telegramId = customer.telegram?.id
  const handle = customer.telegram?.username ?? null

  return (
    <section aria-label="مشخصات حساب" className="card animate-rise relative isolate flex flex-col gap-4 overflow-hidden p-4">
      <span aria-hidden className="bg-accent absolute -start-12 -top-20 -z-10 size-48 rounded-full opacity-20 blur-3xl" />
      <div className="flex items-center gap-4">
        <Avatar name={name} photo={photoOf(customer)} />
        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
          <p className="truncate text-heading font-bold">{name}</p>
          {handle !== null && <p className="ltr text-hint truncate text-right text-footnote">@{handle}</p>}
          {customer.created_at !== null && <p className="text-hint text-caption">{`عضو از ${formatDate(customer.created_at, { dateStyle: 'long' })}`}</p>}
        </div>
      </div>

      {(telegramId !== undefined || tier !== null) && (
        <div className="flex flex-wrap items-center gap-2">
          {telegramId !== undefined && (
            <button
              type="button"
              onClick={() => {
                tap()
                void copyText(String(telegramId), 'شناسه تلگرام کپی شد')
              }}
              aria-label={`کپی شناسه تلگرام ${telegramId}`}
              className="press tap-target relative inline-flex h-8 items-center gap-1.5 rounded-full bg-fill px-3 text-footnote leading-none"
            >
              <span className="text-hint">شناسه تلگرام</span>
              <span className="ltr font-semibold">{telegramId}</span>
              <Copy aria-hidden className="text-accent-text size-4" />
            </button>
          )}
          {tier !== null && (
            <Link
              to="/club"
              onClick={() => tap()}
              className="press tap-target relative inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-footnote leading-none font-semibold"
              style={{ background: 'color-mix(in srgb, #e6a100 14%, transparent)', color: 'color-mix(in srgb, #e6a100 80%, var(--app-text))' }}
            >
              <Crown aria-hidden className="size-4" />
              {`سطح ${tier}`}
              <ChevronLeft aria-hidden className="size-4 opacity-70" />
            </Link>
          )}
        </div>
      )}
    </section>
  )
}
