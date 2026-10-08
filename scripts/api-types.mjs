/**
 * The panel's API types, generated from the API's description (resources/api/openapi.yaml): one exported type per
 * schema there — the answers and the request bodies (`…Request`) alike —, with its descriptions as doc comments; and,
 * for each API, its writes as one union (PanelWrite, InstallWrite — the ones the panels call — and StoreWrite, a shop's
 * website's) — every POST, PUT, PATCH and DELETE by its method and its path under the API's address, with the body it
 * takes and its answer —, which lib/api types each write by. The PHP tests check every request and every response
 * against the same file, so a field here is a field the server sends, or reads.
 *
 *   pnpm api:types            writes resources/panel/src/lib/api-types.ts
 *   pnpm api:types --check    fails when that file is not what the description gives (`pnpm check` runs it)
 *
 * The schemas use a small part of OpenAPI 3.0 — objects, arrays, enums, $ref, allOf, oneOf, nullable,
 * additionalProperties, and a binary string (an uploaded file: a Blob) — and this covers exactly that; anything else
 * fails loudly rather than turning into a wrong type. A write taken as JSON or as a form (a message with its picture)
 * takes either body.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import * as prettier from 'prettier'
import { parseDocument } from 'yaml'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const specPath = path.join(root, 'resources/api/openapi.yaml')
const outPath = path.join(root, 'resources/panel/src/lib/api-types.ts')

const document = parseDocument(fs.readFileSync(specPath, 'utf8'), { merge: true })
if (document.errors.length > 0) {
  throw document.errors[0]
}
const description = document.toJS({ maxAliasCount: -1 })
const schemas = description.components.schemas

/**
 * The APIs written to, by the address each is reached at: the panels' and the shops' websites'. A write under none of
 * them fails the run: nobody could make it.
 */
const APIS = [
  {
    type: 'PanelWrite',
    prefixes: ['/api/{panel}', '/api/admin', '/api/agent'],
    description:
      "Every write of a panel's own API — the owner's `/api/admin`, an agent's `/api/agent`; what both have is described under `/api/{panel}` —: its method, its path under the panel's API (a path parameter as the type of its value: `/plans/${number}`), the body it takes (`undefined`: none) and its answer (`void`: none). lib/api types each POST, PUT, PATCH and DELETE by it.",
  },
  {
    type: 'InstallWrite',
    prefixes: ['/api/install'],
    description: "Every write of the web installer's API (`/api/install`), as PanelWrite has the panel's.",
  },
  {
    type: 'StoreWrite',
    prefixes: ['/api/store/v1/{store}'],
    description: "Every write of the Store API — a shop's website's, under its store key (`/api/store/v1/{store}`) —, as PanelWrite has the panel's.",
  },
]

/** The methods that change something; a read (GET) names its answer where it is made. */
const WRITES = ['post', 'put', 'patch', 'delete']

/** A doc comment for a description, on one line when it fits on one. */
function comment(description, indent) {
  if (!description) return ''
  const text = String(description).trim().replaceAll('*/', '*\\/')
  const lines = text.split('\n')
  if (lines.length === 1) return `${indent}/** ${text} */\n`
  return `${indent}/**\n${lines.map((line) => `${indent} * ${line}`.trimEnd()).join('\n')}\n${indent} */\n`
}

function typeOf(schema, indent, where) {
  let type
  if (schema.$ref) {
    type = schema.$ref.replace('#/components/schemas/', '')
  } else if (schema.allOf) {
    type = schema.allOf.map((part) => typeOf(part, indent, where)).join(' & ')
  } else if (schema.oneOf || schema.anyOf) {
    type = (schema.oneOf ?? schema.anyOf).map((part) => typeOf(part, indent, where)).join(' | ')
  } else if (schema.enum) {
    type = schema.enum.map((value) => JSON.stringify(value)).join(' | ')
  } else {
    switch (schema.type) {
      case 'string':
        type = schema.format === 'binary' ? 'Blob' : 'string'
        break
      case 'integer':
      case 'number':
        type = 'number'
        break
      case 'boolean':
        type = 'boolean'
        break
      case 'array': {
        const items = typeOf(schema.items, indent, `${where}[]`)
        type = /[|&]/.test(items) ? `(${items})[]` : `${items}[]`
        break
      }
      case 'object':
        type = objectOf(schema, indent, where)
        break
      default:
        throw new Error(`${where}: no TypeScript for ${JSON.stringify(schema)}`)
    }
  }

  // A type that is null already (an enum of null alone) does not say so twice.
  return schema.nullable && !type.split(' | ').includes('null') ? `${type} | null` : type
}

function objectOf(schema, indent, where) {
  const properties = Object.entries(schema.properties ?? {})
  const required = new Set(schema.required ?? [])
  const extra = schema.additionalProperties
  const index = extra === true ? 'unknown' : extra && typeof extra === 'object' ? typeOf(extra, indent, `${where}[key]`) : null

  if (properties.length === 0) {
    return index === null ? 'Record<string, never>' : `Record<string, ${index}>`
  }

  const inner = `${indent}  `
  const lines = properties.map(([name, property]) => {
    const key = /^[A-Za-z_$][\w$]*$/.test(name) ? name : JSON.stringify(name)
    return `${comment(property.description, inner)}${inner}${key}${required.has(name) ? '' : '?'}: ${typeOf(property, inner, `${where}.${name}`)}`
  })
  if (index !== null) lines.push(`${inner}[key: string]: ${index}`)

  return `{\n${lines.join('\n')}\n${indent}}`
}

