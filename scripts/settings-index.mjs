/**
 * The settings search's index of single settings (lib/settings-search), generated from the settings sections' own
 * source, read with TypeScript's parser: for each section — a settings page's, or a feature page's settings card — the
 * cards it draws (their titles and descriptions), the titled parts of them (FormPart), the fields (Field and the
 * wrappers that take a field's id and label), the switches (SwitchRow) and the groups of controls (FieldGroup), each
 * with its hint and the fold (Disclosure) it sits in. What a section renders is followed into the components it draws,
 * with their props as the caller writes them, and a `.map()` over a constant list is drawn once per item; only words
 * written in the source are taken — a label the server sends, or one computed at run time, is skipped and said so.
 *
 *   pnpm settings:index            writes resources/panel/src/lib/settings-search/settings.generated.ts
 *   pnpm settings:index --check    fails when that file is not what the sources give (`pnpm check` runs it)
 *
 * Which components draw which section is the hand-written SECTIONS below: a section added to the panel's nav is added
 * here too (the settings search's tests check every address here is one the nav has).
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import * as prettier from 'prettier'
import { SyntaxKind as K } from 'typescript/unstable/ast'
import { API } from 'typescript/unstable/sync'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const src = path.join(root, 'resources/panel/src')
const outPath = path.join(src, 'lib/settings-search/settings.generated.ts')

/**
 * Each section's address in the shop (components/shell/nav, apps/admin/nav) → the components its page draws there, as
 * `file#Export` under resources/panel/src, in the order they are drawn.
 */
const SECTIONS = {
  // «تنظیمات ربات» (pages/bot-settings).
  '/bot-settings/general': ['components/bot/settings/general-section.tsx#GeneralSection'],
  '/bot-settings/client-names': ['components/bot/settings/client-names-section.tsx#ClientNamesSection'],
  '/bot-settings/channels': ['components/bot/settings/channels-section.tsx#ChannelsSection'],
  '/bot-settings/wallet': ['components/bot/settings/wallet-section.tsx#WalletSection'],
  '/bot-settings/wallet-transfers': ['components/bot/settings/wallet-transfers-section.tsx#WalletTransfersSection'],
  '/bot-settings/payments': ['components/bot/settings/payments-section.tsx#PaymentsSection'],
  '/bot-settings/renewal': ['components/bot/settings/renewal-section.tsx#RenewalSection'],
  '/bot-settings/extras': ['components/bot/settings/extras-section.tsx#ExtrasSection'],
  '/bot-settings/reminders': ['components/bot/settings/reminders-section.tsx#RemindersSection'],
  '/bot-settings/expired-services': ['components/bot/settings/expired-services-section.tsx#ExpiredServicesSection'],
  '/bot-settings/service-claims': ['components/bot/settings/service-claims-section.tsx#ServiceClaimsSection'],
  '/bot-settings/service-transfers': ['components/bot/settings/service-transfers-section.tsx#ServiceTransfersSection'],
  '/bot-settings/referral': ['components/bot/settings/referral-section.tsx#ReferralSection'],
  '/bot-settings/reports': ['components/bot/settings/reports-section.tsx#ReportsSection'],
  '/bot-settings/qr': ['components/bot/settings/qr-section.tsx#QrSection'],
  // «تنظیمات وب‌سایت» (pages/website-settings).
  '/website-settings/connection': ['components/website/connection-section.tsx#ConnectionSection'],
  '/website-settings/telegram': ['components/website/telegram-login-section.tsx#TelegramLoginSection'],
  '/website-settings/google': ['components/website/google-login-section.tsx#GoogleLoginSection'],
  '/website-settings/email': ['components/website/email-section.tsx#EmailSection'],
  '/website-settings/phone': ['components/website/phone-login-section.tsx#PhoneLoginSection'],
  '/website-settings/reviews': ['components/website/reviews-section.tsx#ReviewsSection'],
  '/website-settings/staff': ['components/website/staff-section.tsx#StaffSection'],
  // «مینی‌اپ تلگرام» (pages/mini-app-settings); its preview is the app itself, no settings.
  '/mini-app-settings/connection': ['components/mini-app/connection-section.tsx#ConnectionSection'],
  '/mini-app-settings/appearance': ['components/mini-app/appearance-section.tsx#AppearanceSection'],
  // «تنظیمات پنل»: the owner's (apps/admin/pages/settings), and the backups and the look an agent's has too.
  '/settings/telegram': ['apps/admin/settings/telegram-section.tsx#TelegramSection', 'apps/admin/settings/webhook-card.tsx#WebhookCard'],
  '/settings/database': ['apps/admin/settings/database-section.tsx#DatabaseSection'],
  '/settings/app': ['apps/admin/settings/app-section.tsx#AppSection'],
  '/settings/mail': ['apps/admin/settings/mail-section.tsx#MailSection', 'apps/admin/settings/mail-section.tsx#MailTestCard'],
  '/settings/sms': ['apps/admin/settings/sms-section.tsx#SmsSection', 'apps/admin/settings/sms-section.tsx#SmsTestCard'],
  '/settings/login': ['apps/admin/settings/login-section.tsx#LoginSection'],
  '/settings/appearance': ['components/appearance-card.tsx#AppearanceCard'],
  '/settings/update': ['apps/admin/settings/update-section.tsx#UpdateSection'],
  '/settings/schedule': ['apps/admin/settings/schedule-section.tsx#ScheduleSection'],
  '/settings/backups': ['components/backups/backups-section.tsx#BackupsSection'],
  '/settings/advanced': ['apps/admin/settings/advanced-section.tsx#AdvancedSection'],
  // The feature pages' settings sections, and the pages whose settings are a card on the page itself.
  '/discounts/settings': ['components/discounts/discount-settings-card.tsx#DiscountSettingsCard'],
  '/tests/settings': ['components/tests/test-settings-card.tsx#TestSettingsCard'],
  '/wheel/settings': ['components/wheel/wheel-settings-card.tsx#WheelSettingsCard'],
  '/agents/settings': ['apps/admin/agency/settings-card.tsx#AgencySettingsCard'],
  '/cashback': ['components/cashback/cashback-settings-card.tsx#CashbackSettingsCard'],
  '/gift-codes': ['components/gift-codes/gift-codes-settings-card.tsx#GiftCodesSettingsCard'],
  '/lottery': ['components/lottery/lottery-settings-card.tsx#LotterySettingsCard'],
  '/custom-services': ['components/custom-services/custom-services-card.tsx#CustomServicesCard'],
}

