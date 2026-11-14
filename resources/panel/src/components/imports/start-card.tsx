import { useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Check, DatabaseZap, ExternalLink, FileUp, Upload } from 'lucide-react'
import { BACKUP_TIMEOUT_MS } from '@/components/backups/use-save-backup'
import { EmptyState } from '@/components/empty-state'
import { Field } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { SecretInput } from '@/components/secret-input'
import { externalHref } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { api, ApiError, type FieldErrors } from '@/lib/api'
import type { DriverDescription, ImportState } from '@/lib/api-types'
import { isRtl, unbrokenVersions } from '@/lib/direction'
import { messageOf } from '@/lib/failure'
import { formatBytes } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { asWhole, useForm } from '@/lib/use-form'
import { cn } from '@/lib/utils'

/** What the picker offers; the server reads the bytes, not the name, and says what it refuses. */
const ACCEPT = '.zip,.sql,.gz,application/zip,application/gzip,application/sql'

/** Where a bot's own backup file is found, by its migrator — what the panel adds to the migrator's description. */
const BACKUP_FROM: Record<string, ReactNode> = {
  faoxima: (
    <>
      فایل <bdi dir="ltr">backup.zip</bdi> که ربات Faoxima هر چند ساعت به کانال گزارش‌هایش می‌فرستد؛ آخرین آن را از همان کانال دانلود کنید
    </>
  ),
}

/** What a migrator's description says of it in words (`mark`, `vendor`); undefined for what it does not say. */
function trait(migrator: DriverDescription, name: string): string | undefined {
  const value = migrator.traits[name]
  return typeof value === 'string' ? value : undefined
}

type Source = 'backup' | 'database'

/** Why a backup for good is taken with the old bot stopped: what changes in it afterwards never comes over. */
const FINAL_BACKUP = 'برای انتقال نهایی، اول ربات قبلی را خاموش کنید و بعد پشتیبانش را بگیرید؛ آنچه بعد از پشتیبان در ربات قبلی عوض شود (کدهای استفاده‌شده، خریدها، موجودی‌ها) منتقل نمی‌شود.'

/** A start refused as another import is under way (another tab started one): the imports are read again, showing it. */
function useReadOnBusy(): (errors: FieldErrors) => void {
  const queryClient = useQueryClient()
  return (errors) => {
    if (errors.import !== undefined) void queryClient.invalidateQueries({ queryKey: queryKeys.imports })
  }
}

/**
 * «انتقال تازه»: which bot the data is of — its migrator, as a card —, then where it is read from: the bot's backup file,
 * uploaded, or its database, connected to. Either starts the import's reading (`onStarted`). `uploadLimit` is the host's
 * largest upload (0 when it does not say).
 */
