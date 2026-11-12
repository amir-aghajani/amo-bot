import type { ReactNode } from 'react'

/*
 * A step of paying card to card — «۱. واریز به کارت», «۲. ارسال رسید» —: one card, its number in a circle (28px, the
 * digit on its centre line) and its title at its top, what the step needs under them, and a note under the card.
 */

interface StepProps {
  /** Its number, in Persian digits («۱»). */
  number: string
  title: string
  /** A note under the card. */
  footer?: ReactNode
  children: ReactNode
}

export function Step({ number, title, footer, children }: StepProps) {
  return (
    <section className="flex flex-col gap-2">
      <div className="card flex flex-col gap-4 p-4">
        <h2 aria-label={`${number}. ${title}`} className="flex items-center gap-3 text-heading font-bold">
          <span aria-hidden className="bg-accent text-on-accent inline-flex size-7 shrink-0 items-center justify-center rounded-full text-footnote leading-none font-bold">
            {number}
          </span>
          {title}
        </h2>
        {children}
      </div>
      {footer !== undefined && <p className="text-hint px-4 text-caption">{footer}</p>}
    </section>
  )
}
