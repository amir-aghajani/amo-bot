import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { TABLE_NAMES } from '@/components/backups/table-names'

/*
 * The names the restore card gives a backup's tables (components/backups/table-names), held to what a backup holds —
 * app/Modules/Backups/BackupTables.php, read from its source: a table added there without a name here fails, and so does
 * a name left for a table no backup holds any more.
 */

const SOURCE = fs.readFileSync(path.join(import.meta.dirname, '../../../../../app/Modules/Backups/BackupTables.php'), 'utf8')

/** The body of one of BackupTables' constants, `[...]` (one level of brackets inside, for CHILDREN's pairs). */
function constant(name: string): string {
  const found = new RegExp(`const ${name} = \\[((?:[^\\[\\]]|\\[[^\\[\\]]*\\])*)\\];`).exec(SOURCE)?.[1]
  if (found === undefined) throw new Error(`BackupTables.php no longer has ${name}`)
  return found
}

/** The quoted names in a list: `'settings', 'users', …`. */
const listed = (body: string) => [...body.matchAll(/'([a-z_]+)'/g)].map((match) => match[1] as string)

/** The keys of a map: `'plan_servers' => [...]`. */
const keyed = (body: string) => [...body.matchAll(/'([a-z_]+)'\s*=>/g)].map((match) => match[1] as string)

/** Every table a backup of the whole installation holds: a shop's own, a shop's by their parent, the owner's. */
const HELD = [...listed(constant('SHOP')), ...keyed(constant('CHILDREN')), ...listed(constant('OWNER'))]

describe('the names of a backup’s tables', () => {
  it('are read from BackupTables: every table once', () => {
    expect(HELD.length).toBeGreaterThanOrEqual(56)
    expect(new Set(HELD).size).toBe(HELD.length)
  })

  it('name every table a backup holds', () => {
    expect(HELD.filter((table) => TABLE_NAMES[table] === undefined)).toEqual([])
  })

  it('name no table a backup does not hold', () => {
    expect(Object.keys(TABLE_NAMES).filter((table) => !HELD.includes(table))).toEqual([])
  })

  it('are each a name of its own', () => {
    const names = Object.values(TABLE_NAMES)
    expect(new Set(names).size).toBe(names.length)
  })
})
