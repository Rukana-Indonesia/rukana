/**
 * Menu aplikasi per peran. `roles` kosong = semua peran. `icon` = nama berkas di assets/icons.
 * Menyembunyikan menu hanya soal tampilan; hak akses tetap dicek backend.
 */
export const navigation = [
  { name: 'dashboard', label: 'Dasbor', icon: 'chart-square', roles: [] },
  { name: 'residents', label: 'Data Warga', icon: 'user-search', roles: ['rt', 'operator'] },
  {
    name: 'officials',
    label: 'Akun Pengurus',
    icon: 'profile-2user',
    roles: ['operator', 'kepala_desa'],
  },
  { name: 'regions', label: 'Wilayah', icon: 'location', roles: ['operator', 'kepala_desa'] },
]

export function navigationFor(role) {
  return navigation.filter((item) => item.roles.length === 0 || item.roles.includes(role))
}
