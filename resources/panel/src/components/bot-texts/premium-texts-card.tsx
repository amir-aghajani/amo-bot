import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, ChevronDown, CircleCheck, CircleHelp, Info, Plus, RefreshCw, Sparkles, TriangleAlert, X, type LucideIcon } from 'lucide-react'
import { toast } from 'sonner'
import { useRowFocus } from '@/components/add-row'
import { leadingEmoji } from '@/components/bot-texts/button-icon'
import { useCustomEmojis } from '@/components/bot-texts/custom-emojis'
import { drawnAsEmoji, emojiKey, untailed } from '@/components/bot-texts/emoji-map'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { PremiumEmojiPicker } from '@/components/bot-texts/premium-emoji-picker'
import { Callout } from '@/components/callout'
import { EmptyState } from '@/components/empty-state'
import { ErrorState } from '@/components/error-state'
import { FieldGroup } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { IconButton } from '@/components/icon-button'
import { useReadFailure } from '@/components/page'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { SectionCard } from '@/components/section-card'
import { CountBadge } from '@/components/status-badge'
import { SwitchRow } from '@/components/switch-row'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { api, type FieldErrors } from '@/lib/api'
import type { CustomEmojiRow, PremiumTextItem, PremiumTextsResponse, PremiumTextUse } from '@/lib/api-types'
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

/** A row's refusals, as the server words them (`items.N.emoji`, `items.N.emoji_id`); N is its item's place in the map. */
const rowField = /^items\.(\d+)\.(.+)$/

/** What became of the map's items: one taken out (`removed`), put in (`inserted`), or given another premium emoji (`changed`). */
interface ItemsEdit {
  removed?: number
  inserted?: number
  changed?: number
}

/**
 * The refusals once the map was edited, so each stays with its row: the list's own gone (set() drops it too); the item
 * taken out takes its own, those after it moving up one; those from where one was put in moving down one; the item given
 * another premium emoji loses its own.
 */
function refusalsAfter(errors: FieldErrors, { removed, inserted, changed }: ItemsEdit): FieldErrors {
  const kept: FieldErrors = {}
  for (const [field, messages] of Object.entries(errors)) {
    const row = rowField.exec(field)
    const index = Number(row?.[1])
    if (row === null) {
      if (field !== 'items') kept[field] = messages
    } else if (index !== removed && index !== changed) {
      const now = removed !== undefined && index > removed ? index - 1 : inserted !== undefined && index >= inserted ? index + 1 : index
      kept[`items.${now}.${row[2]}`] = messages
    }
  }
  return kept
}

/**
 * Where an item for the emoji keyed `key` goes in the draft: back in its saved place when the saved map has it — before
 * the first item saved after it, or new —, so a swap taken off and put back leaves the map as saved; last otherwise.
 */
