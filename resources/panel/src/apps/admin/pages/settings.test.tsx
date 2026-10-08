import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SettingsPage } from '@/apps/admin/pages/settings'
import { APPEARANCE_DESCRIPTION } from '@/components/appearance-card'
import { queryKeys } from '@/lib/query-keys'
import { ThemeProvider } from '@/lib/theme'
import { signedIn, until } from '@/test/render'
import { refusal, server } from '@/test/server'

/*
 * The owner's «تنظیمات پنل» (apps/admin/pages/settings): config.php's sections are drawn from the file's read; the
 * owner's login and the panel's look need none of it — a read that failed is said on the sections it keeps from
 * drawing, not on theirs.
 */

/** The page at `at`, config.php's read refused by the server. */
async function show(at: string) {
  server().on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
  const { client, wrapper } = signedIn({ at })
  render(
    <ThemeProvider>
      <SettingsPage />
    </ThemeProvider>,
    { wrapper },
  )
  await until(() => expect(client.getQueryState(queryKeys.configSettings)?.status).toBe('error'))
}

describe('the panel’s settings, config.php unread', () => {
  it('say so on a section of the file', async () => {
    await show('/settings/telegram')

    expect(screen.getByText('تنظیمات بارگذاری نشد.')).toBeTruthy()
  })

  it('say so on the shop’s email, a section of the file too — its test send with it', async () => {
    await show('/settings/mail')

    expect(screen.getByText('تنظیمات بارگذاری نشد.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'ارسال' })).toBeNull()
  })

  it('offer the owner’s login all the same', async () => {
    await show('/settings/login')

    expect(screen.getByLabelText('رمز عبور فعلی')).toBeTruthy()
    expect(screen.queryByText('تنظیمات بارگذاری نشد.')).toBeNull()
  })

  it('offer the panel’s look, said to be kept in the browser rather than the file', async () => {
    await show('/settings/appearance')

    expect(screen.getByRole('radiogroup', { name: 'تم پنل' })).toBeTruthy()
    expect(screen.getByText(APPEARANCE_DESCRIPTION)).toBeTruthy()
    expect(screen.queryByText('تنظیمات بارگذاری نشد.')).toBeNull()
  })
})
