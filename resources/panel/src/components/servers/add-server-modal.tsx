import { ExternalLink } from 'lucide-react'
import { PickerModal } from '@/components/picker-card'
import { serverDriversQuery } from '@/components/servers/queries'
import { ServerForm } from '@/components/servers/server-form'
import { externalHref } from '@/components/text-link'
import type { DriverDescription, Probe, ServerRow } from '@/lib/api-types'

interface AddServerModalProps {
  open: boolean
  onClose: () => void
  /** The server added, with what its panel answered as it was saved. */
  onCreated: (server: ServerRow, probe: Probe | null) => void
}

/** What a connector's description says of it in words (`mark`, `vendor`, `docs_url`); undefined for what it does not say. */
function trait(driver: DriverDescription, name: string): string | undefined {
  const value = driver.traits[name]
  return typeof value === 'string' ? value : undefined
}

/** Two steps: pick a connector — each drawn by what its description says of it —, then fill a server's form on it. */
export function AddServerModal({ open, onClose, onCreated }: AddServerModalProps) {
  return (
    <PickerModal
      open={open}
      onClose={onClose}
      title="افزودن سرور"
      question="پنل این سرور با کدام نرم‌افزار مدیریت می‌شود؟"
      options={serverDriversQuery}
      card={(driver) => {
        const vendor = trait(driver, 'vendor')
        const docs = trait(driver, 'docs_url')
        return {
          mark: trait(driver, 'mark') ?? driver.label.slice(0, 2),
          title: (
            <span className="text-body font-medium" dir="ltr">
              {driver.label}
            </span>
          ),
          meta: vendor && (
            <span className="text-footnote text-muted-foreground" dir="ltr">
              {vendor}
            </span>
          ),
          footer: docs && <DocsLink url={docs} />,
        }
      }}
      form={{
        title: (driver) => `افزودن سرور ${driver.label}`,
        description: () => 'اطلاعات اتصال به پنل را وارد کنید. قبل از ذخیره می‌توانید اتصال را تست کنید.',
        size: 'lg',
        render: (driver) => <ServerForm driver={driver} onSaved={onCreated} onCancel={onClose} />,
      }}
    />
  )
}

function DocsLink({ url }: { url: string }) {
  const href = externalHref(url)
  if (href === null) return null

  return (
    <a
      href={href}
      target="_blank"
      rel="noreferrer"
      className="relative z-10 inline-flex w-fit items-center gap-1.5 rounded-sm text-caption text-muted-foreground transition-colors outline-none hover:text-foreground hover:underline focus-visible:focus-ring"
    >
      {/* Flex centering lines the icon up with the line box, which Vazirmatn's tall ascent pushes below the Latin
          glyphs; 2px up puts the icon's ink between baseline and cap height, where the URL's letters are. */}
      <ExternalLink className="relative -top-0.5 size-3" aria-hidden />
      <span dir="ltr">{url.replace(/^https?:\/\//, '')}</span>
    </a>
  )
}