/**
 * What a section's page header says of it, where the page writes that in a constant: `file#NAME` (a description) or
 * `file#NAME.key` (an object with the header's `title` and `description`). The description becomes the section's
 * words (instead of its first card's), the header's title another name it is found by.
 */
const ABOUT = {
  '/settings/appearance': 'components/appearance-card.tsx#APPEARANCE_DESCRIPTION',
  '/settings/update': 'apps/admin/settings/update-section.tsx#UPDATE_DESCRIPTION',
  '/settings/schedule': 'apps/admin/settings/schedule-section.tsx#SCHEDULE_DESCRIPTION',
  '/users': 'pages/users.tsx#HEADERS.users',
  '/users/groups': 'pages/users.tsx#HEADERS.groups',
  '/referrals': 'pages/referrals.tsx#HEADERS.referrers',
  '/referrals/invitees': 'pages/referrals.tsx#HEADERS.invitees',
  '/referrals/commissions': 'pages/referrals.tsx#HEADERS.commissions',
  '/referrals/alerts': 'pages/referrals.tsx#HEADERS.alerts',
  '/discounts': 'pages/discount-codes.tsx#HEADERS.codes',
  '/discounts/settings': 'pages/discount-codes.tsx#HEADERS.settings',
  '/tests': 'pages/tests.tsx#HEADERS.tests',
  '/tests/servers': 'pages/tests.tsx#HEADERS.servers',
  '/tests/settings': 'pages/tests.tsx#HEADERS.settings',
  '/wheel': 'pages/wheel.tsx#HEADERS.prizes',
  '/wheel/spins': 'pages/wheel.tsx#HEADERS.spins',
  '/wheel/settings': 'pages/wheel.tsx#HEADERS.settings',
  '/agents': 'apps/admin/pages/agents.tsx#HEADERS.requests',
  '/agents/list': 'apps/admin/pages/agents.tsx#HEADERS.agents',
  '/agents/levels': 'apps/admin/pages/agents.tsx#HEADERS.levels',
  '/agents/settings': 'apps/admin/pages/agents.tsx#HEADERS.settings',
}

/** A form's field: a control under a label, with its DOM id (`id`) and its hint. */
const FIELDS = new Set(['Field', 'MoneyField', 'SecretField', 'DriverPicker'])

/**
 * What draws no setting — or none a search could land on: dialogs (closed until opened), notes, states, buttons, the
 * server's own driver forms (their fields are the server's) — not walked into.
 */
const SKIPPED = new Set([
  'AlertDialog',
  'Badge',
  'Button',
  'Callout',
  'ConfirmModal',
  'DetailModal',
  'Dialog',
  'DriverFields',
  'DropdownMenu',
  'EmptyState',
  'ErrorState',
  'IconButton',
  'InfoTip',
  'MenuButtonNotice',
  'Modal',
  'PageTabs',
  'Popover',
  'SectionActions',
  'Select',
  'Sheet',
  'Skeleton',
  'StatCard',
  'StatGrid',
  'TextLink',
  'Tooltip',
])

/** A value the source does not settle: a call's result, the server's data, a hook's. */
const UNKNOWN = Symbol('unknown')
/** On an object built with a spread of something unknown: a key it lacks may still be there. */
const PARTIAL = Symbol('partial')

/** What was skipped, or found by its label alone: said after a run, so what the index misses is known. */
const warnings = []
/** The panel's program (tsconfig.json), parsed by TypeScript; set while the index is made. */
let program

/* Reading the source. */

/** The parsed file at `file` (absolute), or null when the program has none there. */
function sourceFile(file) {
  return program.getSourceFile(file) ?? null
}

/** `file:line`, under resources/panel/src, of a node — where a warning points. */
function where(node) {
  const file = node.getSourceFile()
  const line = file.text.slice(0, node.getStart()).split('\n').length
  return `${path.relative(src, file.fileName).replaceAll('\\', '/')}:${line}`
}

/** Strips what only types or groups an expression: parentheses, `as`, `satisfies`, `!`. */
function unwrap(node) {
  while (
    node &&
    (node.kind === K.ParenthesizedExpression || node.kind === K.AsExpression || node.kind === K.SatisfiesExpression || node.kind === K.NonNullExpression || node.kind === K.TypeAssertionExpression)
  ) {
    node = node.expression
  }
  return node
}

function isFunctionLike(node) {
  return node.kind === K.ArrowFunction || node.kind === K.FunctionExpression || node.kind === K.FunctionDeclaration || node.kind === K.MethodDeclaration
}

function isExported(node) {
  return node.modifiers?.some((modifier) => modifier.kind === K.ExportKeyword) ?? false
}

/** The identifier a binding name (an identifier or a destructuring pattern) declares as `name`, or null. */
function bindingNamed(nameNode, name) {
  if (nameNode.kind === K.Identifier) return nameNode.text === name ? nameNode : null
  if (nameNode.kind === K.ObjectBindingPattern || nameNode.kind === K.ArrayBindingPattern) {
    for (const element of nameNode.elements) {
      if (element.kind !== K.BindingElement) continue
      const found = bindingNamed(element.name, name)
      if (found) return found
    }
  }
  return null
}

/**
 * Where the identifier `id` is declared, looking out through its scopes: a parameter, a variable, a function or an
 * import — or null (a global).
 */
