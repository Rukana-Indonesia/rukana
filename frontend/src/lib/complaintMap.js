/** Helper peta sebaran laporan (PRD 6.9). */

import { complaintStatusLabels } from './recap'

// Warna penanda per status, dari palet template. Yang baru masuk paling mencolok.
export const statusColors = {
  submitted: '#ff5070',
  verified: '#fa7139',
  forwarded: '#fbad4a',
  in_progress: '#005caa',
  resolved: '#34613a',
  rejected: '#5a7a7e',
}

export const statusLegend = Object.entries(complaintStatusLabels).map(([value, label]) => ({
  value,
  label,
  color: statusColors[value],
}))

export const statusFilterOptions = [
  { value: 'all', label: 'Semua status' },
  { value: 'active', label: 'Belum selesai' },
  ...statusLegend.map(({ value, label }) => ({ value, label })),
]

/** Pilihan kategori: induk mencakup semua subkategorinya (sama seperti daftar laporan). */
export function categoryFilterOptions(categories) {
  return [
    { value: 'all', label: 'Semua kategori' },
    ...categories.map((parent) => ({
      label: parent.name,
      options:
        parent.children.length > 1
          ? [
              { value: String(parent.id), label: `Semua ${parent.name}` },
              ...parent.children.map((child) => ({ value: String(child.id), label: child.name })),
            ]
          : [{ value: String(parent.id), label: parent.children[0]?.name ?? parent.name }],
    })),
  ]
}