function placeOf(items: PremiumTextItem[], saved: PremiumTextItem[], key: string): number {
  const savedAt = new Map(saved.map((item, at) => [emojiKey(item.emoji), at]))
  const at = savedAt.get(key)
  if (at === undefined) return items.length
  const after = items.findIndex((item) => (savedAt.get(emojiKey(item.emoji)) ?? Infinity) > at)
  return after === -1 ? items.length : after
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

  /** The map as now laid out — an item added goes last, one swapped again keeps its place —; each refusal stays with its row. */
  const setItems = (items: PremiumTextItem[], edit: ItemsEdit = {}) => {
    set('items', items)
    if (Object.keys(errors).some((field) => rowField.test(field))) setErrors(refusalsAfter(errors, edit))
  }

  return (
    <SectionCard
      form={{ ...group, save }}
      title="ایموجی پرمیوم متن‌ها"
      description="هر ایموجی معمولی که در فهرست زیر با یک ایموجی پرمیوم عوض کنید، در همه متن‌های ربات، پیش‌فرض و تغییر یافته، به شکل همان ایموجی پرمیوم فرستاده می‌شود؛ مثلا هر ❤️ همان قلب پرمیوم."
    >
      <BotAbility premium={premium} />
      <SwitchRow
        label="ایموجی پرمیوم متن‌ها روشن باشد"
        hint="روشن که باشد، ربات ایموجی‌های عوض‌شده را در متن‌ها پرمیوم می‌فرستد؛ خاموش، همه همان ایموجی معمولی می‌مانند و انتخاب‌های فهرست نگه داشته می‌شوند."
        checked={values.enabled}
        onCheckedChange={(checked) => set('enabled', checked)}
        // Only turning it on asks that the bot was found able, as the server does: one on may still be turned off.
        held={!premium.can_enable && !premium.enabled && !values.enabled}
        error={error('enabled')}
      />
      <EmojiList premium={premium} items={values.items} errors={errors} error={error('items')} busy={group.busy} onChange={setItems} />
      <Callout tone="info" icon={Info}>
        <ul className="grid list-disc gap-1.5 ps-4">
          <li>ایموجی پرمیومی که خود متن دارد همان می‌ماند.</li>
          <li>اعلان‌ها ایموجی معمولی نشان می‌دهند؛ تلگرام در اعلان ایموجی پرمیوم ندارد.</li>
          <li>
            دکمه‌ای که با یکی از ایموجی‌های عوض‌شده شروع شود، ایموجی پرمیوم آن را آیکون می‌گیرد — در منوی ربات وقتی منو «دکمه‌های شیشه‌ای» است؛ دکمه‌ای که آیکون خودش را دارد همان را نگه می‌دارد.
          </li>
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

/** Which rows the list shows: all, those swapped for a premium emoji in the draft, those not. */
type Shown = 'all' | 'swapped' | 'plain'

/** The rows shown before «نمایش همه»: the most carried, a phone's screenful or two. */
const FOLD = 12

/** A row of the list: a plain emoji the texts carry — or one only the draft swaps —, and its swap in the draft and as saved. */
interface Row {
  /** emojiKey() of the emoji: the row's identity, ❤ and ❤️ one row. */
  key: string
  /** As the server sent it; the draft's own for one only the draft has. */
  emoji: string
  /** Where the texts carry it; null for one only the draft has (from «ایموجی دیگر», not saved yet). */
  use: PremiumTextUse | null
  /** Its item's place in the draft's map, where its refusals are (`items.N.…`); -1 for none. */
  index: number
  /** The premium emoji it is swapped for in the draft, and as saved; null for none. */
  id: string | null
  saved: string | null
}

/** The list's rows: the emoji the texts carry in the server's order, then those only the draft swaps, in its order. */
function rowsOf(premium: PremiumTexts, items: PremiumTextItem[]): Row[] {
  const draft = new Map(items.map((item, index) => [emojiKey(item.emoji), index]))
  const saved = new Map(premium.items.map((item) => [emojiKey(item.emoji), item.emoji_id]))
  const row = (emoji: string, use: PremiumTextUse | null): Row => {
    const key = emojiKey(emoji)
    const index = draft.get(key) ?? -1
    return { key, emoji, use, index, id: items[index]?.emoji_id ?? null, saved: saved.get(key) ?? null }
  }
  const listed = new Set(premium.emojis.map((use) => emojiKey(use.emoji)))

  return [...premium.emojis.map((use) => row(use.emoji, use)), ...items.filter((item) => !listed.has(emojiKey(item.emoji))).map((item) => row(item.emoji, null))]
}

/** Where the texts carry an emoji — «در ۲ متن و ۱ دکمه منو» —, or that they no longer do (one the map has). */
function whereOf(use: PremiumTextUse): string {
  const places = [use.texts > 0 ? `${formatNumber(use.texts)} متن` : null, use.menu > 0 ? `${formatNumber(use.menu)} دکمه منو` : null].filter((place) => place !== null)
  return places.length === 0 ? 'فعلا در هیچ متنی نیست' : `در ${places.join(' و ')}`
}

interface EmojiListProps {
  premium: PremiumTexts
  items: PremiumTextItem[]
  /** The save's refusals: each row's under `items.N.…`. */
  errors: FieldErrors
  /** The refusal of the map as a whole (too long). */
  error?: string
  /** The card is being saved: the map holds still meanwhile, the refusals to come being about the map sent. */
  busy: boolean
  onChange: (items: PremiumTextItem[], edit?: ItemsEdit) => void
}

/**
 * The map, built from the emoji the texts carry: each row a plain emoji, the premium one swapped in or an empty slot,
 * where it is, and its swap chosen from the bot's premium emoji under it, changed or taken off. Filtered by swapped or
 * not and folded after the most carried; a row being edited or refused shows whatever the filter and the fold. One the
 * texts do not carry yet is swapped by «ایموجی دیگر».
 */
function EmojiList({ premium, items, errors, error, busy, onChange }: EmojiListProps) {
  const library = useCustomEmojis()
  const [shown, setShown] = useState<Shown>('all')
  const [unfolded, setUnfolded] = useState(false)
  // The rows edited since the filter or the fold last changed: kept in sight while the admin is at them, back as saved
  // or not.
  const [edited, setEdited] = useState<ReadonlySet<string>>(() => new Set())
  // The row whose picker is open (its key); one at a time, and not while «ایموجی دیگر» is.
  const [picking, setPicking] = useState<string | null>(null)
  const [adding, setAdding] = useState(false)
  const { block, added, toAdd } = useRowFocus(emojiKey)
  const listId = useId()
  const fullId = useId()
  const none = library.data?.emojis.length === 0
  const full = items.length >= premium.max

  // The bot's last premium emoji taken off in an open picker: the picker goes with it, the focus to why there is none.
  const [hadNone, setHadNone] = useState(none)
  if (none !== hadNone) {
    setHadNone(none)
    if (none && (picking !== null || adding)) {
      setPicking(null)
      setAdding(false)
      toAdd()
    }
  }

  const rows = rowsOf(premium, items)
  const refusalsOf = (row: Row) => (row.index === -1 ? [] : [errors[`items.${row.index}.emoji`]?.[0], errors[`items.${row.index}.emoji_id`]?.[0]].filter((message) => message !== undefined))
  // Shown whatever the filter and the fold: a row being edited (its swap not saved, edited since they changed, its
  // picker open), or refused — a refused save moves the focus to it.
  const pinned = (row: Row) => row.id !== row.saved || edited.has(row.key) || row.key === picking || refusalsOf(row).length > 0
  const listed = rows.filter((row) => shown === 'all' || (shown === 'swapped') === (row.id !== null))
  const first = new Set((unfolded ? listed : listed.slice(0, FOLD)).map((row) => row.key))
  const visible = rows.filter((row) => first.has(row.key) || pinned(row))
  const swapped = rows.filter((row) => row.id !== null).length

  const tabs: PageTab<Shown>[] = [
    {
      value: 'all',
      label: (
        <>
          همه
          <CountBadge count={rows.length} />
        </>
      ),
    },
    {
      value: 'swapped',
      label: (
        <>
          عوض‌شده
          <CountBadge count={swapped} tone={swapped > 0 ? 'info' : 'neutral'} />
        </>
      ),
    },
    {
      value: 'plain',
      label: (
        <>
          عوض‌نشده
          <CountBadge count={rows.length - swapped} />
        </>
      ),
    },
  ]

  // What is shown changed: the rows edited before it need not stay in sight.
  const show = (next: Shown) => {
    setShown(next)
    setEdited(new Set())
  }
  const fold = () => {
    setUnfolded(!unfolded)
    setEdited(new Set())
  }
  const touch = (key: string) => setEdited((keys) => new Set(keys).add(key))

  /** A swap put in for an emoji the draft has none for, in its saved place (placeOf()) as it was saved, or last as given. */
  const insert = (item: PremiumTextItem) => {
    const key = emojiKey(item.emoji)
    const at = placeOf(items, premium.items, key)
    const emoji = premium.items.find((kept) => emojiKey(kept.emoji) === key)?.emoji ?? item.emoji
    touch(key)
    onChange([...items.slice(0, at), { emoji, emoji_id: item.emoji_id }, ...items.slice(at)], { inserted: at })
  }

  const toggle = (row: Row) => {
    if (busy) return
    setAdding(false)
    setPicking(picking === row.key ? null : row.key)
  }
  // The picker closes once one is picked or given up, the focus back on the row's swap.
  const close = (row: Row) => {
    added(row.emoji, 'swap')
    setPicking(null)
  }
  const pick = (row: Row, emoji: CustomEmojiRow) => {
    if (busy) return
    close(row)
    if (emoji.id === row.id) return
    if (row.index === -1) return insert({ emoji: row.emoji, emoji_id: emoji.id })
    touch(row.key)
    onChange(
      items.map((item, index) => (index === row.index ? { ...item, emoji_id: emoji.id } : item)),
      { changed: row.index },
    )
  }
  // A row the texts carry stays, the focus on its swap; one only the draft had goes, the focus to «ایموجی دیگر» (or, the
  // bot having no premium emoji, to why not).
  const remove = (row: Row) => {
    if (busy) return
    if (row.use !== null && !none) added(row.emoji, 'swap')
    else toAdd()
    if (picking === row.key) setPicking(null)
    touch(row.key)
    onChange(
      items.filter((_, index) => index !== row.index),
      { removed: row.index },
    )
  }
  const add = (item: PremiumTextItem) => {
    if (busy) return
    added(item.emoji, 'remove')
    insert(item)
    setAdding(false)
  }
  const cancel = () => {
    toAdd()
    setAdding(false)
  }

  return (
    <div ref={block}>
      <FieldGroup
        label="ایموجی‌های متن‌ها"
        description="ایموجی‌های معمولی که متن‌ها و دکمه‌های ربات دارند، پرکاربردترها اول؛ برای هر کدام که بخواهید یک ایموجی پرمیوم انتخاب کنید."
        error={error}
        className="gap-3"
      >
        {none && (
          // In «ایموجی دیگر»'s place: a swap taken off with nothing to pick from hands it the focus.
          <div data-add tabIndex={-1} className="rounded-xl outline-none">
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
          </div>
        )}

        {rows.length === 0 ? (
          <p className="text-footnote text-muted-foreground">متن‌های ربات فعلا ایموجی معمولی ندارند.</p>
        ) : (
          <>
            <PageTabs as="choice" size="sm" className="w-fit" aria-label="ایموجی‌های نمایش‌داده‌شده" value={shown} tabs={tabs} onChange={show} />
            {/* Why the swaps of the rows not swapped and «ایموجی دیگر» are held: each points at it. */}
            {full && !none && <p id={fullId} className="text-footnote text-muted-foreground">{`حداکثر ${formatNumber(premium.max)} ایموجی؛ برای ایموجی تازه یکی را بردارید.`}</p>}
            {visible.length === 0 ? (
              <EmptyState
                compact
                framed
                title={shown === 'swapped' ? 'هنوز ایموجی‌ای عوض نشده است' : 'همه ایموجی‌ها عوض شده‌اند'}
                description={shown === 'swapped' ? 'در «همه» برای هر ایموجی که بخواهید یک ایموجی پرمیوم انتخاب کنید.' : undefined}
              />
            ) : (
              <ul id={listId} aria-label="ایموجی‌های متن‌ها" className="grid divide-y divide-border rounded-lg border border-border">
                {visible.map((row) => (
                  <EmojiRow
                    key={row.key}
                    row={row}
                    refused={refusalsOf(row)}
                    none={none}
                    full={row.id === null && full ? fullId : null}
                    busy={busy}
                    // The bot's last premium emoji taken off in the picker leaves nothing to pick, nor a swap to close it by.
                    picking={row.key === picking && !none}
                    except={items.filter((_, index) => index !== row.index).map((item) => item.emoji_id)}
                    onToggle={() => toggle(row)}
                    onPick={(emoji) => pick(row, emoji)}
                    onClose={() => close(row)}
                    onRemove={() => remove(row)}
                  />
                ))}
              </ul>
            )}
            {listed.length > FOLD && (
              <Button
                variant="ghost"
                size="sm"
                icon={ChevronDown}
                aria-expanded={unfolded}
                aria-controls={listId}
                onClick={fold}
                className="w-fit text-muted-foreground aria-expanded:[&_svg]:rotate-180"
              >
                {unfolded ? 'نمایش کمتر' : `نمایش همه ${formatNumber(listed.length)} ایموجی`}
              </Button>
            )}
          </>
        )}

        {!none &&
          (adding ? (
            <AddEmoji items={items} saved={premium.items} busy={busy} onAdd={add} onCancel={cancel} />
          ) : (
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <Button
                data-add
                variant="secondary"
                size="sm"
                icon={Plus}
                aria-disabled={full || busy}
                aria-describedby={full ? fullId : undefined}
                onClick={
                  full || busy
                    ? undefined
                    : () => {
                        setPicking(null)
                        setAdding(true)
                      }
                }
              >
                ایموجی دیگر
              </Button>
              <span className="text-footnote text-muted-foreground">برای ایموجی‌ای که هنوز در متن‌ها نیست، مثلا برای متنی که بعدا می‌نویسید.</span>
            </div>
          ))}
      </FieldGroup>
    </div>
  )
}

interface EmojiRowProps {
  row: Row
  refused: string[]
  /** The bot has no premium emoji: nothing to swap it for, so no swap. */
  none: boolean
  /** The map is full and the row not swapped: its swap held, pointing at the note that says so (its id); null otherwise. */
  full: string | null
  /** The card is being saved: every control of the row held. */
  busy: boolean
  /** Its picker is open, and the premium emoji other rows have (not offered). */
  picking: boolean
  except: string[]
  onToggle: () => void
  onPick: (emoji: CustomEmojiRow) => void
  onClose: () => void
  onRemove: () => void
}

/**
 * One row: the plain emoji, the premium one swapped in (or an empty slot), where the texts carry it — and whether the
 * draft's swap waits for the save —, «انتخاب ایموجی پرمیوم» or «تغییر» opening the picker under it, and the swap's
 * cross; each control named with the emoji. Its refusals under it.
 */
function EmojiRow({ row, refused, none, full, busy, picking, except, onToggle, onPick, onClose, onRemove }: EmojiRowProps) {
  const id = useId()
  const errorId = `${id}-error`
  const plainId = `${id}-plain`
  const pickerId = `${id}-picker`
  const invalid = refused.length > 0
  const held = full !== null || busy
  const where = row.use === null ? 'با ذخیره به کار می‌رود' : whereOf(row.use)
  const waits = row.use === null || row.id === row.saved ? null : row.id === null ? 'با ذخیره برداشته می‌شود' : 'با ذخیره به کار می‌رود'

  // Escape anywhere in the row — its swap just pressed, or the picker — gives the picker up, the focus back on its swap.
  const onKeyDown = (event: KeyboardEvent<HTMLLIElement>) => {
    if (event.key !== 'Escape' || !picking) return
    event.preventDefault()
    onClose()
  }

  return (
    // Refused, the row is what a refused save moves the focus to (SaveFooter looks for `[data-invalid]`).
    <li
      data-row={row.key}
      data-invalid={invalid ? '' : undefined}
      tabIndex={invalid ? -1 : undefined}
      aria-describedby={invalid ? errorId : undefined}
      onKeyDown={onKeyDown}
      className="grid gap-2 px-3 py-2 outline-none first:rounded-t-lg last:rounded-b-lg focus-visible:focus-ring"
    >
      <div className="flex items-center gap-3">
        <span className="flex shrink-0 items-center gap-1.5">
          <span id={plainId} className="grid size-9 place-items-center rounded-lg border border-border text-lg leading-none">
            {row.emoji}
          </span>
          <ArrowRight className="size-3.5 text-muted-foreground rtl:rotate-180" aria-hidden />
          <span className={cn('grid size-9 place-items-center rounded-lg border', row.id === null ? 'border-dashed border-border-strong' : 'border-border bg-fill')}>
            {row.id === null ? (
              <span className="sr-only">بدون ایموجی پرمیوم</span>
            ) : (
              <>
                <span className="sr-only">ایموجی پرمیوم</span>
                <PremiumEmoji id={row.id} fallback={row.emoji} className="size-6 text-lg leading-none" />
              </>
            )}
          </span>
        </span>
        <span className="grid min-w-0 flex-1 gap-0.5 text-footnote">
          <span className="text-muted-foreground">{where}</span>
          {waits !== null && <span className="text-info">{waits}</span>}
        </span>
        <span className="flex shrink-0 items-center gap-1">
          {row.id !== null && (
            <IconButton data-part="remove" aria-label={`برداشتن ایموجی پرمیوم ${row.emoji}`} aria-disabled={busy} onClick={busy ? undefined : onRemove}>
              <X className="size-4" aria-hidden />
            </IconButton>
          )}
          {/* One button whichever it says, so the focus it is given back stays on it as its words change; its name
              starts with the words it shows. */}
          {!none && (
            <Button
              data-part="swap"
              variant={picking ? 'default' : 'secondary'}
              size="sm"
              aria-label={row.id === null ? `انتخاب ایموجی پرمیوم ${row.emoji}` : `تغییر ایموجی پرمیوم ${row.emoji}`}
              aria-expanded={picking}
              aria-controls={picking ? pickerId : undefined}
              aria-describedby={full ?? undefined}
              aria-disabled={held}
              onClick={held ? undefined : onToggle}
            >
              {row.id === null ? (
                <>
                  انتخاب
                  {/* The whole of it on a wider screen; a phone's row has room for the first word. */}
                  <span className="max-sm:sr-only">{' ایموجی پرمیوم'}</span>
                </>
              ) : (
                'تغییر'
              )}
            </Button>
          )}
        </span>
      </div>
      {picking && (
        <div id={pickerId}>
          <PremiumEmojiPicker selected={row.id} except={except} onPick={onPick} />
        </div>
      )}
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
  /** The map as saved: an emoji it has may stay a sign drawn as text (★), one new to it may not (EmojiMap::save()). */
  saved: PremiumTextItem[]
  /** The card is being saved: nothing is added meanwhile. */
  busy: boolean
  onAdd: (item: PremiumTextItem) => void
  onCancel: () => void
}

/**
 * «ایموجی دیگر», a swap the list has no row for (an emoji a later text will carry): a premium emoji picked from the
 * bot's (those in the map left out), its own plain emoji written beside it — what it stands for, which may be another —
 * and added to the map, kept with the card's save. One the texts carry already is swapped in its row.
 */
function AddEmoji({ items, saved, busy, onAdd, onCancel }: AddEmojiProps) {
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
  // As the server reads it (EmojiMap::save()): a half-space typed after it no part of it; one new to the map drawn as an
  // emoji, not a sign (drawnAsEmoji()).
  const add = () => {
    if (busy) return
    if (picked === null) return setProblem('یک ایموجی پرمیوم از بالا انتخاب کنید.')
    const [emoji] = untailed(plain.trim())
    const key = emojiKey(emoji)
    if (emoji === '' || leadingEmoji(emoji) !== emoji) return setProblem(NOT_EMOJI)
    if (!drawnAsEmoji(emoji) && !saved.some((item) => emojiKey(item.emoji) === key)) return setProblem(NOT_EMOJI)
    if (items.some((item) => emojiKey(item.emoji) === key)) return setProblem('این ایموجی در فهرست بالا عوض شده است؛ برای هر ایموجی معمولی یک ایموجی پرمیوم کافی است.')
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
        ایموجی دیگر
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
        <Button size="sm" icon={Plus} aria-disabled={busy} onClick={add}>
          افزودن
        </Button>
        <Button variant="ghost" size="sm" onClick={onCancel}>
          انصراف
        </Button>
      </div>
    </div>
  )
}
