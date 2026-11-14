import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Info, Play, ServerOff, Tags, TriangleAlert, Users, X } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { Field, FormPart } from '@/components/field'
import { FormError, INVALID_FIELD } from '@/components/form-footer'
import { SourceWords } from '@/components/imports/progress-card'
import { useReadFailure } from '@/components/page'
import { serversQuery } from '@/components/servers/queries'
import { StatCard, StatGrid } from '@/components/stat-card'
import { SwitchRow } from '@/components/switch-row'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { api, ApiError } from '@/lib/api'
import type { ImportChoices, ImportPanelChoice, ImportState, ImportSurvey, ImportSurveyCounts, ImportSurveyPanel, ServerRow } from '@/lib/api-types'
import { handleLabel, isolate } from '@/lib/direction'
import { messageOf } from '@/lib/failure'
import { formatAmount, formatMoney, formatNumber, MONEY_UNIT } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/** The switches of what to bring: every one of ImportChoices but the panels'. */
type Toggle = Exclude<keyof ImportChoices, 'panels'>

/** The choices the review starts from when the import has none saved and the server proposed none: every panel left behind. */
function fallback(survey: ImportSurvey): ImportChoices {
  return {
    panels: Object.fromEntries(survey.panels.map((panel) => [panel.key, { action: 'skip', server_id: null }])),
    balances: true,
    banned: true,
    phones: true,
    referrals: true,
    discounts: true,
    groups: true,
    admins: false,
    resellers: false,
    services: true,
    tests: true,
    plans: true,
    plans_on: false,
    discount_codes: true,
    gift_codes: true,
    channels: false,
    cards: true,
  }
}

/** The choices as the screen shows them: a switch held off by the one it goes with is sent off. */
function shownChoices(choices: ImportChoices): ImportChoices {
  return { ...choices, tests: choices.services && choices.tests, plans_on: choices.plans && choices.plans_on }
}

/** The refusals of a choices' save, by what they are under: a panel's, a switch's, and those under neither (the import's). */
function refusalsOf(error: unknown, survey: ImportSurvey) {
  const errors = error instanceof ApiError ? error.errors : {}
  const panels: Record<string, string> = {}
  const toggles: Partial<Record<Toggle, string>> = {}
  const rest: string[] = []
  for (const [field, messages] of Object.entries(errors)) {
    const message = messages[0]
    if (message === undefined) continue
    const panel = survey.panels.find((each) => field === `choices.panels.${each.key}` || field.startsWith(`choices.panels.${each.key}.`))
    if (panel) panels[panel.key] ??= message
    else if (field.startsWith('choices.') && !field.startsWith('choices.panels')) toggles[field.slice('choices.'.length) as Toggle] ??= message
    else rest.push(message)
  }
  return { panels, toggles, rest }
}

interface ReviewProps {
  state: ImportState & { survey: ImportSurvey }
  /** Started: the import as the server has it now (importing). */
  onStarted: (state: ImportState) => void
  /** «لغو انتقال»: asks first. */
  onCancel: () => void
  cancelling: boolean
}

/**
 * The review, before anything is written: what the other bot holds, where each of its panels' services go — one of the
 * shop's servers on the same connector, the panel added as a new server, or nowhere —, what to bring, what never comes,
 * and the warnings; then «شروع انتقال», which asks first and saves the choices — as the screen shows them — before it
 * starts; refused, the focus goes to the first choice refused. The choices start from the import's saved ones, else from
 * the server's proposal.
 */
