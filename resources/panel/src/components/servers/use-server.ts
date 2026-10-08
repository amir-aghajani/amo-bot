import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { serverQuery } from '@/components/servers/queries'
import { api } from '@/lib/api'
import type { Probe, ServerDetailResponse, ServerInboundRow, ServerRow } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * One server's page: its read, and what the page does to it — check its panel (the answer kept to show), read the
 * inbounds again, choose which are sold, save the connection (its form names the list too), delete it. Each change writes
 * the page's copy and has the servers list read again (its counts, the check, whether the server can sell).
 */
export function useServer(id: number, created: Probe | null) {
  const queryClient = useQueryClient()
  const query = serverQuery(id)
  const detail = useQuery(query)
  // The last check's answer belongs to the server it was about: the page stays mounted from one server to another.
  const [checked, setChecked] = useState<{ id: number; probe: Probe } | null>(created && { id, probe: created })

  const write = (change: (current: ServerDetailResponse) => ServerDetailResponse) => queryClient.setQueryData<ServerDetailResponse>(query.queryKey, (current) => current && change(current))

  const check = useMutation({
    mutationFn: () => api.post(`/servers/${id}/test`),
    meta: { invalidates: [queryKeys.servers] },
    onSuccess: ({ server, inbounds, probe }) => {
      setChecked({ id, probe })
      write(() => ({ server, inbounds }))
      if (probe.ok) toast.success('اتصال برقرار است')
      // Why, in the panel's words — the status card shows it all, with its detail.
      else toast.error('اتصال برقرار نشد', { description: probe.error ?? undefined })
    },
  })

  const sync = useMutation({
    mutationFn: () => api.post(`/servers/${id}/inbounds/sync`),
    meta: { invalidates: [queryKeys.servers] },
    onSuccess: (data) => {
      write(() => data)
      toast.success('اینباندها به‌روز شدند')
    },
  })

  const sell = useMutation({
    mutationFn: ({ inbound, selectable }: { inbound: ServerInboundRow; selectable: boolean }) => api.patch(`/servers/${id}/inbounds/${inbound.id}`, { is_selectable: selectable }),
    meta: { invalidates: [queryKeys.servers] },
    onSuccess: ({ inbounds }) => write((current) => ({ ...current, inbounds })),
  })

  // Refused (services were sold on it), the dialog that asked says why.
  const remove = useMutation({
    mutationFn: () => api.delete(`/servers/${id}`),
    meta: { quiet: true, invalidates: [queryKeys.servers] },
  })

  /** The connection saved from its card (ServerForm, whose save has the list read again). */
  const saved = (server: ServerRow) => write((current) => ({ ...current, server }))

  return { detail, probe: checked?.id === id ? checked.probe : null, check, sync, sell, remove, saved }
}

export type ServerPage = ReturnType<typeof useServer>
