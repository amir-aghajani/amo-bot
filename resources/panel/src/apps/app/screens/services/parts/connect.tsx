import { useState } from 'react'
import { ChevronLeft, Copy, Zap } from 'lucide-react'
import { appFolder } from '@/apps/app/config'
import { tap } from '@/apps/app/telegram/haptics'
import { openLink, telegram } from '@/apps/app/telegram/webapp'
import { CopyValue } from '@/apps/app/ui/fields'
import { Section } from '@/apps/app/ui/section'
import { Sheet } from '@/apps/app/ui/sheet'
import { Notice } from '@/apps/app/ui/states'
import { IconTile } from '@/apps/app/ui/visuals'

/*
 * «اتصال سریع»: the service's link handed to a VPN app by the address that app documents for adding a subscription —
 * v2rayNG's `install-sub` (the link encoded), Happ's `add`, Hiddify's and Streisand's `import` (the link as it is, the
 * service's name after a `#`) —, the apps for the customer's device first. Telegram's openLink takes http and https
 * alone (its script throws on any other), so inside Telegram a tile opens the app's bridge page (app/open.html, the
 * address after its `#`) in Telegram's browser, which shows whose subscription it is, hands the address to the device
 * at the customer's tap and says itself what to do when the app did not open; without Telegram (the tests) the tile is a
 * plain link, and when the page is still in front a moment later — the app is not installed, or nothing took the
 * address —, the customer is given the link to copy and the steps to add it by hand. v2rayN, which has no such address,
 * always is.
 */

type Device = 'android' | 'ios' | 'desktop'

interface ClientApp {
  name: string
  devices: Device[]
  /** The devices it runs on, in words. */
  runs: string
  /** Its address that adds the subscription `link`, named `title`; null for an app that has none. */
  address: ((link: string, title: string) => string) | null
  /** How to add the link in it by hand. */
  steps: string
}

/** The link with its name after a `#` — unless the link has a fragment of its own, which the name would break. */
const named = (link: string, title: string) => (link.includes('#') ? link : `${link}#${encodeURIComponent(title)}`)

const APPS: ClientApp[] = [
  {
    name: 'v2rayNG',
    devices: ['android'],
    runs: 'اندروید',
    address: (link, title) => named(`v2rayng://install-sub?url=${encodeURIComponent(link)}`, title),
    steps: 'در v2rayNG از منوی کناری «گروه اشتراک» را باز کنید، با + یک گروه بسازید، لینک را در «آدرس» بگذارید و ذخیره کنید؛ بعد از منوی بالا «به‌روزرسانی اشتراک» را بزنید.',
  },
  {
    name: 'Happ',
    devices: ['android', 'ios', 'desktop'],
    runs: 'اندروید، iOS و کامپیوتر',
    address: (link) => `happ://add/${link}`,
    steps: 'در Happ دکمه + بالای صفحه را بزنید و «افزودن از کلیپ‌بورد» را انتخاب کنید.',
  },
  {
    name: 'Hiddify',
    devices: ['android', 'ios', 'desktop'],
    runs: 'اندروید، iOS و کامپیوتر',
    address: (link, title) => named(`hiddify://import/${link}`, title),
    steps: 'در Hiddify دکمه «پروفایل جدید» را بزنید و «افزودن از کلیپ‌بورد» را انتخاب کنید.',
  },
  {
    name: 'Streisand',
    devices: ['ios'],
    runs: 'iOS و مک',
    address: (link, title) => named(`streisand://import/${link}`, title),
    steps: 'در Streisand دکمه + را بزنید و «افزودن از کلیپ‌بورد» را انتخاب کنید.',
  },
  {
    name: 'v2rayN',
    devices: ['desktop'],
    runs: 'ویندوز',
    address: null,
    steps: 'در v2rayN از منوی «گروه اشتراک» گزینه «تنظیمات گروه اشتراک» و بعد «افزودن» را بزنید، لینک را در «آدرس» بگذارید و ذخیره کنید؛ بعد «به‌روزرسانی اشتراک» را بزنید.',
  },
]