function declarationOf(id) {
  const name = id.text
  for (let scope = id.parent; scope; scope = scope.parent) {
    if (isFunctionLike(scope)) {
      for (const parameter of scope.parameters) {
        const binding = bindingNamed(parameter.name, name)
        if (binding) return { kind: 'parameter', parameter, binding }
      }
    }
    const statements = scope.kind === K.Block || scope.kind === K.SourceFile || scope.kind === K.ModuleBlock || scope.kind === K.CaseClause ? scope.statements : null
    if (!statements) continue
    for (const statement of statements) {
      if (statement.kind === K.VariableStatement) {
        for (const declaration of statement.declarationList.declarations) {
          const binding = bindingNamed(declaration.name, name)
          if (binding) return { kind: 'variable', declaration, binding }
        }
      } else if (statement.kind === K.FunctionDeclaration && statement.name?.text === name) {
        return { kind: 'function', node: statement }
      } else if (statement.kind === K.ImportDeclaration && statement.importClause && !statement.importClause.isTypeOnly) {
        const named = statement.importClause.namedBindings
        if (named?.kind === K.NamedImports) {
          for (const specifier of named.elements) {
            if (specifier.name.text === name) return { kind: 'import', module: statement.moduleSpecifier.text, name: (specifier.propertyName ?? specifier.name).text, from: scope }
          }
        }
      }
    }
  }
  return null
}

/** The file a module specifier names: `@/…` under resources/panel/src, or a relative path; null for a package. */
function moduleFile(specifier, from) {
  let base
  if (specifier.startsWith('@/')) base = path.join(src, specifier.slice(2))
  else if (specifier.startsWith('.')) base = path.resolve(path.dirname(from.fileName), specifier)
  else return null
  for (const candidate of [base, `${base}.tsx`, `${base}.ts`, path.join(base, 'index.ts'), path.join(base, 'index.tsx')]) {
    if (/\.tsx?$/.test(candidate) && fs.existsSync(candidate)) return candidate
  }
  return null
}

/** The declaration `name` exports from `file`: `{ kind: 'function', node }` or `{ kind: 'variable', declaration, binding }`, or null. */
function exportOf(file, name) {
  const source = sourceFile(file)
  if (!source) return null
  for (const statement of source.statements) {
    if (statement.kind === K.FunctionDeclaration && statement.name?.text === name && isExported(statement)) return { kind: 'function', node: statement }
    if (statement.kind === K.VariableStatement && isExported(statement)) {
      for (const declaration of statement.declarationList.declarations) {
        const binding = bindingNamed(declaration.name, name)
        if (binding) return { kind: 'variable', declaration, binding }
      }
    }
    if (statement.kind === K.ExportDeclaration && statement.exportClause?.kind === K.NamedExports) {
      for (const element of statement.exportClause.elements) {
        if (element.name.text !== name) continue
        const local = (element.propertyName ?? element.name).text
        if (statement.moduleSpecifier) {
          const target = moduleFile(statement.moduleSpecifier.text, source)
          return target ? exportOf(target, local) : null
        }
        return declarationIn(source, local)
      }
    }
  }
  return null
}

/** A top-level declaration of `file` by its name, exported or not. */
function declarationIn(source, name) {
  for (const statement of source.statements) {
    if (statement.kind === K.FunctionDeclaration && statement.name?.text === name) return { kind: 'function', node: statement }
    if (statement.kind === K.VariableStatement) {
      for (const declaration of statement.declarationList.declarations) {
        const binding = bindingNamed(declaration.name, name)
        if (binding) return { kind: 'variable', declaration, binding }
      }
    }
  }
  return null
}

/* Evaluating what the source settles. */

/** A run of a function: the values its parameters were given, and the run it was defined in. */
class Frame {
  constructor(parent = null) {
    this.parent = parent
    this.values = new Map()
  }

  /** The value a parameter was given in this run or an outer one; UNKNOWN when none was. */
  valueOf(parameter) {
    for (let frame = this; frame; frame = frame.parent) {
      if (frame.values.has(parameter)) return frame.values.get(parameter)
    }
    return UNKNOWN
  }
}

/** A function as a value, with the run it was defined in. */
class Closure {
  constructor(node, frame) {
    this.node = node
    this.frame = frame
  }
}

/** JSX as a value — an element, or a list of children —, with the run it was written in. */
class Jsx {
  constructor(nodes, frame) {
    this.nodes = nodes
    this.frame = frame
  }
}

const moduleValues = new Map()
const evaluating = new Set()

/** The value at the end of `binding` inside the destructuring of `value` by `nameNode` (the binding itself, when it is the name). */
function destructured(nameNode, binding, value, frame) {
  if (nameNode === binding) return value
  if (nameNode.kind !== K.ObjectBindingPattern && nameNode.kind !== K.ArrayBindingPattern) return UNKNOWN
  for (const [index, element] of nameNode.elements.entries()) {
    if (element.kind !== K.BindingElement || !contains(element.name, binding)) continue
    if (element.dotDotDotToken) return UNKNOWN
    let inner
    if (nameNode.kind === K.ArrayBindingPattern) inner = Array.isArray(value) ? value[index] : UNKNOWN
    else {
      const key = element.propertyName ? propertyKey(element.propertyName, frame) : element.name.kind === K.Identifier ? element.name.text : UNKNOWN
      inner = member(value, key)
    }
    if (inner === undefined && element.initializer) inner = evaluate(element.initializer, frame)
    return destructured(element.name, binding, inner, frame)
  }
  return UNKNOWN
}

function contains(node, target) {
  if (node === target) return true
  return node.forEachChild((child) => (contains(child, target) ? true : undefined)) === true
}

/** `object[key]`, as far as the source settles it. */
function member(object, key) {
  if (key === UNKNOWN || object === UNKNOWN || object === null || object === undefined || typeof object !== 'object' || object instanceof Closure || object instanceof Jsx) return UNKNOWN
  if (Array.isArray(object) && key === 'length') return object.length
  if (Object.hasOwn(object, key)) return object[key]
  return object[PARTIAL] ? UNKNOWN : undefined
}

