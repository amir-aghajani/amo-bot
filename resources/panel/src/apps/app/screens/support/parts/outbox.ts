import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { keys, queryClient } from '@/apps/app/lib/query'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { keepTicket } from '@/apps/app/screens/support/parts/words'
import { result } from '@/apps/app/telegram/haptics'
import { isTransportError } from '@/lib/api'
import type { StoreTicketDetail, StoreTicketMessage, StoreTicketResponse } from '@/lib/api-types'

/*
 * The messages the customer is sending in a ticket, as a messenger sends them: each is shown at once and goes in turn,
 * one at a time, in the order written (`POST /tickets/{id}/messages`, a form when it has a picture). Once the shop has
 * it, the ticket it answers is kept (lib/query) and its new message takes the sent one's place — keeping its bubble and
 * its picture from the device. One that failed stays with the shop's words for why, to send again or let go; one refused
 * for its picture alone may go without it. A message that got no answer may have arrived all the same, and the
 * write takes no Idempotency-Key: before it goes again, the ticket is read, and found there it is not sent twice.
 */

export interface Outgoing {
  /** Its bubble's key, kept by the message it becomes. */
  key: string
  body: string
  file: Blob | null
  /** The picture's address on the device, for its bubble. */
  preview: string | null
  /** When it was written. */
  at: string
  state: 'waiting' | 'sending' | 'failed'
  /** Why it did not go, in the shop's words. */
  problem: string | null
  /** The shop refused its picture (the ticket's 20 pictures, the day's room): it may go without it. */
  pictureRefused: boolean
  /** No answer came: the shop may have it. */
  unanswered: boolean
}

/** A message the shop has, sent from this screen: its bubble's key and its picture on the device. */
export interface Adopted {
  key: string
  preview: string | null
}

/** Text as the shop keeps it, to know a sent message again: its line breaks one kind, its ends trimmed. */
const normalized = (text: string) => text.replace(/\r\n?/g, '\n').trim()

/** How long before it was written a message found in the ticket may still be the one sent (the clocks may differ). */
const CLOCK_SLACK_MS = 120_000

let counter = 0

/** The first message waiting to go. */
const waiting = (items: readonly Outgoing[]) => items.find((item) => item.state === 'waiting')

/** The customer's message in `ticket` that `item` became: new since `known`, its words the same — else, unless `exact`, the newest new one. */
function messageFor(ticket: StoreTicketDetail, item: Outgoing, known: ReadonlySet<number>, exact: boolean): StoreTicketMessage | undefined {
  const since = new Date(item.at).getTime() - CLOCK_SLACK_MS
  const fresh = ticket.messages.filter((message) => message.author === 'customer' && !known.has(message.id) && (message.created_at === null || new Date(message.created_at).getTime() >= since))
  const same = fresh.filter((message) => normalized(message.body) === normalized(item.body)).at(-1)

  return exact ? same : (same ?? fresh.at(-1))
}

export function useOutbox(ticketId: number) {
  const [items, setItems] = useState<Outgoing[]>([])
  const list = useRef<Outgoing[]>([])
  const [adopted, setAdopted] = useState<ReadonlyMap<number, Adopted>>(new Map())
  const taken = useRef(new Map<number, Adopted>())
  const busy = useRef(false)
  const write = useMutation({
    mutationFn: ({ body, file }: { body: string; file: Blob | null }) => store.post(`/tickets/${ticketId}/messages`, file === null ? { body } : { body, file }),
    meta: { quiet: true },
  })

  const update = (next: Outgoing[]) => {
    list.current = next
    setItems(next)
  }
  const change = (key: string, fields: Partial<Outgoing>) => update(list.current.map((item) => (item.key === key ? { ...item, ...fields } : item)))

  /** The message became `message` in the ticket: its bubble is that message's from now on. */
  const adopt = (item: Outgoing, message: StoreTicketMessage | undefined) => {
    if (message !== undefined) {
      taken.current = new Map(taken.current).set(message.id, { key: item.key, preview: item.preview })
      setAdopted(taken.current)
    } else if (item.preview !== null) {
      URL.revokeObjectURL(item.preview)
    }
    update(list.current.filter((entry) => entry.key !== item.key))
  }

  /** Send one message; failed, it stays with the shop's words for why. */
  const deliver = async (item: Outgoing) => {
    change(item.key, { state: 'sending' })
    // A read on its way would answer the ticket without this message, or with it before its answer comes.
    await queryClient.cancelQueries({ queryKey: keys.ticket(ticketId), exact: true })
    // The messages it cannot be: those shown already, and those other messages sent from here became.
    const known = new Set([...(queryClient.getQueryData<StoreTicketResponse>(keys.ticket(ticketId))?.ticket.messages.map((message) => message.id) ?? []), ...taken.current.keys()])
    try {
      if (item.unanswered) {
        const { ticket } = await store.get<StoreTicketResponse>(`/tickets/${ticketId}`)
        keepTicket(ticket)
        const arrived = messageFor(ticket, item, known, true)
        if (arrived !== undefined) {
          adopt(item, arrived)
          return
        }
        ticket.messages.forEach((message) => known.add(message.id))
      }
      const { ticket } = await write.mutateAsync({ body: item.body, file: item.file })
      keepTicket(ticket)
      adopt(item, messageFor(ticket, item, known, false))
    } catch (error) {
      result('error')
      change(item.key, { state: 'failed', problem: problemOf(error), pictureRefused: item.file !== null && fieldProblem(error, 'file') !== undefined, unanswered: isTransportError(error) })
    }
  }

  /** Send the messages waiting, one after another, unless they are on their way already. */
  const pump = async () => {
    if (busy.current) return
    busy.current = true
    try {
      for (let item = waiting(list.current); item !== undefined; item = waiting(list.current)) await deliver(item)
    } finally {
      busy.current = false
    }
  }

  /** Write a message: shown at once, sent in its turn. */
  const add = (body: string, file: Blob | null) => {
    counter += 1
    const item: Outgoing = {
      key: `outgoing-${counter}`,
      body,
      file,
      preview: file === null ? null : URL.createObjectURL(file),
      at: new Date().toISOString(),
      state: 'waiting',
      problem: null,
      pictureRefused: false,
      unanswered: false,
    }
    update([...list.current, item])
    void pump()
  }

  /** Send a failed message again — without its picture when `withoutPicture`. */
  const retry = (key: string, withoutPicture = false) => {
    const item = list.current.find((entry) => entry.key === key)
    if (item === undefined) return
    if (withoutPicture && item.preview !== null) URL.revokeObjectURL(item.preview)
    change(key, { state: 'waiting', problem: null, pictureRefused: false, ...(withoutPicture ? { file: null, preview: null } : {}) })
    void pump()
  }

  /** Let a failed message go. */
  const discard = (key: string) => {
    const item = list.current.find((entry) => entry.key === key)
    if (item?.preview) URL.revokeObjectURL(item.preview)
    update(list.current.filter((entry) => entry.key !== key))
  }

  // The pictures' addresses on the device go with the screen.
  useEffect(
    () => () => {
      list.current.forEach((item) => item.preview !== null && URL.revokeObjectURL(item.preview))
      taken.current.forEach((entry) => entry.preview !== null && URL.revokeObjectURL(entry.preview))
    },
    [],
  )

  return { items, adopted, sending: items.some((item) => item.state !== 'failed'), add, retry, discard }
}