/** A part of the description reached through a `$ref` (`#/components/<kind>/<name>`), or the part itself. */
function resolved(part) {
  if (!part.$ref) return part
  const [, , kind, name] = part.$ref.split('/')
  const target = description.components[kind]?.[name]
  if (target === undefined) throw new Error(`${part.$ref}: nothing there`)
  return target
}

/** A path parameter as a template literal's hole: its enum (by name when it has one), a number, or any text. */
function holeOf(schema, where) {
  if (schema.$ref) {
    return resolved(schema).enum ? schema.$ref.replace('#/components/schemas/', '') : holeOf(resolved(schema), where)
  }
  if (schema.enum) return schema.enum.map((value) => JSON.stringify(value)).join(' | ')
  if (schema.type === 'integer' || schema.type === 'number') return 'number'
  if (schema.type === 'string') return 'string'
  throw new Error(`${where}: no template literal for ${JSON.stringify(schema)}`)
}

/**
 * The body a write takes: its media type's schema — or any of them, for one taken as JSON or as a form with a file (a
 * body holding a Blob goes as a form, lib/api) —; `undefined` for one that takes none.
 */
function bodyOf(method, operation, where) {
  if (!operation.requestBody) {
    if (method === 'delete' || operation['x-no-body'] === true) return 'undefined'
    throw new Error(`${where}: neither a requestBody nor x-no-body`)
  }
  const media = Object.values(operation.requestBody.content)
  if (media.length === 0) throw new Error(`${where}: a body of no media type`)
  const type = [...new Set(media.map((part) => typeOf(part.schema, '', `${where} body`)))].join(' | ')
  return operation.requestBody.required ? type : `${type} | undefined`
}

/** What a write answers with when it succeeds: its JSON, or `void` for an answer without content. */
function answerOf(operation, where) {
  const answers = new Set()
  for (const [status, response] of Object.entries(operation.responses)) {
    if (!/^2\d\d$/.test(status)) continue
    const content = Object.entries(resolved(response).content ?? {})
    if (content.length === 0) answers.add('void')
    for (const [type, media] of content) {
      if (type !== 'application/json') throw new Error(`${where}: a write answering ${type}`)
      answers.add(typeOf(media.schema, '', `${where} answer`))
    }
  }
  if (answers.size === 0) throw new Error(`${where}: no answer of success`)
  return [...answers].join(' | ')
}

/** An API's writes, a union member each, in the description's order — every write it claims put in `claimed`. */
function writesOf(api, claimed) {
  const members = []
  const addresses = new Set()
  for (const [path, item] of Object.entries(description.paths)) {
    const prefix = api.prefixes.find((candidate) => path === candidate || path.startsWith(`${candidate}/`))
    if (prefix === undefined) continue
    for (const method of WRITES) {
      const operation = item[method]
      if (!operation) continue
      const where = `${method.toUpperCase()} ${path}`
      const parameters = [...(item.parameters ?? []), ...(operation.parameters ?? [])].map(resolved)
      const relative = path.slice(prefix.length)
      const holes = relative.replace(/\{(\w+)\}/g, (_, name) => {
        const parameter = parameters.find((candidate) => candidate.in === 'path' && candidate.name === name)
        if (!parameter) throw new Error(`${where}: {${name}} is not described`)
        return '${' + holeOf(parameter.schema, `${where} {${name}}`) + '}'
      })
      const address = holes === relative ? `'${relative}'` : `\`${holes}\``
      if (addresses.has(`${method} ${address}`)) throw new Error(`${where}: another write of ${api.type} is at the same address`)
      addresses.add(`${method} ${address}`)
      claimed.add(`${method} ${path}`)
      members.push(`${comment(operation.summary, '  ')}  | { method: '${method.toUpperCase()}'; path: ${address}; body: ${bodyOf(method, operation, where)}; answer: ${answerOf(operation, where)} }`)
    }
  }

  return members
}

let code = `/*
 * The shapes of the API's answers and of the bodies its requests carry, and each API's writes by method and path,
 * generated from resources/api/openapi.yaml by \`pnpm api:types\` — do not edit by hand: change the description, then run
 * it again. The PHP tests check every request and every response against the same file, and so do the panels' tests.
 */
`
for (const [name, schema] of Object.entries(schemas)) {
  const isInterface = schema.type === 'object' && schema.properties && !schema.nullable && !schema.allOf
  code += `\n${comment(schema.description, '')}`
  code += isInterface ? `export interface ${name} ${objectOf(schema, '', name)}\n` : `export type ${name} = ${typeOf(schema, '', name)}\n`
}

const claimed = new Set()
for (const api of APIS) {
  code += `\n${comment(api.description, '')}export type ${api.type} =\n${writesOf(api, claimed).join('\n')}\n`
}
for (const [path, item] of Object.entries(description.paths)) {
  for (const method of WRITES) {
    if (item[method] && !claimed.has(`${method} ${path}`)) {
      throw new Error(`${method.toUpperCase()} ${path}: a write under none of the APIs (APIS in scripts/api-types.mjs)`)
    }
  }
}

const options = await prettier.resolveConfig(outPath)
const formatted = await prettier.format(code, { ...options, filepath: outPath })

if (process.argv.includes('--check')) {
  const current = fs.existsSync(outPath) ? fs.readFileSync(outPath, 'utf8') : ''
  if (current !== formatted) {
    console.error('resources/panel/src/lib/api-types.ts is not what resources/api/openapi.yaml gives: run `pnpm api:types`.')
    process.exit(1)
  }
  console.log('api-types.ts matches the API description.')
} else {
  fs.writeFileSync(outPath, formatted)
  console.log(`Wrote ${path.relative(root, outPath)} (${Object.keys(schemas).length} types).`)
}