export function Review({ state, onStarted, onCancel, cancelling }: ReviewProps) {
  const { survey } = state
  const queryClient = useQueryClient()
  const [choices, setChoices] = useState<ImportChoices>(() => state.choices ?? state.proposed ?? fallback(survey))
  const [asking, setAsking] = useState(false)
  const start = useMutation({
    mutationFn: async (chosen: ImportChoices) => {
      await api.put(`/imports/${state.id}/choices`, { choices: chosen })
      return (await api.post(`/imports/${state.id}/start`)).import
    },
    meta: { quiet: true },
    onSuccess: (started) => onStarted(started),
    // Refused because the import moved on meanwhile (another tab started or cancelled it): it is read again.
    onError: (error) => {
      if (error instanceof ApiError && error.field('import') !== undefined) void queryClient.invalidateQueries({ queryKey: queryKeys.imports })
    },
    onSettled: () => setAsking(false),
  })
  const refusals = refusalsOf(start.error, survey)
  const placed = Object.keys(refusals.panels).length + Object.keys(refusals.toggles).length

  // A refused start: the first choice refused takes the focus — on a phone it is cards above the button.
  const root = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (start.error) root.current?.querySelector<HTMLElement>(INVALID_FIELD)?.focus()
  }, [start.error])

  // A choice changed is the owner fixing what was refused: the refusals go.
  const change = (next: (current: ImportChoices) => ImportChoices) => {
    if (start.error) start.reset()
    setChoices(next)
  }
  const toggle = (name: Toggle, on: boolean) => change((current) => ({ ...current, [name]: on }))
  const choosePanel = (key: string, choice: ImportPanelChoice) => change((current) => ({ ...current, panels: { ...current.panels, [key]: choice } }))

  return (
    <div ref={root} className="grid gap-4">
      <SurveyCard state={state} />
      {survey.panels.length > 0 && <PanelsCard panels={survey.panels} choices={choices.panels} errors={refusals.panels} onChoose={choosePanel} />}
      <BringCard survey={survey} choices={choices} errors={refusals.toggles} onToggle={toggle} />
      <LeftCard survey={survey} />

      <Card>
        <CardHeader>
          <CardHeading>
            <CardTitle className="text-heading">شروع انتقال</CardTitle>
            <CardDescription>انتخاب‌ها ذخیره می‌شود و انتقال شروع می‌شود. روی پنل‌های VPN و ربات قبلی چیزی تغییر نمی‌کند و به مشتری‌ها پیامی فرستاده نمی‌شود.</CardDescription>
          </CardHeading>
        </CardHeader>
        <CardContent className="grid gap-3">
          <Callout tone="info" icon={Info}>
            {state.source === 'database'
              ? 'اگر این انتقال نهایی است، ربات قبلی باید پیش از خوانده شدن دیتابیسش خاموش شده باشد'
              : 'اگر این انتقال نهایی است، این پشتیبان باید بعد از خاموش کردن ربات قبلی گرفته شده باشد'}
            ؛ آنچه بعد از آن در ربات قبلی عوض شده (کدهای استفاده‌شده، خریدها، موجودی‌ها) منتقل نمی‌شود.
          </Callout>
          <FormError
            message={start.error ? (refusals.rest[0] ?? (placed > 0 ? 'بعضی انتخاب‌ها پذیرفته نشد؛ دلیل هرکدام کنار خودش آمده است.' : messageOf(start.error))) : null}
            failure={start.error ?? undefined}
          />
          <div className="flex flex-wrap items-center gap-2">
            <Button icon={Play} busy={start.isPending} onClick={() => setAsking(true)}>
              شروع انتقال
            </Button>
            <Button variant="danger-outline" icon={X} busy={cancelling} aria-disabled={start.isPending} onClick={onCancel}>
              لغو انتقال
            </Button>
          </div>
        </CardContent>
      </Card>

      <ConfirmModal
        open={asking}
        onClose={() => setAsking(false)}
        title="شروع انتقال"
        description="داده‌های انتخاب‌شده در این فروشگاه نوشته می‌شود."
        confirmLabel="شروع انتقال"
        pending={start.isPending}
        onConfirm={() => start.mutate(shownChoices(choices))}
      >
        روی پنل‌های VPN و ربات قبلی چیزی تغییر نمی‌کند و به مشتری‌ها پیامی فرستاده نمی‌شود. انتقال ممکن است چند دقیقه طول بکشد؛ تا تمام شود، این صفحه را باز نگه دارید. اگر این فروشگاه از قبل مشتری یا
        سرویس دارد، اول از «تنظیمات پنل › پشتیبان‌گیری» یک پشتیبان بگیرید تا در صورت نیاز به همین حالت برگردید.
      </ConfirmModal>
    </div>
  )
}