function propertyKey(nameNode, frame) {
  if (nameNode.kind === K.Identifier || nameNode.kind === K.StringLiteral || nameNode.kind === K.NumericLiteral || nameNode.kind === K.NoSubstitutionTemplateLiteral) return nameNode.text
  if (nameNode.kind === K.ComputedPropertyName) {
    const key = evaluate(nameNode.expression, frame)
    return typeof key === 'string' || typeof key === 'number' ? String(key) : UNKNOWN
  }
  return UNKNOWN
}

/** The value an identifier stands for in `frame`. */
function valueOfIdentifier(id, frame) {
  if (id.text === 'undefined') return undefined
  const declaration = declarationOf(id)
  if (!declaration) return UNKNOWN
  return valueOfDeclaration(declaration, frame)
}

function valueOfDeclaration(declaration, frame) {
  switch (declaration.kind) {
    case 'parameter':
      return destructured(declaration.parameter.name, declaration.binding, frame.valueOf(declaration.parameter), frame)
    case 'function':
      return new Closure(declaration.node, frame)
    case 'import': {
      const file = moduleFile(declaration.module, declaration.from)
      const target = file && exportOf(file, declaration.name)
      return target ? valueOfDeclaration(target, new Frame()) : UNKNOWN
    }
    case 'variable': {
      const { declaration: node, binding } = declaration
      if (!node.initializer || evaluating.has(node)) return UNKNOWN
      // A module's constant is the same in every run: settled once.
      const topLevel = node.parent.parent.parent?.kind === K.SourceFile
      if (topLevel && moduleValues.has(node)) return destructured(node.name, binding, moduleValues.get(node), frame)
      evaluating.add(node)
      try {
        const value = evaluate(node.initializer, topLevel ? new Frame() : frame)
        if (topLevel) moduleValues.set(node, value)
        return destructured(node.name, binding, value, frame)
      } finally {
        evaluating.delete(node)
      }
    }
  }
  return UNKNOWN
}

/** Truthiness as far as it is settled: true, false, or undefined. */
function truth(value) {
  return value === UNKNOWN ? undefined : Boolean(value)
}

/**
 * The value of an expression as far as the source settles it, UNKNOWN beyond. `loose`, for words that only help a
 * search (a hint, a description): of two choices the longer one settled.
 */
function evaluate(node, frame, loose = false) {
  node = unwrap(node)
  if (!node) return UNKNOWN
  switch (node.kind) {
    case K.StringLiteral:
    case K.NoSubstitutionTemplateLiteral:
      return node.text
    case K.NumericLiteral:
      return Number(node.text)
    case K.TrueKeyword:
      return true
    case K.FalseKeyword:
      return false
    case K.NullKeyword:
      return null
    case K.TemplateExpression: {
      let text = node.head.text
      for (const span of node.templateSpans) {
        const part = evaluate(span.expression, frame)
        if (typeof part !== 'string' && typeof part !== 'number') return UNKNOWN
        text += String(part) + span.literal.text
      }
      return text
    }
    case K.Identifier:
      return valueOfIdentifier(node, frame)
    case K.PropertyAccessExpression:
      return member(evaluate(node.expression, frame), node.name.text)
    case K.ElementAccessExpression: {
      const key = evaluate(node.argumentExpression, frame)
      return member(evaluate(node.expression, frame), typeof key === 'number' ? key : typeof key === 'string' ? key : UNKNOWN)
    }
    case K.ObjectLiteralExpression: {
      const object = {}
      for (const property of node.properties) {
        if (property.kind === K.PropertyAssignment) {
          const key = propertyKey(property.name, frame)
          if (key === UNKNOWN) object[PARTIAL] = true
          else object[key] = evaluate(property.initializer, frame)
        } else if (property.kind === K.ShorthandPropertyAssignment) {
          object[property.name.text] = valueOfIdentifier(property.name, frame)
        } else if (property.kind === K.SpreadAssignment) {
          const spread = evaluate(property.expression, frame)
          if (spread && typeof spread === 'object' && !(spread instanceof Closure) && !(spread instanceof Jsx)) Object.assign(object, spread)
          else object[PARTIAL] = true
        } else if (property.kind === K.MethodDeclaration && property.name.kind === K.Identifier) {
          object[property.name.text] = new Closure(property, frame)
        }
      }
      return object
    }
    case K.ArrayLiteralExpression: {
      const array = []
      for (const element of node.elements) {
        if (element.kind === K.SpreadElement) {
          const spread = evaluate(element.expression, frame)
          if (!Array.isArray(spread)) return UNKNOWN
          array.push(...spread)
        } else array.push(evaluate(element, frame))
      }
      return array
    }
    case K.ConditionalExpression: {
      const condition = truth(evaluate(node.condition, frame))
      if (condition !== undefined) return evaluate(condition ? node.whenTrue : node.whenFalse, frame, loose)
      const a = evaluate(node.whenTrue, frame, loose)
      const b = evaluate(node.whenFalse, frame, loose)
      if (a === b) return a
      if (!loose) return UNKNOWN
      const words = [textOf(a, true), textOf(b, true)].filter((text) => text !== UNKNOWN && text !== '')
      return words.length === 0 ? UNKNOWN : words.reduce((longest, text) => (text.length > longest.length ? text : longest))
    }
    case K.BinaryExpression:
      return binary(node, frame, loose)
    case K.PrefixUnaryExpression: {
      const operand = evaluate(node.operand, frame)
      if (node.operator === K.ExclamationToken) return operand === UNKNOWN ? UNKNOWN : !operand
      if (node.operator === K.MinusToken) return typeof operand === 'number' ? -operand : UNKNOWN
      return UNKNOWN
    }
    case K.ArrowFunction:
    case K.FunctionExpression:
      return new Closure(node, frame)
    case K.JsxElement:
    case K.JsxSelfClosingElement:
    case K.JsxFragment:
      return new Jsx([node], frame)
    default:
      return UNKNOWN
  }
}

