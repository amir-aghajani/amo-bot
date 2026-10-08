import { gatewayIcon, gatewayKind } from '@/components/payments/gateways'
import { MethodForm } from '@/components/payments/gateways/method-form'
import { KIND_LABELS } from '@/components/payments/kinds'
import { PickerModal } from '@/components/picker-card'
import { Badge } from '@/components/ui/badge'
import type { PaymentMethodRow } from '@/lib/api-types'
import { paymentDriversQuery } from '@/lib/queries'

interface AddMethodModalProps {
  open: boolean
  onClose: () => void
  onCreated: (method: PaymentMethodRow) => void
}

/**
 * Two steps: pick a driver (card-to-card today, online gateways later), then fill its form — drawn from its description
 * (gateways/method-form). Built-in drivers (the wallet) are listed for completeness but cannot be added — the shop
 * already has them.
 */
export function AddMethodModal({ open, onClose, onCreated }: AddMethodModalProps) {
  return (
    <PickerModal
      open={open}
      onClose={onClose}
      title="افزودن روش پرداخت"
      question="مشتری چطور پرداخت می‌کند؟"
      options={paymentDriversQuery}
      card={(driver) => {
        const Icon = gatewayIcon(driver.key)
        const kind = gatewayKind(driver)
        return {
          mark: Icon ? <Icon className="size-5" /> : driver.label.slice(0, 2),
          title: <span className="text-body font-medium">{driver.label}</span>,
          meta: kind && <Badge variant="outline">{KIND_LABELS[kind]}</Badge>,
          disabledReason: driver.traits.builtin === true ? 'داخلی؛ همیشه هست' : undefined,
        }
      }}
      form={{
        title: (driver) => `افزودن ${driver.label}`,
        description: (driver) => driver.description,
        // Only a driver that is not built in can be picked (the card above is disabled otherwise).
        render: (driver) => <MethodForm driver={driver} onSaved={onCreated} onCancel={onClose} />,
      }}
    />
  )
}
