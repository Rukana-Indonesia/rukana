/**
 * Menu aplikasi per peran. `roles` kosong = semua peran. `icon` = nama berkas di assets/icons.
 * Menyembunyikan menu hanya soal tampilan; hak akses tetap dicek backend.
 */
export const navigation = [{ name: 'dashboard', label: 'Dasbor', icon: 'chart-square', roles: [] }]

export function navigationFor(role) {
  return navigation.filter((item) => item.roles.length === 0 || item.roles.includes(role))
}
