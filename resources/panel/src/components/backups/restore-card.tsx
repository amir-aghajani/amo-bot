import { useEffect, useId, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CircleAlert, CircleCheck, Download, FileUp, RotateCcw, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { ShopName } from '@/components/backups/shop-name'
import { TABLE_NAMES } from '@/components/backups/table-names'
import { BACKUP_TIMEOUT_MS, keptPath, useSaveBackup } from '@/components/backups/use-save-backup'
import { Callout } from '@/components/callout'
import { Disclosure } from '@/components/disclosure'
import { Fact, FactList } from '@/components/fact-list'
import { FormError } from '@/components/form-footer'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { api } from '@/lib/api'
import type { BackupInspection, BackupRestoreResponse, BackupsStatus } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatBytes, formatDate, formatNumber, timeAgo } from '@/lib/format'

/** What the picker offers; the server reads the bytes, not the name, and says what it refuses. */
const ACCEPT = '.gz,.json.gz,application/gzip'

/** A file checked by the server, waiting to be restored: its name as picked, and what the server read in it. */
interface Picked {
  name: string
  inspection: BackupInspection
}

/** What the scope is, as a sentence's subject: «کل نصب», «فروشگاه @shop». */
function Subject({ status }: { status: Pick<BackupsStatus, 'scope' | 'shop'> }) {
  return status.scope === 'installation' ? (
    <>کل نصب</>
  ) : (
    <>
      فروشگاه <ShopName shop={status.shop} />
    </>
  )
}

/**
 * «بازگردانی»: a backup file picked — the host's upload limit said up front, and held to before anything goes —, checked
 * by the server and described (when it was made, by which version, of what, its rows and what a restore leaves out),
 * then restored only once the admin ticks that what changed since is lost; or dropped. A restore's answer stays on the
 * card with the state kept from just before, to save; every read is asked again, as everything changed. A restore ends
 * the customers' sign-ins on the websites and mini apps, the half-way chats with the bots and the queued reports — every
 * shop's for the whole installation, the shop's alone for a shop —; the panels' own sign-ins stay.
 */
export function RestoreCard({ status }: { status: BackupsStatus }) {
  const queryClient = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  const pick = useRef<HTMLButtonElement>(null)
  const done = useRef<HTMLDivElement>(null)
  const [picked, setPicked] = useState<Picked | null>(null)
  const [tooLarge, setTooLarge] = useState<string | null>(null)
  const [confirmed, setConfirmed] = useState(false)
  const [result, setResult] = useState<BackupRestoreResponse['restore'] | null>(null)
  const limit = status.upload_max_bytes

  const inspect = useMutation({
    mutationFn: (file: File) => api.post('/backups/inspect', { file }, { timeoutMs: BACKUP_TIMEOUT_MS }),
    meta: { quiet: true },
    onSuccess: (answer, file) => setPicked({ name: file.name, inspection: answer.inspection }),
  })
  const restore = useMutation({
    mutationFn: (token: string) => api.post('/backups/restore', { token, confirm: true }, { timeoutMs: BACKUP_TIMEOUT_MS }),
    meta: { quiet: true },
    onSuccess: (answer) => {
      setPicked(null)
      setConfirmed(false)
      setResult(answer.restore)
      if (status.scope === 'installation') {
        toast.success('نصب به حالت پشتیبان برگشت', { description: 'کاربران وب‌سایت‌ها و مینی‌اپ‌ها باید دوباره وارد شوند. حالت پیش از بازگردانی در «پیش از بازگردانی‌ها» نگه داشته شد.' })
      } else {
        toast.success('فروشگاه به حالت پشتیبان برگشت', { description: 'مشتری‌های این فروشگاه باید دوباره وارد وب‌سایت و مینی‌اپ شوند. حالت پیش از بازگردانی در «پیش از بازگردانی‌ها» نگه داشته شد.' })
      }
      // Everything the panel read is of the state before.
      void queryClient.invalidateQueries()
    },
  })

  // The restore's button goes with the file: the focus goes to what it did.
  useEffect(() => {
    if (result !== null) done.current?.focus()
  }, [result])

  const choose = (file: File) => {
    inspect.reset()
    restore.reset()
    setPicked(null)
    setConfirmed(false)
    setResult(null)
    if (limit > 0 && file.size > limit) {
      setTooLarge(`حجم این فایل ${formatBytes(file.size)} است؛ هاست فایل بزرگ‌تر از ${formatBytes(limit)} را نمی‌پذیرد.`)
      return
    }
    setTooLarge(null)
    inspect.mutate(file)
  }

  const drop = () => {
    setPicked(null)
    setConfirmed(false)
    restore.reset()
    pick.current?.focus()
  }

  const busy = inspect.isPending || restore.isPending

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">بازگردانی</CardTitle>
          <CardDescription>
            <Subject status={status} /> را به حالت یک فایل پشتیبان برگردانید. اول فایل بررسی و محتوایش نشان داده می‌شود؛ تا تایید نکنید، چیزی تغییر نمی‌کند.
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-4">
        <div className="grid gap-2">
          <input
            ref={input}
            type="file"
            accept={ACCEPT}
            className="sr-only"
            // The button beside it opens it: no second stop for the keyboard.
            tabIndex={-1}
            aria-label="انتخاب فایل پشتیبان"
            onChange={(e) => {
              const file = e.target.files?.[0]
              e.target.value = ''
              if (file) choose(file)
            }}
          />
          <div>
            {/* Held while either runs — held, not disabled, so the one pressed keeps the focus. */}
            <Button ref={pick} variant="secondary" icon={FileUp} busy={inspect.isPending} aria-disabled={busy} onClick={() => input.current?.click()}>
              {picked ? 'انتخاب فایل دیگر' : 'انتخاب فایل پشتیبان'}
            </Button>
          </div>
          <p className="text-footnote leading-relaxed text-muted-foreground">
            فایل پشتیبانی (<bdi dir="ltr">.json.gz</bdi>) که از همین‌جا گرفته شده است{limit > 0 && <>؛ هاست فایل بزرگ‌تر از {formatBytes(limit)} را نمی‌پذیرد</>}.
          </p>
          <FormError message={tooLarge ?? (inspect.error ? messageOf(inspect.error, 'file') : null)} failure={inspect.error ?? undefined} />
        </div>

        {picked && (
          <Inspection
            picked={picked}
            status={status}
            confirmed={confirmed}
            onConfirm={setConfirmed}
            restoring={restore.isPending}
            error={restore.error}
            onRestore={() => restore.mutate(picked.inspection.token)}
            onDrop={drop}
          />
        )}

        {result && (
          <div ref={done} tabIndex={-1} className="rounded-lg outline-none focus-visible:focus-ring">
            <Restored result={result} status={status} />
          </div>
        )}
      </CardContent>
    </Card>
  )
}

