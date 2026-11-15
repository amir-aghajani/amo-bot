import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ProbeResult } from '@/components/servers/probe-result'
import type { PanelStatusInfo, Probe } from '@/lib/api-types'

/*
 * A connection test's vitals (components/servers/probe-result): what the panel reported, and a dash for what it did not —
 * Remnawave reports no CPU load and no disk, which is no zero.
 */

const STATUS: PanelStatusInfo = {
  core_name: 'Xray',
  core_state: 'running',
  core_version: '25.10.15',
  core_error: '',
  cpu_percent: 12.4,
  memory_used: 1024 ** 3,
  memory_total: 4 * 1024 ** 3,
  disk_used: 10 * 1024 ** 3,
  disk_total: 40 * 1024 ** 3,
  uptime_seconds: 3600,
  connections: 0,
}

function probe(status: Partial<PanelStatusInfo>): Probe {
  return { ok: true, error: null, error_detail: null, status: { ...STATUS, ...status }, inbounds: [], serves_subscriptions: true, subscription_probed: true }
}

/** The value a stat shows, found by its label. */
function stat(label: string): string {
  return screen.getByText(label).nextElementSibling?.textContent ?? ''
}

describe('a connection test’s vitals', () => {
  it('shows the CPU load and the disk a panel reports', () => {
    render(<ProbeResult probe={probe({})} />)

    expect(stat('CPU')).not.toBe('—')
    expect(stat('دیسک')).not.toBe('—')
  })

  it('shows a dash for a CPU load and a disk the panel does not report', () => {
    render(<ProbeResult probe={probe({ cpu_percent: null, disk_used: 0, disk_total: 0 })} />)

    expect(stat('CPU')).toBe('—')
    expect(stat('دیسک')).toBe('—')
  })
})
