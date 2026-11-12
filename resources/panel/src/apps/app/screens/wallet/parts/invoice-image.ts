import { invoiceGroups, invoiceNumber, orderStatus, orderTitle } from '@/apps/app/screens/wallet/parts/orders'
import type { StoreOrder } from '@/lib/api-types'
import { formatDate, initials } from '@/lib/format'

/*
 * The invoice as a picture to keep: drawn on a canvas here — the browser shapes the Persian text, right to left, in the
 * app's own font and colours —, as the invoice screen shows it: a header in the shop's colour with its logo and name,
 * the invoice's number and date, then its facts by group, the total larger, and a word of thanks under a ticket's tear.
 * Telegram's own download wants an https address, so the picture is a PNG in the page for the customer to hold and save.
 */

export interface InvoiceLook {
  /** The shop's name as the app shows it. */
  title: string
  /** Its logo's address (`app.logo_url`); drawn only from the app's own origin, which never taints the canvas. */
  logo: string | null
  /** The bot's username, without the @, for the footer. */
  bot: string | null
}

/** The picture's width in CSS pixels; it is drawn at twice that for a phone's screen. */
const WIDTH = 720
const SCALE = 2
const MARGIN = 28
const PAD = 40
const HEADER = 190
const GROUP_TITLE = 52
const ROW = 46
const GROUP_GAP = 14
const FOOTER = 130
const RADIUS = 28

interface Palette {
  page: string
  card: string
  text: string
  hint: string
  accent: string
  onAccent: string
  success: string
  separator: string
}

/** The app's colours as the canvas takes them, read off the page (a colour the page cannot give stays its fallback). */
function palette(): Palette {
  const probe = document.createElement('span')
  probe.style.display = 'none'
  document.body.append(probe)
  const read = (variable: string, fallback: string): string => {
    probe.style.color = fallback
    probe.style.color = `var(${variable}, ${fallback})`
    const color = getComputedStyle(probe).color
    return color === '' || color.includes('var(') ? fallback : color
  }
  const colors: Palette = {
    page: read('--app-page', '#efeff4'),
    card: read('--app-section', '#ffffff'),
    text: read('--app-text', '#000000'),
    hint: read('--app-hint', '#8e8e93'),
    accent: read('--app-accent', '#2481cc'),
    onAccent: read('--app-on-accent', '#ffffff'),
    success: read('--app-success', '#31a24c'),
    separator: read('--app-separator', 'rgba(0, 0, 0, 0.09)'),
  }
  probe.remove()

  return colors
}

/** A rectangle with round corners, as a path (arcTo: older webviews have no roundRect). */
function roundRect(ctx: CanvasRenderingContext2D, x: number, y: number, width: number, height: number, radius: number): void {
  ctx.beginPath()
  ctx.moveTo(x + radius, y)
  ctx.arcTo(x + width, y, x + width, y + height, radius)
  ctx.arcTo(x + width, y + height, x, y + height, radius)
  ctx.arcTo(x, y + height, x, y, radius)
  ctx.arcTo(x, y, x + width, y, radius)
  ctx.closePath()
}

/** `text` cut to `width` with an ellipsis. */
function fit(ctx: CanvasRenderingContext2D, text: string, width: number): string {
  if (ctx.measureText(text).width <= width) return text
  let cut = text
  while (cut.length > 1 && ctx.measureText(`${cut}…`).width > width) cut = cut.slice(0, -1)
  return `${cut}…`
}

/** A line of text at (x, y): right to left, or left to right for a number or a name in Latin. */
function write(ctx: CanvasRenderingContext2D, text: string, x: number, y: number, align: 'right' | 'left' | 'center', ltr = false): void {
  ctx.direction = ltr ? 'ltr' : 'rtl'
  ctx.textAlign = align
  ctx.fillText(text, x, y)
}

