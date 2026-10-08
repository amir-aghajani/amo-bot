import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ButtonModal } from '@/components/keyboards/button-modal'
import type { CustomEmojisResponse, KeyboardAction } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * A keyboard button's dialog (components/keyboards/button-modal): a premium emoji pasted into the label moves to the
 * button's icon, and the plain emoji its tag held goes with it — what the panel shows in its place while its picture
 * cannot be had, even when the bot's list does not keep that emoji.
 */

const ACTIONS: KeyboardAction[] = [{ key: 'buy', title: 'خرید', label: '🛍️ خرید', screen: 'menu:buy' }]

describe('a premium emoji pasted into a label', () => {
  it('becomes the icon, its plain emoji kept to stand in for it', async () => {
    server().on('GET', '/api/admin/bot/custom-emojis', json({ emojis: [], status: null } satisfies CustomEmojisResponse))
    render(
      <ButtonModal
        open
        onClose={vi.fn()}
        actions={ACTIONS}
        styles={['primary', 'success', 'danger']}
        taken={['buy']}
        button={{ action: 'buy', label: '<tg-emoji emoji-id="5368324170671202286">🛒</tg-emoji> خرید', style: null, icon: null }}
        labelMax={64}
        onSave={vi.fn()}
      />,
      { wrapper: providers().wrapper },
    )
    await until(() => expect(server().sent('GET', '/api/admin/bot/custom-emojis')).toHaveLength(1))

    fireEvent.click(screen.getByRole('button', { name: 'انتقال به آیکون دکمه' }))

    const dialog = within(document.querySelector<HTMLElement>('dialog[open]') ?? document.body)
    expect((dialog.getByLabelText('متن دکمه') as HTMLInputElement).value).toBe('خرید')
    expect(dialog.getByAltText('🛒')).toBeTruthy()
    expect(dialog.queryByAltText('✨')).toBeNull()
  })
})
