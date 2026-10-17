import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { serverQuery } from '@/components/servers/queries'
import { api } from '@/lib/api'
import type { Probe, ServerDetailResponse, ServerInboundRow, ServerRow } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * A server page's read and operations: check the panel (the answer kept to show), sync the inbounds, choose which are
 * sold, delete. Each writes the page's copy and has the servers list read again.
 */
export function useServer(id: number, created: Probe | null) {
  const queryClient = useQueryClient()
  const query = serverQuery(id)
  const detail = useQuery(query)
  // The last check's answer (the page is drawn afresh for each server: ServerDetailPage keys it by the server's id).
  const [lastProbe, setLastProbe] = useState<Probe | null>(created)

  const write = (change: (current: ServerDetailResponse) => ServerDetailResponse) => queryClient.setQueryData<ServerDetailResponse>(query.queryKey, (current) => current && change(current))

  const check = useMutation({
    mutationFn: () => api.post(`/servers/${id}/test`),
    meta: { invalidates: [queryKeys.servers] },
    onSuccess: ({ server, inbounds, probe }) => {
      setLastProbe(probe)
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

  return { detail, probe: lastProbe, check, sync, sell, remove, saved }
}

export type ServerPage = ReturnType<typeof useServer>
