import { StrictMode } from 'react'
import WebApp from '@twa-dev/sdk'
import { createRoot } from 'react-dom/client'
import { App } from '@/apps/app/app'
import { startTelegram } from '@/apps/app/telegram/theme'
import type { TelegramWebApp } from '@/apps/app/telegram/webapp'
import { reloadForNewBuild } from '@/lib/stale-build'
import '@/fonts/vazirmatn/vazirmatn.css'
import '@/apps/app/app.css'

// telegram-web-app.js, bundled, has read the launch data and put Telegram's WebApp on the window (telegram/webapp reads it
// there, typed for the parts the app uses). Its package calls itself free of side effects, so a bare import would leave
// it out of the build: its WebApp is read here, which keeps it in.
window.Telegram ??= { WebApp: WebApp as unknown as TelegramWebApp }

// A screen's file from the build before an update is gone: one reload brings the new build (the script keeps the launch
// data for it). A reload that did not help lets the failure through to the screen that says so (app.tsx).
window.addEventListener('vite:preloadError', (event) => {
  if (reloadForNewBuild()) event.preventDefault()
})

// The page hands itself over to Telegram, then draws.
startTelegram()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
