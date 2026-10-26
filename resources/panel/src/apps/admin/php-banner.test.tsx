import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PhpBanner } from '@/apps/admin/php-banner'
import type { SystemResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The owner's strip over every page (apps/admin/php-banner): while the host's PHP has its branch's known security holes
 * open, it names the release to move to and that no update is installed meanwhile; on a PHP without them, no strip.
 */

function system(php: string, safe: boolean, required: string): SystemResponse {
  return {
    system: {
      version: '0.7.1',
      php,
      php_safe: safe,
      php_required: required,
      bot: { id: 1, configured: true, enabled: true, username: 'amo_bot', mode: 'polling', last_update_at: null },
      main_bot: { configured: true, enabled: true, username: 'amo_bot', mode: 'polling', last_update_at: null },
      cron: { last_run_at: null, tasks: 9 },
    },
  }
}

function open(response: SystemResponse) {
  server().on('GET', '/api/admin/system', json(response))
  const { client, wrapper } = providers()
  render(<PhpBanner />, { wrapper })
  return client
}

describe('the PHP strip', () => {
  it('names the host’s PHP, the release to move to, and that no update is installed meanwhile', async () => {
    open(system('8.3.10', false, '8.3.36'))

    const strip = await screen.findByText(/حفره امنیتی جدی دارد/)
    expect(strip.textContent).toContain('PHP این هاست (8.3.10) حفره امنیتی جدی دارد.')
    expect(strip.textContent).toContain('به PHP 8.3.36 یا بالاتر ببرید')
    expect(strip.textContent).toContain('تا آن وقت به‌روزرسانی فروشگاه نصب نمی‌شود.')
    expect(strip.closest('[role="status"]')).toBeTruthy()
    expect([...strip.querySelectorAll('bdi[dir="ltr"]')].map((bdi) => bdi.textContent)).toEqual(['8.3.10', '8.3.36'])
  })

  it('is not there on a PHP without the holes', async () => {
    const client = open(system('8.3.36', true, '8.3.36'))

    await until(() => expect(client.getQueryState(queryKeys.system)?.status).toBe('success'))
    expect(screen.queryByText(/حفره امنیتی/)).toBeNull()
  })
})
