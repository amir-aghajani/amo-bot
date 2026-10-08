import { inject } from 'vitest'
import type { SentRequest } from '@/test/server'

/*
 * The API description as the panels' tests hold their requests to it — what tests/HttpTestCase does with the PHP tests'
 * (league/openapi-psr7-validator), read the same way: a request to the API must be an operation of
 * resources/api/openapi.yaml — its method and path, its path's parameters as they are described —, its query's and its
 * headers' described parameters what their schemas say (a value as the server casts it: "2" is an integer; the shop the
 * owner's tab names, `X-Shop`, among them), and its body what the operation takes: none where it takes none
 * (`x-no-body`, a GET, a DELETE), else its schema's — closed objects, so a field the operation does not read is a drift
 * too. The schemas use a small part of OpenAPI 3.0, the part
 * scripts/api-types.mjs generates the panels' types from; a keyword outside it fails loudly rather than letting a request
 * pass unread.
 */

/** A schema as the description writes one: OpenAPI 3.0's Schema Object, the part the description uses. */
interface Schema {
  $ref?: string
  type?: 'object' | 'array' | 'string' | 'integer' | 'number' | 'boolean'
  format?: string
  enum?: unknown[]
  nullable?: boolean
  properties?: Record<string, Schema>
  required?: string[]
  additionalProperties?: boolean | Schema
  minProperties?: number
  items?: Schema
  maxItems?: number
  allOf?: Schema[]
  oneOf?: Schema[]
  anyOf?: Schema[]
  pattern?: string
  minimum?: number
  description?: string
}

interface Parameter {
  $ref?: string
  name: string
  in: 'path' | 'query' | 'header' | 'cookie'
  required?: boolean
  schema: Schema
}

interface Operation {
  parameters?: Parameter[]
  requestBody?: { required?: boolean; content: Record<string, { schema: Schema }> }
}

const METHODS = ['get', 'post', 'put', 'patch', 'delete'] as const

type PathItem = { parameters?: Parameter[] } & Partial<Record<(typeof METHODS)[number], Operation>>

/** What src/test/global-setup reads of resources/api/openapi.yaml, once a run. */
export interface ApiDescription {
  paths: Record<string, PathItem>
  components: { schemas: Record<string, Schema>; parameters: Record<string, Parameter> }
}

declare module 'vitest' {
  interface ProvidedContext {
    apiDescription: ApiDescription
  }
}

/** An operation, ready to match a path against. */
interface Route {
  pattern: RegExp
  /** Its placeholders, in order. */
  names: string[]
  /** How many of its segments are no placeholder: of the templates a path fits, the most literal is its operation. */
  literal: number
  parameters: Parameter[]
  operation: Operation
}

/** The description's operations by method, made the first time a test file's request asks. */
let routes: Map<string, Route[]> | null = null

function description(): ApiDescription {
  return inject('apiDescription')
}

function routesBy(method: string): Route[] {
  routes ??= new Map(
    METHODS.map((name) => [
      name.toUpperCase(),
      Object.entries(description().paths).flatMap(([template, item]) => {
        const operation = item[name]
        if (operation === undefined) return []
        const names = [...template.matchAll(/\{(\w+)\}/g)].map(([, placeholder = '']) => placeholder)
        const source = template
          .split(/\{\w+\}/)
          .map((part) => part.replace(/[.*+?^$()|[\]\\]/g, '\\$&'))
          .join('([^/]+)')
        const parameters = [...(item.parameters ?? []), ...(operation.parameters ?? [])].map(parameterOf)
        return [{ pattern: new RegExp(`^${source}$`), names, literal: template.split('/').filter((segment) => !segment.startsWith('{')).length, parameters, operation }]
      }),
    ]),
  )
  return routes.get(method) ?? []
}

function parameterOf(parameter: Parameter): Parameter {
  if (parameter.$ref === undefined) return parameter
  const found = description().components.parameters[parameter.$ref.replace('#/components/parameters/', '')]
  if (found === undefined) throw new Error(`${parameter.$ref}: not in the API description`)
  return found
}

function resolved(schema: Schema): Schema {
  if (schema.$ref === undefined) return schema
  const found = description().components.schemas[schema.$ref.replace('#/components/schemas/', '')]
  if (found === undefined) throw new Error(`${schema.$ref}: not in the API description`)
  return resolved(found)
}