/** «آنچه در ربات قبلی پیدا شد»: how much of each kind, the customers' balances together, and the bot's version. */
function SurveyCard({ state }: { state: ImportState & { survey: ImportSurvey } }) {
  const { survey } = state
  const count = (kind: keyof ImportSurveyCounts) => survey.counts[kind]
  const version = state.version ?? survey.version

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle tabIndex={-1} data-phase-heading="" className="text-heading outline-none">
            آنچه در ربات قبلی پیدا شد
          </CardTitle>
          <CardDescription>
            داده‌های <bdi dir="ltr">{state.migrator_label}</bdi>
            {version !== null && (
              <>
                {' '}
                نسخه <bdi dir="ltr">{version}</bdi>
              </>
            )}{' '}
            از <SourceWords state={state} /> خوانده شد. هنوز چیزی در فروشگاه نوشته نشده است؛ پایین‌تر انتخاب کنید چه چیزهایی منتقل شود.
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        <StatGrid label="داده‌های ربات قبلی">
          <StatCard label="مشتری‌ها" value={formatNumber(count('customers'))} hint={count('resellers') > 0 ? `${formatNumber(count('resellers'))} نفر از ربات‌های نماینده` : undefined} />
          <StatCard label="سرویس‌های فعال" value={formatNumber(count('services'))} hint={count('tests') > 0 ? `و ${formatNumber(count('tests'))} اکانت تست` : undefined} />
          <StatCard label="موجودی کیف پول‌ها" value={formatAmount(survey.balance_total)} unit={MONEY_UNIT} hint={`${formatNumber(count('balances'))} مشتری موجودی دارند`} />
          <StatCard label="پلن‌ها" value={formatNumber(count('plans'))} />
          <StatCard label="کدهای تخفیف" value={formatNumber(count('discount_codes'))} />
          <StatCard label="کدهای هدیه" value={formatNumber(count('gift_codes'))} />
          <StatCard label="کانال‌های اجباری" value={formatNumber(count('channels'))} />
          <StatCard label="کارت‌های بانکی" value={formatNumber(count('cards'))} />
        </StatGrid>
      </CardContent>
    </Card>
  )
}

/** A panel's choice as its select holds it: `server:<id>`, `add` or `skip`. */
const choiceValue = (choice: ImportPanelChoice | undefined) => (choice === undefined ? 'skip' : choice.action === 'server' ? `server:${choice.server_id ?? 0}` : choice.action)

function choiceOf(value: string): ImportPanelChoice {
  if (value.startsWith('server:')) return { action: 'server', server_id: Number(value.slice('server:'.length)) }
  return { action: value === 'add' ? 'add' : 'skip', server_id: null }
}

interface PanelsCardProps {
  panels: ImportSurveyPanel[]
  choices: ImportChoices['panels']
  errors: Record<string, string>
  onChoose: (key: string, choice: ImportPanelChoice) => void
}

/**
 * «پنل‌ها»: each of the other bot's panels — its name, kind, address and services — and where its services go: a server
 * of the shop on the same connector (the one on the same address suggested), the panel added as a new server, or
 * nowhere. A panel no connector of the shop speaks to says why, and its services stay behind.
 */
function PanelsCard({ panels, choices, errors, onChoose }: PanelsCardProps) {
  const servers = useQuery(serversQuery)
  const failure = useReadFailure(servers)

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">پنل‌ها</CardTitle>
          <CardDescription>
            سرویس‌های هر پنل روی سروری از فروشگاه که به همان پنل وصل است، با نام کاربری‌شان پیدا می‌شوند. پلن‌ها هم روی همان سرورها فروخته می‌شوند. روی پنل‌ها چیزی تغییر نمی‌کند.
          </CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-3">
        {failure.error && <ErrorState what="سرورها" error={failure.error} onRetry={() => void servers.refetch()} retrying={failure.retrying} />}
        {servers.data ? (
          <ul aria-label="پنل‌های ربات قبلی" className="grid divide-y divide-border overflow-hidden rounded-xl border border-border">
            {panels.map((panel, place) => (
              <PanelRow
                key={panel.key}
                id={`import_panel_${place}`}
                panel={panel}
                servers={servers.data.servers.filter((server) => panel.driver !== null && server.driver === panel.driver)}
                choice={choices[panel.key]}
                error={errors[panel.key]}
                onChoose={(choice) => onChoose(panel.key, choice)}
              />
            ))}
          </ul>
        ) : (
          !failure.error && <Skeleton className="h-40 w-full rounded-xl" />
        )}
      </CardContent>
    </Card>
  )
}

