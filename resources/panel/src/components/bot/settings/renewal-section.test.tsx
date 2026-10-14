import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { RenewalSection } from '@/components/bot/settings/renewal-section'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «تمدید سرویس» in the bot settings (components/bot/settings/renewal-section): the traffic left, the window a customer
 * may renew in («آستانه مجاز تمدید», each number's hint saying what it comes to) and the renewal methods — a switch each,
 * explained, and automatic renewal's method among those on —, saved as one PUT of the group as typed; a refusal lands
 * under its field.
 */

const PATH = '/api/admin/bot/settings/renewal'

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData = botSettingsRow()) {
  render(<RenewalSection settings={settings} />, { wrapper: signedIn().wrapper })
  await screen.findByLabelText('روز مانده به پایان')
}

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
const toggle = (name: string) => fireEvent.click(screen.getByRole('switch', { name }))
const method = () => screen.getByRole('combobox', { name: 'روش تمدید خودکار' })
/** The renewal card's «ذخیره» (the automatic renewal card has its own after it). */
const save = () => fireEvent.click(screen.getAllByRole('button', { name: 'ذخیره' })[0]!)

/** The option `option` picked in automatic renewal's select, once its options are there. */
async function pick(option: string) {
  fireEvent.keyDown(method(), { key: 'Enter' })
  fireEvent.click(await screen.findByRole('option', { name: option }))
}

describe('the renewal rules', () => {
  it('says what each number of the window comes to: so near the end, so little traffic, never by it, any time, or the bounds', async () => {
    await show()

    expect(screen.getAllByText('با هر دو 0، مشتری هر وقت بخواهد می‌تواند تمدید کند.')).toHaveLength(2)
    type('روز مانده به پایان', '۵')
    expect(screen.getByText('از ۵ روز مانده به پایان سرویس، مشتری می‌تواند آن را تمدید کند.')).toBeTruthy()
    expect(screen.getByText('0: حجم مانده تمدید را باز نمی‌کند؛ فقط روزهای مانده بازش می‌کند.')).toBeTruthy()
    type('روز مانده به پایان', '400')
    expect(screen.getByText('بین 0 تا 365 روز؛ 0 یعنی بدون این شرط.')).toBeTruthy()
    type('درصد حجم مانده', '20')
    expect(screen.getByText('وقتی ۲۰٪ از حجم سرویس یا کمتر مانده باشد، مشتری می‌تواند آن را تمدید کند.')).toBeTruthy()
    type('روز مانده به پایان', '0')
    expect(screen.getByText('0: روزهای مانده تمدید را باز نمی‌کند؛ فقط درصد حجم مانده بازش می‌کند.')).toBeTruthy()
    type('درصد حجم مانده', '101')
    expect(screen.getByText('بین 0 تا 100 درصد؛ 0 یعنی بدون این شرط.')).toBeTruthy()
  })

  it('explains each method and offers automatic renewal only those on', async () => {
    await show()

    expect(screen.getByText('روزهای پلن به روزهای مانده اضافه می‌شود و حجم از نو با حجم پلن شمرده می‌شود؛ حجم مانده از بین می‌رود.')).toBeTruthy()
    expect(screen.getByRole('switch', { name: 'تمدید پلن فعلی' }).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByRole('switch', { name: 'ریست زمان + انتقال حجم' }).getAttribute('aria-checked')).toBe('false')
    expect(method().textContent).toBe('تمدید پلن فعلی')

    toggle('ریست زمان + انتقال حجم')
    fireEvent.keyDown(method(), { key: 'Enter' })
    expect((await screen.findAllByRole('option')).map((option) => option.textContent)).toEqual(['تمدید پلن فعلی', 'ریست زمان + انتقال حجم'])
  })

  it('says under automatic renewal’s method when a reset of the time renews: the last half hour, a short wallet told days ahead', async () => {
    await show()

    expect(screen.getByText(/با «ریست زمان \+ انتقال حجم» تمدید تا آخرین نیم‌ساعت پیش از پایان سرویس صبر می‌کند/)).toBeTruthy()
    expect(screen.getByText(/مشتری همچنان از «چند روز پیش از پایان سرویس» خبردار می‌شود/)).toBeTruthy()
  })

  it('moves automatic renewal to the first method still on when its own is switched off', async () => {
    await show(botSettingsRow({ renew_reset_traffic: true, renew_reset_time: true, renew_default_method: 'reset_time' }))
    expect(method().textContent).toBe('ریست زمان + انتقال حجم')

    toggle('ریست زمان + انتقال حجم')

    expect(method().textContent).toBe('تمدید پلن فعلی')
  })

  it('gives automatic renewal the method switched on when every method was off — and sends it, never a blank one', async () => {
    server().on('PUT', PATH, json({ settings: botSettingsRow({ renew_extend: false, renew_reset_time: true, renew_default_method: 'reset_time' }) } satisfies BotSettingsSaved))
    await show()

    toggle('تمدید پلن فعلی')
    toggle('ریست زمان + انتقال حجم')
    save()

    expect(method().textContent).toBe('ریست زمان + انتقال حجم')
    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toMatchObject({ renew_extend: false, renew_reset_time: true, renew_default_method: 'reset_time' })
  })

  it('is saved as the group’s PUT: the window as typed and the methods', async () => {
    const saved: BotSettingsSaved = { settings: botSettingsRow({ renew_window_days: 5, renew_window_percent: 20, renew_reset_traffic: true, renew_default_method: 'reset_traffic' }) }
    server().on('PUT', PATH, json(saved))
    await show()

    type('روز مانده به پایان', '۵')
    type('درصد حجم مانده', '20')
    toggle('ریست حجم + افزایش روز')
    await pick('ریست حجم + افزایش روز')
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({
      carry_traffic: false,
      renew_window_days: '۵',
      renew_window_percent: '20',
      renew_extend: true,
      renew_reset_traffic: true,
      renew_reset_time: false,
      renew_default_method: 'reset_traffic',
    })
  })

  it('shows a refusal under its field', async () => {
    const refused = 'دست‌کم یک روش تمدید را روشن کنید.'
    server().on('PUT', PATH, refusal(422, refused, { renew_extend: [refused] }))
    await show()

    toggle('تمدید پلن فعلی')
    save()

    expect(await screen.findByText(refused)).toBeTruthy()
  })
})
