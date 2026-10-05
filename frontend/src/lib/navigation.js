/**
 * Menu aplikasi per peran. `roles` kosong = semua peran. `icon` = nama berkas di assets/icons.
 * `labels` mengganti label untuk peran tertentu. Menyembunyikan menu hanya soal
 * tampilan; hak akses tetap dicek backend.
 */
export const navigation = [
  { name: 'dashboard', label: 'Dasbor', icon: 'chart-square', roles: [] },
  {
    name: 'letters',
    label: 'Surat Pengantar',
    labels: { warga: 'Surat Saya' },
    icon: 'document-text',
    roles: [],
  },
  {
    name: 'complaints',
    label: 'Laporan Warga',
    labels: { warga: 'Laporan Saya' },
    icon: 'note',
    roles: [],
  },
  { name: 'residents', label: 'Data Warga', icon: 'user-search', roles: ['rt', 'operator'] },
  {
    name: 'officials',
    label: 'Akun Pengurus',
    icon: 'profile-2user',
    roles: ['operator', 'kepala_desa'],
  },
  { name: 'regions', label: 'Wilayah', icon: 'location', roles: ['operator', 'kepala_desa'] },
  {
    name: 'services-admin',
    label: 'Katalog Layanan',
    icon: 'grid-5',
    roles: ['operator', 'kepala_desa'],
  },
]

export function navigationFor(role) {
  return navigation
    .filter((item) => item.roles.length === 0 || item.roles.includes(role))
    .map((item) => ({ ...item, label: item.labels?.[role] ?? item.label }))
}
