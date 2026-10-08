import type { GatewayKind } from '@/lib/api-types'

/** How a method settles, as the list and the picker word it. */
export const KIND_LABELS: Record<GatewayKind, string> = {
  instant: 'تسویه آنی',
  manual: 'تایید دستی',
}

/** The label of a row whose driver may be gone (null kind). */
export function kindLabel(kind: GatewayKind | null): string {
  return kind ? KIND_LABELS[kind] : 'درایور نصب نیست'
}
