/// <reference types="vitest/config" />
import { createHash } from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import zlib from 'node:zlib'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, type Plugin } from 'vite'

const projectRoot = fileURLToPath(new URL('.', import.meta.url))

/** The panels, each a page of its own built from the same sources: the owner's at /admin/, an agent's at /agent/. */
const PANELS = ['admin', 'agent'] as const

/** Where the build writes: public/ — beside the PHP front controller, which is why the build cleans only its own. */
const outDir = path.join(projectRoot, 'public')

/** What the build writes there: each panel's page, and the hashed files both panels' pages share. */
const OUTPUTS = [...PANELS, 'assets']

/** The least a script or stylesheet weighs to be worth a brotli of its own beside it. */
const BROTLI_MIN_BYTES = 1024

/* What the chunks are told apart by (the build's codeSplitting, below). */
const NODE_MODULES = /[\\/]node_modules[\\/]/
const REACT = /[\\/]node_modules[\\/](react|react-dom|scheduler)[\\/]/
const ICONS = /[\\/]node_modules[\\/]lucide-react[\\/]/
const PANEL_SOURCES = /[\\/]resources[\\/]panel[\\/]src[\\/]/
/** A panel's own code (src/apps/admin, src/apps/agent): never in a chunk the other panel loads. */
const OWN_APPS = /[\\/]src[\\/]apps[\\/]/
const SHARED_SOURCE = (id: string) => PANEL_SOURCES.test(id) && !OWN_APPS.test(id)

/**
 * What the built panels need from the web server, written by the build itself:
 *
 * - each page's Content-Security-Policy, ahead of every script — a panel loads only the build's files and talks only
 *   to its own origin; the page's inline script (it sets the page's <base> before anything loads) is allowed by its
 *   hash, taken from the built page, so editing the script never leaves a stale one;
 * - beside each script and stylesheet its brotli (x.js.br), compressed once at the best level, and assets/.htaccess:
 *   the build's files carry a hash of their content in their names, so a browser keeps them for good, and one that reads
 *   brotli is given that file as it is — nothing is compressed per request (Apache, by that .htaccess);
 * - a clean slate: public/ also holds the PHP front controller, so the build removes only what it writes there.
 */
function production(): Plugin {
  return {
    name: 'amobot-production',
    apply: 'build',
    buildStart() {
      for (const output of OUTPUTS) {
        fs.rmSync(path.join(outDir, output), { recursive: true, force: true })
      }
    },
    transformIndexHtml: {
      order: 'post',
      handler(html) {
        const hashes = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(([, script = '']) => `'sha256-${createHash('sha256').update(script).digest('base64')}'`)
        const policy = [
          "default-src 'self'",
          `script-src 'self' ${hashes.join(' ')}`,
          // Radix positions its layers with inline styles, the overview's chart the box of a day's figure.
          "style-src 'self' 'unsafe-inline'",
          "img-src 'self' data: blob:",
          "font-src 'self' data:",
          "media-src 'self' blob:",
          "connect-src 'self'",
          // The panel frames nothing: a frame no code of its asks for opens nowhere.
          "frame-src 'none'",
          "object-src 'none'",
          "base-uri 'self'",
          "form-action 'self'",
        ].join('; ')

        return html.replace('<meta charset="utf-8" />', `<meta charset="utf-8" />\n    <meta http-equiv="Content-Security-Policy" content="${policy}" />`)
      },
    },
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'assets/.htaccess',
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
        if (!/^assets\/.+\.(js|css|svg)$/.test(fileName)) continue
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
 * `pnpm dev` serves the panels as the web server does: any address under /admin/ or /agent/ that names no file is that
 * panel's page (a deep link), and the site's root opens the owner's.
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
        const panel = PANELS.find((name) => pathname === `/${name}` || pathname.startsWith(`/${name}/`))
        if (panel && !/\.\w+$/.test(pathname)) request.url = `/${panel}/index.html`
        next()
      })
    },
  }
}

/**
 * The panels are static files: `pnpm build` writes them to public/ — admin/index.html and agent/index.html, and the
 * hashed files they share in assets/ — where the web server serves them as they are (public/.htaccess sends any other
 * address under a panel's folder to its page), and they talk to PHP only through the JSON API.
 * `pnpm dev` serves both — http://127.0.0.1:5173/admin/ and /agent/ — and hands /api to the PHP server on :8080.
 */
export default defineConfig(({ command }) => ({
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
  plugins: [react(), tailwindcss(), production(), panelPages()],
  resolve: {
    alias: { '@': path.join(projectRoot, 'resources/panel/src') },
  },
  build: {
    outDir,
    // The front controller lives in public/ too: production() removes the build's own outputs instead.
    emptyOutDir: false,
    rolldownOptions: {
      input: Object.fromEntries(PANELS.map((panel) => [panel, path.join(projectRoot, `resources/panel/${panel}/index.html`)])),
      output: {
        // The chunks, by how often they change and who needs them. What a panel's first screen needs from its libraries
        // changes only with a dependency — React, then the rest (the router, react-query, Radix's menus and tooltips, the
        // toasts) —, so a browser keeps it across the shop's upgrades, as the bundler's own runtime beside it. The panels' own
        // first-load code is one chunk whichever panel, and what three pages or more share (the page header, the lists,
        // the forms, the select…) one more, loaded with the first of them; the rest goes with its page. A panel's own
        // code (src/apps/…) stays in its own chunks: an agent's page never names the owner's.
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
  // `pnpm test`: the panels' unit tests, each beside what it tests (src/**/*.test.ts[x]), in a DOM opened at the owner's
  // panel (/admin/ — what lib/config reads the panel and its API off), every request answered by src/test/server and held
  // to the API description (read once a run: src/test/global-setup), the clock in UTC wherever the suite runs, in an
  // order of their own each run (as the PHP suite: no test leans on another).
  test: {
    include: ['src/**/*.test.{ts,tsx}'],
    environment: 'happy-dom',
    environmentOptions: { happyDOM: { url: 'http://localhost/admin/' } },
    env: { TZ: 'UTC' },
    globalSetup: ['src/test/global-setup.ts'],
    setupFiles: ['src/test/setup.ts'],
    unstubGlobals: true,
    sequence: { shuffle: true },
  },
}))
