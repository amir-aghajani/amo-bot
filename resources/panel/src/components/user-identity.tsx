import type { ComponentProps, ReactNode } from 'react'
import { ExternalLink } from 'lucide-react'
import { Link, useLocation } from 'react-router'
import { Fact } from '@/components/fact-list'
import { StatusBadge } from '@/components/status-badge'
import { externalHref, TextLink } from '@/components/text-link'
import type { UserRef, UserStatus } from '@/lib/api-types'
import { handleLabel, isolate } from '@/lib/direction'
import { initials } from '@/lib/format'
import { USER_STATUS } from '@/lib/statuses'
import { cn } from '@/lib/utils'

const NO_NAME = 'بدون نام'
const NO_USERNAME = 'بدون نام کاربری'
const NO_EMAIL = 'بدون ایمیل'

/** A customer's account identifier: the Telegram id, else (a website-only customer) the email, else «#id». Not isolated. */
function account(user: UserRef): string {
  return user.telegram_id !== null ? String(user.telegram_id) : (user.email ?? `#${user.id}`)
}

/** Where only one identifier fits: the name, else the handle, else the account identifier. Not isolated. */
function oneLine(user: UserRef): string {
  return user.name ?? (user.username ? `@${user.username}` : account(user))
}

/** The one-line label in a plain string (a toast, a dialog's title, the tab's title), isolated so «@amir» stays whole. */
export function userLabel(user: UserRef): string {
  return isolate(oneLine(user))
}

/** The one-line label in markup (a narrow cell, a filter pill), in a `<bdi>`. */
export function UserLabel({ user }: { user: UserRef }) {
  return <bdi>{oneLine(user)}</bdi>
}

/**
 * A label that tells a customer from every other — the name with the handle or the account identifier — for a control
 * each of many rows carries (its ⋮ menu): two customers of the same name get two different names.
 */
export function distinctUserLabel(user: UserRef): string {
  const handle = user.username ? handleLabel(user.username) : isolate(account(user))
  return user.name ? `${isolate(user.name)} (${handle})` : handle
}

/**
 * The customer's Telegram chat: their public handle's t.me address, else the tg:// id link only the apps understand;
 * null for a customer without a Telegram account.
 */
function telegramLink(user: UserRef): string | null {
  if (user.telegram_id === null) return null
  return externalHref(user.username ? `https://t.me/${encodeURIComponent(user.username)}` : `tg://user?id=${user.telegram_id}`)
}

/**
 * «گفتگو در تلگرام», the only way to a customer's chat (a dialog's foot, their page, a row's menu — styled by the place
 * through `asChild`); nothing for a customer without a Telegram account.
 */
export function TelegramChatLink({ user, ...props }: { user: UserRef } & Omit<ComponentProps<'a'>, 'href' | 'children'>) {
  const href = telegramLink(user)
  if (href === null) return null

  return (
    <a {...props} href={href} target="_blank" rel="noreferrer">
      <ExternalLink aria-hidden />
      گفتگو در تلگرام
    </a>
  )
}

/** A customer's page, in both panels. */
export function userPage(id: number): string {
  return `/users/${id}`
}

/** A person's initials in a small rounded square (the Console's account mark). */
export function InitialsMark({ name, className }: { name: string; className?: string }) {
  return (
    <span aria-hidden className={cn('flex size-8 shrink-0 items-center justify-center rounded-lg bg-fill-hover text-caption font-semibold text-muted-foreground', className)}>
      {initials(name) || '#'}
    </span>
  )
}

/**
 * The line under a customer's name: the handle ("@username", or "بدون نام کاربری"); for one without a Telegram account
 * their email in its place ("بدون ایمیل" without one) — set apart, left to right.
 */
function SecondLine({ user }: { user: UserRef }) {
  if (user.username) return <bdi dir="ltr">@{user.username}</bdi>
  if (user.telegram_id !== null) return NO_USERNAME
  return user.email ? <bdi dir="ltr">{user.email}</bdi> : NO_EMAIL
}

interface UserIdentityProps {
  user: UserRef
  avatar?: boolean
  /** The account's status: a banned one is marked beside the name. */
  status?: UserStatus
  /** Sits at the end of the name line (the bot admin's «مدیر» mark). */
  badge?: ReactNode
  /** Where the name leads: the customer's page (`userPage`). */
  to?: string
  className?: string
}

/**
 * Two fixed lines, so rows line up whatever the account has: the name line (the name, or "بدون نام" in tertiary ink)
 * and the handle line ("@username", or "بدون نام کاربری" — the email, for a customer without Telegram). The numeric id
 * is never here — it gets its own column. With `to`, the name is the link to the customer's page.
 */
export function UserIdentity({ user, avatar = false, status, badge, to, className }: UserIdentityProps) {
  const name = user.name ? <bdi className="truncate font-medium text-foreground">{user.name}</bdi> : <span className="text-faint">{NO_NAME}</span>

  return (
    <div className={cn('flex min-w-0 items-center gap-2.5', className)}>
      {avatar && <InitialsMark name={user.name ?? user.username ?? user.email ?? ''} />}
      <div className="grid max-w-64 min-w-32 gap-0">
        <div className="flex min-w-0 items-center gap-1.5 leading-[1.375rem]">
          {to ? (
            <Link to={to} className="flex min-w-0 rounded-sm underline-offset-4 outline-none hover:underline focus-visible:focus-ring">
              {name}
            </Link>
          ) : (
            name
          )}
          {status === 'banned' && <StatusBadge status={USER_STATUS.banned} />}
          {badge}
        </div>
        <div className="truncate text-footnote leading-5 text-muted-foreground">
          <SecondLine user={user} />
        </div>
      </div>
    </div>
  )
}

/**
 * The customer among a dialog's facts (FactList): the name — the link to their page, but on that page itself — with the
 * handle, and the Telegram id on a line of its own; for a customer without Telegram, their email there instead.
 */
export function CustomerFacts({ user }: { user: UserRef }) {
  const { pathname } = useLocation()
  const page = userPage(user.id)
  const name = user.name ? <bdi>{user.name}</bdi> : NO_NAME

  return (
    <>
      <Fact label="مشتری">
        {/* A space, not a margin: the name's isolate takes its own direction (a Latin name is LTR), so a logical margin lands on its far side. */}
        {pathname === page ? name : <TextLink to={page}>{name}</TextLink>}{' '}
        {user.username ? (
          <bdi dir="ltr" className="text-footnote text-muted-foreground">
            @{user.username}
          </bdi>
        ) : (
          user.telegram_id !== null && <span className="text-footnote text-muted-foreground">{NO_USERNAME}</span>
        )}
      </Fact>
      {user.telegram_id !== null ? (
        <Fact label="شناسه تلگرام">
          <bdi dir="ltr" className="text-footnote">
            {user.telegram_id}
          </bdi>
        </Fact>
      ) : (
        <Fact label="ایمیل">
          {user.email ? (
            <bdi dir="ltr" className="text-footnote">
              {user.email}
            </bdi>
          ) : (
            <span className="text-footnote text-muted-foreground">{NO_EMAIL}</span>
          )}
        </Fact>
      )}
    </>
  )
}
