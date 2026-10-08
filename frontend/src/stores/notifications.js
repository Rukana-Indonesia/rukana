import { ref } from 'vue'
import { defineStore } from 'pinia'

import { api } from '@/lib/api'

const POLL_MS = 60_000

/**
 * Notifikasi lonceng (PRD 6.11). Tanpa WebSocket/push: jumlah belum dibaca diambil
 * ulang tiap menit selama tab terlihat, dan saat tab kembali dibuka.
 */
export const useNotificationStore = defineStore('notifications', () => {
  const unread = ref(0)
  const latest = ref([])
  const loadingLatest = ref(false)
  let timer = null

  async function refreshCount() {
    try {
      const { data } = await api.get('/notifications/unread-count')
      unread.value = data.data.unread
    } catch {
      // Lonceng tidak boleh mengganggu halaman bila gagal; dicoba lagi di putaran berikutnya.
    }
  }

  async function loadLatest() {
    loadingLatest.value = true
    try {
      const { data } = await api.get('/notifications', { params: { per_page: 8 } })
      latest.value = data.data
      unread.value = data.meta.unread
    } finally {
      loadingLatest.value = false
    }
  }

  async function markRead(notification) {
    if (notification.read_at) return
    notification.read_at = new Date().toISOString()
    unread.value = Math.max(0, unread.value - 1)
    try {
      await api.post(`/notifications/${notification.id}/read`)
    } catch {
      refreshCount()
    }
  }

  async function markAllRead() {
    await api.post('/notifications/read-all')
    unread.value = 0
    const now = new Date().toISOString()
    latest.value.forEach((n) => (n.read_at ??= now))
  }

  function onVisibility() {
    if (document.visibilityState === 'visible') refreshCount()
  }

  function start() {
    if (timer) return
    refreshCount()
    timer = setInterval(() => document.visibilityState === 'visible' && refreshCount(), POLL_MS)
    document.addEventListener('visibilitychange', onVisibility)
  }

  function stop() {
    clearInterval(timer)
    timer = null
    document.removeEventListener('visibilitychange', onVisibility)
    unread.value = 0
    latest.value = []
  }

  return {
    unread,
    latest,
    loadingLatest,
    refreshCount,
    loadLatest,
    markRead,
    markAllRead,
    start,
    stop,
  }
})
