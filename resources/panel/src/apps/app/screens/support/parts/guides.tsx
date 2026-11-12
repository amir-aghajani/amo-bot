import { useState } from 'react'
import { ChevronDown, ExternalLink } from 'lucide-react'
import { selection } from '@/apps/app/telegram/haptics'
import { openLink } from '@/apps/app/telegram/webapp'
import { Button } from '@/apps/app/ui/button'
import { Badge } from '@/apps/app/ui/visuals'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * Short guides to connecting with the apps customers use most — v2rayNG, Happ, Streisand, v2rayN —, each a few numbered
 * steps from installing it to the first connection. The subscription link comes from the service's page in «سرویس‌های
 * من»: its «اتصال سریع» (services/parts/connect) hands the link straight to the app where the app takes one that way
 * (v2rayNG, Happ, Hiddify, Streisand), else the link is copied there and added in the app by hand. Where each runs is
 * said as «اتصال سریع» says it. The menus are named as the apps name them, in English where a phone shows them so.
 */

interface Guide {
  name: string
  /** Where it runs. */
  runs: string
  /** Where it is installed from (official pages only). */
  install: { label: string; url: string }[]
  steps: string[]
}

const GUIDES: Guide[] = [
  {
    name: 'v2rayNG',
    runs: 'اندروید',
    install: [
      { label: 'Google Play', url: 'https://play.google.com/store/apps/details?id=com.v2ray.ang' },
      { label: 'GitHub', url: 'https://github.com/2dust/v2rayNG/releases' },
    ],
    steps: [
      'برنامه v2rayNG را از Google Play یا صفحه GitHub آن نصب کنید.',
      'در «سرویس‌های من» صفحه سرویس را باز کنید و در «اتصال سریع» گزینه v2rayNG را بزنید؛ اشتراک خودش به برنامه اضافه می‌شود.',
      'اگر برنامه باز نشد، لینک اشتراک را کپی کنید. در v2rayNG از منوی کناری «گروه اشتراک» (Subscription group setting) را باز کنید، با + یک گروه بسازید، لینک را در قسمت URL بگذارید و ذخیره کنید.',
      'در صفحه اصلی برنامه از منوی سه‌نقطه بالا «به‌روزرسانی اشتراک» (Update subscription) را بزنید تا سرورها بیایند.',
      'یک سرور را انتخاب کنید و دکمه گرد پایین صفحه را بزنید؛ درخواست اتصال VPN اندروید را تایید کنید.',
    ],
  },
  {
    name: 'Happ',
    runs: 'اندروید، iOS و کامپیوتر',
    install: [],
    steps: [
      'برنامه Happ را از App Store یا Google Play نصب کنید.',
      'در «سرویس‌های من» صفحه سرویس را باز کنید و در «اتصال سریع» گزینه Happ را بزنید.',
      'اگر برنامه باز نشد، لینک اشتراک را کپی کنید، در Happ دکمه + بالای صفحه را بزنید و افزودن از کلیپ‌بورد (From clipboard) را انتخاب کنید.',
      'یک سرور را انتخاب کنید و دکمه بزرگ اتصال را بزنید؛ بار اول اجازه افزودن VPN را بدهید.',
    ],
  },
  {
    name: 'Streisand',
    runs: 'iOS و مک',
    install: [],
    steps: [
      'برنامه Streisand را از App Store نصب کنید.',
      'در «سرویس‌های من» صفحه سرویس را باز کنید و در «اتصال سریع» گزینه Streisand را بزنید.',
      'اگر برنامه باز نشد، لینک اشتراک را کپی کنید، در Streisand دکمه + بالای صفحه را بزنید و افزودن از کلیپ‌بورد را انتخاب کنید.',
      'یک سرور را انتخاب کنید و کلید اتصال را روشن کنید؛ بار اول اجازه افزودن VPN را بدهید.',
    ],
  },
  {
    name: 'v2rayN',
    runs: 'ویندوز',
    install: [{ label: 'GitHub', url: 'https://github.com/2dust/v2rayN/releases' }],
    steps: [
      'آخرین نسخه v2rayN برای ویندوز را از صفحه GitHub آن دانلود کنید، فایل را از حالت فشرده خارج کنید و v2rayN را باز کنید.',
      'لینک اشتراک را از صفحه سرویس در «سرویس‌های من» کپی کنید.',
      'در v2rayN از منوی «گروه اشتراک» (Subscription group) گزینه «تنظیمات گروه اشتراک» را باز کنید، «افزودن» را بزنید، لینک را در قسمت URL بگذارید و تایید کنید.',
      'از همان منو «به‌روزرسانی اشتراک» (Update subscription) را بزنید تا سرورها بیایند.',
      'یک سرور را انتخاب کنید و Enter را بزنید تا سرور فعال شود؛ بعد پایین پنجره «پراکسی سیستم» را روی Set system proxy بگذارید.',
    ],
  },
]

/** An app's mark: the first two letters of its name in a 40px square, drawn as ui/visuals IconTile draws a soft one. */
function Monogram({ name }: { name: string }) {
  return (
    <span
      aria-hidden
      className="text-accent-text ltr inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-[color-mix(in_srgb,var(--app-accent-text)_14%,transparent)] text-footnote leading-none font-bold"
    >
      {name.slice(0, 2)}
    </span>
  )
}

/** One app's guide: its name and where it runs; tapped, its steps. */
function GuideCard({ guide }: { guide: Guide }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="card overflow-hidden">
      <button
        type="button"
        aria-expanded={open}
        onClick={() => {
          selection()
          setOpen(!open)
        }}
        className="flex h-16 w-full items-center gap-3 px-4 text-start active:bg-fill"
      >
        <Monogram name={guide.name} />
        {/* Its name beside its mark (the row's start), the badge and the chevron at the row's end. */}
        <span className="ltr min-w-0 truncate text-body font-semibold">{guide.name}</span>
        <span className="ms-auto flex shrink-0 items-center gap-2">
          <Badge tone="hint">{guide.runs}</Badge>
          <ChevronDown aria-hidden className={cn('text-hint size-4.5 transition-transform', open && 'rotate-180')} />
        </span>
      </button>
      {open && (
        <div className="animate-rise flex flex-col gap-4 px-4 pb-4">
          {/* Each step's number in a 24px circle on its first line (24px lines), its words beside it. */}
          <ol className="flex flex-col gap-3">
            {guide.steps.map((step, index) => (
              <li key={step} className="flex gap-3">
                <span className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-fill text-caption leading-none font-bold">{formatNumber(index + 1)}</span>
                <span className="min-w-0 flex-1 text-footnote leading-6">{step}</span>
              </li>
            ))}
          </ol>
          {guide.install.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {guide.install.map((place) => (
                <Button key={place.url} variant="secondary" size="sm" icon={<ExternalLink />} onClick={() => openLink(place.url)}>
                  <span className="ltr">{place.label}</span>
                </Button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

/** Every guide, one under the other. */
export function ConnectionGuides() {
  return (
    <div className="flex flex-col gap-3">
      {GUIDES.map((guide) => (
        <GuideCard key={guide.name} guide={guide} />
      ))}
    </div>
  )
}