/** The customer's device: Telegram's word for its client, else the browser's. */
function deviceOf(): Device {
  const platform = telegram()?.platform ?? ''
  if (platform.startsWith('android') || (platform === '' && /Android/i.test(navigator.userAgent))) return 'android'
  if (platform === 'ios' || (platform === '' && /iPhone|iPad|iPod/i.test(navigator.userAgent))) return 'ios'

  return 'desktop'
}

/** Calls `stayed` when the page is still in front a moment after a link to another app was followed. */
function watchLeaving(stayed: () => void): void {
  let left = false
  const leave = () => {
    left = true
  }
  const hidden = () => {
    if (document.visibilityState === 'hidden') left = true
  }
  window.addEventListener('blur', leave)
  window.addEventListener('pagehide', leave)
  document.addEventListener('visibilitychange', hidden)
  window.setTimeout(() => {
    window.removeEventListener('blur', leave)
    window.removeEventListener('pagehide', leave)
    document.removeEventListener('visibilitychange', hidden)
    if (!left) stayed()
  }, 1800)
}

/** The bridge page that opens `address` in its app from Telegram's browser (app/open.html): the address after the `#`. */
function bridge(address: string, name: string): string {
  return `${window.location.origin}${appFolder}/open.html#${new URLSearchParams({ to: address, name }).toString()}`
}

/** The help shown for an app: the link to copy and its steps, and whether it is there because the app did not open. */
type Help = { app: ClientApp; missed: boolean } | null

export function QuickConnect({ link, title }: { link: string; title: string }) {
  const [help, setHelp] = useState<Help>(null)
  const device = deviceOf()
  const apps = [...APPS.filter((app) => app.devices.includes(device)), ...APPS.filter((app) => !app.devices.includes(device))]

  return (
    <>
      <Section title="اتصال سریع" footer="برنامه را نصب کنید و بعد اینجا آن را بزنید تا لینک سرویس به آن اضافه شود." bare>
        {/* Every row of tiles as high as the highest: an app that runs on more wraps its line, and the grid stays even. */}
        <div className="grid auto-rows-fr grid-cols-2 gap-3">
          {apps.map((app) => {
            const body = (
              <>
                <IconTile icon={app.address === null ? Copy : Zap} look="soft" />
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span className="truncate text-body font-semibold">
                    <span className="ltr">{app.name}</span>
                  </span>
                  <span className="text-hint text-caption">{app.runs}</span>
                </span>
                <ChevronLeft aria-hidden className="text-hint size-4 shrink-0 opacity-50" />
              </>
            )
            const tile = 'card press flex min-h-16 items-center gap-3 p-3 text-start'

            return app.address === null ? (
              <button
                key={app.name}
                type="button"
                className={tile}
                onClick={() => {
                  tap()
                  setHelp({ app, missed: false })
                }}
              >
                {body}
              </button>
            ) : (
              <a
                key={app.name}
                href={app.address(link, title)}
                aria-label={`افزودن به ${app.name}`}
                className={tile}
                onClick={(event) => {
                  tap()
                  if (telegram() !== null && app.address !== null) {
                    // Not watched: this page could only tell whether Telegram's browser opened, never the app — the bridge says that.
                    event.preventDefault()
                    openLink(bridge(app.address(link, title), app.name))
                    return
                  }
                  watchLeaving(() => setHelp({ app, missed: true }))
                }}
              >
                {body}
              </a>
            )
          })}
        </div>
      </Section>

      <Sheet open={help !== null} onClose={() => setHelp(null)} title={help === null ? undefined : `افزودن به ${help.app.name}`}>
        {help !== null && (
          <div className="flex flex-col gap-3">
            {help.missed && <Notice tone="warning">{`${help.app.name} باز نشد. اگر نصبش نکرده‌اید اول آن را نصب کنید؛ یا لینک را کپی کنید و دستی اضافه کنید.`}</Notice>}
            <CopyValue label="لینک اشتراک" value={link} done="لینک اشتراک کپی شد" />
            <p className="text-hint px-1 text-footnote">{help.app.steps}</p>
          </div>
        )}
      </Sheet>
    </>
  )
}
