import { CircleCheck, CircleX, RotateCw, TriangleAlert } from 'lucide-react'
import type { StepProps } from '@/apps/admin/install/steps'
import { Callout } from '@/components/callout'
import { PublicPanel } from '@/components/public-screen'
import { Button } from '@/components/ui/button'

/** A Persian line with its Latin runs (PHP, pdo_mysql, config.php) kept left to right, so a leading dot stays in front. */
function Isolated({ text }: { text: string }) {
  return (
    <>
      {text.split(/(\.?[A-Za-z][\w./-]*)/).map((part, index) =>
        index % 2 === 1 ? (
          <bdi key={index} dir="ltr">
            {part}
          </bdi>
        ) : (
          part
        ),
      )}
    </>
  )
}

/** Whether a requirement is met — or, an optional one, missing without holding the installation up. */
function Met({ ok, optional }: { ok: boolean; optional: boolean }) {
  if (ok) return <CircleCheck className="size-4 shrink-0 text-success" aria-label="فراهم است" />
  if (optional) return <TriangleAlert className="size-4 shrink-0 text-warning" aria-label="فراهم نیست، اختیاری" />

  return <CircleX className="size-4 shrink-0 text-danger" aria-label="فراهم نیست" />
}

/**
 * What the host must have, each met or not; the installation goes on once every one but the optional ones is — those
 * (what the panel updates the shop itself with) only say what is missing.
 */
export function RequirementsStep({ status, onDone, recheck, rechecking }: StepProps & { recheck: () => void; rechecking: boolean }) {
  return (
    <PublicPanel title="پیش‌نیازهای سرور" description="فروشگاه روی هاستی اجرا می‌شود که این‌ها را دارد. هر کدام که نیست را از پشتیبانی هاست بخواهید، بعد دوباره بررسی کنید.">
      <ul className="grid gap-1.5">
        {status.requirements.map((requirement) => (
          <li key={requirement.name} className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2 text-body">
            <span>
              <Isolated text={requirement.label} />
              {requirement.optional && <span className="text-muted-foreground"> · اختیاری</span>}
            </span>
            <Met ok={requirement.ok} optional={requirement.optional} />
          </li>
        ))}
      </ul>
      {!status.ready && (
        <Callout tone="warning" icon={TriangleAlert}>
          تا همه موارد فراهم نشود نصب ادامه پیدا نمی‌کند.
        </Callout>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <Button disabled={!status.ready} onClick={() => onDone(status, 'database')}>
          ادامه
        </Button>
        <Button variant="secondary" icon={RotateCw} busy={rechecking} onClick={recheck}>
          بررسی دوباره
        </Button>
      </div>
    </PublicPanel>
  )
}