/** The shop's logo, when it is the app's own picture and it loads in time; else null (its initials are drawn instead). */
async function logoImage(address: string | null): Promise<HTMLImageElement | null> {
  if (address === null) return null
  const url = new URL(address, document.baseURI)
  if (url.origin !== window.location.origin) return null
  const image = new Image()
  image.src = url.href
  try {
    await Promise.race([image.decode(), new Promise((_, reject) => setTimeout(reject, 3000))])
    return image
  } catch {
    return null
  }
}

/** The invoice of `order` as a PNG. */
export async function invoiceImage(order: StoreOrder, look: InvoiceLook): Promise<Blob> {
  await document.fonts?.ready
  const colors = palette()
  const family = getComputedStyle(document.body).fontFamily || 'Vazirmatn, sans-serif'
  const font = (size: number, weight = 400) => `${weight} ${size}px ${family}`
  const logo = await logoImage(look.logo)

  const groups = invoiceGroups(order)
  const height = MARGIN * 2 + HEADER + groups.reduce((sum, group) => sum + GROUP_TITLE + group.rows.length * ROW + GROUP_GAP, 0) + FOOTER

  const canvas = document.createElement('canvas')
  canvas.width = WIDTH * SCALE
  canvas.height = height * SCALE
  const ctx = canvas.getContext('2d')
  if (ctx === null) throw new Error('No 2D canvas here.')
  ctx.scale(SCALE, SCALE)
  ctx.textBaseline = 'middle'

  const left = MARGIN
  const right = WIDTH - MARGIN
  const inner = { left: left + PAD, right: right - PAD }

  // The page, and the card on it.
  ctx.fillStyle = colors.page
  ctx.fillRect(0, 0, WIDTH, height)
  ctx.save()
  ctx.shadowColor = 'rgba(0, 0, 0, 0.10)'
  ctx.shadowBlur = 30
  ctx.shadowOffsetY = 8
  ctx.fillStyle = colors.card
  roundRect(ctx, left, MARGIN, right - left, height - MARGIN * 2, RADIUS)
  ctx.fill()
  ctx.restore()

  // The header in the shop's colour, lit from its top corner.
  ctx.save()
  roundRect(ctx, left, MARGIN, right - left, HEADER + RADIUS, RADIUS)
  ctx.clip()
  ctx.fillStyle = colors.accent
  ctx.fillRect(left, MARGIN, right - left, HEADER)
  const light = ctx.createLinearGradient(right, MARGIN, left, MARGIN + HEADER)
  light.addColorStop(0, 'rgba(255, 255, 255, 0.28)')
  light.addColorStop(0.55, 'rgba(255, 255, 255, 0)')
  light.addColorStop(1, 'rgba(0, 0, 0, 0.22)')
  ctx.fillStyle = light
  ctx.fillRect(left, MARGIN, right - left, HEADER)
  ctx.restore()

  const logoSize = 72
  const logoCenter = { x: inner.right - logoSize / 2, y: MARGIN + 66 }
  ctx.save()
  ctx.beginPath()
  ctx.arc(logoCenter.x, logoCenter.y, logoSize / 2, 0, Math.PI * 2)
  ctx.fillStyle = 'rgba(255, 255, 255, 0.22)'
  ctx.fill()
  if (logo !== null) {
    ctx.clip()
    ctx.drawImage(logo, logoCenter.x - logoSize / 2, logoCenter.y - logoSize / 2, logoSize, logoSize)
  } else {
    ctx.fillStyle = colors.onAccent
    ctx.font = font(32, 800)
    write(ctx, initials(look.title) || '؟', logoCenter.x, logoCenter.y + 2, 'center')
  }
  ctx.restore()

  ctx.fillStyle = colors.onAccent
  ctx.font = font(28, 800)
  write(ctx, fit(ctx, look.title, inner.right - inner.left - logoSize - 200), inner.right - logoSize - 18, MARGIN + 52, 'right')
  ctx.globalAlpha = 0.85
  ctx.font = font(18, 500)
  write(ctx, 'فاکتور الکترونیکی', inner.right - logoSize - 18, MARGIN + 86, 'right')
  ctx.globalAlpha = 1

  ctx.font = font(30, 800)
  write(ctx, invoiceNumber(order), inner.left, MARGIN + 52, 'left', true)
  ctx.globalAlpha = 0.85
  ctx.font = font(17, 500)
  write(ctx, formatDate(order.created_at), inner.left, MARGIN + 86, 'left')
  ctx.globalAlpha = 1

  ctx.font = font(20, 700)
  write(ctx, fit(ctx, orderTitle(order), inner.right - inner.left - 180), inner.right, MARGIN + 142, 'right')
  const status = orderStatus(order.status).label
  ctx.font = font(16, 700)
  const pill = ctx.measureText(status).width + 28
  ctx.fillStyle = 'rgba(255, 255, 255, 0.22)'
  roundRect(ctx, inner.left, MARGIN + 124, pill, 36, 18)
  ctx.fill()
  ctx.fillStyle = colors.onAccent
  write(ctx, status, inner.left + pill / 2, MARGIN + 143, 'center')

  // The ticket's tear under the header: a notch at each edge, a dashed line between them.
  const tear = MARGIN + HEADER
  ctx.fillStyle = colors.page
  for (const x of [left, right]) {
    ctx.beginPath()
    ctx.arc(x, tear, 14, 0, Math.PI * 2)
    ctx.fill()
  }
  ctx.strokeStyle = colors.separator
  ctx.lineWidth = 2
  ctx.setLineDash([10, 8])
  ctx.beginPath()
  ctx.moveTo(left + 22, tear)
  ctx.lineTo(right - 22, tear)
  ctx.stroke()
  ctx.setLineDash([])

  // The facts, by group.
  let y = tear + 8
  for (const group of groups) {
    ctx.fillStyle = colors.accent
    ctx.font = font(17, 700)
    write(ctx, group.title, inner.right, y + GROUP_TITLE / 2 + 6, 'right')
    y += GROUP_TITLE
    group.rows.forEach((row, index) => {
      const middle = y + ROW / 2
      ctx.fillStyle = colors.hint
      ctx.font = font(18, 400)
      write(ctx, row.label, inner.right, middle, 'right')
      const labelWidth = ctx.measureText(row.label).width
      ctx.fillStyle = row.tone === 'success' ? colors.success : row.strong ? colors.accent : colors.text
      ctx.font = row.strong ? font(24, 800) : font(19, 600)
      write(ctx, fit(ctx, row.value, inner.right - inner.left - labelWidth - 28), inner.left, middle, 'left', row.ltr)
      if (index < group.rows.length - 1) {
        ctx.fillStyle = colors.separator
        ctx.fillRect(inner.left, y + ROW - 0.5, inner.right - inner.left, 1)
      }
      y += ROW
    })
    y += GROUP_GAP
  }

  // The thanks, and a tear along the bottom.
  ctx.fillStyle = colors.separator
  ctx.fillRect(inner.left, y + 6, inner.right - inner.left, 1)
  ctx.fillStyle = colors.text
  ctx.font = font(19, 700)
  write(ctx, 'با سپاس از خرید شما', WIDTH / 2, y + 48, 'center')
  if (look.bot !== null) {
    ctx.fillStyle = colors.hint
    ctx.font = font(16, 500)
    write(ctx, `@${look.bot}`, WIDTH / 2, y + 80, 'center', true)
  }
  const bottom = height - MARGIN
  ctx.fillStyle = colors.page
  for (let x = left + 18; x < right - 8; x += 26) {
    ctx.beginPath()
    ctx.arc(x, bottom, 7, 0, Math.PI * 2)
    ctx.fill()
  }

  return new Promise<Blob>((resolve, reject) => canvas.toBlob((blob) => (blob === null ? reject(new Error('The canvas made no picture.')) : resolve(blob)), 'image/png'))
}
