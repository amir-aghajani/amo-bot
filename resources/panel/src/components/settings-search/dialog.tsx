import { Fragment, useCallback, useId, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from 'react'
import { ArrowDown, ArrowUp, History, Search, SearchX, Settings, Star, X, type LucideIcon } from 'lucide-react'
import { useNavigate } from 'react-router'
import { EmptyState } from '@/components/empty-state'
import { ErrorState } from '@/components/error-state'
import { IconButton } from '@/components/icon-button'
import { Kbd } from '@/components/kbd'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { PortalContainerContext, useModalLayer } from '@/components/portal-container'
import { arrivalState } from '@/components/settings-search/arrival'
import { useFavorites } from '@/components/settings-search/favorites'
import { SEARCH_LABEL } from '@/components/settings-search/launcher'
import { readRecent, recordRecent } from '@/components/settings-search/recent'
import { isGroup, isNavItemActive, type PanelNav } from '@/components/shell/nav'
import { MOD_KEY, useShell, withModifier } from '@/components/shell/shell-context'
import { CountBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { pageOf } from '@/lib/config'
import { formatNumber } from '@/lib/format'
import { buildIndex, searchSettings, type Ranges, type SettingEntry, type SettingMatch } from '@/lib/settings-search'
import { useNativeDialog } from '@/lib/use-native-dialog'
import { cn } from '@/lib/utils'

/*
 * The settings search (opened from the shell: components/settings-search/launcher). A field, then two tabs: «همه» —
 * empty, what was opened lately and the map of the settings, a group per page of them; typed into, what the index finds,
 * best first — and «علاقه‌مندی‌ها», the starred ones in the user's order. A combobox over a listbox: the focus stays in the
 * field, ↑/↓ move along the rows (Ctrl/⌘+Home/End to their ends: Home and End alone are the field's), Enter opens one,
 * Ctrl/⌘+D stars it, Alt+↑/↓ moves a favorite; Escape closes.
 * The index is the panel's own for the shop on screen (its navigation), so a panel never finds what it does not have.
 */

/** The most matches shown: what is typed narrows them further. */
const LIMIT = 50

/** How many of the lately opened are shown. */
const RECENT_SHOWN = 5

/** The modifier of the moves, as hints show it. */
const ALT_KEY = MOD_KEY === '⌘' ? '⌥' : 'Alt'

const KIND_WORDS: Partial<Record<SettingEntry['kind'], string>> = { page: 'صفحه', section: 'بخش' }

/** A matched part of a title or a trail: a soft tint, nothing that changes the letters' shapes (a weight would break Persian joins). */
const MARK = 'rounded-[3px] bg-selected/20 text-inherit [box-decoration-break:clone]'

type Tab = 'all' | 'favorites'

/** A row of the list, with what of it matched. */
interface Row {
  entry: SettingEntry
  marks?: SettingMatch['marks']
}

/** Rows under a heading — none for the matches, which are one ranked list. */
interface RowGroup {
  id: string
  title?: string
  icon?: LucideIcon
  /** The map's rows: a section's title alone, under its page's heading. */
  compact?: boolean
  rows: Row[]
}

export function SettingsSearchDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const ref = useRef<HTMLDialogElement>(null)
  const [container, setContainer] = useState<HTMLDialogElement | null>(null)
  // Typing is its whole point: the field takes the focus on a phone too, keyboard and all.
  const handlers = useNativeDialog(ref, open, onClose, { typing: true })
  useModalLayer(container, open)

  const attach = useCallback((node: HTMLDialogElement | null) => {
    ref.current = node
    setContainer(node)
  }, [])

  return (
    <dialog
      ref={attach}
      tabIndex={-1}
      aria-label={SEARCH_LABEL}
      {...handlers}
      // A desktop's: centred, 40rem wide and a fixed height, so nothing moves as the rows change. A phone's: the whole screen.
      className="app-modal text-popover-foreground outline-none max-md:h-dvh max-md:max-h-none max-md:rounded-none max-md:border-0 md:h-[min(36rem,calc(100dvh-4rem))] md:max-w-[40rem]"
    >
      <PortalContainerContext.Provider value={container}>
        <SearchBody open={open} onClose={onClose} />
      </PortalContainerContext.Provider>
    </dialog>
  )
}

/** What a path's rows are drawn with: its settings page's icon, else its menu page's. */
function iconFinder(nav: PanelNav): (path: string) => LucideIcon {
  const sections = nav.settings.groups.flatMap((group) => group.sections.map((section) => ({ to: section.to, icon: group.icon })))
  const items = nav.menu.flatMap((entry) => (isGroup(entry) ? entry.items : [entry]))

  return (path) =>
    sections.find((section) => section.to === path)?.icon ??
    sections.find((section) => pageOf(section.to) === pageOf(path))?.icon ??
    // Not by `end`: the dashboard's address is every address's start.
    items.find((item) => isNavItemActive({ to: item.to }, path))?.icon ??
    Settings
}

function SearchBody({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate()
  const { panel, isMobile } = useShell()
  const { nav } = panel
  const { favorites, read, toggle, move } = useFavorites(open)
  const [query, setQuery] = useState('')
  const [tab, setTab] = useState<Tab>('all')
  const [active, setActive] = useState(0)
  const [recent, setRecent] = useState(readRecent)
  const [said, setSaid] = useState({ words: '', count: 0 })
  const inputRef = useRef<HTMLInputElement>(null)
  const scroller = useRef<HTMLDivElement>(null)
  /** The keyboard moved the active row: it is kept in sight. */
  const follow = useRef(false)
  const baseId = useId()
  const listId = `${baseId}-list`
  const tabsId = `${baseId}-tabs`
  const hintsId = `${baseId}-hints`
  const starredId = `${baseId}-starred`
  const optionId = (place: number) => `${baseId}-option-${place}`

  // Each opening starts afresh: the field empty, the first row active, what was opened lately as it is now; the tab stays.
  const [shownOpen, setShownOpen] = useState(open)
  if (shownOpen !== open) {
    setShownOpen(open)
    if (open) {
      setQuery('')
      setActive(0)
      setRecent(readRecent())
    }
  }

  const index = useMemo(() => buildIndex(nav), [nav])
  const byId = useMemo(() => new Map(index.map((entry) => [entry.id, entry])), [index])
  const iconOf = useMemo(() => iconFinder(nav), [nav])
  const starred = useMemo(() => (favorites === undefined ? undefined : new Set(favorites)), [favorites])
  /** The favorites the panel on screen has, in the user's order (the others stay stored, unseen). */
  const favoriteEntries = useMemo(() => (favorites ?? []).flatMap((id) => byId.get(id) ?? []), [favorites, byId])
  const typed = query.trim() !== ''

  const matches = useMemo(() => (typed && tab === 'all' ? searchSettings(index, query, { favorites: favorites ?? [], recent, limit: LIMIT + 1 }) : []), [typed, tab, index, query, favorites, recent])

  const groups = useMemo((): RowGroup[] => {
    if (tab === 'favorites') {
      if (!typed) return [{ id: 'favorites', rows: favoriteEntries.map((entry) => ({ entry })) }]
      // What is typed narrows them; they stay in the user's order.
      const found = new Map(searchSettings(favoriteEntries, query).map((match) => [match.entry.id, match.marks]))
      return [{ id: 'favorites', rows: favoriteEntries.flatMap((entry) => (found.has(entry.id) ? [{ entry, marks: found.get(entry.id) }] : [])) }]
    }
    if (typed) return [{ id: 'matches', rows: matches.slice(0, LIMIT).map(({ entry, marks }) => ({ entry, marks })) }]

    const lately = recent.flatMap((id) => byId.get(id) ?? []).slice(0, RECENT_SHOWN)
    // The map: every page of the settings with its sections, as the settings' column lists them.
    const map = nav.settings.groups.map((group): RowGroup => ({
      id: group.id,
      title: group.title,
      icon: group.icon,
      compact: true,
      rows: group.sections.map((section) => ({
        entry: byId.get(`section:${section.to}`) ?? { id: `section:${section.to}`, kind: 'section', title: section.title, path: section.to, trail: [group.title] },
      })),
    }))

    return [...(lately.length > 0 ? [{ id: 'recent', title: 'اخیر', icon: History, rows: lately.map((entry) => ({ entry })) }] : []), ...map]
  }, [tab, typed, query, favoriteEntries, matches, recent, byId, nav])

  const rows = groups.flatMap((group) => group.rows)
  const current = rows.length === 0 ? -1 : Math.min(active, rows.length - 1)
  const currentRow = rows[current]
  /** Favorites move while the whole list shows (not narrowed by a search). */
  const reordering = tab === 'favorites' && !typed && starred !== undefined
  const shownFavorites = useMemo(() => new Set(favoriteEntries.map((entry) => entry.id)), [favoriteEntries])

  useLayoutEffect(() => {
    if (!follow.current) return
    follow.current = false
    document.getElementById(`${baseId}-option-${current}`)?.scrollIntoView({ block: 'nearest' })
  }, [current, baseId])

  /** The list from its top: another search, another tab. */
  const restart = () => {
    setActive(0)
    if (scroller.current) scroller.current.scrollTop = 0
  }

  const say = (words: string) => setSaid((last) => ({ words, count: last.count + 1 }))

  const choose = (entry: SettingEntry) => {
    // Kept for the next opening, which reads it afresh: the list does not reorder under the dialog as it fades out.
    recordRecent(entry.id)
    onClose()
    void navigate(entry.path, { state: arrivalState(entry) })
  }

  const star = (entry: SettingEntry) => {
    const added = toggle(entry.id)
    if (added !== null) say(added ? 'به علاقه‌مندی‌ها اضافه شد' : 'از علاقه‌مندی‌ها حذف شد')
  }

  const reorder = (entry: SettingEntry, delta: -1 | 1, place: number) => {
    if (!move(entry.id, delta, shownFavorites)) return
    follow.current = true
    setActive(place + delta)
    say(delta < 0 ? 'یک جا بالاتر رفت' : 'یک جا پایین‌تر رفت')
  }

  const goTo = (place: number) => {
    follow.current = true
    setActive(place)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.nativeEvent.isComposing) return
    const plain = !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey
    const last = rows.length - 1

    if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && event.altKey && !event.ctrlKey && !event.metaKey) {
      event.preventDefault()
      if (reordering && currentRow) reorder(currentRow.entry, event.key === 'ArrowUp' ? -1 : 1, current)
    } else if (event.key === 'ArrowDown' && plain) {
      event.preventDefault()
      if (last >= 0) goTo(current >= last ? 0 : current + 1)
    } else if (event.key === 'ArrowUp' && plain) {
      event.preventDefault()
      if (last >= 0) goTo(current <= 0 ? last : current - 1)
    } else if ((event.key === 'Home' || event.key === 'End') && withModifier(event) && !event.altKey && !event.shiftKey && last >= 0) {
      // With the modifier alone: Home and End move the field's caret.
      event.preventDefault()
      goTo(event.key === 'Home' ? 0 : last)
    } else if (event.key === 'Enter' && plain) {
      event.preventDefault()
      if (currentRow) choose(currentRow.entry)
    } else if (event.code === 'KeyD' && withModifier(event) && !event.altKey && !event.shiftKey) {
      // By the key's place: «ی» on a Persian layout. The browser's own (a bookmark) does not come.
      event.preventDefault()
      if (currentRow) star(currentRow.entry)
    }
  }

  const changeTab = (next: Tab) => {
    setTab(next)
    restart()
    // A press on a tab leaves the typing in the field — on a desktop: a phone's keyboard would rise unasked.
    if (!isMobile) inputRef.current?.focus()
  }

  const clear = () => {
    setQuery('')
    restart()
    inputRef.current?.focus()
  }

  const favoritesCount = starred === undefined ? null : favoriteEntries.length
  const overflow = tab === 'all' && matches.length > LIMIT
  let place = 0

  return (
    <div className="flex min-h-0 flex-1 flex-col max-md:pt-[env(safe-area-inset-top)]">
      <div className="flex h-14 shrink-0 items-center gap-2 border-b border-border ps-4 pe-2 max-md:ps-3">
        <Search className="size-[18px] shrink-0 text-faint" aria-hidden />
        <input
          ref={inputRef}
          data-autofocus
          type="text"
          role="combobox"
          aria-label={SEARCH_LABEL}
          aria-expanded
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={current === -1 ? undefined : optionId(current)}
          aria-describedby={hintsId}
          value={query}
          onChange={(event) => {
            setQuery(event.target.value)
            restart()
          }}
          onKeyDown={onKeyDown}
          placeholder={`${SEARCH_LABEL}…`}
          autoComplete="off"
          autoCorrect="off"
          autoCapitalize="off"
          spellCheck={false}
          enterKeyHint="go"
          className="h-full min-w-0 flex-1 bg-transparent text-heading text-foreground outline-none placeholder:text-faint max-md:text-base"
        />
        {query !== '' && (
          <IconButton aria-label="پاک کردن جستجو" onMouseDown={keepFocus} onClick={clear} className="max-md:size-11">
            <X className="size-4" aria-hidden />
          </IconButton>
        )}
        {isMobile && (
          <Button variant="ghost" onClick={onClose} className="h-11 px-3">
            بستن
          </Button>
        )}
      </div>

      <div className="shrink-0 px-4 pt-1">
        <PageTabs
          id={tabsId}
          value={tab}
          onChange={changeTab}
          aria-label="نمای جستجو"
          tabs={[
            { value: 'all', label: 'همه' },
            {
              value: 'favorites',
              label: (
                <>
                  علاقه‌مندی‌ها
                  {favoritesCount !== null && favoritesCount > 0 && <CountBadge count={favoritesCount} />}
                </>
              ),
            },
          ]}
        />
      </div>

      <div ref={scroller} {...tabPanel(tabsId, tab)} className="min-h-0 flex-1 scrollbar-thin overflow-y-auto overscroll-contain px-2 py-2">
        <div id={listId} role="listbox" aria-label={tab === 'favorites' ? 'علاقه‌مندی‌ها' : typed ? 'نتیجه‌ها' : 'تنظیمات'} className="grid gap-2">
          {groups.map((group) => {
            if (group.rows.length === 0) return null
            const headingId = `${baseId}-group-${group.id}`
            const Icon = group.icon
            return (
              <div key={group.id} role="group" aria-labelledby={group.title === undefined ? undefined : headingId} className="grid gap-px">
                {group.title !== undefined && (
                  <div id={headingId} className="flex h-8 items-center gap-3 px-2 text-footnote font-medium text-muted-foreground">
                    {Icon && <Icon className="size-4 shrink-0" strokeWidth={1.75} aria-hidden />}
                    <span className="truncate">{group.title}</span>
                  </div>
                )}
                {group.rows.map((row, at) => {
                  const here = place++
                  return (
                    <ResultRow
                      key={`${group.id}-${row.entry.id}`}
                      id={optionId(here)}
                      row={row}
                      icon={iconOf(row.entry.path)}
                      compact={group.compact === true}
                      active={here === current}
                      starred={starred === undefined ? undefined : starred.has(row.entry.id)}
                      starredId={starredId}
                      onActivate={() => setActive(here)}
                      onChoose={() => choose(row.entry)}
                      onStar={() => star(row.entry)}
                      moves={reordering ? { up: at > 0 ? () => reorder(row.entry, -1, here) : undefined, down: at < group.rows.length - 1 ? () => reorder(row.entry, 1, here) : undefined } : undefined}
                    />
                  )
                })}
              </div>
            )
          })}
        </div>

        {tab === 'all' && typed && rows.length === 0 && (
          <EmptyState compact icon={SearchX} title="نتیجه‌ای پیدا نشد" description="کلمه دیگری را امتحان کنید. با پاک کردن جستجو، نقشه همه تنظیمات را می‌بینید." />
        )}
        {overflow && <p className="px-2 pt-3 pb-1 text-center text-footnote text-muted-foreground">{formatNumber(LIMIT)} نتیجه اول نشان داده شده؛ دقیق‌تر بنویسید.</p>}
        {tab === 'favorites' && <FavoritesState read={read} count={favoriteEntries.length} narrowed={typed && rows.length === 0} isMobile={isMobile} />}
      </div>

      {!isMobile && (
        <div className="flex h-10 shrink-0 items-center gap-4 border-t border-border px-4 text-caption text-muted-foreground">
          <Hint keys={['↑', '↓']}>جابه‌جایی</Hint>
          <Hint keys={['Enter']}>باز کردن</Hint>
          <Hint keys={[`${MOD_KEY} D`]}>علاقه‌مندی</Hint>
          {reordering && <Hint keys={[`${ALT_KEY} ↑↓`]}>ترتیب</Hint>}
          <span className="ms-auto">
            <Hint keys={['Esc']}>بستن</Hint>
          </span>
        </div>
      )}

      <p id={hintsId} className="sr-only">
        با جهت‌نماهای بالا و پایین میان ردیف‌ها بروید و با Enter باز کنید. {MOD_KEY} D ستاره ردیف را می‌زند یا برمی‌دارد.
        {reordering && ` ${ALT_KEY} با جهت‌نماهای بالا و پایین، ردیف را یک جا جابه‌جا می‌کند.`}
      </p>
      {/* What a starred row's option says besides its name: its star is out of the reading. */}
      <span id={starredId} hidden>
        در علاقه‌مندی‌ها
      </span>
      <div role="status" className="sr-only">
        {typed && tab === 'all' ? (rows.length === 0 ? 'نتیجه‌ای پیدا نشد' : `${formatNumber(rows.length)} نتیجه`) : ''}
      </div>
      <div aria-live="polite" className="sr-only">
        <span key={said.count}>{said.words}</span>
      </div>
    </div>
  )
}