export function StartCard({ migrators, uploadLimit, onStarted }: { migrators: DriverDescription[]; uploadLimit: number; onStarted: (state: ImportState) => void }) {
  const tabs = useId()
  const [picked, setPicked] = useState(migrators[0]?.key ?? '')
  const [source, setSource] = useState<Source>('backup')
  const migrator = migrators.find((each) => each.key === picked)

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle tabIndex={-1} data-phase-heading="" className="text-heading outline-none">
            انتقال تازه
          </CardTitle>
          <CardDescription>
            داده‌های ربات قبلی یک بار خوانده می‌شود و پیش از آن‌که چیزی در فروشگاه نوشته شود، همه را می‌بینید و انتخاب می‌کنید چه چیزهایی منتقل شود. سرویس‌ها همان‌طور که روی پنل هستند می‌آیند: همان
            لینک، همان حجم مصرف‌شده و همان تاریخ پایان.
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-5">
        {migrators.length === 0 ? (
          <EmptyState compact framed icon={DatabaseZap} title="انتقال از رباتی پشتیبانی نمی‌شود" description="این نسخه AmoBot هنوز داده‌های هیچ ربات دیگری را نمی‌خواند." />
        ) : (
          <>
            <MigratorPicker migrators={migrators} value={picked} onChange={setPicked} />

            <div className="grid gap-3">
              <PageTabs
                id={tabs}
                aria-label="منبع داده‌ها"
                value={source}
                onChange={setSource}
                tabs={[
                  { value: 'backup', label: 'فایل پشتیبان' },
                  { value: 'database', label: 'اتصال به دیتابیس' },
                ]}
              />
              <div {...tabPanel(tabs, 'backup')} hidden={source !== 'backup'}>
                <BackupForm migrator={picked} from={migrator && BACKUP_FROM[migrator.key]} limit={uploadLimit} onStarted={onStarted} />
              </div>
              <div {...tabPanel(tabs, 'database')} hidden={source !== 'database'}>
                <DatabaseForm migrator={picked} onStarted={onStarted} />
              </div>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}

/** «ربات قبلی»: the bots AmoBot reads, each a card — its mark, name, project, what comes over and its notes —, one picked. */
function MigratorPicker({ migrators, value, onChange }: { migrators: DriverDescription[]; value: string; onChange: (key: string) => void }) {
  const label = useId()
  const radios = useRef(new Map<string, HTMLButtonElement>())

  // Arrows move the pick along the cards, as a radio group's do; Tab leaves.
  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const [forward, backward] = isRtl() ? ['ArrowLeft', 'ArrowRight'] : ['ArrowRight', 'ArrowLeft']
    const step = event.key === 'ArrowDown' || event.key === forward ? 1 : event.key === 'ArrowUp' || event.key === backward ? -1 : 0
    if (step === 0) return
    event.preventDefault()
    const index = migrators.findIndex((each) => each.key === value)
    const next = migrators[(index + step + migrators.length) % migrators.length]
    if (!next) return
    onChange(next.key)
    radios.current.get(next.key)?.focus()
  }

  return (
    <div className="grid gap-2">
      <p id={label} className="text-body font-medium">
        ربات قبلی
      </p>
      <div role="radiogroup" aria-labelledby={label} onKeyDown={onKeyDown} className="grid gap-2.5 md:grid-cols-2">
        {migrators.map((migrator, index) => {
          const selected = migrator.key === value
          const vendor = trait(migrator, 'vendor')
          const href = vendor === undefined ? null : externalHref(vendor)
          // Named by its label alone; what it brings and its notes describe it.
          const ids = { name: `${label}-${index}-name`, about: `${label}-${index}-about`, notes: `${label}-${index}-notes` }

          return (
            <div
              key={migrator.key}
              className={cn(
                'relative grid content-start gap-3 rounded-xl border p-4 transition-colors duration-150 has-[button:focus-visible]:focus-ring',
                selected ? 'border-selected bg-info-soft/40' : 'border-border hover:bg-fill',
              )}
            >
              {/* The pick covers the card (its ::after); the project's link sits above it, so no control is inside another. */}
              <button
                ref={(element) => {
                  if (element) radios.current.set(migrator.key, element)
                  else radios.current.delete(migrator.key)
                }}
                type="button"
                role="radio"
                aria-checked={selected}
                aria-labelledby={ids.name}
                aria-describedby={migrator.notes.length > 0 ? `${ids.about} ${ids.notes}` : ids.about}
                tabIndex={selected ? 0 : -1}
                onClick={() => onChange(migrator.key)}
                className="grid gap-3 text-start outline-none after:absolute after:inset-0 after:rounded-xl"
              >
                <span className="flex items-start gap-3">
                  <span aria-hidden className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-fill-hover text-footnote font-semibold text-foreground">
                    {trait(migrator, 'mark') ?? migrator.label.slice(0, 2)}
                  </span>
                  <span className="grid min-w-0 flex-1 gap-1">
                    <span id={ids.name} className="text-body font-medium" dir="ltr">
                      {migrator.label}
                    </span>
                    <span id={ids.about} className="text-footnote text-muted-foreground">
                      {migrator.description}
                    </span>
                  </span>
                  {selected && <Check className="mt-1 size-4 shrink-0 text-selected" aria-hidden />}
                </span>
                {migrator.notes.length > 0 && (
                  <span id={ids.notes} className="grid gap-1 border-t border-border pt-3 text-caption text-muted-foreground">
                    {migrator.notes.map((note) => (
                      <span key={note} className="flex gap-2">
                        <span aria-hidden className="mt-[7px] size-1 shrink-0 rounded-full bg-faint" />
                        {unbrokenVersions(note)}
                      </span>
                    ))}
                  </span>
                )}
              </button>
              {href !== null && (
                <a
                  href={href}
                  target="_blank"
                  rel="noreferrer"
                  className="relative z-10 inline-flex w-fit max-w-full items-center gap-1.5 rounded-sm text-caption text-muted-foreground transition-colors outline-none hover:text-foreground hover:underline focus-visible:focus-ring"
                >
                  <ExternalLink className="relative -top-0.5 size-3 shrink-0" aria-hidden />
                  <span dir="ltr" className="min-w-0 break-all">
                    {href.replace(/^https?:\/\//, '')}
                  </span>
                </a>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

/**
 * «فایل پشتیبان»: the bot's backup picked — its own (`from`, where the bot sends it) or a database export —, then
 * uploaded and read. A large file takes a while to go up: the button spins meanwhile. The host's upload limit is said up
 * front and held to before anything goes.
 */
function BackupForm({ migrator, from, limit, onStarted }: { migrator: string; from: ReactNode; limit: number; onStarted: (state: ImportState) => void }) {
  const input = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [tooLarge, setTooLarge] = useState<string | null>(null)
  const busy = useReadOnBusy()
  const upload = useMutation({
    mutationFn: (chosen: File) => api.post('/imports', { migrator, file: chosen }, { timeoutMs: BACKUP_TIMEOUT_MS }),
    meta: { quiet: true },
    onSuccess: (answer) => onStarted(answer.import),
    onError: (error) => error instanceof ApiError && busy(error.errors),
  })

  return (
    <div className="grid gap-3 pt-1">
      <div className="grid gap-1.5 text-footnote leading-relaxed text-muted-foreground">
        <p>یکی از این‌ها را انتخاب کنید:</p>
        <ul className="grid list-disc gap-1 ps-4">
          {from && <li>{from}</li>}
          <li>
            خروجی دیتابیس ربات از phpMyAdmin هاستش (Export با فرمت SQL) یا فایل <bdi dir="ltr">mysqldump</bdi>
          </li>
        </ul>
        <p>
          فایل <bdi dir="ltr">.zip</bdi>، <bdi dir="ltr">.sql</bdi> یا <bdi dir="ltr">.gz</bdi> باشد{limit > 0 && <>؛ هاست فایل بزرگ‌تر از {formatBytes(limit)} را نمی‌پذیرد</>}. پس از پایان انتقال،
          فایل و هرچه از آن خوانده شده پاک می‌شود.
        </p>
        <p>{FINAL_BACKUP}</p>
      </div>
      <input
        ref={input}
        type="file"
        accept={ACCEPT}
        className="sr-only"
        // The button beside it opens it: no second stop for the keyboard.
        tabIndex={-1}
        aria-label="انتخاب فایل پشتیبان ربات قبلی"
        onChange={(e) => {
          const chosen = e.target.files?.[0]
          e.target.value = ''
          if (!chosen) return
          upload.reset()
          if (limit > 0 && chosen.size > limit) {
            setFile(null)
            setTooLarge(`حجم این فایل ${formatBytes(chosen.size)} است؛ هاست فایل بزرگ‌تر از ${formatBytes(limit)} را نمی‌پذیرد. آن را zip کنید، یا حد بارگذاری را در تنظیمات PHP هاست بالا ببرید.`)
            return
          }
          setTooLarge(null)
          setFile(chosen)
        }}
      />
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <Button variant="secondary" icon={FileUp} aria-disabled={upload.isPending} onClick={() => input.current?.click()}>
          {file ? 'انتخاب فایل دیگر' : 'انتخاب فایل'}
        </Button>
        {file && (
          <span className="min-w-0 text-footnote wrap-anywhere text-muted-foreground">
            <bdi dir="ltr">{file.name}</bdi> · {formatBytes(file.size)}
          </span>
        )}
      </div>
      <FormError message={tooLarge ?? (upload.error ? messageOf(upload.error, 'file', 'migrator', 'import') : null)} failure={upload.error ?? undefined} />
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        {/* Held until a file is picked: the button stays where the focus can reach it. */}
        <Button icon={Upload} busy={upload.isPending} aria-disabled={file === null} onClick={() => file && upload.mutate(file)}>
          بارگذاری و خواندن
        </Button>
        <p role="status" className="text-footnote text-muted-foreground empty:hidden">
          {upload.isPending ? 'فایل در حال بارگذاری است؛ فایل بزرگ ممکن است چند دقیقه طول بکشد.' : ''}
        </p>
      </div>
    </div>
  )
}

/** The connection the form holds, every field as typed. */
interface Connection {
  host: string
  port: string
  database: string
  username: string
  password: string
}

/**
 * «اتصال به دیتابیس»: the old bot's MySQL database, connected to read-only — tried at once, each refusal under the field
 * at fault — and read.
 */
function DatabaseForm({ migrator, onStarted }: { migrator: string; onStarted: (state: ImportState) => void }) {
  const form = useForm<Connection>({ host: '', port: '3306', database: '', username: '', password: '' })
  const { values, set, error } = form
  const busy = useReadOnBusy()

  const connect = async () => {
    const answer = await form.submit(
      () =>
        api.post('/imports/database', {
          migrator,
          host: values.host.trim(),
          port: asWhole(values.port),
          database: values.database.trim(),
          username: values.username.trim(),
          password: values.password,
        }),
      { onInvalid: busy },
    )
    if (answer !== undefined) onStarted(answer.import)
  }

  return (
    <form onSubmit={form.handleSubmit(connect)} noValidate className="grid gap-4 pt-1">
      <p className="text-footnote leading-relaxed text-muted-foreground">
        دیتابیس فقط خوانده می‌شود و چیزی در آن نوشته نمی‌شود. اگر دیتابیس ربات قبلی روی هاست دیگری است، در cPanel همان هاست اتصال از بیرون (Remote MySQL) را برای این سرور باز کنید. برای انتقال نهایی،
        اول ربات قبلی را خاموش کنید و بعد به دیتابیسش وصل شوید؛ آنچه بعد از خوانده شدن در ربات قبلی عوض شود منتقل نمی‌شود.
      </p>
      <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
        <Field id="import_host" label="هاست" error={error('host')} hint="مثلا localhost یا آدرس سرور دیتابیس">
          <Input dir="ltr" value={values.host} onChange={(e) => set('host', e.target.value)} autoComplete="off" autoCapitalize="none" spellCheck={false} />
        </Field>
        <Field id="import_port" label="پورت" error={error('port')}>
          <Input dir="ltr" inputMode="numeric" value={values.port} onChange={(e) => set('port', e.target.value)} autoComplete="off" />
        </Field>
      </div>
      <Field id="import_database" label="نام دیتابیس" error={error('database')}>
        <Input dir="ltr" value={values.database} onChange={(e) => set('database', e.target.value)} autoComplete="off" autoCapitalize="none" spellCheck={false} />
      </Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="import_username" label="نام کاربری" error={error('username')}>
          <Input dir="ltr" value={values.username} onChange={(e) => set('username', e.target.value)} autoComplete="off" autoCapitalize="none" spellCheck={false} />
        </Field>
        <Field id="import_password" label="رمز عبور" error={error('password')}>
          <SecretInput value={values.password} onChange={(e) => set('password', e.target.value)} />
        </Field>
      </div>
      <FormError message={form.formError} failure={form.failure} />
      <div>
        <Button type="submit" icon={DatabaseZap} busy={form.busy}>
          اتصال و خواندن
        </Button>
      </div>
    </form>
  )
}
