import type { ComponentType, ReactNode } from 'react'
import { CreditCard, TimerReset, Wallet, type LucideIcon } from 'lucide-react'
import { KIND_LABELS } from '@/components/payments/kinds'
import type { DriverDescription, GatewayKind, PaymentMethodRow, UnregisteredMethodRow } from '@/lib/api-types'
import { formatDuration } from '@/lib/format'

/*
 * What the panel adds to a gateway driver beyond its description (GET /payment-methods/drivers: its words, its traits
 * and its form, which components/driver-form draws — gateways/method-form): its icon, and what the list says of a method
 * beside its summary — handed the method as its driver describes it, so nothing is cast. Adding a driver to the API
 * makes this registry ask for it.
 */

/** A method of a driver the panel knows, by its driver: a card's (`manual`), the wallet. */
type Registered = Exclude<PaymentMethodRow, UnregisteredMethodRow>
type DriverKey = Registered['driver']
type MethodOf<Key extends DriverKey> = Extract<Registered, { driver: Key }>

interface GatewayUi<Method extends PaymentMethodRow> {
  icon: LucideIcon
  /** What the list says of a method beside its summary (a card's automatic approval). */
  Note?: ComponentType<{ method: Method }>
}

const GATEWAYS: { [Key in DriverKey]: GatewayUi<MethodOf<Key>> } = {
  wallet: { icon: Wallet },
  manual: { icon: CreditCard, Note: AutoApproval },
}

const isKnown = (key: string): key is DriverKey => Object.hasOwn(GATEWAYS, key)

const isKind = (value: unknown): value is GatewayKind => typeof value === 'string' && Object.hasOwn(KIND_LABELS, value)

/** The driver's icon; none for one the panel has nothing for (no longer installed). */
export function gatewayIcon(key: string): LucideIcon | undefined {
  return isKnown(key) ? GATEWAYS[key].icon : undefined
}

/** How a driver settles, as its `kind` trait says; null for a word the panel does not know. */
export function gatewayKind(driver: DriverDescription): GatewayKind | null {
  const kind = driver.traits.kind
  return isKind(kind) ? kind : null
}

/** What the list says of a method beside its summary, by its driver. */
export function MethodNote({ method }: { method: PaymentMethodRow }): ReactNode {
  return method.kind === null ? null : <DriverNote driverKey={method.driver} method={method} />
}

function DriverNote<Key extends DriverKey>({ driverKey, method }: { driverKey: Key; method: MethodOf<Key> }) {
  const Note = GATEWAYS[driverKey].Note
  return Note ? <Note method={method} /> : null
}

/** A card's receipts accepted on their own after a while, said under its card. */
function AutoApproval({ method }: { method: MethodOf<'manual'> }) {
  const minutes = method.config.auto_approve_after
  if (minutes === 0) return null

  return (
    <span className="flex items-center gap-1 text-muted-foreground">
      <TimerReset className="size-3" aria-hidden />
      تایید خودکار بعد از {formatDuration(minutes)}
    </span>
  )
}
