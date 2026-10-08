import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { InfoBadge, InfoTip } from '@/components/info-tip'
import { Modal } from '@/components/modal'
import { until } from '@/test/render'

/*
 * What the panel explains behind a press (components/info-tip): a tap or a click opens it — never a hover alone, which a
 * phone does not have —, Escape closes it with the focus back where it was; in a dialog it opens inside the dialog, above
 * its backdrop.
 */

const REVENUE = 'پولی که در این بازه واقعا وارد شد.'

describe('an ⓘ', () => {
  it('opens its explanation on a press, and Escape closes it with the focus back on the ⓘ', async () => {
    render(<InfoTip label="درباره درآمد">{REVENUE}</InfoTip>)
    const tip = screen.getByRole('button', { name: 'درباره درآمد' })
    expect(screen.queryByText(REVENUE)).toBeNull()

    fireEvent.click(tip)

    const explanation = await screen.findByRole('dialog')
    expect(explanation.textContent).toBe(REVENUE)
    expect(tip.getAttribute('aria-expanded')).toBe('true')

    fireEvent.keyDown(explanation, { key: 'Escape' })
    await until(() => expect(screen.queryByRole('dialog')).toBeNull())
    await until(() => expect(document.activeElement).toBe(tip))
  })

  it('opens inside the dialog it is in, where the dialog’s backdrop does not cover it', async () => {
    render(
      <Modal open onClose={() => undefined} title="ویرایش پلن">
        <InfoTip label="درباره قیمت">قیمتی که مشتری می‌پردازد.</InfoTip>
      </Modal>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'درباره قیمت' }))

    const explanation = await screen.findByText('قیمتی که مشتری می‌پردازد.')
    expect(explanation.closest('dialog')).toBe(screen.getByRole('dialog', { name: 'ویرایش پلن' }))
  })
})

describe('a badge that says more', () => {
  it('is a button named by its words, and a press shows what it means', async () => {
    render(
      <InfoBadge variant="warning" info="سرور غیرفعال است.">
        آلمان ۲
      </InfoBadge>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'آلمان ۲' }))

    expect((await screen.findByRole('dialog')).textContent).toBe('سرور غیرفعال است.')
  })
})
