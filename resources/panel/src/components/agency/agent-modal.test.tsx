import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AgentModal } from '@/components/agency/agent-modal'
import type { AgencyLevelsResponse, AgentChangedResponse, AgentRow } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * An agent's level and credit (components/agency/agent-modal): saved, the toast says whether the agent was told their new
 * terms — and terms the server finds as they were tell the agent nothing, which the toast says too. Nothing changed,
 * there is nothing to save: the save waits for a change.
 */

/** Agent 21 «رضا» on level «طلایی», without credit. */
const REZA: AgentRow = {
  id: 21,
  user: { id: 21, name: 'رضا', username: 'reza', telegram_id: 555_000, email: null },
  status: 'active',
  level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
  credit_limit: '0.00',
  balance: '0.00',
  bot: null,
  counts: { customers: 0, sold: 0, active: 0 },
}

/** The agent's dialog, its credit typed as `credit`, saved — the server answering with `delivery`. */
function save(credit: string, delivery: AgentChangedResponse['delivery']) {
  server()
    .on(
      'GET',
      '/api/admin/agency/levels',
      json({
        levels: [{ id: 1, name: 'طلایی', price_per_gb: '3000.00', sort: 1, counts: { agents: 1 }, created_at: '2026-09-01T10:00:00Z', updated_at: '2026-09-01T10:00:00Z' }],
      } satisfies AgencyLevelsResponse),
    )
    .on('PUT', '/api/admin/agency/agents/21', json({ agent: REZA, delivery } satisfies AgentChangedResponse))
  render(<AgentModal agent={REZA} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })
  fireEvent.change(screen.getByLabelText('اعتبار (تومان)'), { target: { value: credit } })
  fireEvent.click(screen.getByRole('button', { name: 'ذخیره تغییرات' }))
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
  vi.spyOn(toast, 'warning')
})

describe('an agent’s terms saved', () => {
  it('say the agent was told', async () => {
    save('500000', 'told')
    await until(() => expect(toast.success).toHaveBeenCalledWith('نمایندگی به‌روز شد و به نماینده خبر داده شد'))
  })

  it('as the server finds they were, say nothing changed — the agent was told nothing', async () => {
    // Typed in the API's own form: other text to the panel, the same credit to the server.
    save('0.00', null)
    await until(() => expect(toast.success).toHaveBeenCalledWith('سطح و اعتبار تغییری نکرد'))
    expect(toast.warning).not.toHaveBeenCalled()
  })

  it('wait for a change: as they were, and once a change is typed back, there is nothing to save nor to revert', () => {
    save('0', 'told')
    const submit = screen.getByRole('button', { name: 'ذخیره تغییرات' })
    expect(submit.getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByRole('button', { name: 'بازگردانی تغییرات' }).getAttribute('aria-disabled')).toBe('true')
    expect(server().sent('PUT', '/api/admin/agency/agents/21')).toEqual([])
  })
})
