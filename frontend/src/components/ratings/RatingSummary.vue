<script setup>
import { computed, onMounted, ref } from 'vue'

import AppIcon from '@/components/ui/AppIcon.vue'
import { api, errorMessage } from '@/lib/api'
import { timeAgo } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

import StarRating from './StarRating.vue'

// Rekap rating kepuasan untuk wilayah akun yang login (PRD 6.7, UAT 10). Backend
// menentukan scope; nama pemberi rating tidak pernah ikut.
const emit = defineEmits(['loaded'])
const auth = useAuthStore()

const summary = ref(null)
const error = ref('')
const loading = ref(true)

const maxBucket = computed(() =>
  Math.max(1, ...(summary.value?.distribution ?? []).map((d) => d.total)),
)
// Rincian satu tingkat di bawah scope: desa → per RW, RW → per RT.
const regionTitle = computed(() => (auth.hasRole('rw') ? 'Per RT' : 'Per RW'))
const decimal = (n) =>
  n === null || n === undefined ? '—' : n.toLocaleString('id-ID', { minimumFractionDigits: 1 })

onMounted(async () => {
  try {
    const { data } = await api.get('/ratings/summary')
    summary.value = data.data
    emit('loaded', data.data)
  } catch (err) {
    error.value = errorMessage(err, 'Rekap rating tidak bisa dimuat.')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section
    class="flex flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8"
    aria-labelledby="rating-summary"
  >
    <div class="flex flex-col gap-1">
      <h2 id="rating-summary" class="text-lg font-semibold">Kepuasan warga</h2>
      <p class="text-sm text-desa-secondary">
        Rating 1–5 dari warga setelah surat atau laporannya selesai, sejak awal pemakaian.
      </p>
    </div>

    <p v-if="loading" class="font-medium text-desa-secondary">Memuat rekap rating…</p>
    <p v-else-if="error" class="font-medium text-desa-red">{{ error }}</p>

    <div
      v-else-if="!summary.total"
      class="flex items-center gap-4 rounded-2xl bg-desa-background/50 p-5"
    >
      <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-white">
        <AppIcon name="tick-circle" class="text-desa-dark-green" />
      </span>
      <p class="leading-5 text-desa-secondary">
        Belum ada rating. Warga diminta memberi rating setelah surat atau laporannya selesai.
      </p>
    </div>

    <div v-else class="grid gap-6 lg:grid-cols-2">
      <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-end gap-4">
          <p class="text-5xl leading-none font-semibold">{{ decimal(summary.average) }}</p>
          <div class="flex flex-col gap-1.5 pb-1">
            <StarRating :score="summary.average" size="size-6" />
            <p class="text-sm font-medium text-desa-secondary">dari {{ summary.total }} rating</p>
          </div>
        </div>

        <ul class="flex flex-col gap-2" aria-label="Sebaran rating">
          <li
            v-for="row in summary.distribution"
            :key="row.score"
            class="flex items-center gap-3 text-sm"
          >
            <span class="w-4 font-semibold">{{ row.score }}</span>
            <span class="h-2.5 flex-1 overflow-hidden rounded-full bg-desa-background">
              <span
                class="block h-full rounded-full bg-desa-yellow"
                :style="{ width: `${(row.total / maxBucket) * 100}%` }"
              />
            </span>
            <span class="w-8 text-right text-desa-secondary">{{ row.total }}</span>
          </li>
        </ul>

        <dl class="grid grid-cols-2 gap-3">
          <div
            v-for="[key, label] in [
              ['letter_request', 'Surat pengantar'],
              ['complaint', 'Laporan warga'],
            ]"
            :key="key"
            class="flex flex-col gap-1 rounded-2xl bg-desa-background/50 p-4"
          >
            <dt class="text-sm font-medium text-desa-secondary">{{ label }}</dt>
            <dd class="text-xl font-semibold">
              {{ decimal(summary.by_type[key].average) }}
              <span class="text-sm font-medium text-desa-secondary"
                >· {{ summary.by_type[key].total }} rating</span
              >
            </dd>
          </div>
        </dl>
      </div>

      <div class="flex flex-col gap-5">
        <div v-if="summary.by_region.length" class="flex flex-col gap-3">
          <h3 class="font-semibold">{{ regionTitle }}</h3>
          <ul class="flex flex-col gap-2">
            <li
              v-for="region in summary.by_region"
              :key="region.id"
              class="flex items-center justify-between gap-3 rounded-xl bg-desa-background/50 px-4 py-3"
            >
              <span class="font-medium">{{ region.label }}</span>
              <span class="flex items-center gap-2 text-sm">
                <StarRating :score="region.average" size="size-4" />
                <span class="font-semibold">{{ decimal(region.average) }}</span>
                <span class="text-desa-secondary">({{ region.total }})</span>
              </span>
            </li>
          </ul>
        </div>

        <div class="flex flex-col gap-3">
          <h3 class="font-semibold">Komentar terbaru</h3>
          <ul v-if="summary.recent.length" class="flex flex-col gap-3">
            <li
              v-for="item in summary.recent"
              :key="item.id"
              class="flex flex-col gap-1.5 rounded-xl border border-desa-background p-4"
            >
              <div class="flex flex-wrap items-center gap-2 text-sm">
                <StarRating :score="item.score" size="size-4" />
                <span class="font-medium">{{ item.subject }}</span>
                <span class="text-desa-secondary"
                  >· {{ item.region }} · {{ timeAgo(item.created_at) }}</span
                >
              </div>
              <p class="leading-relaxed">“{{ item.comment }}”</p>
            </li>
          </ul>
          <p v-else class="text-sm text-desa-secondary">Belum ada komentar.</p>
        </div>
      </div>
    </div>
  </section>
</template>
