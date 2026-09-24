/**
 * Menu aplikasi per peran. `roles` kosong = semua peran.
 * Menyembunyikan menu hanya soal tampilan; hak akses tetap dicek backend.
 */
export const navigation = [{ name: 'dashboard', label: 'Beranda', roles: [] }]

export function navigationFor(role) {
  return navigation.filter((item) => item.roles.length === 0 || item.roles.includes(role))
}
