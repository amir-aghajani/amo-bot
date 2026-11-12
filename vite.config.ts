/// <reference types="vitest/config" />
import { createHash } from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import zlib from 'node:zlib'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, type Plugin } from 'vite'

const projectRoot = path.dirname(fileURLToPath(import.meta.url))

/** The panels, each a page of its own built from the same sources: the owner's at /admin/, an agent's at /agent/. */
const PANELS = ['admin', 'agent'] as const

/**
 * The shop's Telegram mini app, at /app/{its store key}/: a page of its own from the same sources, built apart
 * (`vite build --mode app`) into public/app with hashed files of its own (public/app/assets), so a customer's phone
 * loads none of the panels' code and the panels none of its.
 */
const MINI_APP = 'app'

/** Where the build writes: public/, beside the PHP front controller — which is why the build cleans only its own outputs. */
const outDir = path.join(projectRoot, 'public')

/** What a build writes there: each panel's page and the hashed files both share — or the mini app's folder. */
const outputsOf = (miniApp: boolean) => (miniApp ? [MINI_APP] : [...PANELS, 'assets'])

/** Where a build's hashed files go, under public/. */
const assetsOf = (miniApp: boolean) => (miniApp ? `${MINI_APP}/assets` : 'assets')

/** The smallest script or stylesheet worth a brotli copy beside it. */
const BROTLI_MIN_BYTES = 1024

/* What the chunks are told apart by (the build's codeSplitting, below). */
const NODE_MODULES = /[\\/]node_modules[\\/]/
const REACT = /[\\/]node_modules[\\/](react|react-dom|scheduler)[\\/]/
const ICONS = /[\\/]node_modules[\\/]lucide-react[\\/]/
const PANEL_SOURCES = /[\\/]resources[\\/]panel[\\/]src[\\/]/
/** A panel's own code (src/apps/admin, src/apps/agent — and the mini app's, src/apps/app, which they never load). */
const OWN_APPS = /[\\/]src[\\/]apps[\\/]/
const SHARED_SOURCE = (id: string) => PANEL_SOURCES.test(id) && !OWN_APPS.test(id)

/**
 * Who may show the mini app in a frame (its folder's app/.htaccess, written by the build): Telegram's web client, and the
 * site itself — the panels' preview of the app (components/mini-app/preview), on the same site.
 */
export const MINI_APP_FRAME_ANCESTORS = "frame-ancestors 'self' https://web.telegram.org"

/**
 * A built page's Content-Security-Policy: the build's own files and the site's origin, the page's inline scripts by
 * their hashes. The mini app also shows the customer's Telegram picture, from t.me (its account card); the panels load
 * no picture from anywhere else. The panels frame the site's own pages alone (the mini app's preview); the mini app
 * frames nothing.
 */
export function pagePolicy(miniApp: boolean, html: string): string {
  const hashes = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(([, script = '']) => `'sha256-${createHash('sha256').update(script).digest('base64')}'`)

  return [
    "default-src 'self'",
    `script-src 'self' ${hashes.join(' ')}`,
    // Radix positions its layers with inline styles, the overview's chart the box of a day's figure.
    "style-src 'self' 'unsafe-inline'",
    `img-src 'self' data: blob:${miniApp ? ' https://t.me' : ''}`,
    "font-src 'self' data:",
    "media-src 'self' blob:",
    "connect-src 'self'",
    // A panel frames the mini app beside it (its preview), on the same site, and nothing else; the mini app frames nothing.
    `frame-src ${miniApp ? "'none'" : "'self'"}`,
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
  ].join('; ')
}

/**
 * What the production build adds:
 * - each page's Content-Security-Policy (pagePolicy): only the build's files and the site's origin — and, for the mini
 *   app, the customer's Telegram picture —; the page's inline script (which sets its <base> before anything loads) is
 *   allowed by its hash, taken from the built page, so it never goes stale;
 * - for the mini app, app/.htaccess: Telegram's web client shows it in a frame, and so does the panels' preview of it
 *   (the panels' pages may be framed by nobody), so its folder lets web.telegram.org and the site itself frame it, and
 *   nobody else (MINI_APP_FRAME_ANCESTORS);
 * - beside each script and stylesheet a brotli copy (x.js.br, best level, made once), and assets/.htaccess: the hashed
 *   files are cached for good, and a browser that reads brotli gets the .br as it is — nothing compressed per request;
 * - a clean slate: public/ also holds the PHP front controller, so the build removes only its own outputs.
 */