/** One of the other bot's panels: what it is, and where its services go — or why they cannot go anywhere. */
function PanelRow({
  id,
  panel,
  servers,
  choice,
  error,
  onChoose,
}: {
  id: string
  panel: ImportSurveyPanel
  servers: ServerRow[]
  choice: ImportPanelChoice | undefined
  error: string | undefined
  onChoose: (choice: ImportPanelChoice) => void
}) {
  const value = choiceValue(choice)
  const server = choice?.action === 'server' ? servers.find((each) => each.id === choice.server_id) : undefined
  const hint =
    choice?.action === 'server' ? (
      server ? (
        <>
          سرویس‌ها روی سرور «<bdi>{server.name}</bdi>» پیدا و منتقل می‌شوند.
        </>
      ) : (
        'سروری انتخاب کنید.'
      )
    ) : choice?.action === 'add' ? (
      'این پنل با همان اطلاعات اتصال ربات قبلی، سرور تازه این فروشگاه می‌شود و سرویس‌ها روی آن منتقل می‌شوند.'
    ) : servers.length === 0 && !panel.addable ? (
      'سروری با همین نوع پنل در فروشگاه نیست؛ برای انتقال سرویس‌هایش، اول آن را در «سرورها» اضافه کنید.'
    ) : (
      'سرویس‌های این پنل منتقل نمی‌شوند.'
    )

  return (
    <li className="grid gap-2.5 px-3 py-3">
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <span className="flex min-w-0 flex-wrap items-center gap-2">
          <span className="min-w-0 text-body font-medium wrap-anywhere">
            <bdi>{panel.name}</bdi>
          </span>
          <Badge variant="outline">
            <bdi dir="ltr">{panel.type}</bdi>
          </Badge>
        </span>
        <span className="text-footnote text-muted-foreground tabular">{formatNumber(panel.services)} سرویس</span>
      </div>
      {panel.url !== null && (
        <p className="text-footnote break-all text-muted-foreground">
          <bdi dir="ltr">{panel.url}</bdi>
        </p>
      )}
      {panel.driver === null ? (
        <Callout tone="warning" icon={ServerOff} className="text-footnote">
          {panel.unsupported ?? 'AmoBot به این نوع پنل وصل نمی‌شود.'} سرویس‌های این پنل منتقل نمی‌شوند.
        </Callout>
      ) : (
        <Field
          id={id}
          label={
            // The name isolated in a plain string (a Latin one keeps its order), so the field's name is read as one line.
            <span className="min-w-0 wrap-anywhere">{`سرویس‌های «${isolate(panel.name)}» کجا بروند`}</span>
          }
          error={error}
          hint={hint}
        >
          {(control) => (
            <Select value={value} onValueChange={(picked) => onChoose(choiceOf(picked))}>
              <SelectTrigger {...control} className="w-full sm:max-w-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {servers.map((each) => (
                  <SelectItem key={each.id} value={`server:${each.id}`} hint={each.id === panel.suggested_server_id ? 'پیشنهادی: همان آدرس پنل' : undefined}>
                    سرور «{each.name}»
                  </SelectItem>
                ))}
                {panel.addable && <SelectItem value="add">افزودن به‌عنوان سرور تازه</SelectItem>}
                <SelectItem value="skip">منتقل نشود</SelectItem>
              </SelectContent>
            </Select>
          )}
        </Field>
      )}
    </li>
  )
}

/** One switch of what to bring: its name and, under it, what it brings — counted from the survey. */
interface ToggleSpec {
  name: Toggle
  label: string
  hint: ReactNode
  /** Its switch is not offered: there is nothing of it (no admins, no groups). */
  hidden?: boolean
  /** It goes only with another switch, which is off: held, and the hint says so. */
  needs?: { name: Toggle; hint: string }
}

