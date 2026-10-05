<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'

import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/lib/api'

/**
 * Gambar dari disk privat (mis. foto laporan). Diambil lewat klien API supaya
 * sesi Sanctum pasti ikut, lalu ditampilkan sebagai blob URL.
 */
const props = defineProps({
  src: { type: String, required: true }, // path lengkap, mis. '/api/complaints/1/photos/2'
  alt: { type: String, default: '' },
})

const url = ref('')
const failed = ref(false)

async function load() {
  revoke()
  failed.value = false
  try {
    const { data } = await api.get(props.src, { baseURL: '', responseType: 'blob' })
    url.value = URL.createObjectURL(data)
  } catch {
    failed.value = true
  }
}

function revoke() {
  if (url.value) URL.revokeObjectURL(url.value)
  url.value = ''
}

watch(() => props.src, load, { immediate: true })
onBeforeUnmount(revoke)
</script>

<template>
  <img v-if="url" :src="url" :alt="alt" class="size-full object-cover" />
  <span
    v-else
    class="flex size-full items-center justify-center bg-desa-background text-desa-secondary"
    :aria-label="failed ? 'Foto gagal dimuat' : 'Memuat foto'"
  >
    <AppIcon :name="failed ? 'close-circle' : 'timer'" />
  </span>
</template>