function binary(node, frame, loose) {
  const operator = node.operatorToken.kind
  const left = evaluate(node.left, frame, loose)
  switch (operator) {
    case K.AmpersandAmpersandToken: {
      const settled = truth(left)
      return settled === false ? left : settled === true ? evaluate(node.right, frame, loose) : UNKNOWN
    }
    case K.BarBarToken: {
      const settled = truth(left)
      return settled === true ? left : settled === false ? evaluate(node.right, frame, loose) : UNKNOWN
    }
    case K.QuestionQuestionToken:
      return left === UNKNOWN ? UNKNOWN : left === null || left === undefined ? evaluate(node.right, frame, loose) : left
  }
  const right = evaluate(node.right, frame, loose)
  if (left === UNKNOWN || right === UNKNOWN) return UNKNOWN
  switch (operator) {
    case K.PlusToken:
      return (typeof left === 'string' || typeof left === 'number') && (typeof right === 'string' || typeof right === 'number') ? left + right : UNKNOWN
    case K.EqualsEqualsEqualsToken:
    case K.EqualsEqualsToken:
      return isPlain(left) && isPlain(right) ? left === right : UNKNOWN
    case K.ExclamationEqualsEqualsToken:
    case K.ExclamationEqualsToken:
      return isPlain(left) && isPlain(right) ? left !== right : UNKNOWN
  }
  return UNKNOWN
}

function isPlain(value) {
  return value === null || value === undefined || ['string', 'number', 'boolean'].includes(typeof value)
}

/* Words: what a label, a hint or a title says. */

/** JSX text as React draws it: each line trimmed, the lines joined by a space. */
function jsxTextOf(node) {
  if (node.containsOnlyTriviaWhiteSpaces) return ''
  return node.text.replace(/\s*\n\s*/g, ' ')
}

/** One space between words, none at the ends. */
function tidy(text) {
  return text.replace(/\s+/g, ' ').trim()
}

/**
 * What a value says as plain words, or UNKNOWN. Strictly, every part of JSX must be settled; `loose`, a part shown only
 * on a condition (`a && <b/>`) or a component is left out — but a value the server fills in still loses the whole.
 */
function textOf(value, loose = false) {
  if (typeof value === 'string') return tidy(value)
  if (typeof value === 'number') return String(value)
  if (value instanceof Jsx) {
    const words = value.nodes.map((node) => jsxWords(node, value.frame, loose))
    return words.includes(UNKNOWN) ? UNKNOWN : tidy(words.join(''))
  }
  return UNKNOWN
}

function jsxWords(node, frame, loose) {
  switch (node.kind) {
    case K.JsxText:
      return jsxTextOf(node)
    case K.JsxExpression: {
      const expression = unwrap(node.expression)
      if (!expression) return ''
      if (loose && expression.kind === K.BinaryExpression && expression.operatorToken.kind === K.AmpersandAmpersandToken && truth(evaluate(expression.left, frame)) === undefined) {
        const shown = textOf(evaluate(expression.right, frame, true), true)
        return shown === UNKNOWN ? '' : shown
      }
      const value = evaluate(expression, frame, loose)
      if (value === null || value === undefined || value === false || value === true) return ''
      return textOf(value, loose)
    }
    case K.JsxFragment:
    case K.JsxElement: {
      // A component inside words (a shop's name drawn by one) is words the source does not hold: the sentence would break.
      const tag = node.kind === K.JsxElement ? tagOf(node.openingElement.tagName) : ''
      if (/^[A-Z]/.test(tag) && !INLINE.has(tag)) return UNKNOWN
      const words = node.children.map((child) => jsxWords(child, frame, loose))
      return words.includes(UNKNOWN) ? UNKNOWN : words.join('')
    }
    case K.JsxSelfClosingElement: {
      const tag = tagOf(node.tagName)
      return tag === 'br' ? ' ' : /^[A-Z]/.test(tag) ? UNKNOWN : ''
    }
    default:
      return UNKNOWN
  }
}

/** Components that only wrap words — a link, a key —: their words are the sentence's. */
const INLINE = new Set(['TextLink', 'Kbd'])

/** A JSX tag's name: `Field`, `div`, `Card.Title`. */
function tagOf(tagName) {
  if (tagName.kind === K.Identifier) return tagName.text
  if (tagName.kind === K.PropertyAccessExpression) return `${tagOf(tagName.expression)}.${tagName.name.text}`
  if (tagName.kind === K.ThisKeyword) return 'this'
  return ''
}

/* Walking what a section draws. */

/** The settings found, by section, in the order the page draws them; a section's own words from its first card. */
const found = new Map()

/**
 * What the walk is inside of: the section being indexed, the card (`{ title, hint }`), the card's part and the fold
 * around the current element, and the components being followed (against a loop).
 */
function emptyContext(section) {
  return { section, card: null, part: null, fold: null, stack: [] }
}

function add(context, setting) {
  const entry = found.get(context.section)
  const same = entry.settings.find((other) => other.title === setting.title && other.anchor === setting.anchor && other.card === setting.card && other.part === setting.part)
  if (!same) entry.settings.push(setting)
}

/** A setting under the current card, part and fold. */
function settingOf(context, title, extra) {
  const setting = { title, ...extra }
  if (context.card && context.card.title !== title) setting.card = context.card.title
  if (context.part && context.part !== title) setting.part = context.part
  if (context.fold) setting.fold = context.fold
  for (const key of Object.keys(setting)) if (setting[key] === undefined || setting[key] === '') delete setting[key]
  return setting
}

/** The initializer of a JSX element's attribute, or undefined. */
function attributeOf(opening, name) {
  for (const attribute of opening.attributes.properties) {
    if (attribute.kind === K.JsxAttribute && attribute.name.text === name) return attribute
  }
  return undefined
}

/** An attribute's value: a string as written, `{expression}`'s value, true when bare. */
function attributeValue(attribute, frame, loose = false) {
  if (!attribute) return undefined
  if (!attribute.initializer) return true
  if (attribute.initializer.kind === K.StringLiteral) return attribute.initializer.text
  if (attribute.initializer.kind === K.JsxExpression) return attribute.initializer.expression ? evaluate(attribute.initializer.expression, frame, loose) : undefined
  return evaluate(attribute.initializer, frame, loose)
}

