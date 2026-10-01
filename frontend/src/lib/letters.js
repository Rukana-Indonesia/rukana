/** Label dan warna untuk surat pengantar, dipakai halaman aplikasi dan cek tiket publik. */

export const statusBadge = {
  submitted: 'warning',
  rt_verified: 'info',
  rw_approved: 'info',
  village_received: 'info',
  ready_for_pickup: 'success',
  completed: 'success',
  rejected: 'danger',
}

export const isFinal = (status) => status === 'completed' || status === 'rejected'

/** Teks tombol dan konfirmasi untuk tiap aksi pengurus (nama aksi sama dengan URL API). */
export const actionText = {
  verify: {
    button: 'Verifikasi',
    icon: 'tick-circle',
    title: 'Verifikasi Pengantar',
    description: 'Pastikan pemohon benar warga RT Anda dan keperluannya sesuai.',
  },
  approve: {
    button: 'Setujui',
    icon: 'tick-circle',
    title: 'Setujui Pengantar',
    description: 'Pengantar ini sudah diverifikasi RT. Setujui untuk tingkat RW.',
  },
  receive: {
    button: 'Terima di Desa',
    icon: 'receipt-search',
    title: 'Terima Surat',
    description: 'Tandai surat ini sedang diproses di sistem surat desa.',
  },
  ready: {
    button: 'Siap Diambil',
    icon: 'ticket',
    title: 'Tandai Siap Diambil',
    description: 'Warga akan melihat bahwa suratnya bisa diambil di kantor desa.',
  },
  pickup: {
    button: 'Sudah Diambil',
    icon: 'tick-circle',
    title: 'Tandai Sudah Diambil',
    description: 'Surat sudah diserahkan ke pemohon. Pengajuan ini akan selesai.',
  },
  reject: {
    button: 'Tolak',
    icon: 'close-circle',
    title: 'Tolak Pengantar',
    description: 'Alasan penolakan akan dilihat pemohon, jadi tulis apa yang perlu diperbaiki.',
  },
}

export const rejectReasons = [
  'Lampiran tidak lengkap',
  'Foto lampiran tidak terbaca',
  'Keperluan kurang jelas',
  'Bukan warga RT ini',
]

/** '3 hari' atau '—'. */
export function estimateLabel(days) {
  return days ? `± ${days} hari kerja` : '—'
}

/** Ukuran berkas: 1536 → '1,5 KB'. */
export function formatSize(bytes) {
  if (!bytes) return ''
  const units = ['B', 'KB', 'MB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / 1024 ** i).toLocaleString('id-ID', { maximumFractionDigits: 1 })} ${units[i]}`
}
