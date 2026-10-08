import { Fragment } from 'react'

/** What a driver's line about a method (`summary`) holds apart with « · »: a masked card, its holder. */
const SUMMARY_PARTS = ' · '

/** No letter of any script: a card's digits and bullets — an identifier, set apart left to right in the mono. */
const NUMBERS_ONLY = /^[^\p{L}]+$/u

/**
 * A payment method's line in its driver's words (GatewayDriver::summary(), on the methods list and on a payment) —
 * "6037 •••• •••• 1119 · امیر رضایی" —, each part in its own direction: the masked card left to right in the mono, the
 * holder's name as written.
 */
export function MethodSummary({ summary, className }: { summary: string; className?: string }) {
  return (
    <span className={className}>
      {summary.split(SUMMARY_PARTS).map((part, index) => (
        <Fragment key={index}>
          {index > 0 && SUMMARY_PARTS}
          {NUMBERS_ONLY.test(part) ? <bdi dir="ltr">{part}</bdi> : <bdi>{part}</bdi>}
        </Fragment>
      ))}
    </span>
  )
}
