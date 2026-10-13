<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api, errorMessage } from '@/lib/api'
import { urgencyBadge } from '@/lib/complaints'

// Saran Smart Complaint untuk pengurus (UAT 07). AI hanya menyarankan; tombol
// "Pakai saran" menyimpan kategori/urgensi lewat endpoint klasifikasi yang sama
// dengan koreksi manual, jadi keputusan tetap tercatat atas nama pengurus.
const props = defineProps({
  complaint: { type: Object, required: true },
})
const emit = defineEmits(['updated'])

const label = computed(() => props.complaint.ai_label)
const busy = ref(false)
const error = ref('')

const applied = computed(
  () =>
    label.value?.status === 'done' &&
    props.complaint.category?.id === label.value.category?.id &&
    (props.complaint.urgency ?? null) === label.value.urgency,
)

async function applySuggestion() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.put(`/complaints/${props.complaint.id}/classification`, {
      category_id: label.value.category.id,
      urgency: label.value.urgency,
    })
    emit('updated', data.data, 'Saran AI dipakai: kategori dan urgensi disimpan.')
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    busy.value = false
  }
}

async function retry() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/complaints/${props.complaint.id}/ai-label/retry`)
    emit('updated', data.data, data.message)
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    busy.value = false
  }
}

// Selama AI masih menganalisis (diproses di queue), cek ulang berkala sampai ~2 menit.
let timer = null
let tries = 0

async function poll() {
  try {
    const { data } = await api.get(`/complaints/${props.complaint.id}`)
    emit('updated', data.data, null)
  } catch {
    // Coba lagi di putaran berikutnya.
  }
}

watch(
  () => label.value?.status,
  (status) => {
    clearInterval(timer)
    timer = null
    if (status !== 'pending') return
    tries = 0
    timer = setInterval(() => {
      if (++tries > 30) return clearInterval(timer)
      poll()
    }, 4000)
  },
  { immediate: true },
)

onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <section class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <span
          class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-desa-foreshadow text-desa-dark-green"
        >
          <AppIcon name="magic-star" />
        </span>
        <div class="flex flex-col">
          <h2 class="text-lg font-semibold">Saran AI</h2>
          <p class="text-sm text-desa-secondary">Smart Complaint · hanya saran, bisa dikoreksi</p>
        </div>
      </div>
      <AppBadge v-if="applied" variant="success">Saran sudah dipakai</AppBadge>
      <AppBadge v-else-if="label?.status === 'pending'" variant="info">Menganalisis…</AppBadge>
      <AppBadge v-else-if="label?.status === 'failed'" variant="warning">Gagal</AppBadge>
    </div>

    <AppAlert v-if="error">{{ error }}</AppAlert>

    <p v-if="!label" class="text-desa-secondary">Laporan ini belum punya saran AI.</p>

    <p v-else-if="label.status === 'pending'" class="flex items-center gap-3 text-desa-secondary">
      <span
        class="size-5 shrink-0 animate-spin rounded-full border-2 border-desa-foreshadow border-t-desa-dark-green"
        aria-hidden="true"
      />
      AI sedang membaca laporan dan menyusun saran kategori, urgensi, dan tingkat penanganan.
    </p>

    <p v-else-if="label.status === 'failed'" class="text-desa-secondary">
      {{ label.error || 'Saran AI belum bisa disusun.' }}
    </p>

    <template v-else>
      <p class="leading-relaxed">{{ label.summary }}</p>
      <dl class="grid gap-4 sm:grid-cols-3">
        <div class="flex flex-col gap-1 rounded-2xl bg-desa-background p-4">
          <dt class="text-sm font-medium text-desa-secondary">Kategori</dt>
          <dd class="font-semibold">
            {{ label.category?.name ?? '—' }}
            <span
              v-if="label.category?.parent_name"
              class="block text-sm font-normal text-desa-secondary"
            >
              {{ label.category.parent_name }}
            </span>
          </dd>
        </div>
        <div class="flex flex-col gap-1 rounded-2xl bg-desa-background p-4">
          <dt class="text-sm font-medium text-desa-secondary">Urgensi</dt>
          <dd>
            <AppBadge :variant="urgencyBadge[label.urgency]">{{ label.urgency_label }}</AppBadge>
          </dd>
        </div>
        <div class="flex flex-col gap-1 rounded-2xl bg-desa-background p-4">
          <dt class="text-sm font-medium text-desa-secondary">Saran ditangani</dt>
          <dd class="font-semibold">{{ label.level_label }}</dd>
        </div>
      </dl>
    </template>

    <div
      v-if="
        (label?.status === 'done' && complaint.can.classify && !applied) || complaint.can.retry_ai
      "
      class="flex flex-col gap-3 sm:flex-row sm:flex-wrap"
    >
      <AppButton
        v-if="label?.status === 'done' && complaint.can.classify && !applied"
        :loading="busy"
        @click="applySuggestion"
      >
        <AppIcon name="tick-circle" />
        Pakai saran kategori & urgensi
      </AppButton>
      <AppButton v-if="complaint.can.retry_ai" variant="secondary" :loading="busy" @click="retry">
        <AppIcon name="magic-star" />
        Analisis ulang
      </AppButton>
    </div>

    <p class="text-sm text-desa-secondary">
      AI tidak meneruskan, menolak, atau menutup laporan. Keputusan kategori, urgensi, dan penerusan
      tetap di tangan pengurus, dan koreksi Anda dipakai untuk menilai ketepatan AI.
    </p>
  </section>
</template>
