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