/** What an attribute says in words: UNKNOWN when unsettled, undefined when absent. */
function attributeText(opening, name, frame, loose = false) {
  const attribute = attributeOf(opening, name)
  if (!attribute) return undefined
  const value = attributeValue(attribute, frame, loose)
  return value === undefined || value === null || value === false ? undefined : textOf(value, loose)
}

/** Words that only help a search, or nothing — never UNKNOWN. */
function helpText(opening, names, frame) {
  for (const name of names) {
    const text = attributeText(opening, name, frame, true)
    if (text !== undefined && text !== UNKNOWN && text !== '') return text
  }
  return undefined
}

/** A component's props as its JSX element writes them; its children, as JSX. */
function propsOf(opening, children, frame) {
  const props = {}
  for (const attribute of opening.attributes.properties) {
    if (attribute.kind === K.JsxAttribute) props[attribute.name.text] = attributeValue(attribute, frame)
    else if (attribute.kind === K.JsxSpreadAttribute) {
      const spread = evaluate(attribute.expression, frame)
      if (spread && typeof spread === 'object' && !(spread instanceof Closure) && !(spread instanceof Jsx)) Object.assign(props, spread)
      else props[PARTIAL] = true
    }
  }
  if (children.length > 0) props.children = new Jsx(children, frame)
  return props
}

function warn(node, context, message) {
  warnings.push(`${where(node)} (${context.section}): ${message}`)
}

/** Walks whatever an expression draws. */
function walk(node, frame, context) {
  node = unwrap(node)
  if (!node) return
  switch (node.kind) {
    case K.JsxElement:
    case K.JsxSelfClosingElement:
      return walkElement(node, frame, context)
    case K.JsxFragment:
      return walkChildren(node.children, frame, context)
    case K.JsxExpression:
      return walk(node.expression, frame, context)
    case K.ConditionalExpression: {
      const condition = truth(evaluate(node.condition, frame))
      if (condition !== false) walk(node.whenTrue, frame, context)
      if (condition !== true) walk(node.whenFalse, frame, context)
      return
    }
    case K.BinaryExpression: {
      const operator = node.operatorToken.kind
      if (operator === K.AmpersandAmpersandToken) {
        if (truth(evaluate(node.left, frame)) !== false) walk(node.right, frame, context)
      } else if (operator === K.BarBarToken || operator === K.QuestionQuestionToken) {
        walk(node.left, frame, context)
        walk(node.right, frame, context)
      }
      return
    }
    case K.Identifier: {
      const value = valueOfIdentifier(node, frame)
      if (value instanceof Jsx) for (const jsx of value.nodes) walk(jsx, value.frame, context)
      return
    }
    case K.CallExpression:
      return walkCall(node, frame, context)
    case K.ArrayLiteralExpression:
      for (const element of node.elements) walk(element, frame, context)
      return
  }
}

function walkChildren(children, frame, context) {
  for (const child of children) if (child.kind !== K.JsxText) walk(child, frame, context)
}

/** A call that draws: `list.map(item => <…/>)` once per item when the list is settled, or a function of the file's that returns JSX. */
function walkCall(node, frame, context) {
  const callee = unwrap(node.expression)
  if (callee.kind === K.PropertyAccessExpression && (callee.name.text === 'map' || callee.name.text === 'flatMap')) {
    const callback = unwrap(node.arguments[0])
    if (!callback || (callback.kind !== K.ArrowFunction && callback.kind !== K.FunctionExpression)) return
    const list = evaluate(callee.expression, frame)
    const items = Array.isArray(list) ? list : [UNKNOWN]
    for (const [index, item] of items.entries()) {
      const run = new Frame(frame)
      if (callback.parameters[0]) run.values.set(callback.parameters[0], item)
      if (callback.parameters[1]) run.values.set(callback.parameters[1], Array.isArray(list) ? index : UNKNOWN)
      walkBody(callback.body, run, context)
    }
    return
  }
  if (callee.kind === K.Identifier) {
    const value = valueOfIdentifier(callee, frame)
    if (!(value instanceof Closure) || context.stack.includes(value.node) || context.stack.length > 12) return
    const run = new Frame(value.frame)
    for (const [index, parameter] of value.node.parameters.entries()) {
      run.values.set(parameter, node.arguments[index] ? evaluate(node.arguments[index], frame) : undefined)
    }
    walkBody(value.node.body, run, { ...context, stack: [...context.stack, value.node] })
  }
}

/** A function's body: its returns, each branch whose condition the source does not settle. */
function walkBody(body, frame, context) {
  if (!body) return
  if (body.kind === K.Block) walkStatements(body.statements, frame, context)
  else walk(body, frame, context)
}

/** Walks statements as they run; true once a return is certain. */
function walkStatements(statements, frame, context) {
  for (const statement of statements) {
    if (walkStatement(statement, frame, context)) return true
  }
  return false
}

function walkStatement(statement, frame, context) {
  switch (statement.kind) {
    case K.ReturnStatement:
      if (statement.expression) walk(statement.expression, frame, context)
      return true
    case K.Block:
      return walkStatements(statement.statements, frame, context)
    case K.IfStatement: {
      const condition = truth(evaluate(statement.expression, frame))
      const then = condition !== false ? walkStatement(statement.thenStatement, frame, context) : false
      const otherwise = condition !== true && statement.elseStatement ? walkStatement(statement.elseStatement, frame, context) : false
      if (condition === true) return then
      if (condition === false) return otherwise
      return then && otherwise
    }
    case K.SwitchStatement:
      for (const clause of statement.caseBlock.clauses) walkStatements(clause.statements, frame, context)
      return false
    default:
      return false
  }
}

