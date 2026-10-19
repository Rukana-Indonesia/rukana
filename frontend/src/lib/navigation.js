/**
 * Menu aplikasi per peran, dikelompokkan. `roles` kosong = semua peran. `icon` = nama
 * berkas di assets/icons. `labels` mengganti label untuk peran tertentu. Menyembunyikan
 * menu hanya soal tampilan; hak akses tetap dicek backend.
 */
export const navigation = [
  {
    title: 'Utama',
    items: [
      {
        name: 'dashboard',
        label: 'Dasbor',
        labels: { warga: 'Beranda' },
        icon: 'chart-square',
        roles: [],
      },
    ],
  },
  {
    title: 'Layanan',
    items: [
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
      {
        name: 'complaint-map',
        label: 'Peta Laporan',
        icon: 'map',
        roles: ['rt', 'rw', 'operator', 'kepala_desa'],
      },
      { name: 'announcements', label: 'Pengumuman', icon: 'sms', roles: [] },
    ],
  },
  {
    title: 'Asisten AI',
    items: [
      {
        name: 'insights',
        label: 'Ringkasan Bulanan',
        icon: 'status-up',
        roles: ['rw', 'operator', 'kepala_desa'],
      },
      {
        name: 'ask',
        label: 'Ask Rukana',
        icon: 'message-question',
        roles: ['rt', 'rw', 'operator', 'kepala_desa'],
      },
    ],
  },
  {
    title: 'Administrasi',
    items: [
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
      {
        name: 'village-settings',
        label: 'Pengaturan Desa',
        icon: 'setting-2',
        roles: ['operator', 'kepala_desa'],
      },
    ],
  },
  {
    title: 'Akun',
    items: [{ name: 'change-password', label: 'Kata Sandi', icon: 'key', roles: [] }],
  },
]

const visibleTo = (role) => (item) => item.roles.length === 0 || item.roles.includes(role)
const labelFor = (role) => (item) => ({ ...item, label: item.labels?.[role] ?? item.label })

/** Kelompok menu yang terlihat untuk peran ini; kelompok kosong dibuang. */
export function navigationFor(role) {
  return navigation
    .map((group) => ({
      ...group,
      items: group.items.filter(visibleTo(role)).map(labelFor(role)),
    }))
    .filter((group) => group.items.length > 0)
}

/**
 * Tab bawah di HP (gaya aplikasi): empat tujuan paling sering + "Lainnya". Warga
 * mendapat tombol tengah "Buat" untuk mengajukan surat atau melapor.
 */
export function bottomTabsFor(role) {
  const all = navigationFor(role).flatMap((group) => group.items)
  const pick = (name, label) => {
    const item = all.find((i) => i.name === name)
    return item && { ...item, label: label ?? item.label }
  }

  if (role === 'warga') {
    return [
      pick('dashboard'),
      pick('letters', 'Surat'),
      { name: 'create', label: 'Buat', icon: 'add-square', action: true },
      pick('complaints', 'Laporan'),
    ]
  }

  return [
    pick('dashboard', 'Beranda'),
    pick('letters', 'Surat'),
    pick('complaints', 'Laporan'),
    pick('complaint-map', 'Peta'),
  ].filter(Boolean)
}
