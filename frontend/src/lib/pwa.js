import { computed, ref } from 'vue'

/*
 * Pemasangan Rukana sebagai aplikasi (PWA). Chrome/Edge Android mengirim
 * `beforeinstallprompt` sekali saat halaman dimuat, jadi listener dipasang dari
 * main.js sebelum komponen mana pun ada. iPhone/iPad tidak punya event ini:
 * pemasangannya lewat Bagikan → Tambah ke Layar Utama, jadi cukup diberi petunjuk.
 */
const deferredPrompt = ref(null)
const installed = ref(false)

const isStandalone = () =>
  window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true

const isIos = () =>
  /iphone|ipad|ipod/i.test(navigator.userAgent) ||
  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

export function listenForInstallPrompt() {
  installed.value = isStandalone()
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferredPrompt.value = event
  })
  window.addEventListener('appinstalled', () => {
    installed.value = true
    deferredPrompt.value = null
  })
}

/**
 * Service worker hanya dipasang di build produksi: di `npm run dev` ia akan
 * menahan berkas lama dan mengacaukan hot reload. Coba PWA lewat
 * `npm run build && npm run preview` (proksi /api tetap berlaku).
 */
export function registerServiceWorker() {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) return
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {})
  })
}

export function usePwaInstall() {
  const canPrompt = computed(() => !installed.value && deferredPrompt.value !== null)
  const showIosHint = computed(() => !installed.value && !deferredPrompt.value && isIos())

  async function install() {
    const prompt = deferredPrompt.value
    if (!prompt) return
    deferredPrompt.value = null
    await prompt.prompt()
    const { outcome } = await prompt.userChoice
    if (outcome === 'accepted') installed.value = true
  }

  return { canPrompt, showIosHint, install }
}
