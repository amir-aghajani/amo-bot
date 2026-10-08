import { useState } from 'react'
import { ChevronsUpDown, LoaderCircle, RotateCw, Store } from 'lucide-react'
import { useOpenShop, useShops } from '@/apps/admin/owner'
import { IconButton } from '@/components/icon-button'
import { pillClasses } from '@/components/pill'
import { RailTooltip, useRail } from '@/components/shell/rail'
import { StatusBadge } from '@/components/status-badge'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import type { ShopRef } from '@/lib/api-types'
import { useMainShop, useSession } from '@/lib/auth'
import { MAIN_SHOP } from '@/lib/config'
import { contentSide } from '@/lib/direction'
import { messageOf } from '@/lib/failure'
import { BOT_STATUS } from '@/lib/statuses'
import { cn } from '@/lib/utils'

/**
 * The owner's way between shops — the main bot's and every agent's — where the Console keeps its workspace picker, under
 * the brand (on the icon rail, a store icon with the same menu). Opening one loads the panel afresh at its dashboard
 * (useOpenShop). It names the shop the tab shows whatever the list of them does — while it is read, when it could not
 * be (said in its menu, with a way to ask again) —, so the way back from an agent's shop never goes; in the main shop,
 * with no agent's shop to open, there is no picker. A shop whose bot is off (its agency ended) is marked so — in the
 * menu, and on the picker while it is the one open. While a shop opens, the picker waits (held).
 */
export function ShopSwitcher() {
  const rail = useRail()
  const session = useSession()
  const main = useMainShop()
  const shops = useShops()
  const { open, opening } = useOpenShop()
  const [menuOpen, setMenuOpen] = useState(false)

  if (main && (shops.isPending || (shops.data !== undefined && shops.data.length < 2))) return null

  // The shops to pick from: the list once read; meanwhile, or failing that, the one open.
  const listed: ShopRef[] = shops.data ?? [session.shop]
  const off = session.shop.status === 'disabled'
  // The shop open, as the rail's tooltip and assistive tech say it — off, when it is.
  const said = off ? `${session.shop.name} (${BOT_STATUS.disabled.label})` : session.shop.name
  // A shop opening: the picker waits for the page that loads, its focus kept.
  const held = opening !== null
  const pick = (value: string) => {
    if (Number(value) !== session.shop.id) void open(Number(value))
  }
  const menu = (
    <DropdownMenuContent side={rail ? contentSide() : 'bottom'} align="start" className={rail ? 'min-w-64' : 'w-(--radix-dropdown-menu-trigger-width) min-w-60'}>
      <DropdownMenuLabel>فروشگاه‌ها</DropdownMenuLabel>
      <DropdownMenuRadioGroup value={String(session.shop.id)} onValueChange={pick}>
        {listed.map((shop) => (
          <DropdownMenuRadioItem key={shop.id} value={String(shop.id)}>
            <span className="grid min-w-0 flex-1">
              <span className="truncate">{shop.name}</span>
              <span className="truncate text-caption text-faint">{shop.id === MAIN_SHOP ? 'ربات اصلی' : shop.username ? <bdi dir="ltr">@{shop.username}</bdi> : 'ربات نماینده'}</span>
            </span>
            {shop.status === 'disabled' && <StatusBadge status={BOT_STATUS.disabled} />}
          </DropdownMenuRadioItem>
        ))}
      </DropdownMenuRadioGroup>
      {shops.isPending && <p className="px-2 py-1.5 text-footnote text-muted-foreground">در حال خواندن فروشگاه‌ها…</p>}
      {shops.isError && (
        <>
          <p role="alert" className="max-w-72 px-2 py-1.5 text-footnote text-danger">
            فروشگاه‌های دیگر خوانده نشدند: {messageOf(shops.error)}
          </p>
          <DropdownMenuItem
            onSelect={(event) => {
              // The menu stays open for the list the read brings.
              event.preventDefault()
              void shops.refetch()
            }}
          >
            {shops.isFetching ? <LoaderCircle className="animate-spin" aria-hidden /> : <RotateCw aria-hidden />}
            تلاش دوباره
          </DropdownMenuItem>
        </>
      )}
    </DropdownMenuContent>
  )

  return (
    <DropdownMenu open={menuOpen && !held} onOpenChange={setMenuOpen}>
      <RailTooltip label={said}>
        <DropdownMenuTrigger asChild>
          {rail ? (
            <IconButton aria-label={`فروشگاه: ${said}`} className="mx-auto size-9" aria-disabled={held} aria-busy={held || undefined}>
              {held ? <LoaderCircle className="size-[18px] animate-spin" aria-hidden /> : <Store className="size-[18px]" strokeWidth={1.75} aria-hidden />}
            </IconButton>
          ) : (
            <button type="button" aria-disabled={held || undefined} aria-busy={held || undefined} aria-label={`فروشگاه: ${said}`} className={cn(pillClasses, 'w-full aria-disabled:opacity-50')}>
              <Store className="size-4 shrink-0 text-faint" aria-hidden />
              <span className="min-w-0 flex-1 truncate text-start">{session.shop.name}</span>
              {off && <StatusBadge status={BOT_STATUS.disabled} />}
              {held ? <LoaderCircle className="size-3.5 shrink-0 animate-spin text-faint" aria-hidden /> : <ChevronsUpDown className="size-3.5 shrink-0 text-faint" aria-hidden />}
            </button>
          )}
        </DropdownMenuTrigger>
      </RailTooltip>
      {menu}
    </DropdownMenu>
  )
}
