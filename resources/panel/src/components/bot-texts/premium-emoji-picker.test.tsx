import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { PremiumEmojiPicker } from '@/components/bot-texts/premium-emoji-picker'
import type { CustomEmojiRow, CustomEmojisResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, noContent, refusal, server } from '@/test/server'

/*
 * The premium emoji picker (components/bot-texts/premium-emoji-picker): a cross takes an emoji off the list — held while
 * it runs, the focus staying on it — and, as the emoji leaves, hands the focus to the emoji after it, never to the page.
 */

const emoji = (id: string, plain: string): CustomEmojiRow => ({ id, emoji: plain, format: 'static', repaint: false, updated_at: '2026-10-06T09:00:00Z' })

describe('the list’s read', () => {
  it('failed, stays said while it is asked again — its button spinning, asking once —, until the answer', async () => {
    server().on('GET', '/api/admin/bot/custom-emojis', refusal(500, 'خطای سرور.'))
    render(<PremiumEmojiPicker onPick={vi.fn()} />, { wrapper: providers().wrapper })
    const retry = await screen.findByRole('button', { name: 'تلاش دوباره' })
    const again = server().hold('GET', '/api/admin/bot/custom-emojis')

    fireEvent.click(retry)
    await until(() => expect(retry.getAttribute('aria-busy')).toBe('true'))
    expect(screen.getByText('ایموجی‌های پرمیوم بارگذاری نشد.')).toBeTruthy()
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0)
    fireEvent.click(retry)
    expect(server().sent('GET', '/api/admin/bot/custom-emojis')).toHaveLength(2)

    again.answer(json({ emojis: [emoji('41', '🔥')], status: null } satisfies CustomEmojisResponse))
    expect(await screen.findByRole('button', { name: 'افزودن ایموجی پرمیوم 🔥' })).toBeTruthy()
  })
})

describe('taking an emoji off the list', () => {
  it('holds the cross while it runs, then hands the focus to the emoji after it', async () => {
    server().on('GET', '/api/admin/bot/custom-emojis', json({ emojis: [emoji('41', '🔥'), emoji('42', '🎁')], status: null } satisfies CustomEmojisResponse))
    const removal = server().hold('DELETE', '/api/admin/bot/custom-emojis/41')
    render(<PremiumEmojiPicker onPick={vi.fn()} />, { wrapper: providers().wrapper })
    const cross = await screen.findByRole('button', { name: 'برداشتن 🔥 از لیست' })

    cross.focus()
    fireEvent.click(cross)
    await until(() => expect(removal.waiting).toBe(1))
    expect([(cross as HTMLButtonElement).disabled, cross.getAttribute('aria-disabled')]).toEqual([false, 'true'])
    fireEvent.click(cross)
    expect(server().sent('DELETE', '/api/admin/bot/custom-emojis/41')).toHaveLength(1)

    removal.answer(noContent)
    await until(() => expect(screen.queryByRole('button', { name: 'برداشتن 🔥 از لیست' })).toBeNull())
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'افزودن ایموجی پرمیوم 🎁' }))
  })
})
