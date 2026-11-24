import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, CircleCheck, CircleHelp, Info, Plus, RefreshCw, Sparkles, TriangleAlert, X, type LucideIcon } from 'lucide-react'
import { toast } from 'sonner'
import { AddRow, useRowFocus } from '@/components/add-row'
import { leadingEmoji } from '@/components/bot-texts/button-icon'
import { useCustomEmojis } from '@/components/bot-texts/custom-emojis'
import { emojiKey } from '@/components/bot-texts/emoji-map'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { PremiumEmojiPicker } from '@/components/bot-texts/premium-emoji-picker'
import { Callout } from '@/components/callout'
import { EmptyState } from '@/components/empty-state'
import { ErrorState } from '@/components/error-state'
import { FieldGroup } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { IconButton } from '@/components/icon-button'
import { useReadFailure } from '@/components/page'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { api, type FieldErrors } from '@/lib/api'
import type { CustomEmojiRow, PremiumTextItem, PremiumTextsResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatNumber } from '@/lib/format'
import { premiumTextsQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { useSettingsGroup } from '@/lib/use-settings-group'
import { cn } from '@/lib/utils'

type PremiumTexts = PremiumTextsResponse['premium_texts']

/** The card's draft, as the PUT takes it: the switch and the whole map — a row's count of texts is the server's alone. */
interface Draft {
  enabled: boolean
  items: PremiumTextItem[]
}

function draftOf(premium: PremiumTexts): Draft {
  return { enabled: premium.enabled, items: premium.items.map(({ emoji, emoji_id }) => ({ emoji, emoji_id })) }
}

/** A row's refusals, as the server words them (`items.N.emoji`, `items.N.emoji_id`). */
const rowField = /^items\.(\d+)\.(.+)$/

/** The refusals with the row at `removed` gone: its own dropped, those of the rows after it moved up one — so each stays with its row. */
function withoutRow(errors: FieldErrors, removed: number): FieldErrors {
  const kept: FieldErrors = {}
  for (const [field, messages] of Object.entries(errors)) {
    const row = rowField.exec(field)
    const index = Number(row?.[1])
    if (row === null) kept[field] = messages
    else if (index !== removed) kept[`items.${index > removed ? index - 1 : index}.${row[2]}`] = messages
  }
  return kept
}

/**
 * «ایموجی پرمیوم متن‌ها» at the top of «متن‌های ربات», once read — a placeholder meanwhile, the failure with a retry in
 * its place.
 */
export function PremiumTextsCard() {
  const read = useQuery(premiumTextsQuery)
  const failure = useReadFailure(read)
  if (!read.data) {
    return failure.error ? (
      <ErrorState what="ایموجی پرمیوم متن‌ها" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />
    ) : (
      <Skeleton className="h-56 w-full rounded-xl" />
    )
  }

  return <PremiumTextsForm premium={read.data.premium_texts} />
}

/**
 * The switch and the map as one settings card, saved whole (`PUT /bot/premium-texts`, the answer put in place of the
 * read); above them whether the bot can send premium emoji, and the check that finds it out. The switch goes on only
 * once the bot was found able; one on may always go off.
 */
function PremiumTextsForm({ premium }: { premium: PremiumTexts }) {
  const group = useSettingsGroup({
    save: (values: Draft) => api.put('/bot/premium-texts', values),
    queryKey: queryKeys.premiumTexts,
    apply: (_current: PremiumTextsResponse | undefined, result: PremiumTextsResponse) => result,
    initial: draftOf(premium),
    saved: 'ایموجی پرمیوم متن‌ها ذخیره شد',
  })
  const { values, set, error, errors, setErrors } = group
  const queryClient = useQueryClient()

  // A save refused may come of a finding newer than the read (the bot found unable since): the read is asked again.
  const save = async () => {
    const saved = await group.save()
    if (!saved) void queryClient.invalidateQueries({ queryKey: queryKeys.premiumTexts })
    return saved
  }

  /** The map as now laid out; `removed`, the row taken out — each refusal stays with its row (one added goes last). */
  const setItems = (items: PremiumTextItem[], removed?: number) => {
    set('items', items)
    if (removed !== undefined && Object.keys(errors).some((field) => rowField.test(field))) setErrors(withoutRow(errors, removed))
  }

  return (
    <SectionCard
      form={{ ...group, save }}
      title="ایموجی پرمیوم متن‌ها"
      description="هر ایموجی معمولی فهرست زیر، در همه متن‌های ربات، پیش‌فرض و تغییر یافته، به شکل ایموجی پرمیومی که برایش انتخاب کرده‌اید فرستاده می‌شود؛ مثلا هر ❤️ همان قلب پرمیوم."
    >
      <BotAbility premium={premium} />
      <SwitchRow
        label="ایموجی پرمیوم متن‌ها روشن باشد"
        hint="روشن که باشد، ربات ایموجی‌های فهرست را در متن‌ها پرمیوم می‌فرستد؛ خاموش، همه همان ایموجی معمولی می‌مانند و فهرست سر جایش می‌ماند."
        checked={values.enabled}
        onCheckedChange={(checked) => set('enabled', checked)}
        // Only turning it on asks that the bot was found able, as the server does: one on may still be turned off.
        held={!premium.can_enable && !premium.enabled && !values.enabled}
        error={error('enabled')}
      />
      <EmojiRows premium={premium} items={values.items} errors={errors} error={error('items')} onChange={setItems} />
      <Callout tone="info" icon={Info}>
        <ul className="grid list-disc gap-1.5 ps-4">
          <li>ایموجی پرمیومی که خود متن دارد همان می‌ماند.</li>
          <li>اعلان‌ها ایموجی معمولی نشان می‌دهند؛ تلگرام در اعلان ایموجی پرمیوم ندارد.</li>
          <li>دکمه‌ای که با یکی از این ایموجی‌ها شروع شود، ایموجی پرمیوم آن را آیکون می‌گیرد — در منوی ربات وقتی منو «دکمه‌های شیشه‌ای» است؛ دکمه‌ای که آیکون خودش را دارد همان را نگه می‌دارد.</li>
          <li>فقط متن خود فروشگاه: مقدارهایی که ربات جای متغیرها می‌گذارد، مثل نام پلن یا پیام مشتری، همان‌طور که هست می‌ماند.</li>
        </ul>
      </Callout>
    </SectionCard>
  )
}

/**
 * Whether the bot can send premium emoji, as last found, and «بررسی دوباره»: the bot sends a premium emoji to the panel's
 * Telegram account, reads whether Telegram kept it and deletes the message (`POST /bot/premium-texts/check`). Why it
 * could not check is said here.
 */
function BotAbility({ premium }: { premium: PremiumTexts }) {
  const queryClient = useQueryClient()
  // Quiet: why it could not check is said in place.
  const check = useMutation({
    mutationFn: () => api.post('/bot/premium-texts/check'),
    meta: { quiet: true },
    onSuccess: (result) => {
      queryClient.setQueryData(queryKeys.premiumTexts, result)
      if (result.premium_texts.status?.ok) toast.success('ربات می‌تواند ایموجی پرمیوم بفرستد')
      else toast.warning('ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد')
    },
    // Refused, the finding may have changed all the same (the server asks afresh before it sends): read again.
    onError: () => void queryClient.invalidateQueries({ queryKey: queryKeys.premiumTexts }),
    // The premium emoji picker warns by the same finding.
    onSettled: () => void queryClient.invalidateQueries({ queryKey: queryKeys.customEmojis }),
  })
  const { status } = premium
  // Why the switch is held, while it is off: one on stays on.
  const held = premium.enabled ? null : <> تا وقتی ربات نتواند ایموجی پرمیوم بفرستد، این بخش روشن نمی‌شود.</>

  /** The finding as a strip: what was found, why while the bot cannot or was not checked, when, and the check. */
  const finding = (tone: 'success' | 'warning' | 'info', icon: LucideIcon, title: string, why?: ReactNode) => (
    <Callout tone={tone} icon={icon} role="status">
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <span className="grid gap-0.5">
          <span className="font-medium">{title}</span>
          {why !== undefined && (
            <span className="text-footnote">
              {why}
              {held}
            </span>
          )}
          {status && (
            <span className="text-footnote text-muted-foreground">
              آخرین بررسی <TimeAgo at={status.checked_at} />
            </span>
          )}
        </span>
        <Button variant="secondary" size="sm" icon={RefreshCw} busy={check.isPending} onClick={() => check.mutate()}>
          بررسی دوباره
        </Button>
      </div>
    </Callout>
  )

  return (
    <div className="grid gap-2">
      {status?.ok
        ? finding('success', CircleCheck, 'ربات می‌تواند ایموجی پرمیوم بفرستد')
        : status
          ? finding(
              'warning',
              TriangleAlert,
              'ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد',
              <>
                ربات فقط وقتی ایموجی پرمیوم می‌فرستد که حسابی که آن را در <bdi dir="ltr">@BotFather</bdi> ساخته تلگرام پرمیوم داشته باشد؛ تا آن موقع مشتری‌ها جایشان ایموجی معمولی می‌بینند.
              </>,
            )
          : finding(
              'info',
              CircleHelp,
              'هنوز بررسی نشده که ربات می‌تواند ایموجی پرمیوم بفرستد',
              'با «بررسی دوباره» ربات یک پیام آزمایشی با ایموجی پرمیوم به حساب تلگرام شما می‌فرستد، می‌بیند تلگرام آن را نگه داشته یا نه و پیام را پاک می‌کند.',
            )}
      {check.error !== null && <FormError message={messageOf(check.error, 'check')} failure={check.error} />}
    </div>
  )
}

interface EmojiRowsProps {
  premium: PremiumTexts
  items: PremiumTextItem[]
  /** The save's refusals: each row's under `items.N.…`. */
  errors: FieldErrors
  /** The refusal of the list as a whole (too long). */
  error?: string
  onChange: (items: PremiumTextItem[], removed?: number) => void
}

/**
 * The map: each plain emoji with the premium one drawn in its place and how many texts it is in, taken off by its cross;
 * then one added from the bot's premium emoji. Each row's refusal under it.
 */
function EmojiRows({ premium, items, errors, error, onChange }: EmojiRowsProps) {
  const library = useCustomEmojis()
  const [adding, setAdding] = useState(false)
  const { block, added, removed, toAdd } = useRowFocus((item: PremiumTextItem) => item.emoji_id)
  const saved = new Map(premium.items.map((row) => [row.emoji_id, row]))
  const none = library.data?.emojis.length === 0

  const remove = (index: number) => {
    const left = items.filter((_, i) => i !== index)
    removed(left, index)
    onChange(left, index)
  }
  const add = (item: PremiumTextItem) => {
    added(item, 'remove')
    onChange([...items, item])
    setAdding(false)
  }
  const cancel = () => {
    toAdd()
    setAdding(false)
  }

  return (
    <div ref={block}>
      <FieldGroup label="ایموجی‌ها" description="هر ایموجی معمولی و ایموجی پرمیومی که در متن‌ها جای آن فرستاده می‌شود." error={error} className="gap-2">
        {items.length > 0 && (
          <ul className="grid divide-y divide-border rounded-lg border border-border">
            {items.map((item, index) => {
              const row = saved.get(item.emoji_id)
              const refused = [errors[`items.${index}.emoji`]?.[0], errors[`items.${index}.emoji_id`]?.[0]].filter((message) => message !== undefined)
              return (
                <EmojiRow
                  key={item.emoji_id}
                  item={item}
                  texts={row !== undefined && emojiKey(row.emoji) === emojiKey(item.emoji) ? row.texts : null}
                  refused={refused}
                  onRemove={() => remove(index)}
                />
              )
            })}
          </ul>
        )}

        {none ? (
          <EmptyState
            compact
            framed
            icon={Sparkles}
            title="هنوز ایموجی پرمیومی در فهرست ربات نیست"
            description={
              <>
                اول در ربات <bdi dir="ltr">/emoji</bdi> را بفرستید و بعد پیامی با ایموجی‌های پرمیومی که می‌خواهید؛ همین‌جا برای انتخاب می‌آیند.
              </>
            }
          />
        ) : adding ? (
          <AddEmoji items={items} onAdd={add} onCancel={cancel} />
        ) : (
          <AddRow noun="ایموجی" count={items.length} most={premium.max} onAdd={() => setAdding(true)} empty="فهرست خالی است؛ ربات همه ایموجی‌ها را معمولی می‌فرستد." />
        )}
      </FieldGroup>
    </div>
  )
}

interface EmojiRowProps {
  item: PremiumTextItem
  /** How many texts it is drawn premium in, as saved; null for a row not saved yet. */
  texts: number | null
  refused: string[]
  onRemove: () => void
}

/** One row of the map: the plain emoji, the premium one in its place, in how many texts; its refusals under it. */
function EmojiRow({ item, texts, refused, onRemove }: EmojiRowProps) {
  const errorId = `premium_text_${item.emoji_id}_error`
  const invalid = refused.length > 0

  return (
    // Refused, the row is what a refused save moves the focus to (SaveFooter looks for `[data-invalid]`).
    <li
      data-row={item.emoji_id}
      data-invalid={invalid ? '' : undefined}
      tabIndex={invalid ? -1 : undefined}
      aria-describedby={invalid ? errorId : undefined}
      className="grid gap-1 px-3 py-2 outline-none first:rounded-t-lg last:rounded-b-lg focus-visible:focus-ring"
    >
      <div className="flex items-center gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-lg border border-border text-lg leading-none">{item.emoji}</span>
        <ArrowRight className="size-4 shrink-0 text-muted-foreground rtl:rotate-180" aria-hidden />
        <span className="grid size-9 shrink-0 place-items-center rounded-lg border border-border bg-fill">
          <span className="sr-only">ایموجی پرمیوم</span>
          <PremiumEmoji id={item.emoji_id} fallback={item.emoji} className="size-6 text-lg leading-none" />
        </span>
        <span className="min-w-0 flex-1 text-footnote text-muted-foreground">
          {texts === null ? 'با ذخیره به کار می‌رود' : texts === 0 ? 'فعلا در هیچ متنی نیست' : `در ${formatNumber(texts)} متن`}
        </span>
        <IconButton data-part="remove" aria-label={`برداشتن ${item.emoji} از فهرست`} onClick={onRemove}>
          <X className="size-4" aria-hidden />
        </IconButton>
      </div>
      {invalid && (
        <div id={errorId} className="grid gap-0.5 text-footnote text-danger">
          {refused.map((message) => (
            <p key={message}>{message}</p>
          ))}
        </div>
      )}
    </li>
  )
}

/** The server's words for a plain emoji that is not one (EmojiMap::NOT_EMOJI). */
const NOT_EMOJI = 'یک ایموجی معمولی بنویسید؛ همان که ایموجی پرمیوم در متن‌ها جای آن می‌نشیند.'

interface AddEmojiProps {
  /** The map as laid out: its premium emoji are not offered again, its plain ones not taken twice. */
  items: PremiumTextItem[]
  onAdd: (item: PremiumTextItem) => void
  onCancel: () => void
}

/**
 * A row in the making: a premium emoji picked from the bot's (those in the map left out), its own plain emoji written
 * beside it — what it stands for, which may be another — and added to the map, kept with the card's save.
 */
function AddEmoji({ items, onAdd, onCancel }: AddEmojiProps) {
  const [picked, setPicked] = useState<CustomEmojiRow | null>(null)
  const [plain, setPlain] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  // Opened, it takes the focus from the button that opened it, which is gone.
  const panel = useRef<HTMLDivElement>(null)
  useEffect(() => panel.current?.focus(), [])

  const write = (emoji: string) => {
    setPlain(emoji)
    setProblem(null)
  }
  const pick = (emoji: CustomEmojiRow) => {
    setPicked(emoji)
    write(emoji.emoji)
  }
  const add = () => {
    if (picked === null) return setProblem('یک ایموجی پرمیوم از بالا انتخاب کنید.')
    const emoji = plain.trim()
    if (emoji === '' || leadingEmoji(emoji) !== emoji) return setProblem(NOT_EMOJI)
    if (items.some((item) => emojiKey(item.emoji) === emojiKey(emoji))) return setProblem('این ایموجی در فهرست هست؛ برای هر ایموجی معمولی یک ایموجی پرمیوم کافی است.')
    onAdd({ emoji, emoji_id: picked.id })
  }
  // Enter adds the row; the card's form is saved by its own button.
  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key !== 'Enter') return
    event.preventDefault()
    add()
  }

  return (
    // In the add button's place while open: the last row taken off hands it the focus (useRowFocus).
    <div ref={panel} data-add role="group" aria-labelledby="premium_text_adding" tabIndex={-1} className="grid gap-3 rounded-lg border border-border p-3 outline-none focus-visible:focus-ring">
      <p id="premium_text_adding" className="text-body font-medium">
        افزودن ایموجی
      </p>
      <PremiumEmojiPicker selected={picked?.id ?? null} except={items.map((item) => item.emoji_id)} onPick={pick} />
      {/* Wired by hand, not a Field: a step of adding a row, not a setting the settings search should lead to. */}
      <div className="grid gap-1.5">
        <Label htmlFor="premium_text_emoji">ایموجی معمولی</Label>
        <Input
          id="premium_text_emoji"
          value={plain}
          onChange={(event) => write(event.target.value)}
          onKeyDown={onKeyDown}
          autoComplete="off"
          aria-invalid={problem !== null}
          aria-describedby="premium_text_emoji_line"
          className="w-24 text-center text-lg"
        />
        <p id="premium_text_emoji_line" className={cn('text-footnote', problem === null ? 'text-muted-foreground' : 'text-danger')}>
          {problem ?? 'همان که ایموجی پرمیوم در متن‌ها جای آن فرستاده می‌شود؛ از ایموجی پرمیوم انتخاب‌شده پر می‌شود و می‌توانید عوضش کنید.'}
        </p>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button size="sm" icon={Plus} onClick={add}>
          افزودن
        </Button>
        <Button variant="ghost" size="sm" onClick={onCancel}>
          انصراف
        </Button>
      </div>
    </div>
  )
}
