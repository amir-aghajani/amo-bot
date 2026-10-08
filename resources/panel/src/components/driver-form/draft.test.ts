import { describe, expect, it } from 'vitest'
import { driverDraft, driverPayload, leftBehind, shownFields } from '@/components/driver-form/draft'
import type { DriverDescription, FieldDescription } from '@/lib/api-types'

/*
 * A driver's form as the panel holds it (components/driver-form/draft): started from what the server keeps, else the
 * defaults; a field shown only while the ones it names hold a value it lists; sent as typed, a secret only when typed;
 * and a kept secret bound to an address that moved, said before the server refuses it.
 */

function field(name: string, overrides: Partial<FieldDescription> = {}): FieldDescription {
  return {
    name,
    type: 'text',
    label: name,
    hint: null,
    placeholder: null,
    required: false,
    secret: false,
    bound_to: [],
    moved: null,
    advanced: false,
    options: [],
    when: {},
    unit: null,
    min: null,
    max: null,
    ltr: false,
    default: '',
    ...overrides,
  }
}

const SMTP: DriverDescription = {
  key: 'smtp',
  label: 'SMTP',
  description: '',
  notes: [],
  traits: {},
  fields: [
    field('host', { default: '' }),
    field('port', { type: 'number', default: 587 }),
    field('encryption', { type: 'choice', default: 'tls' }),
    field('auth', { type: 'toggle', default: false }),
    field('username', { when: { auth: ['true'] } }),
    field('password', { type: 'secret', secret: true, default: null, bound_to: ['host', 'port', 'username'], moved: 'سرور عوض شد.', when: { auth: ['true'] } }),
    field('presets', { type: 'list', default: [50, 100] }),
  ],
}

const KEPT = { host: 'mail.example.com', port: 465, encryption: 'ssl', auth: true, username: 'me', password: { set: true, hint: '••••' }, presets: [10] }

describe('a driver’s form', () => {
  it('starts from what the server keeps, else the defaults — a list typed out, every secret blank', () => {
    expect(driverDraft(SMTP)).toEqual({ host: '', port: '587', encryption: 'tls', auth: false, username: '', password: '', clear_password: false, presets: '50, 100' })
    expect(driverDraft(SMTP, KEPT)).toEqual({ host: 'mail.example.com', port: '465', encryption: 'ssl', auth: true, username: 'me', password: '', clear_password: false, presets: '10' })
  })

  it('shows a field only while the ones it names, shown, hold a value it lists', () => {
    const names = (draft: Record<string, string | boolean>) => shownFields(SMTP, draft).map((shown) => shown.name)

    expect(names(driverDraft(SMTP))).toEqual(['host', 'port', 'encryption', 'auth', 'presets'])
    expect(names({ ...driverDraft(SMTP), auth: true })).toEqual(['host', 'port', 'encryption', 'auth', 'username', 'password', 'presets'])
  })

  it('sends what is shown as typed — a secret only when typed, beside its clear flag', () => {
    expect(driverPayload(SMTP, { ...driverDraft(SMTP, KEPT), auth: false })).toEqual({ host: 'mail.example.com', port: '465', encryption: 'ssl', auth: false, presets: '10' })
    expect(driverPayload(SMTP, driverDraft(SMTP, KEPT))).toEqual({ host: 'mail.example.com', port: '465', encryption: 'ssl', auth: true, username: 'me', clear_password: false, presets: '10' })
    expect(driverPayload(SMTP, { ...driverDraft(SMTP, KEPT), password: 'new one' })).toMatchObject({ password: 'new one', clear_password: false })
    expect(driverPayload(SMTP, { ...driverDraft(SMTP, KEPT), clear_password: true })).toMatchObject({ clear_password: true })
  })

  it('says a kept secret would stay behind while a field it belongs with moved, read as the server reads it', () => {
    const password = SMTP.fields[5] as FieldDescription
    const draft = driverDraft(SMTP, KEPT)

    expect(leftBehind(SMTP, password, draft, KEPT)).toBe(false)
    expect(leftBehind(SMTP, password, { ...draft, port: '۴۶۵' }, KEPT)).toBe(false)
    expect(leftBehind(SMTP, password, { ...draft, host: 'smtp.elsewhere.example' }, KEPT)).toBe(true)
    expect(leftBehind(SMTP, password, { ...draft, username: 'other' }, KEPT)).toBe(true)
    expect(leftBehind(SMTP, password, { ...draft, host: 'smtp.elsewhere.example', password: 'typed' }, KEPT)).toBe(false)
    expect(leftBehind(SMTP, password, { ...draft, host: 'smtp.elsewhere.example', clear_password: true }, KEPT)).toBe(false)
    expect(leftBehind(SMTP, password, { ...draft, host: 'smtp.elsewhere.example' }, { ...KEPT, password: { set: false, hint: '' } })).toBe(false)
    expect(leftBehind(SMTP, password, { ...draft, host: 'smtp.elsewhere.example' })).toBe(false)
  })

  it('reads a web address a secret belongs with by its origin', () => {
    const panel: DriverDescription = {
      ...SMTP,
      fields: [field('base_url', { type: 'url' }), field('token', { type: 'secret', secret: true, default: null, bound_to: ['base_url'], moved: 'x' })],
    }
    const token = panel.fields[1] as FieldDescription
    const kept = { base_url: 'https://panel.example.com:2053/path', token: { set: true, hint: '••••' } }

    expect(leftBehind(panel, token, { base_url: 'https://panel.example.com:2053/other', token: '', clear_token: false }, kept)).toBe(false)
    expect(leftBehind(panel, token, { base_url: 'https://evil.example', token: '', clear_token: false }, kept)).toBe(true)
  })
})
