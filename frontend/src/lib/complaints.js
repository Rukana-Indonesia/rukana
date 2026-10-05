/** Label dan warna untuk laporan warga, dipakai halaman aplikasi dan cek tiket publik. */

export const statusBadge = {
  submitted: 'warning',
  verified: 'info',
  forwarded: 'info',
  in_progress: 'info',
  resolved: 'success',
  rejected: 'danger',
}

export const urgencyBadge = { low: 'neutral', medium: 'warning', high: 'danger' }

// 'none' = belum ditentukan (null di API); '' dipakai AppSelect untuk placeholder.
export const urgencyOptions = [
  { value: 'none', label: 'Belum ditentukan' },
  { value: 'low', label: 'Rendah' },
  { value: 'medium', label: 'Sedang' },
  { value: 'high', label: 'Tinggi' },
]

/** Teks tombol dan konfirmasi untuk tiap aksi pengurus (nama aksi sama dengan URL API). */
export const actionText = {
  verify: {
    button: 'Verifikasi',
    icon: 'tick-circle',
    title: 'Verifikasi Laporan',
    description:
      'Pastikan laporan ini benar terjadi di wilayah Anda. Setelah itu Anda bisa menanganinya sendiri atau meneruskannya.',
  },
  start: {
    button: 'Tangani',
    icon: 'timer',
    title: 'Mulai Tangani',
    description: 'Pelapor akan melihat laporannya sedang diproses.',
  },
  accept: {
    button: 'Terima & Tangani',
    icon: 'tick-circle',
    title: 'Terima Laporan',
    description: 'Laporan ini diteruskan ke tingkat Anda. Terima untuk mulai menanganinya.',
  },
  forward: {
    button: 'Teruskan',
    icon: 'arrow-square-right',
    title: 'Teruskan Laporan',
    description:
      'Teruskan bila penanganannya di luar kemampuan tingkat Anda, mis. perlu anggaran atau alat desa.',
  },
  resolve: {
    button: 'Tandai Selesai',
    icon: 'tick-circle',
    title: 'Tandai Selesai',
    description: 'Tulis tindak lanjut yang sudah dilakukan. Pelapor akan membaca catatan ini.',
  },
  reject: {
    button: 'Tolak',
    icon: 'close-circle',
    title: 'Tolak Laporan',
    description: 'Alasan penolakan akan dilihat pelapor.',
  },
}

export const rejectReasons = [
  'Laporan ganda',
  'Bukan masalah lingkungan',
  'Lokasi di luar wilayah ini',
  'Informasi kurang jelas',
]

/** Tautan peta OpenStreetMap untuk titik laporan. */
export function mapLink({ latitude, longitude }) {
  return `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=18/${latitude}/${longitude}`
}

/** '-6.91474, 107.60981'. */
export function formatCoords({ latitude, longitude }) {
  return `${latitude.toFixed(5)}, ${longitude.toFixed(5)}`
}