/** A JSX element: a setting, a card, a part, a fold — or a component followed into, or a DOM element's children. */
function walkElement(node, frame, context) {
  const opening = node.kind === K.JsxElement ? node.openingElement : node
  const children = node.kind === K.JsxElement ? node.children : []
  const tag = tagOf(opening.tagName)
  if (tag === '' || /^[a-z]/.test(tag)) return walkChildren(children, frame, context)
  if (SKIPPED.has(tag) || tag === 'CardTitle' || tag === 'CardDescription') return

  if (FIELDS.has(tag) || tag === 'SwitchRow' || tag === 'FieldGroup') {
    const label = attributeText(opening, 'label', frame)
    if (label === undefined) return walkChildren(children, frame, context)
    if (label === UNKNOWN || label === '') {
      warn(node, context, `${tag}: its label is not in the source — not indexed`)
      return walkChildren(children, frame, context)
    }
    const extra = { hint: helpText(opening, tag === 'FieldGroup' ? ['description', 'hint'] : ['hint'], frame) }
    if (FIELDS.has(tag)) {
      const id = attributeValue(attributeOf(opening, 'id'), frame)
      if (typeof id === 'string' && id !== '') extra.anchor = id
      else warn(node, context, `${tag} «${label}»: its id is not in the source — found by its label`)
    }
    add(context, settingOf(context, label, extra))
    return walkChildren(children, frame, context)
  }

  if (tag === 'SectionCard' || tag === 'SwitchCard') {
    const card = cardOf(node, attributeText(opening, 'title', frame), helpText(opening, ['description'], frame), context)
    const inner = { ...context, card: card ?? context.card, part: null }
    if (tag === 'SwitchCard') {
      const label = attributeText(opening, 'label', frame)
      if (typeof label === 'string' && label !== '') add(inner, settingOf(inner, label, { hint: helpText(opening, ['hint'], frame) }))
      else warn(node, context, 'SwitchCard: its label is not in the source — not indexed')
      return
    }
    return walkChildren(children, frame, inner)
  }

  if (tag === 'Card') {
    const title = findInside(children, 'CardTitle')
    const description = findInside(children, 'CardDescription')
    const card = title ? cardOf(node, textOf(new Jsx(title.children, frame)), description ? textOrNothing(textOf(new Jsx(description.children, frame), true)) : undefined, context) : null
    return walkChildren(children, frame, { ...context, card: card ?? context.card, part: null })
  }

  if (tag === 'FormPart') {
    const title = attributeText(opening, 'title', frame)
    if (typeof title !== 'string' || title === '') {
      warn(node, context, 'FormPart: its title is not in the source — not indexed')
      return walkChildren(children, frame, context)
    }
    add(context, settingOf({ ...context, part: null }, title, { hint: helpText(opening, ['about'], frame) }))
    return walkChildren(children, frame, { ...context, part: title })
  }

  if (tag === 'Disclosure') {
    const label = attributeText(opening, 'label', frame)
    return walkChildren(children, frame, { ...context, fold: typeof label === 'string' && label !== '' ? label : context.fold })
  }

  // A component: followed into, with its props as written here — or, from a package, only its children.
  const component = componentOf(opening.tagName, frame)
  if (!component || context.stack.includes(component.node) || context.stack.length > 12) return walkChildren(children, frame, context)
  const run = new Frame(component.frame)
  const parameter = component.node.parameters[0]
  if (parameter) run.values.set(parameter, propsOf(opening, children, frame))
  walkBody(component.node.body, run, { ...context, stack: [...context.stack, component.node] })
}

function textOrNothing(text) {
  return text === UNKNOWN || text === '' ? undefined : text
}

/** A card met: an entry of its own, and its description the section's words when it is the section's first. */
function cardOf(node, title, description, context) {
  if (typeof title !== 'string' || title === '') {
    if (title === UNKNOWN) warn(node, context, 'a card whose title is not in the source — not indexed')
    return null
  }
  const entry = found.get(context.section)
  if (entry.hint === undefined && description) entry.hint = description
  const card = { title, hint: description }
  add(context, settingOf({ ...context, card: null, part: null }, title, { hint: description }))
  return card
}

/** The first `tag` element among JSX children, looking inside DOM elements and Card parts but not other cards. */
function findInside(children, tag) {
  for (const child of children) {
    if (child.kind !== K.JsxElement && child.kind !== K.JsxSelfClosingElement) continue
    const opening = child.kind === K.JsxElement ? child.openingElement : child
    const name = tagOf(opening.tagName)
    if (name === tag) return child
    if (name === 'Card' || child.kind !== K.JsxElement) continue
    const inner = findInside(child.children, tag)
    if (inner) return inner
  }
  return null
}

/** The function a component's tag names — its file's own or one imported from the panel's source —, never a primitive of components/ui. */
function componentOf(tagName, frame) {
  if (tagName.kind !== K.Identifier) return null
  const declaration = declarationOf(tagName)
  if (!declaration) return null
  if (declaration.kind === 'import') {
    const file = moduleFile(declaration.module, declaration.from)
    if (!file || file.startsWith(path.join(src, 'components', 'ui'))) return null
    const target = exportOf(file, declaration.name)
    return target ? functionOf(target, new Frame()) : null
  }
  return functionOf(declaration, frame)
}

function functionOf(declaration, frame) {
  if (declaration.kind === 'function') return new Closure(declaration.node, frame)
  if (declaration.kind === 'variable' && declaration.declaration.initializer) {
    const value = unwrap(declaration.declaration.initializer)
    if (value.kind === K.ArrowFunction || value.kind === K.FunctionExpression) return new Closure(value, frame)
  }
  return null
}

/* The index. */

