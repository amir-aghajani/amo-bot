import { useCallback, useId, useRef, useState, type ReactNode } from 'react'
import { ArrowLeft, ChevronDown, PanelRightClose, PanelRightOpen, X, type LucideIcon } from 'lucide-react'
import { Link, useLocation } from 'react-router'
import { BrandMark } from '@/components/brand-mark'
import { IconButton } from '@/components/icon-button'
import { Kbd } from '@/components/kbd'
import { AccountRow } from '@/components/shell/account-row'
import { areaFor, inSettings, isGroup, isNavItemActive, isSectionCurrent, type NavArea, type NavGroup, type NavItem } from '@/components/shell/nav'
import { pressingQueue, QueueBadge, useQueueCounts, type Queue } from '@/components/shell/queue-badge'
import { RailContext, RailTooltip, useRail } from '@/components/shell/rail'
import { MOD_KEY, useShell } from '@/components/shell/shell-context'
import { StatusDot } from '@/components/status-badge'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import type { QueueCounts } from '@/lib/api-types'
import { useShopName } from '@/lib/auth'
import { routePath } from '@/lib/config'
import { contentSide } from '@/lib/direction'
import type { Status } from '@/lib/statuses'
import { readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'
import { cn } from '@/lib/utils'

/*
 * The sidebar, drawn as the Console's: the brand and the column's toggle, what the panel puts under it (the owner's shop
 * picker), the pages (groups fold open; their pages indented, text only), and at the foot the settings and the account.
 * Collapsed it is an icon rail — a group's pages then open as a flyout menu. On a page made of sections (the settings,
 * the users…) the column lists that page's sections instead, with the way back to the menu where the picker was; the
 * change slides in as the Console's does.
 */

const ITEM = 'flex h-9 w-full min-w-0 items-center gap-3 rounded-lg px-2 text-body transition-colors duration-100 outline-none focus-visible:focus-ring'
const ITEM_IDLE = 'text-muted-foreground hover:bg-fill hover:text-foreground'
const ITEM_ACTIVE = 'bg-fill-active font-medium text-foreground'
const ICON = 'size-[18px] shrink-0'
/** A group's pages line up with the group's label: the item's padding, the icon and the gap. */
const CHILD = 'ps-[2.375rem]'
/** An item on the rail: its icon, centred in a square. */
const RAIL_ITEM = 'mx-auto size-9 justify-center px-0'
/** A dot on a rail icon — something waits under it: at the square's top corner, away from the column's edge. */
const RAIL_DOT = 'absolute end-1.5 top-1.5'

/** The desktop's column — not drawn on a phone, whose column is the drawer's (one column a size: one menu, one account row). */
export function Sidebar() {
  const { collapsed, isMobile } = useShell()
  if (isMobile) return null

  return (
    <aside
      id="app-sidebar"
      aria-label="سایدبار"
      className="sticky top-0 z-30 flex h-svh shrink-0 flex-col border-e border-sidebar-border bg-sidebar"
      style={{ width: collapsed ? 'var(--sidebar-width-collapsed)' : 'var(--sidebar-width)' }}
    >
      <RailContext value={collapsed}>
        <SidebarBody />
      </RailContext>
    </aside>
  )
}

/** Everything in the column — shared by the desktop sidebar and the phone drawer (`onClose` = the drawer's). */
export function SidebarBody({ onClose }: { onClose?: () => void }) {
  const location = useLocation()
  const { panel } = useShell()
  const collapsed = useRail()
  const Picker = panel.picker
  // «منوی اصلی» shows the menu over a page's sections — until the next navigation, which shows what its page has.
  const [menuAt, setMenuAt] = useState<string | null>(null)
  const area = menuAt === location.key ? null : areaFor(panel.nav, location.pathname)
  const level = area?.id ?? 'menu'

  // A new level slides in as the Console's: a page's sections from the end side (a step in), the menu from the start
  // side (a step back). Nothing moves on the first paint.
  const [motion, setMotion] = useState<{ level: string; from: 'start' | 'end' | null }>({ level, from: null })
  if (motion.level !== level) setMotion({ level, from: area ? 'end' : 'start' })

  // A press inside the column that opens another level takes the pressed link or button away with the old one: the
  // focus goes on to the new level's current page (or its first control) instead of falling to the document.
  const refocus = useRef(false)
  const onPanel = useCallback((element: HTMLDivElement | null) => {
    if (!element || !refocus.current) return
    refocus.current = false
    const target = element.querySelector<HTMLElement>('[aria-current="page"]') ?? element.querySelector<HTMLElement>('a[href], button:not(:disabled)')
    target?.focus()
  }, [])

  const backToMenu = () => {
    refocus.current = true
    setMenuAt(location.key)
  }

  return (
    <div className="flex h-full min-h-0 flex-col overflow-x-clip">
      <SidebarHead onClose={onClose} />

      <div
        key={level}
        ref={onPanel}
        className={cn(
          'flex min-h-0 flex-1 flex-col',
          motion.from && 'duration-200 ease-out fade-in motion-safe:animate-in',
          motion.from === 'end' && 'slide-in-from-end-3',
          motion.from === 'start' && 'slide-in-from-start-3',
        )}
      >
        <div className={cn('pb-2', collapsed ? 'px-2' : 'px-3')}>{area ? <MenuButton onClick={backToMenu} /> : Picker && <Picker />}</div>

        <nav
          aria-label={area ? `بخش‌های ${area.label}` : 'منوی اصلی'}
          // A link the router followed (it prevents the browser's own navigation) to a page of another level.
          onClick={(event) => {
            const link = (event.target as Element).closest('a')
            if (event.defaultPrevented && link && (areaFor(panel.nav, routePath(link.pathname))?.id ?? 'menu') !== level) refocus.current = true
          }}
          className={cn('grid min-h-0 flex-1 scrollbar-thin content-start gap-px overflow-x-hidden overflow-y-auto pt-1 pb-3', collapsed ? 'px-2' : 'px-3')}
        >
          {area ? <AreaNav area={area} /> : <MainNav />}
        </nav>
      </div>

      <div className={cn('grid gap-px border-t border-sidebar-border py-2.5', collapsed ? 'px-2' : 'px-3')}>
        <ItemLink item={panel.nav.settings.item} />
        <div className="pt-1.5">
          <AccountRow />
        </div>
      </div>
    </div>
  )
}

/** The column's head: the brand — the name of the shop the panel shows —, and the column's toggle. */
function SidebarHead({ onClose }: { onClose?: () => void }) {
  const shopName = useShopName()
  const { toggleCollapsed } = useShell()
  const collapsed = useRail()
  const label = collapsed ? 'باز کردن سایدبار' : 'جمع کردن سایدبار'

  const toggle = onClose ? (
    <IconButton onClick={onClose} aria-label="بستن منو" data-autofocus>
      <X className="size-4" aria-hidden />
    </IconButton>
  ) : (
    <Tooltip>
      <TooltipTrigger asChild>
        <IconButton onClick={toggleCollapsed} aria-label={label} aria-expanded={!collapsed} aria-controls="app-sidebar">
          {collapsed ? (
            <PanelRightOpen className="size-[18px] ltr:-scale-x-100" strokeWidth={1.75} aria-hidden />
          ) : (
            <PanelRightClose className="size-[18px] ltr:-scale-x-100" strokeWidth={1.75} aria-hidden />
          )}
        </IconButton>
      </TooltipTrigger>
      <TooltipContent side={collapsed ? contentSide() : 'bottom'} className="flex items-center gap-2">
        {label}
        <Kbd className="h-4 border-primary-foreground/25 bg-transparent text-primary-foreground/80">{MOD_KEY} B</Kbd>
      </TooltipContent>
    </Tooltip>
  )

  if (collapsed) {
    return <div className="flex h-14 shrink-0 items-center justify-center">{toggle}</div>
  }

  return (
    <div className="flex h-14 shrink-0 items-center gap-2 ps-4 pe-2">
      <Link to="/" aria-label={`${shopName} — داشبورد`} className="flex min-w-0 flex-1 items-center gap-2 rounded-md outline-none focus-visible:focus-ring">
        <BrandMark className="size-6 rounded-[6px]" />
        <span className="truncate text-subtitle leading-none font-semibold text-foreground">{shopName}</span>
      </Link>
      {toggle}
    </div>
  )
}

/** The groups the admin folded shut, remembered for the next visit. */
function readClosed(): string[] {
  try {
    const stored: unknown = JSON.parse(readStored(STORAGE_KEYS.closedGroups) ?? '[]')
    return Array.isArray(stored) ? stored.filter((id): id is string => typeof id === 'string') : []
  } catch {
    return []
  }
}

function MainNav() {
  const { pathname } = useLocation()
  const { panel } = useShell()
  const collapsed = useRail()
  const [closed, setClosed] = useState<string[]>(readClosed)
  // What waits beside the entries that carry a queue's count — read once for the whole menu.
  const counts = useQueueCounts()

  const toggle = (id: string) => {
    const next = closed.includes(id) ? closed.filter((group) => group !== id) : [...closed, id]
    setClosed(next)
    writeStored(STORAGE_KEYS.closedGroups, JSON.stringify(next))
  }

  return panel.nav.menu.map((entry) => {
    if (!isGroup(entry)) return <ItemLink key={entry.id} item={entry} counts={counts} />
    // The group of the page on screen is always open: its page must be in sight.
    const holdsActive = entry.items.some((item) => isNavItemActive(item, pathname))
    return collapsed ? (
      <GroupFlyout
        key={entry.id}
        title={entry.title}
        icon={entry.icon}
        active={holdsActive}
        items={entry.items.map((item) => ({ key: item.id, title: item.title, to: item.to, active: isNavItemActive(item, pathname), badge: item.badge }))}
        counts={counts}
      />
    ) : (
      <Group key={entry.id} group={entry} open={holdsActive || !closed.includes(entry.id)} onToggle={() => toggle(entry.id)} counts={counts} />
    )
  })
}

/** A group that folds open; folded shut, a dot says when something waits in one of its pages. */
function Group({ group, open, onToggle, counts }: { group: NavGroup; open: boolean; onToggle: () => void; counts: QueueCounts | undefined }) {
  const { pathname } = useLocation()
  // The pages the toggle shows and hides — there, hidden, while the group is shut, so the toggle always points at them.
  const pagesId = useId()
  const Icon = group.icon
  const waiting = open
    ? undefined
    : pressingQueue(
        counts,
        group.items.map((item) => item.badge),
      )

  return (
    <div role="group" aria-label={group.title} className="grid gap-px">
      <button type="button" onClick={onToggle} aria-expanded={open} aria-controls={pagesId} className={cn(ITEM, ITEM_IDLE, 'mt-1.5')}>
        <Icon className={ICON} strokeWidth={1.75} aria-hidden />
        <span className="min-w-0 flex-1 truncate text-start">
          {group.title}
          {waiting && <span className="sr-only"> ({waiting.label})</span>}
        </span>
        {waiting && <StatusDot tone={waiting.tone} />}
        <ChevronDown className={cn('size-4 shrink-0 text-faint transition-transform duration-150', !open && 'ltr:-rotate-90 rtl:rotate-90')} aria-hidden />
      </button>
      <div id={pagesId} hidden={!open} className="grid gap-px">
        {group.items.map((item) => (
          <NavEntryLink key={item.id} to={item.to} active={isNavItemActive(item, pathname)} className={CHILD}>
            <span className="min-w-0 flex-1 truncate">{item.title}</span>
            <QueueBadge queue={item.badge} counts={counts} />
          </NavEntryLink>
        ))}
      </div>
    </div>
  )
}

/** A link of the column; the current page's is marked (aria-current) and filled. */
function NavEntryLink({ to, active, className, children }: { to: string; active: boolean; className?: string; children: ReactNode }) {
  return (
    <Link to={to} aria-current={active ? 'page' : undefined} className={cn(ITEM, active ? ITEM_ACTIVE : ITEM_IDLE, className)}>
      {children}
    </Link>
  )
}

/**
 * A top-level page: icon and title, or the icon alone (with a tooltip) on the rail. The settings' item is current on any
 * of their pages. A queue's count rides beside the title — on the rail, a dot on the icon.
 */
function ItemLink({ item, counts }: { item: NavItem; counts?: QueueCounts }) {
  const { pathname } = useLocation()
  const { panel } = useShell()
  const collapsed = useRail()
  const active = isNavItemActive(item, pathname) || (item === panel.nav.settings.item && inSettings(panel.nav, pathname))
  const waiting = collapsed ? pressingQueue(counts, [item.badge]) : undefined
  const Icon = item.icon

  return (
    <RailTooltip label={item.title}>
      <Link
        to={item.to}
        aria-label={collapsed ? withQueue(item.title, waiting) : undefined}
        aria-current={active ? 'page' : undefined}
        className={cn(ITEM, active ? ITEM_ACTIVE : ITEM_IDLE, collapsed && RAIL_ITEM, collapsed && 'relative')}
      >
        <Icon className={ICON} strokeWidth={1.75} aria-hidden />
        {collapsed ? (
          waiting && <StatusDot tone={waiting.tone} className={RAIL_DOT} />
        ) : (
          <>
            <span className="min-w-0 flex-1 truncate">{item.title}</span>
            <QueueBadge queue={item.badge} counts={counts} />
          </>
        )}
      </Link>
    </RailTooltip>
  )
}

/** A rail control's name, with what waits under it. */
function withQueue(title: string, waiting: Status | undefined): string {
  return waiting ? `${title} (${waiting.label})` : title
}

interface FlyoutItem {
  key: string
  title: string
  to: string
  active: boolean
  badge?: Queue
}

/** A group on the rail: its icon — a dot on it while something waits in one of its pages —, whose pages open as a menu beside it. */
function GroupFlyout({ title, icon: Icon, active, items, counts }: { title: string; icon: LucideIcon; active: boolean; items: FlyoutItem[]; counts?: QueueCounts }) {
  const waiting = pressingQueue(
    counts,
    items.map((item) => item.badge),
  )

  return (
    <DropdownMenu>
      <RailTooltip label={title}>
        <DropdownMenuTrigger asChild>
          <button type="button" aria-label={withQueue(title, waiting)} className={cn(ITEM, RAIL_ITEM, 'relative data-[state=open]:bg-fill-hover', active ? ITEM_ACTIVE : ITEM_IDLE)}>
            <Icon className={ICON} strokeWidth={1.75} aria-hidden />
            {waiting && <StatusDot tone={waiting.tone} className={RAIL_DOT} />}
          </button>
        </DropdownMenuTrigger>
      </RailTooltip>
      <DropdownMenuContent side={contentSide()} align="start" sideOffset={8}>
        <DropdownMenuLabel>{title}</DropdownMenuLabel>
        {items.map((item) => (
          <DropdownMenuItem key={item.key} asChild className={cn(item.active && 'bg-fill-active font-medium')}>
            <Link to={item.to} aria-current={item.active ? 'page' : undefined}>
              <span className="min-w-0 flex-1">{item.title}</span>
              <QueueBadge queue={item.badge} counts={counts} />
            </Link>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/** The way from a page's sections back to the menu, where the panel's picker sits on the menu's level (the Console's «Back to app»). */
function MenuButton({ onClick }: { onClick: () => void }) {
  const collapsed = useRail()

  return (
    <RailTooltip label="منوی اصلی">
      <button type="button" onClick={onClick} aria-label={collapsed ? 'منوی اصلی' : undefined} className={cn(ITEM, ITEM_IDLE, collapsed ? RAIL_ITEM : 'h-8')}>
        <ArrowLeft className={cn(ICON, 'rtl:rotate-180')} strokeWidth={1.75} aria-hidden />
        {!collapsed && <span className="truncate">منوی اصلی</span>}
      </button>
    </RailTooltip>
  )
}

/** A page's sections: a heading per group (the page, or each page of the settings) and its sections under it. */
function AreaNav({ area }: { area: NavArea }) {
  const { pathname } = useLocation()
  const { panel } = useShell()
  const collapsed = useRail()
  const SectionBadge = panel.sectionBadge

  if (collapsed) {
    return area.groups.map((group) => (
      <GroupFlyout
        key={group.id}
        title={group.title}
        icon={group.icon}
        active={group.sections.some((section) => isSectionCurrent(group.sections, section, pathname))}
        items={group.sections.map((section) => ({ key: section.value, title: section.title, to: section.to, active: isSectionCurrent(group.sections, section, pathname) }))}
      />
    ))
  }

  return area.groups.map((group, index) => {
    const Icon = group.icon
    return (
      <div key={group.id} role="group" aria-label={group.title} className={cn('grid gap-px', index > 0 && 'mt-2')}>
        <div className="flex h-9 items-center gap-3 px-2 text-body font-medium text-foreground">
          <Icon className={ICON} strokeWidth={1.75} aria-hidden />
          <span className="truncate">{group.title}</span>
        </div>
        {group.sections.map((section) => (
          <NavEntryLink key={section.value} to={section.to} active={isSectionCurrent(group.sections, section, pathname)} className={CHILD}>
            <span className="min-w-0 flex-1 truncate">{section.title}</span>
            {SectionBadge && <SectionBadge area={area.id} section={section.value} />}
          </NavEntryLink>
        ))}
      </div>
    )
  })
}
