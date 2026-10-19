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

/**
 * Pilihan filter wilayah di dalam scope akun: desa → RW (dengan RT-nya), RW → RT,
 * RT → tidak ada pilihan. `rts` berisi { id, label, rw: { id, label } }.
 */
export function regionSelectOptions({ level, label, rws = [], rts = [] }) {
  if (level === 'village') {
    return [
      { value: 'all', label: `Seluruh ${label}` },
      ...rws.map((rw) => ({
        label: rw.label,
        options: [
          { value: `rw:${rw.id}`, label: `${rw.label} — semua RT` },
          ...rts
            .filter((rt) => rt.rw.id === rw.id)
            .map((rt) => ({ value: `rt:${rt.id}`, label: `${rt.label}/${rw.label}` })),
        ],
      })),
    ]
  }
  if (level === 'rw') {
    return [
      { value: 'all', label: `Seluruh ${label}` },
      ...rts.map((rt) => ({ value: `rt:${rt.id}`, label: `${rt.label}/${rt.rw.label}` })),
    ]
  }
  return []
}

/** 'rw:3' / 'rt:7' / 'all' → { rw_id, rt_id } untuk parameter API. */
export function regionParams(value) {
  const [kind, id] = value.split(':')
  return { rw_id: kind === 'rw' ? id : undefined, rt_id: kind === 'rt' ? id : undefined }
}

export const letterStatusLabels = {
  submitted: 'Diajukan',
  rt_verified: 'Diverifikasi RT',
  rw_approved: 'Disetujui RW',
  village_received: 'Diterima Desa',
  ready_for_pickup: 'Siap Diambil',
  completed: 'Selesai',
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
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

/** Kartu angka rekap. Dipakai dasbor dan Ringkasan Bulanan supaya angkanya tampil sama. */
export function recapTiles(s) {
  const l = s.letters
  const c = s.complaints
  return [
    {
      label: 'Surat masuk',
      icon: 'document-text',
      value: l.total,
      caption:
        `${l.completed} selesai · ${l.active} berjalan · ${l.rejected} ditolak` +
        (l.cancelled ? ` · ${l.cancelled} batal` : ''),
    },
    {
      label: 'Rata-rata proses surat',
      icon: 'timer',
      value: decimal(l.avg_process_days),
      unit: l.avg_process_days === null ? '' : 'hari',
      caption: l.completed ? `dari ${l.completed} surat selesai` : 'Belum ada surat selesai',
    },
    {
      label: 'Laporan masuk',
      icon: 'note',
      value: c.total,
      caption: `${c.resolved} selesai · ${c.active} berjalan · ${c.rejected} ditolak`,
    },
    {
      label: 'Rata-rata penyelesaian laporan',
      icon: 'timer',
      value: decimal(c.avg_resolve_days),
      unit: c.avg_resolve_days === null ? '' : 'hari',
      caption: c.resolved ? `dari ${c.resolved} laporan selesai` : 'Belum ada laporan selesai',
    },
    {
      label: 'Laporan diteruskan',
      icon: 'arrow-square-right',
      value: c.forwarded,
      caption: `${c.escalated_village.total} dieskalasi ke desa`,
    },
    {
      label: 'Rata-rata rating',
      icon: 'tick-circle',
      value: decimal(s.ratings.average),
      unit: s.ratings.average === null ? '' : '/ 5',
      caption: s.ratings.total ? `dari ${s.ratings.total} rating` : 'Belum ada rating',
    },
  ]
}