/** What each part of «چه چیزهایی منتقل شود» offers, worded from the survey. */
function partsOf(survey: ImportSurvey): { title: string; icon: typeof Users; about: string; toggles: ToggleSpec[] }[] {
  const count = (kind: keyof ImportSurveyCounts) => formatNumber(survey.counts[kind])
  const groups = survey.groups.map((group) => `${group.name} (${formatNumber(group.customers)} نفر${group.agent_pricing ? '، قیمت نماینده' : ''})`).join('، ')
  const admins = survey.admins.map((admin) => `${admin.username ? handleLabel(admin.username) : admin.telegram_id}${admin.role ? ` (${admin.role})` : ''}`).join('، ')

  return [
    {
      title: 'مشتری‌ها',
      icon: Users,
      about: `همه ${count('customers')} مشتری منتقل می‌شوند؛ مشتری‌ای که از قبل در فروشگاه هست، همان می‌ماند و فقط آنچه ندارد پر می‌شود. پیام خوش‌آمد یا گزارشی فرستاده نمی‌شود.`,
      toggles: [
        {
          name: 'balances',
          label: 'موجودی کیف پول',
          hint: `موجودی هر مشتری به کیف پولش اضافه می‌شود و بدهی، بدهی می‌ماند. ${count('balances')} مشتری موجودی دارند؛ جمع: ${formatMoney(survey.balance_total)}.`,
        },
        { name: 'banned', label: 'مسدودی‌ها', hint: 'مشتری‌هایی که در ربات قبلی مسدود بودند، این‌جا هم مسدود می‌شوند.' },
        { name: 'phones', label: 'شماره موبایل‌ها', hint: 'شماره موبایلی که مشتری در ربات قبلی فرستاده بود.' },
        { name: 'referrals', label: 'معرف‌ها', hint: 'هر مشتری زیرمجموعه همان کسی می‌شود که در ربات قبلی معرفش بود؛ هدیه معرفی داده نمی‌شود.' },
        { name: 'discounts', label: 'تخفیف شخصی', hint: 'درصد تخفیفی که برای هر مشتری جدا تعیین شده بود.' },
        { name: 'groups', label: 'گروه‌ها', hint: `مشتری‌ها به این گروه‌ها می‌پیوندند؛ گروهی که نیست ساخته می‌شود: ${groups}.`, hidden: survey.groups.length === 0 },
        { name: 'admins', label: 'مدیرها', hint: `این افراد مدیر این فروشگاه هم می‌شوند: ${admins}.`, hidden: survey.admins.length === 0 },
        {
          name: 'resellers',
          label: 'مشتری‌های ربات‌های نماینده',
          hint: `${count('resellers')} مشتری که از ربات‌های نماینده‌های ربات قبلی خرید کرده بودند، با سرویس‌هایشان.`,
          hidden: survey.counts.resellers === 0,
        },
      ],
    },
    {
      title: 'سرویس‌ها و پلن‌ها',
      icon: Archive,
      about: 'سرویس‌ها روی سرورهایی پیدا می‌شوند که برای پنل‌هایشان انتخاب کرده‌اید؛ سرویس پنلی که منتقل نمی‌شود نمی‌آید.',
      toggles: [
        { name: 'services', label: 'سرویس‌ها', hint: `${count('services')} سرویس، همان‌طور که روی پنل هستند: همان لینک، همان حجم مصرف‌شده و همان تاریخ پایان.` },
        {
          name: 'tests',
          label: 'اکانت‌های تست',
          hint: `${count('tests')} اکانت تست که هنوز روی پنل هستند.`,
          needs: { name: 'services', hint: 'با خاموش بودن «سرویس‌ها»، اکانت‌های تست هم منتقل نمی‌شوند.' },
        },
        { name: 'plans', label: 'پلن‌ها', hint: `${count('plans')} پلن با دسته‌بندی‌هایشان، روی سرورهایی که پنل‌هایشان به آن‌ها می‌رسد.` },
        {
          name: 'plans_on',
          label: 'پلن‌ها روشن بیایند',
          hint: 'خاموش بمانند تا قیمت‌ها و سرورهایشان را ببینید و خودتان روشن کنید.',
          needs: { name: 'plans', hint: 'با خاموش بودن «پلن‌ها»، پلنی منتقل نمی‌شود.' },
        },
      ],
    },
    {
      title: 'کدها، کانال‌ها و کارت‌ها',
      icon: Tags,
      about: 'کدی که نامش از قبل در فروشگاه هست، دوباره ساخته نمی‌شود و در گزارش می‌آید.',
      toggles: [
        { name: 'discount_codes', label: 'کدهای تخفیف', hint: `${count('discount_codes')} کد تخفیف؛ کدی که استفاده‌هایش یا مهلتش تمام شده، نمی‌آید.` },
        { name: 'gift_codes', label: 'کدهای هدیه', hint: `${count('gift_codes')} کد شارژ کیف پول؛ کدی که استفاده‌هایش یا مهلتش تمام شده، نمی‌آید.` },
        { name: 'channels', label: 'کانال‌های اجباری', hint: `${count('channels')} کانال. ربات این فروشگاه باید در هر کانال مدیر باشد؛ وگرنه آن کانال اضافه نمی‌شود.` },
        { name: 'cards', label: 'کارت‌های بانکی', hint: `${count('cards')} کارت، به روش پرداخت کارت به کارت؛ خاموش می‌آیند تا بررسی‌شان کنید و خودتان روشن کنید.` },
      ],
    },
  ]
}

