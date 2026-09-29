/** Format tampilan yang dipakai banyak halaman. */

/** '6281234567890' → '0812-3456-7890'. */
export function formatPhone(phone) {
  if (!phone) return ''
  const local = phone.startsWith('62') ? `0${phone.slice(2)}` : phone
  return local.replace(/^(\d{4})(\d{4})(\d+)$/, '$1-$2-$3')
}

const dateFormat = new Intl.DateTimeFormat('id-ID', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
})
const dateTimeFormat = new Intl.DateTimeFormat('id-ID', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})

/** Tanggal ISO dari API → '19 Okt 2026' (atau dengan jam). */
export function formatDate(iso, withTime = false) {
  if (!iso) return '—'
  return (withTime ? dateTimeFormat : dateFormat).format(new Date(iso))
}

/** 'Siti Aminah' → 'SA'. */
export function initials(name) {
  return (name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('')
}

export const residencyLabels = { tetap: 'Tetap', kontrak: 'Kontrak', kos: 'Kos' }

/** Tautan wa.me untuk mengirim pesan ke nomor tertentu (format 62…). */
export function whatsappLink(phone, text) {
  return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`
}