/** Each section's settings, walked from the components SECTIONS names for it; then what ABOUT says of the sections it names. */
function indexSections() {
  for (const [section, components] of Object.entries(SECTIONS)) {
    found.set(section, { hint: undefined, settings: [] })
    for (const component of components) {
      const [file, name] = component.split('#')
      const target = exportOf(path.join(src, file), name)
      const closure = target && functionOf(target, new Frame())
      if (!closure) throw new Error(`${component} (${section}): no such exported component — SECTIONS in scripts/settings-index.mjs`)
      // The section's props are the page's data: none of it is in the source.
      const run = new Frame()
      if (closure.node.parameters[0]) run.values.set(closure.node.parameters[0], { [PARTIAL]: true })
      walkBody(closure.node.body, run, emptyContext(section))
    }
  }
  for (const [section, reference] of Object.entries(ABOUT)) {
    const { title, description } = aboutOf(reference)
    if (!found.has(section)) found.set(section, { hint: undefined, settings: [] })
    const entry = found.get(section)
    entry.hint = description
    if (title) entry.alias = title
  }
  return found
}

/** What a page header's constant (`file#NAME` or `file#NAME.key`) says: its description, and its title when it is an object. */
function aboutOf(reference) {
  const [file, expression] = reference.split('#')
  const [name, ...keys] = expression.split('.')
  const source = sourceFile(path.join(src, file))
  const declaration = source && declarationIn(source, name)
  let value = declaration ? valueOfDeclaration(declaration, new Frame()) : UNKNOWN
  for (const key of keys) value = member(value, key)
  const object = value !== null && typeof value === 'object' && !(value instanceof Jsx)
  const description = textOf(object ? member(value, 'description') : value, true)
  const title = object ? textOf(member(value, 'title')) : undefined
  if (typeof description !== 'string' || description === '') throw new Error(`${reference}: no description in the source — ABOUT in scripts/settings-index.mjs`)
  return { title: typeof title === 'string' && title !== '' ? title : undefined, description }
}

/** A stable slug of a label, for a setting without an id: its words, lower-case, Arabic letters as Persian, joined by dashes. */
function slugOf(text) {
  return text
    .toLowerCase()
    .replace(/[\u064a\u0649\u0626]/g, '\u06cc')
    .replace(/\u0643/g, '\u06a9')
    .replace(/[\u0629\u06c0]/g, '\u0647')
    .replace(/[\u0623\u0625\u0671\u0622]/g, '\u0627')
    .replace(/\u0624/g, '\u0648')
    .replace(/[\u064b-\u065f\u0670\u0640\u0621]/g, '')
    .replace(/[^\p{L}\p{N}]+/gu, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 48)
    .replace(/-+$/, '')
}

/** The generated module: each section's words and settings, each setting under a key unique in its section (its id, else its label's slug). */
function moduleOf() {
  const lines = []
  for (const section of [...sections.keys()].sort()) {
    const { hint, alias, settings } = sections.get(section)
    const keys = new Set()
    const rows = settings.map((setting) => {
      const base = setting.anchor ?? (slugOf(setting.title) || 'setting')
      let key = base
      for (let n = 2; keys.has(key); n++) key = `${base}-${n}`
      keys.add(key)
      return { key, ...setting }
    })
    const words = [hint && `hint: ${JSON.stringify(hint)}`, alias && `alias: ${JSON.stringify(alias)}`, `settings: ${JSON.stringify(rows)}`].filter(Boolean)
    lines.push(`  ${JSON.stringify(section)}: { ${words.join(', ')} },`)
  }

  return `/*
 * The settings search's index of single settings, by section — generated from the sections' source by
 * \`pnpm settings:index\` (scripts/settings-index.mjs): do not edit by hand; change the sections (or SECTIONS there),
 * then run it again. \`pnpm check\` fails while this file is not what the sources give.
 */

/** A setting as its section draws it: a field, a switch, a group of controls, a card, or a titled part of a card. */
interface GeneratedSetting {
  /** Unique in its section: the control's id, else a slug of its title — what its entry's id ends with. */
  key: string
  title: string
  /** The control's DOM id (a Field's), when it has a fixed one. */
  anchor?: string
  hint?: string
  /** The card it sits in; none for a card itself. */
  card?: string
  /** The titled part of the card (FormPart) it sits in. */
  part?: string
  /** The fold (Disclosure) it sits in, opened on arrival. */
  fold?: string
}

/** A section's words and settings — a feature page's own settings card under the page's address. */
interface GeneratedSection {
  /** What the page's header says of it, else its first card's description. */
  hint?: string
  /** The page header's own title for it, when that differs from the nav's («تنظیمات اکانت تست»). */
  alias?: string
  settings: GeneratedSetting[]
}

/** Each section's words and settings, by its address in the shop. */
export const GENERATED_SETTINGS: Record<string, GeneratedSection> = {
${lines.join('\n')}
}
`
}

const api = new API({ cwd: root })
let sections
try {
  const project = api.updateSnapshot({ openProjects: [path.join(root, 'tsconfig.json')] }).getProjects()[0]
  if (!project) throw new Error('tsconfig.json opened no project')
  program = project.program
  sections = indexSections()
} finally {
  api.close()
}

const options = await prettier.resolveConfig(outPath)
const formatted = await prettier.format(moduleOf(), { ...options, filepath: outPath })
const total = [...sections.values()].reduce((sum, section) => sum + section.settings.length, 0)

if (process.argv.includes('--check')) {
  const current = fs.existsSync(outPath) ? fs.readFileSync(outPath, 'utf8') : ''
  if (current !== formatted) {
    console.error('resources/panel/src/lib/settings-search/settings.generated.ts is not what the settings sections give: run `pnpm settings:index`.')
    process.exit(1)
  }
  console.log(`settings.generated.ts matches the settings sections (${total} settings; ${warnings.length} skipped or found by label: \`pnpm settings:index\` lists them).`)
} else {
  for (const warning of warnings) console.warn(`  ${warning}`)
  for (const [section, { settings }] of [...sections.entries()].sort(([a], [b]) => a.localeCompare(b))) console.log(`  ${section}: ${settings.length}`)
  fs.writeFileSync(outPath, formatted)
  console.log(`Wrote ${path.relative(root, outPath).replaceAll('\\', '/')} (${total} settings in ${sections.size} sections; ${warnings.length} warnings above).`)
}