function production(miniApp: boolean): Plugin {
  const assets = assetsOf(miniApp)
  return {
    name: 'amobot-production',
    apply: 'build',
    buildStart() {
      for (const output of outputsOf(miniApp)) {
        fs.rmSync(path.join(outDir, output), { recursive: true, force: true })
      }
    },
    transformIndexHtml: {
      order: 'post',
      handler(html) {
        const policy = pagePolicy(miniApp, html)

        return html.replace('<meta charset="utf-8" />', `<meta charset="utf-8" />\n    <meta http-equiv="Content-Security-Policy" content="${policy}" />`)
      },
    },
    generateBundle() {
      if (miniApp) {
        this.emitFile({
          type: 'asset',
          fileName: `${MINI_APP}/.htaccess`,
          source: [
            '# Written by the build (vite.config.ts). The mini app opens inside Telegram: its web client (web.telegram.org) shows',
            '# it in a frame, which public/.htaccess refuses every other page — and so does the preview in the panels, on this',
            '# site. Its folder lets those two frame it, and no other.',
            '<IfModule mod_headers.c>',
            '    Header unset X-Frame-Options',
            `    Header set Content-Security-Policy "${MINI_APP_FRAME_ANCESTORS}"`,
            '</IfModule>',
            '',
          ].join('\n'),
        })
      }
      this.emitFile({
        type: 'asset',
        fileName: `${assets}/.htaccess`,
        source: [
          '# Written by the build (vite.config.ts). Every file here is named by a hash of its content and never changes: a',
          '# browser keeps it for good. A script or a stylesheet has its brotli beside it (x.js.br), made once by the build:',
          '# a browser that reads brotli is given that file as it is — never compressed again on the way —, any other the',
          '# file itself (compressed by public/.htaccess, where the host can).',
          '<IfModule mod_headers.c>',
          '    Header set Cache-Control "public, max-age=31536000, immutable"',
          '    <FilesMatch "\\.(js|css|svg)(\\.br)?$">',
          '        Header merge Vary Accept-Encoding',
          '    </FilesMatch>',
          '</IfModule>',
          '<IfModule mod_rewrite.c>',
          '    <IfModule mod_mime.c>',
          '        RewriteEngine On',
          '        RewriteCond %{HTTP:Accept-Encoding} \\bbr\\b',
          '        RewriteCond %{REQUEST_FILENAME}.br -f',
          '        RewriteRule \\.(js|css|svg)$ %{REQUEST_URI}.br [L]',
          '        RewriteRule \\.br$ - [E=no-gzip:1,E=no-brotli:1]',
          '        AddEncoding br .br',
          "        # A .br file's type is its script's or its stylesheet's. Apache reads it off the extension before .br;",
          '        # LiteSpeed takes the last one alone and would send application/octet-stream, which a browser neither runs',
          '        # as a module nor applies as a stylesheet: the panel would never start. Named twice, by mod_mime and by',
          '        # mod_headers, so a server that honours either one sends the right type.',
          ...[
            ['js', 'text/javascript'],
            ['css', 'text/css'],
            ['svg', 'image/svg+xml'],
          ].flatMap(([extension, type]) => [
            `        <FilesMatch "\\.${extension}\\.br$">`,
            `            ForceType ${type}`,
            '            <IfModule mod_headers.c>',
            `                Header set Content-Type "${type}"`,
            '            </IfModule>',
            '        </FilesMatch>',
          ]),
          '    </IfModule>',
          '</IfModule>',
          '',
        ].join('\n'),
      })
    },
    writeBundle({ dir = outDir }, bundle) {
      for (const fileName of Object.keys(bundle)) {
        if (!fileName.startsWith(`${assets}/`) || !/\.(js|css|svg)$/.test(fileName)) continue
        const file = path.join(dir, fileName)
        const source = fs.readFileSync(file)
        if (source.length < BROTLI_MIN_BYTES) continue
        const packed = zlib.brotliCompressSync(source, { params: { [zlib.constants.BROTLI_PARAM_QUALITY]: zlib.constants.BROTLI_MAX_QUALITY, [zlib.constants.BROTLI_PARAM_SIZE_HINT]: source.length } })
        if (packed.length < source.length) fs.writeFileSync(`${file}.br`, packed)
      }
    },
  }
}

/**
 * `pnpm dev` serves the panels as the web server does: any address under /admin/, /agent/ or /app/ that names no file is
 * that page (a deep link), and the site's root opens the owner's.
 */
function panelPages(): Plugin {
  return {
    name: 'amobot-panel-pages',
    apply: 'serve',
    configureServer(server) {
      server.middlewares.use((request, response, next) => {
        const pathname = new URL(request.url ?? '/', 'http://localhost').pathname
        if (pathname === '/') {
          response.writeHead(302, { Location: '/admin/' }).end()
          return
        }
        const panel = [...PANELS, MINI_APP].find((name) => pathname === `/${name}` || pathname.startsWith(`/${name}/`))
        if (panel && !/\.\w+$/.test(pathname)) request.url = `/${panel}/index.html`
        next()
      })
    },
  }
}

