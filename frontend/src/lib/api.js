import axios from 'axios'

/**
 * Klien API Laravel (Sanctum SPA, sesi berbasis cookie).
 *
 * Kontrak respons backend:
 *   sukses : { data, meta?, message? }
 *   gagal  : { message, errors?: { field: [..] }, code? }
 */
export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

const SAFE_METHODS = ['get', 'head', 'options']
let csrfReady = null

/** Ambil cookie XSRF-TOKEN sekali sebelum request yang mengubah data. */
export function ensureCsrfCookie() {
  csrfReady ??= axios.get('/sanctum/csrf-cookie', { withCredentials: true }).catch((err) => {
    csrfReady = null
    throw err
  })
  return csrfReady
}

api.interceptors.request.use(async (config) => {
  if (!SAFE_METHODS.includes((config.method ?? 'get').toLowerCase())) {
    await ensureCsrfCookie()
  }
  return config
})

let unauthorizedHandler = () => {}

/** Dipanggil saat backend membalas 401 (sesi habis). */
export function onUnauthorized(handler) {
  unauthorizedHandler = handler
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const { response, config } = error

    // 419: token CSRF kedaluwarsa. Ambil ulang lalu coba sekali lagi.
    if (response?.status === 419 && config && !config._csrfRetried) {
      csrfReady = null
      config._csrfRetried = true
      await ensureCsrfCookie()
      return api(config)
    }

    if (response?.status === 401 && !config?.skipAuthRedirect) {
      unauthorizedHandler()
    }

    return Promise.reject(error)
  },
)

/** Pesan utama dari error API, untuk ditampilkan ke pengguna. */
export function errorMessage(error, fallback = 'Terjadi kesalahan. Coba lagi.') {
  if (!error?.response) return 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.'
  if (error.response.status === 429)
    return 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.'
  return error.response.data?.message || fallback
}

/** Error validasi per field: { field: 'pesan pertama' }. */
export function fieldErrors(error) {
  const errors = error?.response?.status === 422 ? error.response.data?.errors : null
  if (!errors) return {}
  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

/**
 * Buka berkas dari API (PDF pengantar, lampiran) di tab baru. Berkas diambil lewat
 * klien API supaya sesi Sanctum pasti ikut, apa pun pengaturan referrer browser.
 * `path` adalah path lengkap, mis. '/api/letters/1/pdf'.
 */
export async function openFile(path, filename) {
  // Tab dibuka saat masih di dalam klik pengguna supaya tidak diblokir sebagai pop-up.
  const tab = window.open('', '_blank')
  try {
    const { data } = await api.get(path, { baseURL: '', responseType: 'blob' })
    const url = URL.createObjectURL(data)
    if (tab) {
      tab.location.href = url
    } else {
      const link = Object.assign(document.createElement('a'), { href: url, download: filename })
      link.click()
    }
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (error) {
    tab?.close()
    throw await readBlobError(error)
  }
}

/**
 * Unduh berkas dari API (mis. ekspor rekap) dengan nama dari header Content-Disposition.
 * `path` relatif terhadap /api, mis. '/recap/export'.
 */
export async function downloadFile(path, params, fallbackName = 'unduhan') {
  try {
    const { data, headers } = await api.get(path, { params, responseType: 'blob' })
    const disposition = headers['content-disposition'] ?? ''
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
    const filename = match ? decodeURIComponent(match[1]) : fallbackName
    const url = URL.createObjectURL(data)
    Object.assign(document.createElement('a'), { href: url, download: filename }).click()
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
    return filename
  } catch (error) {
    throw await readBlobError(error)
  }
}

/** Respons error berupa Blob; ambil pesannya supaya errorMessage() bisa membacanya. */
async function readBlobError(error) {
  if (error.response?.data instanceof Blob) {
    try {
      error.response.data = JSON.parse(await error.response.data.text())
    } catch {
      error.response.data = {}
    }
  }
  return error
}
