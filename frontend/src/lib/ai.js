/** Helper fitur AI: Ask Rukana dan Ringkasan Bulanan (PRD 7). */

const monthFormat = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' })

/** 12 bulan terakhir sampai bulan ini: [{ value: '2026-10', label: 'Oktober 2026' }]. */
export function monthOptions(count = 12, today = new Date()) {
  return Array.from({ length: count }, (_, i) => {
    const date = new Date(today.getFullYear(), today.getMonth() - i, 1)
    const value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
    return { value, label: monthFormat.format(date) }
  })
}

/** 87.5 → '87,5%'; null → '—'. */
export function percent(value) {
  return value === null || value === undefined
    ? '—'
    : `${value.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`
}

/** Teks chip sumber data untuk satu panggilan tool Ask Rukana. */
export function toolChip(tool) {
  if (tool.status === 'denied') return `${tool.label}: di luar wilayah Anda`
  if (tool.status === 'error') return `${tool.label}: tidak bisa diambil`
  return [tool.label, tool.region, tool.period].filter(Boolean).join(' · ')
}
