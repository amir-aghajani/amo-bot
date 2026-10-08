import fs from 'node:fs'
import { fileURLToPath } from 'node:url'
import type { TestProject } from 'vitest/node'
import { parseDocument } from 'yaml'
import type { ApiDescription } from '@/test/contract'

/**
 * Once a run, before any test file: resources/api/openapi.yaml read as scripts/api-types.mjs reads it (its anchors and
 * merge keys followed) and handed to every test file (`inject('apiDescription')`), whose fake server holds each request to
 * it (src/test/contract).
 */
export default function setup(project: TestProject) {
  const document = parseDocument(fs.readFileSync(fileURLToPath(new URL('../../../api/openapi.yaml', import.meta.url)), 'utf8'), { merge: true })
  if (document.errors.length > 0) throw document.errors[0]
  const { paths, components }: ApiDescription = document.toJS({ maxAliasCount: -1 })
  project.provide('apiDescription', { paths, components })
}