/** A press on a row's button leaves the focus — and the typing — in the field. */
function keepFocus(event: MouseEvent) {
  event.preventDefault()
}

interface ResultRowProps {
  id: string
  row: Row
  icon: LucideIcon
  compact: boolean
  active: boolean
  /** Undefined while the favorites are read: the star waits. */
  starred: boolean | undefined
  /** The words a starred row's option is described by. */
  starredId: string
  onActivate: () => void
  onChoose: () => void
  onStar: () => void
  /** A favorite's moves, while the favorites can be ordered: each where there is room for it. */
  moves?: { up?: () => void; down?: () => void }
}

/**
 * One row: the option itself (its page's icon, the title with what matched marked, where it sits under it, what kind of
 * place it opens), then its buttons — a favorite's moves, and the star — beside it, out of the option. On a desktop the
 * buttons show on the active or hovered row (a star that is on, always); on a phone always, a finger's 44px. A listbox
 * holds options alone: the buttons are for the pointer, out of the reading and the Tab order — the keys do the same
 * (Ctrl/⌘+D, Alt+↑/↓) —, and a starred row's option says it is one.
 */
function ResultRow({ id, row, icon: Icon, compact, active, starred, starredId, onActivate, onChoose, onStar, moves }: ResultRowProps) {
  const { entry, marks } = row
  const kind = compact ? undefined : KIND_WORDS[entry.kind]
  const revealed = 'md:opacity-0 md:group-hover/row:opacity-100 md:group-data-active/row:opacity-100 focus-visible:opacity-100'

  return (
    <div role="none" data-active={active ? '' : undefined} className={cn('group/row flex items-center gap-0.5 rounded-lg pe-1 transition-colors duration-75', active && 'bg-fill-hover')}>
      <div
        id={id}
        role="option"
        aria-selected={active}
        aria-describedby={starred ? starredId : undefined}
        onClick={onChoose}
        onPointerMove={active ? undefined : onActivate}
        className={cn('flex min-w-0 flex-1 cursor-pointer items-center gap-3 py-1.5', compact ? 'min-h-9 ps-9 max-md:min-h-11' : 'min-h-12 ps-2 max-md:min-h-14')}
      >
        {!compact && (
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-card text-muted-foreground dark:bg-fill">
            <Icon className="size-4" strokeWidth={1.75} aria-hidden />
          </span>
        )}
        <span className="grid min-w-0 flex-1">
          <span className="truncate text-body text-foreground">
            <Marked text={entry.title} ranges={marks?.title} />
          </span>
          {!compact && entry.trail.length > 0 && (
            <span className="truncate text-footnote text-muted-foreground">
              <Trail trail={entry.trail} marks={marks?.trail} />
            </span>
          )}
        </span>
        {kind && <span className="shrink-0 text-caption text-faint max-md:hidden">{kind}</span>}
      </div>
      {moves && (
        <>
          <IconButton
            aria-label="بالا بردن"
            aria-hidden
            tabIndex={-1}
            disabled={!moves.up}
            onMouseDown={keepFocus}
            onClick={moves.up}
            className={cn('max-md:size-11', revealed, !moves.up && 'invisible')}
          >
            <ArrowUp className="size-4" aria-hidden />
          </IconButton>
          <IconButton
            aria-label="پایین بردن"
            aria-hidden
            tabIndex={-1}
            disabled={!moves.down}
            onMouseDown={keepFocus}
            onClick={moves.down}
            className={cn('max-md:size-11', revealed, !moves.down && 'invisible')}
          >
            <ArrowDown className="size-4" aria-hidden />
          </IconButton>
        </>
      )}
      <IconButton
        // A toggle's name stays; `aria-pressed` says whether it is on. The tooltip says what a press does.
        aria-label="علاقه‌مندی"
        aria-hidden
        aria-pressed={starred === true}
        title={starred ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها'}
        tabIndex={-1}
        disabled={starred === undefined}
        onMouseDown={keepFocus}
        onClick={onStar}
        className={cn('max-md:size-11', !starred && revealed)}
      >
        <Star className={cn('size-4', starred && 'fill-current text-foreground')} strokeWidth={1.75} aria-hidden />
      </IconButton>
    </div>
  )
}

/** A text with its matched ranges marked. */
function Marked({ text, ranges }: { text: string; ranges: Ranges | undefined }): ReactNode {
  if (ranges === undefined || ranges.length === 0) return text
  const parts: ReactNode[] = []
  let at = 0
  const ordered = [...ranges]
  ordered.sort((a, b) => a[0] - b[0])
  for (const [start, end] of ordered) {
    const from = Math.max(start, at)
    const to = Math.min(end, text.length)
    if (to <= from) continue
    if (from > at) parts.push(text.slice(at, from))
    parts.push(
      <mark key={from} className={MARK}>
        {text.slice(from, to)}
      </mark>,
    )
    at = to
  }
  if (at < text.length) parts.push(text.slice(at))

  return parts
}

/** Where an entry sits, outermost first: «تنظیمات ربات › کیف پول» (the angle turns with the reading direction). */
function Trail({ trail, marks }: { trail: string[]; marks: Ranges[] | undefined }) {
  return trail.map((part, at) => (
    <Fragment key={`${at}-${part}`}>
      {at > 0 && (
        <>
          <span aria-hidden className="mx-1 text-faint">
            ›
          </span>
          <span className="sr-only">، </span>
        </>
      )}
      <Marked text={part} ranges={marks?.[at]} />
    </Fragment>
  ))
}

/** A key hint of the footer: its keys, then what they do. */
function Hint({ keys, children }: { keys: string[]; children: ReactNode }) {
  return (
    <span className="flex items-center gap-1.5">
      <span className="flex items-center gap-0.5">
        {keys.map((key) => (
          <Kbd key={key}>{key}</Kbd>
        ))}
      </span>
      {children}
    </span>
  )
}

/** What the favorites tab says beside (or instead of) its rows: reading, a read that failed, none yet, none matching. */
function FavoritesState({ read, count, narrowed, isMobile }: { read: ReturnType<typeof useFavorites>['read']; count: number; narrowed: boolean; isMobile: boolean }) {
  if (read.status === 'pending') {
    return (
      <div className="grid gap-1 px-2" aria-busy>
        {[0, 1, 2].map((key) => (
          <div key={key} className="flex min-h-12 items-center gap-3">
            <Skeleton className="size-8 shrink-0" />
            <div className="grid flex-1 gap-1.5">
              <Skeleton className="h-3.5 w-2/5 rounded-md" />
              <Skeleton className="h-3 w-1/4 rounded-md" />
            </div>
          </div>
        ))}
      </div>
    )
  }
  if (read.status === 'error' && read.data === undefined) {
    return (
      <div className="px-2 pt-1">
        <ErrorState what="علاقه‌مندی‌ها" error={read.error} onRetry={() => void read.refetch()} retrying={read.isFetching} />
      </div>
    )
  }
  if (count === 0) {
    return (
      <EmptyState
        compact
        icon={Star}
        title="هنوز علاقه‌مندی ندارید"
        description={
          <>
            کنار هر تنظیم، بخش یا صفحه ستاره را بزنید تا اینجا بماند و زودتر به آن برسید
            {isMobile ? '.' : <> (یا {MOD_KEY} D روی ردیف فعال).</>}
          </>
        }
      />
    )
  }

  return narrowed ? <EmptyState compact icon={SearchX} title="در علاقه‌مندی‌ها پیدا نشد" description="برای جستجو در همه تنظیمات، به تب «همه» بروید." /> : null
}
