import type { ReactNode } from 'react'
import { ArrowRight } from 'lucide-react'
import { Link } from 'react-router'

/** The way back from a subject's page to its list («سرورها», «کاربران»), above the page's title. */
export function BackLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Link to={to} className="flex w-fit items-center gap-1 rounded-sm text-footnote text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:focus-ring">
      <ArrowRight className="size-3.5 ltr:rotate-180" aria-hidden />
      {children}
    </Link>
  )
}
