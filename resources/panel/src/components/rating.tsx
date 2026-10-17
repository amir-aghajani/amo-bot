import { Star } from 'lucide-react'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** The most a customer gives: a review's stars, a closed ticket's. */
const MOST = 5

/** A customer's rating, 1 to 5 — a review, a closed ticket —: five stars, as many filled as given, read as «۴ از ۵». */
export function Rating({ rating }: { rating: number }) {
  return (
    <span role="img" aria-label={`${formatNumber(rating)} از ${formatNumber(MOST)}`} className="inline-flex items-center gap-0.5 whitespace-nowrap">
      {Array.from({ length: MOST }, (_, index) => (
        <Star key={index} aria-hidden className={cn('size-3.5', index < rating ? 'fill-current text-warning' : 'text-faint')} />
      ))}
    </span>
  )
}
