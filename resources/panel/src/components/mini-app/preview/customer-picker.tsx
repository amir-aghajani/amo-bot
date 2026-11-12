import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { useSectionShown } from '@/components/sectioned-page'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { UserIdentity } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { UserRow, UsersResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useSettled } from '@/lib/use-settled'

/** How many customers the picker lists: a search narrows the rest. */
const SHOWN = 6

interface CustomerPickerProps {
  id: string
  picked: UserRow | null
  onPick: (customer: UserRow | null) => void
  /** Why the server would not open the preview as the customer picked (not this shop's, banned). */
  error?: string
}

/**
 * «مشتری» of the mini app's preview: the shop's customers who are not banned — the newest while nothing is typed, else
 * those the search finds by name, @username, Telegram id or phone (the users list's own search, `GET /users`) —, one
 * picked with a press, then shown with «تغییر» to pick another. Read only while its section is on screen.
 */
export function CustomerPicker({ id, picked, onPick, error }: CustomerPickerProps) {
  const [typed, setTyped] = useState('')
  const search = useSettled(typed.trim())
  const shown = useSectionShown()
  const found = useQuery({
    queryKey: queryKeys.previewCustomers(search),
    queryFn: () => api.get<UsersResponse>('/users', new URLSearchParams({ status: 'active', ...(search === '' ? {} : { search }) })),
    enabled: shown && picked === null,
    placeholderData: keepPreviousData,
  })
  const line = error ? (
    <p id={`${id}-error`} className="text-footnote text-danger">
      {error}
    </p>
  ) : null

  if (picked !== null) {
    return (
      <div className="grid gap-1.5">
        <span className="text-body font-medium">مشتری</span>
        <div className="flex items-center gap-2 rounded-lg border border-border bg-fill p-2 ps-2.5">
          <UserIdentity user={picked} avatar className="flex-1" />
          <Button variant="ghost" size="sm" onClick={() => onPick(null)}>
            تغییر
          </Button>
        </div>
        {line}
      </div>
    )
  }

  const users = found.data?.users ?? []
  const more = (found.data?.meta.total ?? 0) - Math.min(users.length, SHOWN)

  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>مشتری</Label>
      <div className="relative">
        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-faint" aria-hidden />
        <Input
          id={id}
          type="search"
          dir={typed === '' ? 'rtl' : 'auto'}
          value={typed}
          onChange={(event) => setTyped(event.target.value)}
          placeholder="نام، نام کاربری یا شناسه"
          autoComplete="off"
          spellCheck={false}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? `${id}-error` : undefined}
          className="pl-8"
        />
      </div>
      {line}
      {found.isPending ? (
        <p className="text-footnote text-muted-foreground">در حال خواندن مشتری‌ها…</p>
      ) : found.isError ? (
        <p className="text-footnote text-danger">{messageOf(found.error)}</p>
      ) : users.length === 0 ? (
        <p className="text-footnote text-muted-foreground">{search === '' ? 'این فروشگاه هنوز مشتری‌ای ندارد.' : 'مشتری‌ای با این جستجو پیدا نشد.'}</p>
      ) : (
        <div className="grid gap-1">
          <span className="text-caption text-faint">{search === '' ? 'تازه‌ترین مشتری‌ها' : 'مشتری‌های پیدا شده'}</span>
          <ul aria-label="مشتری‌ها" className="grid gap-0.5 rounded-lg border border-border p-1">
            {users.slice(0, SHOWN).map((user) => (
              <li key={user.id}>
                <button
                  type="button"
                  onClick={() => onPick(user)}
                  className="flex w-full items-center rounded-md px-2 py-1.5 text-start transition-colors outline-none hover:bg-fill-hover focus-visible:focus-ring"
                >
                  <UserIdentity user={user} avatar />
                </button>
              </li>
            ))}
          </ul>
          {more > 0 && <span className="text-caption text-faint">{`و ${formatNumber(more)} مشتری دیگر؛ برای پیدا کردنشان جستجو کنید.`}</span>}
        </div>
      )}
    </div>
  )
}
