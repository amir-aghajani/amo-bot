import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { BalanceAdjust } from '@/components/balance-adjust'
import { ConfirmModal } from '@/components/confirm-modal'
import { GRANT_TERMS, GrantTermsFields } from '@/components/grants/grant-terms'
import { LEDGER_NOTE_MAX } from '@/components/note-input'
import { providers } from '@/test/render'

/*
 * A note the customer reads (components/note-input) — on every "are you sure" that takes one, every operation's strip,
 * a grant's reason, a ledger's line set right by hand: held to what the server takes for that note
 * (App\Support\Input::NOTE_MAX, a ledger line's App\Core\Database\Ledger::NOTE_MAX), with how much of it is used under
 * it, so a long note is caught as it is typed, not refused after it is sent.
 */

function Rejecting({ onConfirm }: { onConfirm: (note: string) => void }) {
  const [open, setOpen] = useState(true)
  return (
    <ConfirmModal
      open={open}
      onClose={() => setOpen(false)}
      title="رد درخواست"
      note={{ label: 'توضیح برای مشتری', placeholder: 'اختیاری' }}
      confirmLabel="رد درخواست"
      destructive
      onConfirm={onConfirm}
    />
  )
}

describe('a decision’s note', () => {
  it('is held to what the server takes, and says how much of it is used', () => {
    const onConfirm = vi.fn()
    render(<Rejecting onConfirm={onConfirm} />)
    const note = screen.getByLabelText('توضیح برای مشتری')

    expect(note.getAttribute('maxlength')).toBe('300')
    expect(screen.getByText('۰ از ۳۰۰ کاراکتر')).toBeTruthy()

    fireEvent.change(note, { target: { value: '  مبلغ رسید با سفارش نمی‌خواند  ' } })

    expect(screen.getByText('۳۲ از ۳۰۰ کاراکتر')).toBeTruthy()
    expect(note.getAttribute('aria-describedby')).toBe(screen.getByText('۳۲ از ۳۰۰ کاراکتر').id)

    fireEvent.click(screen.getByRole('button', { name: 'رد درخواست' }))
    expect(onConfirm).toHaveBeenCalledWith('مبلغ رسید با سفارش نمی‌خواند')
  })

  it('says so once it is full', () => {
    render(<Rejecting onConfirm={vi.fn()} />)

    fireEvent.change(screen.getByLabelText('توضیح برای مشتری'), { target: { value: 'ن'.repeat(300) } })

    expect(screen.getByText('۳۰۰ از ۳۰۰ کاراکتر').className).toContain('text-warning')
  })
})

describe('a grant’s reason', () => {
  it('is a note like a decision’s, held and counted', () => {
    render(<GrantTermsFields values={GRANT_TERMS} change={vi.fn()} error={() => undefined} reach={{ running: 3, unstarted: 0 }} reasonPlaceholder="مثلا: جبران قطعی" />)
    const reason = screen.getByLabelText(/دلیل/)

    expect(reason.getAttribute('maxlength')).toBe('300')
    expect(reason.getAttribute('aria-describedby')).toContain(screen.getByText('۰ از ۳۰۰ کاراکتر').id)
  })
})

describe('a ledger line’s note', () => {
  it('is one line, held to the line’s own limit — a wallet’s, an agent’s traffic’s —, and counted', () => {
    expect(LEDGER_NOTE_MAX).toBe(190)
    render(
      <BalanceAdjust
        balanceLabel="موجودی فعلی"
        balance="۲۵٬۰۰۰ تومان"
        fields={{ amount: 'amount', note: 'description' }}
        amountLabel="مبلغ (تومان)"
        amountPlaceholder="50000"
        noteHint="مشتری این متن را در تاریخچه کیف پولش می‌بیند."
        notePlaceholders={{ add: 'مثلا: هدیه', remove: 'مثلا: اصلاح اشتباه' }}
        submitLabels={{ add: 'افزایش موجودی', remove: 'کاهش موجودی' }}
        send={vi.fn()}
        onDone={vi.fn()}
      />,
      { wrapper: providers().wrapper },
    )
    const note = screen.getByLabelText(/توضیح/)

    expect(note.tagName).toBe('INPUT')
    expect(note.getAttribute('maxlength')).toBe('190')
    fireEvent.change(note, { target: { value: 'هدیه شروع کار' } })
    expect(screen.getByText('۱۳ از ۱۹۰ کاراکتر').id).toBe(note.getAttribute('aria-describedby')?.split(' ').at(-1))
  })
})
