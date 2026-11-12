import { Infinity as Unlimited } from 'lucide-react'
import { termLeft, trafficLeft, type Left } from '@/apps/app/parts/service'
import { Ring, RingValue } from '@/apps/app/ui/visuals'
import type { StoreSubscription } from '@/lib/api-types'

/*
 * A service's two rings — the traffic left of its quota, the term left of its length —, each with its figure and unit
 * at its centre (an ∞ over the word for no limit) and one label: large, the label under it, on the service's page;
 * smaller, the label beside it, on a card of the list. The two sit in equal columns, so their figures line up.
 */

/** The service's page: two large rings side by side, each with what is left at its centre and its label under it. */
export function ServiceRings({ service }: { service: StoreSubscription }) {
  return (
    <div className="mx-auto grid w-full max-w-80 grid-cols-2 gap-4">
      <Gauge left={trafficLeft(service)} name="حجم" />
      <Gauge left={termLeft(service)} name="زمان" />
    </div>
  )
}

function Gauge({ left, name }: { left: Left; name: string }) {
  return (
    <figure className="flex min-w-0 flex-col items-center gap-3" aria-label={`${name}: ${left.figure} ${left.unit}`}>
      <Ring value={left.fill} size={104} stroke={9} tone={left.tone}>
        <span aria-hidden>
          <RingValue size="lg" value={left.fill === null ? <Unlimited className="block size-6" /> : left.figure} unit={left.measure} />
        </span>
      </Ring>
      <figcaption className="text-hint max-w-full truncate text-footnote font-medium" aria-hidden>
        {left.caption}
      </figcaption>
    </figure>
  )
}

/** A card of the list: a ring for each in two equal columns, its label under it — as home's cards show them. */
export function ServiceMiniRings({ service }: { service: StoreSubscription }) {
  return (
    <div className="grid grid-cols-2 gap-3">
      <MiniGauge left={trafficLeft(service)} name="حجم" />
      <MiniGauge left={termLeft(service)} name="زمان" />
    </div>
  )
}

function MiniGauge({ left, name }: { left: Left; name: string }) {
  return (
    <div className="flex min-w-0 flex-col items-center gap-2" aria-label={`${name}: ${left.figure} ${left.unit}`}>
      <Ring value={left.fill} size={72} stroke={6} tone={left.tone}>
        <span aria-hidden>
          <RingValue value={left.fill === null ? <Unlimited className="block size-5" /> : left.figure} unit={left.measure} />
        </span>
      </Ring>
      <span className="text-hint max-w-full truncate text-caption font-medium" aria-hidden>
        {left.caption}
      </span>
    </div>
  )
}
