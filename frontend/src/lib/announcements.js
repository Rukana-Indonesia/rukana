/** Label, warna, dan teks bagikan untuk pengumuman bertarget (PRD 6.6). */

export const stateBadge = { scheduled: 'info', active: 'success', expired: 'neutral' }

export const targetLevelOptions = [
  { value: 'village', label: 'Seluruh desa' },
  { value: 'rw', label: 'Satu RW' },
  { value: 'rt', label: 'Satu RT' },
]

/** Teks siap tempel untuk grup WhatsApp warga, supaya pengurus tidak mengetik ulang. */
export function shareText(announcement) {
  const from = announcement.author?.role_label ? `— ${announcement.author.role_label}` : ''
  return [
    `📢 *${announcement.title}*`,
    `Untuk: ${announcement.target.label}`,
    '',
    announcement.body,
    '',
    from,
    `Pengumuman resmi lewat Rukana: ${window.location.origin}`,
  ]
    .filter((line, i, all) => line !== '' || all[i - 1] !== '')
    .join('\n')
    .trim()
}

export const whatsappShareUrl = (announcement) =>
  `https://wa.me/?text=${encodeURIComponent(shareText(announcement))}`

/** ISO dari API → nilai input datetime-local (jam lokal browser). */
export function toLocalInput(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** Nilai input datetime-local/date → ISO dengan zona waktu, untuk dikirim ke API. */
export function fromLocalInput(value, endOfDay = false) {
  if (!value) return ''
  const local = value.length === 10 ? `${value}T${endOfDay ? '23:59:59' : '00:00'}` : value
  return new Date(local).toISOString()
}