interface InspectionProps {
  picked: Picked
  status: BackupsStatus
  confirmed: boolean
  onConfirm: (confirmed: boolean) => void
  restoring: boolean
  /** Why the restore was refused (the confirm missing, the file waited too long). */
  error: unknown
  onRestore: () => void
  onDrop: () => void
}

/** The file as the server read it, what a restore of it loses, and the admin's yes — or the file dropped. */
function Inspection({ picked, status, confirmed, onConfirm, restoring, error, onRestore, onDrop }: InspectionProps) {
  const titleId = useId()
  const { inspection } = picked
  const made = formatDate(inspection.made_at)

  return (
    <div role="group" aria-labelledby={titleId} className="grid gap-4 rounded-xl border border-border p-4">
      <div className="grid gap-0.5">
        <h3 id={titleId} className="text-body font-medium">
          فایل انتخاب‌شده
        </h3>
        <p className="text-footnote break-all text-muted-foreground">
          <bdi dir="ltr">{picked.name}</bdi>
        </p>
      </div>

      <FactList>
        <Fact label="زمان پشتیبان">
          {made} <span className="text-muted-foreground">({timeAgo(inspection.made_at)})</span>
        </Fact>
        <Fact label="نسخه AmoBot">
          <bdi dir="ltr">{inspection.app_version}</bdi>
        </Fact>
        <Fact label="محتوا">{inspection.scope === 'installation' ? 'کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها' : <Subject status={inspection} />}</Fact>
        <Fact label="ردیف‌ها">
          {formatNumber(inspection.rows)} ردیف در {formatNumber(inspection.tables.length)} جدول
        </Fact>
      </FactList>

      {inspection.tables.length > 0 && (
        <Disclosure label="ردیف‌های هر جدول">
          <ul aria-label="ردیف‌های هر جدول" className="grid gap-x-6 gap-y-1.5 text-footnote sm:grid-cols-2">
            {inspection.tables.map(({ table, rows }) => (
              <li key={table} className="flex min-w-0 items-baseline justify-between gap-3">
                {TABLE_NAMES[table] ? (
                  <span className="min-w-0">{TABLE_NAMES[table]}</span>
                ) : (
                  <code dir="ltr" className="min-w-0 break-all">
                    {table}
                  </code>
                )}
                <span className="shrink-0 text-muted-foreground tabular">{formatNumber(rows)}</span>
              </li>
            ))}
          </ul>
        </Disclosure>
      )}

      {inspection.warnings.length > 0 && (
        <Callout tone="warning" icon={TriangleAlert}>
          <p className="font-medium">پیش از بازگردانی بدانید:</p>
          <ul className="mt-1 grid list-disc gap-1 ps-4">
            {inspection.warnings.map((warning) => (
              <li key={warning}>{warning}</li>
            ))}
          </ul>
        </Callout>
      )}

      <Callout tone="danger" icon={CircleAlert} role="status">
        {status.scope === 'installation' ? (
          <>
            <span className="font-medium">همه داده‌های نصب، یعنی همه فروشگاه‌ها، سرورها و نماینده‌ها، به حالت {made} برمی‌گردد</span> و هرچه از آن زمان تغییر کرده، از سفارش‌ها و پرداخت‌ها تا کاربران
            تازه، از دست می‌رود. پس از بازگردانی، کاربرانی که در وب‌سایت‌ها و مینی‌اپ‌ها وارد شده‌اند باید دوباره وارد شوند، گفت‌وگوهای نیمه‌کاره با ربات‌ها از اول شروع می‌شود و گزارش‌های در صف
            فرستاده نمی‌شوند.
          </>
        ) : (
          <>
            <span className="font-medium">
              داده‌های فروشگاه <ShopName shop={status.shop} /> به حالت {made} برمی‌گردد
            </span>{' '}
            و هرچه از آن زمان در این فروشگاه تغییر کرده، از سفارش‌ها و پرداخت‌ها تا کاربران تازه، از دست می‌رود. مشتری‌هایی که در وب‌سایت و مینی‌اپ این فروشگاه وارد شده‌اند باید دوباره وارد شوند،
            گفت‌وگوهای نیمه‌کاره با ربات این فروشگاه از اول شروع می‌شود و گزارش‌های در صفش فرستاده نمی‌شوند. فروشگاه‌های دیگر و سرورها دست نمی‌خورند.
          </>
        )}{' '}
        حالت فعلی پیش از بازگردانی نگه داشته می‌شود تا اگر لازم شد، فایلش را دریافت کنید.
      </Callout>

      <label className="flex cursor-pointer items-start gap-2.5 text-body">
        <Checkbox checked={confirmed} onCheckedChange={(on) => onConfirm(on === true)} className="mt-[3px]" />
        می‌دانم هرچه بعد از این پشتیبان تغییر کرده، از دست می‌رود.
      </label>

      <FormError message={error ? messageOf(error, 'confirm', 'file', 'token') : null} failure={error ?? undefined} />

      <div className="flex flex-wrap items-center gap-2">
        {/* Held until the admin's yes is ticked: the button stays where the focus can reach it. */}
        <Button variant="destructive" icon={RotateCcw} busy={restoring} aria-disabled={!confirmed} onClick={onRestore}>
          بازگردانی
        </Button>
        <Button variant="secondary" aria-disabled={restoring} onClick={onDrop}>
          انصراف
        </Button>
      </div>
      <p className="text-footnote text-faint">این فایل تا {formatDate(inspection.expires_at, { timeStyle: 'short' })} آماده بازگردانی می‌ماند؛ پس از آن باید دوباره انتخاب شود.</p>
    </div>
  )
}

/** What a restore did: the rows put back, and the state from just before, kept, to save. */
function Restored({ result, status }: { result: BackupRestoreResponse['restore']; status: BackupsStatus }) {
  const { save, saving } = useSaveBackup()
  const counts = Object.values(result.rows)
  const rows = counts.reduce((sum, count) => sum + count, 0)
  const path = keptPath(result.kept)

  return (
    <Callout tone="success" icon={CircleCheck} role="status">
      <div className="grid gap-2">
        <p>
          <Subject status={status} /> به حالت پشتیبان برگشت: {formatNumber(rows)} ردیف در {formatNumber(counts.length)} جدول.
        </p>
        <p>
          حالت پیش از بازگردانی نگه داشته شد:{' '}
          <bdi dir="ltr" className="break-all">
            {result.kept}
          </bdi>
        </p>
        <div>
          <Button variant="secondary" size="sm" icon={Download} busy={saving(path)} onClick={() => save({ path, fallback: result.kept })}>
            دریافت حالت قبل
          </Button>
        </div>
      </div>
    </Callout>
  )
}
