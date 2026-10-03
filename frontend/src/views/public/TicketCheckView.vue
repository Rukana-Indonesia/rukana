<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import LetterProgress from '@/components/letters/LetterProgress.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import { api, errorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { statusBadge } from '@/lib/letters'

// Cek status tanpa login (PRD 6.5). Backend hanya mengirim status & tahapan, tanpa data pribadi.
const route = useRoute()
const router = useRouter()
const code = ref(typeof route.query.kode === 'string' ? route.query.kode : '')
const ticket = ref(null)
const loading = ref(false)
const error = ref('')

async function check() {
  const value = code.value.trim().toUpperCase()
  if (!value) {
    error.value = 'Masukkan kode tiket, mis. SRT-2610-0042.'
    return
  }
  loading.value = true
  error.value = ''
  ticket.value = null
  try {
    const { data } = await api.get(`/tickets/${encodeURIComponent(value)}`)
    ticket.value = data.data
    code.value = value
    router.replace({ query: { kode: value } })
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (code.value) check()
})
</script>

<template>
  <div class="mx-auto flex max-w-2xl flex-col gap-[14px] px-4 py-6 sm:py-10">
    <section class="flex flex-col gap-6 rounded-3xl bg-white p-6 sm:p-8">
      <div class="flex flex-col gap-2">
        <h1 class="text-2xl font-semibold sm:text-[28px]">Cek Status Tiket</h1>
        <p class="leading-relaxed text-desa-secondary">
          Masukkan kode tiket dari pengajuan surat untuk melihat posisinya sekarang. Halaman ini
          tidak menampilkan data pribadi.
        </p>
      </div>
      <form class="flex flex-col gap-4 sm:flex-row sm:items-end" novalidate @submit.prevent="check">
        <div class="flex-1">
          <AppInput
            v-model="code"
            label="Kode tiket"
            icon="ticket"
            placeholder="SRT-2610-0042"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
          />
        </div>
        <AppButton type="submit" :loading="loading">Cek Status</AppButton>
      </form>
      <AppAlert v-if="error">{{ error }}</AppAlert>
    </section>

    <section
      v-if="ticket"
      class="flex flex-col gap-6 rounded-3xl bg-white p-6 sm:p-8"
      aria-live="polite"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 flex-col gap-1">
          <p class="text-sm font-medium text-desa-secondary">
            {{ ticket.type_label }} · {{ ticket.code }}
          </p>
          <h2 class="text-xl leading-7 font-semibold">{{ ticket.title }}</h2>
          <p class="text-sm text-desa-secondary">Diajukan {{ formatDate(ticket.submitted_at) }}</p>
        </div>
        <AppBadge :variant="statusBadge[ticket.status]">{{ ticket.status_label }}</AppBadge>
      </div>

      <AppAlert v-if="ticket.status === 'ready_for_pickup'" variant="success">
        Surat sudah bisa diambil di kantor desa. Bawa KTP asli saat mengambil.
      </AppAlert>
      <AppAlert v-else-if="ticket.status === 'rejected'" variant="warning">
        Pengajuan ditolak di tingkat {{ ticket.rejected_level_label }}. Pemohon bisa melihat
        alasannya setelah masuk ke Rukana.
      </AppAlert>
      <p v-else-if="ticket.current_level_label" class="font-medium text-desa-secondary">
        Sedang menunggu tindakan di tingkat {{ ticket.current_level_label }}.
      </p>

      <LetterProgress
        :stages="ticket.stages"
        :steps="ticket.steps"
        :status="ticket.status"
        :rejected-level-label="ticket.rejected_level_label ?? ''"
      />
    </section>
  </div>
</template>
