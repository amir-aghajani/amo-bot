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
 * Adds a payment method in two steps: pick a driver, then fill its form (drawn from the driver's description). Built-in
 * drivers (the wallet) are listed but cannot be picked: every shop already has one.
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