/**
 * What is wrong with a request by the API description — nothing (null) for one it takes, and for anything but the API
 * (a page, an asset).
 */
export function contractProblem(request: SentRequest): string | null {
  if (!request.path.startsWith('/api/')) return null
  const where = `${request.method} ${request.path}`
  const found = routesBy(request.method).reduce<{ route: Route; values: string[] } | undefined>((best, route) => {
    const values = route.pattern.exec(request.path)?.slice(1)
    return values !== undefined && (best === undefined || route.literal > best.route.literal) ? { route, values } : best
  }, undefined)
  if (found === undefined) return `${where}: no such operation in the API description`

  const { route, values } = found
  const problems: string[] = []
  route.names.forEach((name, index) => {
    const parameter = route.parameters.find((candidate) => candidate.in === 'path' && candidate.name === name)
    if (parameter === undefined) throw new Error(`${where}: {${name}} is not described`)
    problems.push(...check(cast(decodeURIComponent(values[index] ?? ''), parameter.schema), parameter.schema, `{${name}}`))
  })
  for (const parameter of route.parameters.filter((candidate) => candidate.in === 'query' || candidate.in === 'header')) {
    const named = parameter.in === 'query' ? `?${parameter.name}` : parameter.name
    const value = parameter.in === 'query' ? request.query.get(parameter.name) : (request.headers[parameter.name.toLowerCase()] ?? null)
    if (value !== null) problems.push(...check(cast(value, parameter.schema), parameter.schema, named))
    else if (parameter.required === true) problems.push(`${named}: required, not sent`)
  }
  problems.push(...bodyProblems(route.operation, request))

  return problems.length === 0 ? null : `${where}: ${problems.join('; ')}`
}

/** A path's or a query's text as the server reads it by its schema's type (league's SerializedParameter): "2" an integer. */
function cast(text: string, schema: Schema): unknown {
  switch (resolved(schema).type) {
    case 'integer':
      return /^[-+]?\d+$/.test(text) ? Number(text) : text
    case 'number':
      return text.trim() !== '' && Number.isFinite(Number(text)) ? Number(text) : text
    case 'boolean':
      return /^(true|1)$/i.test(text) ? true : /^(false|0)$/i.test(text) ? false : text
    default:
      return text
  }
}

function bodyProblems(operation: Operation, request: SentRequest): string[] {
  const { body } = request
  const described = operation.requestBody
  if (described === undefined) return body === undefined ? [] : ['a body, where the operation takes none']
  if (body === undefined) return described.required === true ? ['no body, where the operation takes one'] : []

  const multipart = body instanceof FormData
  const type = multipart ? 'multipart/form-data' : (request.headers['content-type'] ?? '')
  const media = described.content[type]
  if (media === undefined) return [`a body sent as ${type || 'nothing it names'}, where the operation takes ${Object.keys(described.content).join(' or ')}`]

  return check(multipart ? Object.fromEntries(body.entries()) : body, media.schema, 'body')
}

/** The keywords a schema of the description may use; any other is one this does not read. */
const KEYWORDS = new Set([
  '$ref',
  'type',
  'format',
  'enum',
  'nullable',
  'properties',
  'required',
  'additionalProperties',
  'minProperties',
  'items',
  'maxItems',
  'allOf',
  'oneOf',
  'anyOf',
  'pattern',
  'minimum',
  'description',
])