/**
 * The panels are static files: `pnpm build` writes them to public/ — admin/index.html and agent/index.html, and the
 * hashed files they share in assets/ — where the web server serves them as they are (public/.htaccess sends any other
 * address under a panel's folder to its page), and they talk to PHP only through the JSON API. Its second step,
 * `vite build --mode app`, writes the mini app the same way to public/app (MINI_APP).
 * `pnpm dev` serves all three — http://127.0.0.1:5173/admin/, /agent/ and /app/{key}/ — and hands /api to the PHP
 * server on :8080.
 */
export default defineConfig(({ command, mode }) => {
  const miniApp = mode === MINI_APP

  return {
    root: 'resources/panel',
    // Vite's and Vitest's caches stay with the dependencies, not in the panel's sources (the root above).
    cacheDir: path.join(projectRoot, 'node_modules/.vite'),
    // Built, every address is relative and each page sets its base to its panel's folder, so the same build works
    // from any sub-folder install and any of a panel's pages.
    base: command === 'serve' ? '/' : './',
    appType: 'mpa',
    publicDir: false,
    // Which build a page runs (lib/config's panelBuild): the moment it was made — what a failure's details and its report
    // to the server name, so a tab left open across an upgrade says it runs the old one.
    define: { __PANEL_BUILD__: JSON.stringify(new Date().toISOString().replace(/\.\d+Z$/, 'Z')) },
    plugins: [react(), tailwindcss(), production(miniApp), panelPages()],
    resolve: {
      alias: { '@': path.join(projectRoot, 'resources/panel/src') },
    },
    build: {
      outDir,
      // The front controller lives in public/ too: production() removes the build's own outputs instead.
      emptyOutDir: false,
      assetsDir: assetsOf(miniApp),
      rolldownOptions: miniApp
        ? {
            // The app, and its bridge to a VPN app on the device (app/open.html: Telegram opens only web addresses).
            input: { [MINI_APP]: path.join(projectRoot, `resources/panel/${MINI_APP}/index.html`), open: path.join(projectRoot, `resources/panel/${MINI_APP}/open.html`) },
            output: {
              // React and the other libraries in chunks of their own, kept by a phone across the shop's upgrades.
              codeSplitting: {
                groups: [
                  { name: 'react', test: REACT, tags: ['$initial'], priority: 2 },
                  { name: 'vendor', test: (id) => NODE_MODULES.test(id) || id.includes('\0vite/modulepreload-polyfill'), tags: ['$initial'], priority: 1 },
                ],
              },
            },
          }
        : {
            input: Object.fromEntries(PANELS.map((panel) => [panel, path.join(projectRoot, `resources/panel/${panel}/index.html`)])),
            output: {
              // The chunks, by how often they change and who needs them:
              // - `react` and `vendor`: the libraries the first screen needs. They change only with a dependency, so a
              //   browser keeps them across the shop's upgrades;
              // - `app`: both panels' shared first-load code (and the icons it draws);
              // - `common`: what three pages or more share, loaded with the first of them; the rest goes with its page.
              // A panel's own code (src/apps/…) stays in its own chunks: an agent's page never references the owner's.
              codeSplitting: {
                groups: [
                  { name: 'react', test: REACT, tags: ['$initial'], priority: 4 },
                  { name: 'vendor', test: (id) => (NODE_MODULES.test(id) && !ICONS.test(id)) || id.includes('\0vite/modulepreload-polyfill'), tags: ['$initial'], priority: 3 },
                  { name: 'app', test: (id) => SHARED_SOURCE(id) || ICONS.test(id), tags: ['$initial'], priority: 2 },
                  { name: 'common', test: (id) => SHARED_SOURCE(id) || NODE_MODULES.test(id), minShareCount: 3, priority: 1 },
                ],
              },
            },
          },
    },
    server: {
      // Explicit IPv4: Node maps "localhost" to ::1, where the PHP dev server (127.0.0.1:8080) does not listen.
      host: '127.0.0.1',
      port: 5173,
      strictPort: true,
      proxy: { '/api': 'http://127.0.0.1:8080' },
    },
    // `pnpm test`: the unit tests beside what they test (src/**/*.test.ts[x]), in a happy-dom page at the owner's panel
    // (/admin/, which lib/config reads), every request answered by src/test/server and held to the API description
    // (src/test/global-setup), in UTC, shuffled each run so no test leans on another. A frame (the mini app's preview)
    // opens nothing and says nothing of it: its address is set and its page never asked for.
    test: {
      include: ['src/**/*.test.{ts,tsx}'],
      environment: 'happy-dom',
      environmentOptions: {
        happyDOM: { url: 'http://localhost/admin/', settings: { enableIframePageLoading: true, navigation: { disableChildFrameNavigation: true } } },
      },
      env: { TZ: 'UTC' },
      globalSetup: ['src/test/global-setup.ts'],
      setupFiles: ['src/test/setup.ts'],
      unstubGlobals: true,
      sequence: { shuffle: true },
    },
  }
})
