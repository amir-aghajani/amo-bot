import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { Link } from 'react-router'
import { IconButton } from '@/components/icon-button'
import { pillClasses } from '@/components/pill'
import { UserLabel, userPage } from '@/components/user-identity'
import { customerQuery } from '@/lib/queries'
import { cn } from '@/lib/utils'

interface CustomerFilterProps {
  /** The customer's id as the list's address carries it ('' = not narrowed). */
  value: string
  /** What the customer is to the list: «مشتری» (their services, orders, payments), «معرف» (the ones their link brought). */
  label?: string
  onClear: () => void
}

/**
 * A list narrowed to one customer, as their page links to it: `/orders?user=12` — the invitees, the ones their link
 * brought, by `referrer`.
 */
export function customerList(path: string, id: number, param: 'user' | 'referrer' = 'user'): string {
  return `${path}?${param}=${id}`
}

/** A customer's id as a list's address carries it, when it is one (anything else narrows nothing, at the API too). */
function customerId(value: string): number | null {
  const id = Number(value)
  return value !== '' && Number.isSafeInteger(id) && id > 0 ? id : null
}

/**
 * A list narrowed to one customer — their page's «همه …» —, said beside its other filters: «مشتری  name ✕». The name
 * is the customer's page's own read (there already when their page led here) and leads back to it; the ✕ lets go of
 * the filter, which rides in the list's address like the others.
 */
export function CustomerFilter({ value, label = 'مشتری', onClear }: CustomerFilterProps) {
  const id = customerId(value)
  const customer = useQuery({ ...customerQuery(id ?? 0), enabled: id !== null })
  if (id === null) return null

  return (
    <span role="group" aria-label={`فیلتر ${label}`} className={cn(pillClasses, 'pe-0.5 hover:bg-card dark:hover:bg-transparent')}>
      <span className="text-faint">{label}</span>
      <Link to={userPage(id)} className="min-w-0 truncate rounded-sm font-medium text-foreground underline-offset-4 outline-none hover:underline focus-visible:focus-ring">
        {customer.data ? (
          <UserLabel user={customer.data.user} />
        ) : (
          <bdi dir="ltr" className="tabular">
            #{id}
          </bdi>
        )}
      </Link>
      <IconButton icon={X} onClick={onClear} aria-label={`برداشتن فیلتر ${label}`} className="size-6 rounded-md" />
    </span>
  )
}