/** What is wrong with a value by a schema, each where it is (`body.servers[0].server_id`): nothing for a value it takes. */
function check(value: unknown, given: Schema, where: string): string[] {
  const schema = resolved(given)
  for (const keyword of Object.keys(schema)) {
    if (!KEYWORDS.has(keyword)) throw new Error(`${where}: src/test/contract does not read the keyword "${keyword}" — teach it, as scripts/api-types.mjs is taught`)
  }

  // A null is taken where the schema says so, and then nothing more is asked of it.
  if (value === null) return schema.nullable === true ? [] : [`${where}: null, which it does not take`]

  if (schema.type !== undefined && !isType(value, schema)) return [`${where}: ${shown(value)}, where it takes ${schema.format === 'binary' ? 'a file' : TYPES[schema.type]}`]
  const problems: string[] = []
  if (schema.enum !== undefined && !schema.enum.includes(value)) problems.push(`${where}: ${shown(value)}, none of ${schema.enum.map(shown).join(', ')}`)
  if (schema.pattern !== undefined && typeof value === 'string' && !new RegExp(schema.pattern, 'u').test(value)) problems.push(`${where}: ${shown(value)}, not of the pattern ${schema.pattern}`)
  if (schema.minimum !== undefined && typeof value === 'number' && value < schema.minimum) problems.push(`${where}: ${value}, below ${schema.minimum}`)
  if (typeof value === 'string' && schema.format !== undefined && !formatOf(schema.format, where).test(value)) problems.push(`${where}: ${shown(value)}, not a ${schema.format}`)
  if (isObject(value)) problems.push(...objectProblems(value, schema, where))
  if (Array.isArray(value) && schema.maxItems !== undefined && value.length > schema.maxItems) problems.push(`${where}: ${value.length} items, more than ${schema.maxItems}`)
  if (Array.isArray(value) && schema.items !== undefined) {
    const { items } = schema
    value.forEach((item, index) => problems.push(...check(item, items, `${where}[${index}]`)))
  }
  for (const part of schema.allOf ?? []) problems.push(...check(value, part, where))
  if (schema.oneOf !== undefined) problems.push(...oneOfProblems(value, schema.oneOf, where))
  if (schema.anyOf !== undefined && !schema.anyOf.some((part) => check(value, part, where).length === 0)) problems.push(`${where}: ${shown(value)}, none of its forms`)

  return problems
}

/** What each type takes, in a drift's words. */
const TYPES: Record<NonNullable<Schema['type']>, string> = {
  object: 'an object',
  array: 'a list',
  string: 'text',
  integer: 'a whole number',
  number: 'a number',
  boolean: 'true or false',
}

/** A string's format as the description names it (a file — `binary` — is no string: isType() reads it). */
function formatOf(format: string, where: string): RegExp {
  switch (format) {
    case 'date':
      return /^\d{4}-\d{2}-\d{2}$/
    case 'date-time':
      return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/
    default:
      throw new Error(`${where}: src/test/contract does not read the format "${format}" — teach it`)
  }
}

function isType(value: unknown, schema: Schema): boolean {
  switch (schema.type) {
    case 'string':
      return schema.format === 'binary' ? value instanceof Blob : typeof value === 'string'
    case 'integer':
      return Number.isInteger(value)
    case 'number':
      return typeof value === 'number' && Number.isFinite(value)
    case 'boolean':
      return typeof value === 'boolean'
    case 'array':
      return Array.isArray(value)
    case 'object':
      return isObject(value)
    default:
      throw new Error(`No type "${String(schema.type)}" in the API description's part of OpenAPI`)
  }
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) && !(value instanceof Blob)
}

/** An object's fields: every required one there, each by its own schema — and none the schema does not name while it is closed. */
function objectProblems(value: Record<string, unknown>, schema: Schema, where: string): string[] {
  const problems: string[] = []
  for (const name of schema.required ?? []) {
    if (!(name in value)) problems.push(`${where}.${name}: required, not sent`)
  }
  for (const [name, item] of Object.entries(value)) {
    const property = schema.properties?.[name]
    const extra = schema.additionalProperties
    if (property !== undefined) problems.push(...check(item, property, `${where}.${name}`))
    else if (extra === false) problems.push(`${where}.${name}: a field the operation does not take`)
    else if (typeof extra === 'object') problems.push(...check(item, extra, `${where}.${name}`))
  }
  if (schema.minProperties !== undefined && Object.keys(value).length < schema.minProperties) problems.push(`${where}: fewer than ${schema.minProperties} fields`)
  return problems
}

/** Exactly one of the forms takes the value: none — each form's reasons —, or more than one, is a drift. */
function oneOfProblems(value: unknown, forms: Schema[], where: string): string[] {
  const reasons = forms.map((form) => check(value, form, where))
  const taken = reasons.filter((problems) => problems.length === 0).length
  if (taken === 1) return []
  if (taken > 1) return [`${where}: ${shown(value)} fits more than one of its forms`]
  return [`${where}: fits none of its forms (${reasons.map((problems, index) => `form ${index + 1}: ${problems.join(', ')}`).join(' | ')})`]
}

function shown(value: unknown): string {
  return value instanceof Blob ? 'a file' : value === undefined ? 'nothing' : JSON.stringify(value)
}
