import type { ReactNode } from 'react'
import { Gauge } from 'lucide-react'
import { Callout } from '@/components/callout'
import type { TrafficShortage } from '@/lib/api-types'
import { formatBytes } from '@/lib/format'

interface TrafficShortageNoticeProps {
  shortage: TrafficShortage
  /** What to do about it, the panel's own way: an agent buys traffic in the main bot, the owner sets theirs right. */
  help?: ReactNode
}

/**
 * Shown when an agent's bot has too little traffic for even its smallest plan on sale (the server's `traffic_shortage`):
 * such a bot sells and renews nothing.
 */
export function TrafficShortageNotice({ shortage: { balance, smallest_plan: smallest }, help }: TrafficShortageNoticeProps) {
  const short = balance > 0 && smallest !== null

  return (
    <Callout tone={short ? 'warning' : 'danger'} icon={Gauge}>
      <span className="font-medium">{short ? `حجم باقی‌مانده ربات (${formatBytes(balance)}) برای کوچک‌ترین پلن فروشی (${formatBytes(smallest)}) کافی نیست.` : 'حجم ربات تمام شده است.'}</span> تا حجم
      تازه نرسد، ربات هیچ پلنی نمی‌فروشد و سرویسی را تمدید نمی‌کند.
      {help && <> {help}</>}
    </Callout>
  )
}