/** «چه چیزهایی منتقل شود»: the switches, in three parts — the customers, the services and plans, the codes and the rest. */
function BringCard({ survey, choices, errors, onToggle }: { survey: ImportSurvey; choices: ImportChoices; errors: Partial<Record<Toggle, string>>; onToggle: (name: Toggle, on: boolean) => void }) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">چه چیزهایی منتقل شود</CardTitle>
          <CardDescription>آنچه از قبل با همین انتقال یا انتقال قبلی آمده، دوباره نمی‌آید؛ موجودی هر مشتری فقط یک بار اضافه می‌شود.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-4">
        {partsOf(survey).map((part) => (
          <FormPart key={part.title} icon={part.icon} title={part.title} about={part.about}>
            <div className="grid gap-2">
              {part.toggles
                .filter((spec) => !spec.hidden)
                .map((spec) => {
                  const held = spec.needs !== undefined && !choices[spec.needs.name]
                  return (
                    <SwitchRow
                      key={spec.name}
                      label={spec.label}
                      hint={held && spec.needs ? spec.needs.hint : spec.hint}
                      checked={choices[spec.name] && !held}
                      held={held}
                      error={errors[spec.name]}
                      onCheckedChange={(on) => onToggle(spec.name, on)}
                    />
                  )
                })}
            </div>
          </FormPart>
        ))}
      </CardContent>
    </Card>
  )
}

/** «آنچه منتقل نمی‌شود»: what the other bot has that never comes over, and the warnings worth reading first. */
function LeftCard({ survey }: { survey: ImportSurvey }) {
  if (survey.left.length === 0 && survey.warnings.length === 0) return null

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">آنچه منتقل نمی‌شود</CardTitle>
          <CardDescription>این‌ها در ربات قبلی می‌مانند؛ اگر لازمشان دارید، پیش از کنار گذاشتن ربات قبلی از آن‌ها نسخه بگیرید.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-3">
        {survey.left.length > 0 && (
          <ul aria-label="آنچه منتقل نمی‌شود" className="grid divide-y divide-border rounded-xl border border-border text-body">
            {survey.left.map((left) => (
              <li key={left.what} className="flex items-baseline justify-between gap-3 px-3 py-2">
                <span className="min-w-0">{left.what}</span>
                <span className="shrink-0 text-footnote text-muted-foreground tabular">{formatNumber(left.count)}</span>
              </li>
            ))}
          </ul>
        )}
        {survey.warnings.length > 0 && (
          <Callout tone="warning" icon={TriangleAlert}>
            <p className="font-medium">پیش از شروع بدانید:</p>
            <ul className="mt-1 grid list-disc gap-1 ps-4">
              {survey.warnings.map((warning) => (
                <li key={warning}>{warning}</li>
              ))}
            </ul>
          </Callout>
        )}
      </CardContent>
    </Card>
  )
}
