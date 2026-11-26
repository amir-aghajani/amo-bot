import { useState } from 'react'
import { useMutation, type QueryKey } from '@tanstack/react-query'
import { Ban, ShieldCheck, ShieldOff, UserCheck } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import { InfoBadge } from '@/components/info-tip'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { UserRole, UserRow, UserStatus } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'

/*
 * What is done to a customer's account from the panel — banning and reinstating, giving and taking the bot's admin
 * role —, the same on the users table's row menu and on the customer's page: the menu's items, the second look before a
 * ban or a role changes, and the bot admin's mark.
 */

/**
 * What a bot admin may do — said on their mark, and asked about before an account gets it —, as the bot's own
 * management has it: the whole management from «⚙️ مدیریت», with the owner's access in the main shop and with the
 * agent's in an agent's, under their own name and never about their own account; the report group's buttons too, and
 * none of the bot's conditions.
 */
function botAdminWords(mainShop: boolean): string {
  const access = mainShop
    ? 'کل فروشگاه را با دسترسی مالک مدیریت می‌کند: همه بخش‌ها در هر فروشگاه، تنظیمات پنل، پشتیبان‌گیری و بازگردانی، و به‌روزرسانی، جز ورود به پنل، حساب‌های تلگرام مالک، آدرس سایت، توکن و نام کاربری ربات اصلی، آدرس Bot API، رمز Webhook، دیتابیس، تنظیمات Session پنل‌ها، پشتیبان‌های ساخته‌شده پیش از نسخه 0.11.0 و برگرداندن به نسخه قبلی که با خود مالک می‌ماند'
    : 'کل این فروشگاه را با دسترسی نماینده مدیریت می‌کند: همه بخش‌هایی که نماینده در پنل خودش دارد'
  return `مدیر ربات با «⚙️ مدیریت» در ربات ${access}؛ کارهایش به نام خودش ثبت می‌شود و درباره حساب خودش تصمیم نمی‌گیرد. رسیدها را در گروه گزارش‌ها هم تایید و رد می‌کند و از شرط‌های ربات (خاموش بودن، شماره موبایل، کانال‌ها) معاف است.`
}

/** A change of a customer's account. */
type AccountChange = { user: UserRow; status: UserStatus } | { user: UserRow; role: UserRole }

/**
 * Changes to a customer's account from any screen: an unban at once, a ban or a role change after AccountChangeConfirm.
 * The returned row goes to `onChanged`; the screen's other copies of the customer (`invalidates`) are read again.
 */
export function useAccountChange(onChanged: (user: UserRow) => void, invalidates?: readonly QueryKey[]) {
  const [asking, setAsking] = useState<AccountChange | null>(null)

  const change = useMutation({
    meta: { invalidates },
    // The role has an address of its own: the panels' alone, never the shop's website's admin side.
    mutationFn: (wanted: AccountChange) => ('role' in wanted ? api.put(`/users/${wanted.user.id}/role`, { role: wanted.role }) : api.patch(`/users/${wanted.user.id}`, { status: wanted.status })),
    onSuccess: ({ user }, changes) => {
      onChanged(user)
      setAsking(null)
      const name = userLabel(user)
      toast.success('role' in changes ? (user.role === 'admin' ? `${name} مدیر ربات شد` : `${name} دیگر مدیر ربات نیست`) : user.status === 'banned' ? `${name} مسدود شد` : `${name} دوباره فعال شد`)
    },
  })

  return {
    asking,
    pending: change.isPending,
    /** A change asked for: a reinstatement goes at once, anything else waits for the second look. */
    request: (wanted: AccountChange) => ('status' in wanted && wanted.status === 'active' ? change.mutate(wanted) : setAsking(wanted)),
    confirm: () => asking && change.mutate(asking),
    cancel: () => setAsking(null),
  }
}

export type AccountChanges = ReturnType<typeof useAccountChange>

/** A customer's account in a menu: the bot's admin role given or taken, then a ban or its end. */
export function AccountMenuItems({ user, account }: { user: UserRow; account: AccountChanges }) {
  return (
    <>
      {user.role === 'admin' ? (
        <DropdownMenuItem onSelect={() => account.request({ user, role: 'customer' })}>
          <ShieldOff aria-hidden />
          برداشتن مدیریت ربات
        </DropdownMenuItem>
      ) : (
        <DropdownMenuItem onSelect={() => account.request({ user, role: 'admin' })}>
          <ShieldCheck aria-hidden />
          مدیر ربات کردن
        </DropdownMenuItem>
      )}
      <DropdownMenuSeparator />
      {user.status === 'banned' ? (
        <DropdownMenuItem onSelect={() => account.request({ user, status: 'active' })}>
          <UserCheck aria-hidden />
          رفع مسدودی
        </DropdownMenuItem>
      ) : (
        <DropdownMenuItem variant="destructive" onSelect={() => account.request({ user, status: 'banned' })}>
          <Ban aria-hidden />
          مسدود کردن
        </DropdownMenuItem>
      )}
    </>
  )
}

/** The second look before a customer is banned, or the bot's admin role changes hands. */
export function AccountChangeConfirm({ account }: { account: AccountChanges }) {
  const { asking } = account
  const mainShop = useMainShop()

  return <ConfirmModal open={asking !== null} onClose={account.cancel} pending={account.pending} onConfirm={account.confirm} {...(asking ? question(asking, mainShop) : CLOSED)} />
}

const CLOSED = { title: '' }

/** What the second look says about a change: banning, or giving or taking the bot's admin role — the main shop's or an agent's. */
function question(change: AccountChange, mainShop: boolean): { title: string; description: string; confirmLabel: string; destructive?: boolean; children: string } {
  const name = userLabel(change.user)

  if ('status' in change) {
    return {
      title: 'مسدود کردن کاربر',
      description: `${name} مسدود می‌شود.`,
      confirmLabel: 'مسدود کن',
      destructive: true,
      children: 'ربات به هر پیامش فقط می‌گوید حسابش مسدود شده و هیچ کاری برایش انجام نمی‌دهد. سرویس‌های فعلی‌اش دست نمی‌خورد و هر وقت خواستید می‌توانید رفع مسدودی کنید.',
    }
  }

  return change.role === 'admin'
    ? {
        title: 'مدیر ربات کردن',
        description: `${name} مدیر ربات می‌شود و کل مدیریت ربات به او سپرده می‌شود.`,
        confirmLabel: 'مدیر ربات کن',
        children: `${botAdminWords(mainShop)} این نقش را فقط به کسی بدهید که در همه چیز به او اعتماد دارید.`,
      }
    : {
        title: 'برداشتن مدیریت ربات',
        description: `${name} دیگر مدیر ربات نیست.`,
        confirmLabel: 'برداشتن مدیریت',
        destructive: true,
        children: 'از پیام بعدی‌اش مثل بقیه مشتری‌هاست: دستورهای مدیر، دکمه‌های گروه گزارش‌ها و مدیریت فروشگاه از ربات برایش کار نمی‌کند.',
      }
}

/** The bot admin's mark beside the name, which says what the role allows — in this shop — when pressed. */
export function AdminMark() {
  return (
    <InfoBadge variant="info" info={botAdminWords(useMainShop())} className="h-[18px] gap-0.5 px-1">
      <ShieldCheck aria-hidden />
      مدیر
    </InfoBadge>
  )
}
