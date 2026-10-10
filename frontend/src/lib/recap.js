/** Helper rekap dasbor dan ekspor (PRD 6.8, 6.10). */

export const periodOptions = [
  { value: 'this-month', label: 'Bulan ini' },
  { value: 'last-month', label: 'Bulan lalu' },
  { value: 'last-30', label: '30 hari terakhir' },
  { value: 'this-year', label: 'Tahun ini' },
  { value: 'custom', label: 'Pilih tanggal' },
]

/** Date lokal → 'YYYY-MM-DD' (bukan toISOString, yang bergeser ke UTC). */
export function toDateString(date) {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** Rentang tanggal untuk pilihan periode; 'custom' tidak punya rentang bawaan. */
export function periodRange(preset, today = new Date()) {
  const y = today.getFullYear()
  const m = today.getMonth()
  const range = (from, to) => ({ from: toDateString(from), to: toDateString(to) })
  switch (preset) {
    case 'this-month':
      return range(new Date(y, m, 1), new Date(y, m + 1, 0))
    case 'last-month':
      return range(new Date(y, m - 1, 1), new Date(y, m, 0))
    case 'last-30':
      return range(new Date(y, m, today.getDate() - 29), today)
    case 'this-year':
      return range(new Date(y, 0, 1), today)
    default:
      return null
  }
}

export const letterStatusLabels = {
  submitted: 'Diajukan',
  rt_verified: 'Diverifikasi RT',
  rw_approved: 'Disetujui RW',
  village_received: 'Diterima Desa',
  ready_for_pickup: 'Siap Diambil',
  completed: 'Selesai',
  rejected: 'Ditolak',
}

export const complaintStatusLabels = {
  submitted: 'Dikirim',
  verified: 'Diverifikasi',
  forwarded: 'Diteruskan',
  in_progress: 'Diproses',
  resolved: 'Selesai',
  rejected: 'Ditolak',
}

/** 2.5 → '2,5'; null → '—'. */
export function decimal(value) {
  return value === null || value === undefined
    ? '—'
    : value.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
}
