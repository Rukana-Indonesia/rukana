<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import BarList from '@/components/recap/BarList.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { monthOptions, percent } from '@/lib/ai'
import { api, errorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { recapTiles } from '@/lib/recap'

// Monthly Insight (PRD 7.2, UAT 12): narasi AI per desa atau per RW. Angkanya dihitung
// backend dengan rumus dasbor; AI hanya menyusun kalimatnya. Kepala desa membaca saja.
const months = monthOptions()
const month = ref(months[0].value)
const region = ref('all')
const rwOptions = ref([]) // dari muatan pertama tingkat desa

const data = ref(null)
const loading = ref(true)
const error = ref('')
const notice = ref('')
const generating = ref(false)

const insight = computed(() => data.value?.insight)
const metrics = computed(() => data.value?.metrics)
const tiles = computed(() => (metrics.value ? recapTiles(metrics.value) : []))
const regionSelect = computed(() =>
  rwOptions.value.length
    ? [
        { value: 'all', label: 'Seluruh desa' },
        ...rwOptions.value.map((rw) => ({ value: String(rw.id), label: rw.label })),
      ]
    : [],
)
const accuracy = computed(() => data.value?.smart_complaint)

async function load() {
  error.value = ''
  try {
    const { data: res } = await api.get('/insights', {
      params: { month: month.value, rw_id: region.value === 'all' ? undefined : region.value },
    })
    data.value = res.data
    if (res.data.scope.level === 'village' && !rwOptions.value.length) {
      rwOptions.value = res.data.metrics.rws.map((rw) => ({ id: rw.id, label: rw.label }))
    }
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([month, region], () => {
  notice.value = ''
  loading.value = true
  load()
})

async function generate() {
  generating.value = true
  error.value = ''
  try {
    const { data: res } = await api.post('/insights', {
      month: month.value,
      rw_id: region.value === 'all' ? undefined : region.value,
    })
    notice.value = res.message
    await load()
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    generating.value = false
  }
}

// Narasi disusun di queue: cek ulang berkala selama statusnya "menunggu".
let timer = null
let tries = 0
watch(
  () => insight.value?.status,
  (status) => {
    clearInterval(timer)
    timer = null
    if (status !== 'pending') return
    tries = 0
    timer = setInterval(() => {
      if (++tries > 40) return clearInterval(timer)
      load()
    }, 4000)
  },
)
onBeforeUnmount(() => clearInterval(timer))

const rank = (items) => items.map((item) => ({ label: item.name, total: item.total }))
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <section class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-4">
          <span
            class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-desa-foreshadow text-desa-dark-green"
          >
            <AppIcon name="status-up" class="size-7!" />
          </span>
          <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold sm:text-2xl">Ringkasan Bulanan</h1>
            <p class="text-desa-secondary">
              {{ data ? `${data.scope.label} · ${data.period.label}` : 'Monthly Insight' }}
            </p>
          </div>
        </div>
        <AppButton
          v-if="data?.can.generate && data.ai_configured && insight?.status !== 'pending'"
          :variant="insight?.status === 'done' && !insight.stale ? 'secondary' : 'primary'"
          :loading="generating"
          @click="generate"
        >
          <AppIcon name="magic-star" />
          {{ insight ? 'Susun ulang' : 'Susun ringkasan' }}
        </AppButton>
      </div>

      <div class="grid gap-4 sm:grid-cols-2 lg:max-w-2xl">
        <AppSelect v-model="month" label="Bulan" icon="calendar-2" :options="months" />
        <AppSelect
          v-if="regionSelect.length"
          v-model="region"
          label="Wilayah"
          icon="location"
          :options="regionSelect"
        />
      </div>
    </section>

    <AppAlert v-if="error">{{ error }}</AppAlert>
    <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>
    <AppAlert v-if="data && !data.ai_configured" variant="warning">
      Fitur AI belum dikonfigurasi, jadi narasi belum bisa disusun. Angka di bawah tetap dihitung
      sistem dan sama dengan dasbor.
    </AppAlert>

    <div v-if="loading && !data" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat ringkasan…
    </div>

    <template v-else-if="data">
      <!-- Narasi AI. -->
      <section class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">Ringkasan {{ data.period.label }}</h2>
          <AppBadge v-if="insight?.status === 'pending'" variant="info">Sedang disusun…</AppBadge>
          <AppBadge v-else-if="insight?.status === 'failed'" variant="warning">Gagal</AppBadge>
          <AppBadge v-else-if="insight?.stale" variant="warning">Data sudah berubah</AppBadge>
          <AppBadge v-else-if="insight" variant="success">Disusun AI</AppBadge>
        </div>

        <p v-if="!insight" class="text-desa-secondary">
          Ringkasan bulan ini belum disusun.
          <template v-if="!data.can.generate">
            Ringkasan bulan lalu disusun otomatis setiap tanggal 1; operator desa juga bisa
            menyusunnya kapan saja.
          </template>
        </p>

        <p
          v-else-if="insight.status === 'pending'"
          class="flex items-center gap-3 text-desa-secondary"
        >
          <span
            class="size-5 shrink-0 animate-spin rounded-full border-2 border-desa-foreshadow border-t-desa-dark-green"
            aria-hidden="true"
          />
          AI sedang menyusun narasi dari angka bulan ini. Biasanya selesai dalam satu menit.
        </p>

        <AppAlert v-else-if="insight.status === 'failed'" variant="warning">
          {{ insight.error }}
        </AppAlert>

        <template v-else>
          <AppAlert v-if="insight.stale" variant="warning">
            Data bulan ini sudah berubah sejak ringkasan disusun, jadi angka di narasi bisa berbeda
            dengan dasbor sekarang.
            {{ data.can.generate ? 'Tekan “Susun ulang” untuk memperbarui.' : '' }}
          </AppAlert>
          <p class="leading-relaxed">{{ insight.narrative.ringkasan }}</p>
          <div class="grid gap-5 md:grid-cols-2">
            <div class="flex flex-col gap-3">
              <h3 class="font-semibold">Sorotan</h3>
              <ul class="flex flex-col gap-2">
                <li v-for="(item, i) in insight.narrative.sorotan" :key="i" class="flex gap-3">
                  <AppIcon name="tick-circle" class="mt-0.5 size-5! text-desa-dark-green" />
                  <span>{{ item }}</span>
                </li>
              </ul>
            </div>
            <div v-if="insight.narrative.perlu_perhatian.length" class="flex flex-col gap-3">
              <h3 class="font-semibold">Perlu perhatian</h3>
              <ul class="flex flex-col gap-2">
                <li
                  v-for="(item, i) in insight.narrative.perlu_perhatian"
                  :key="i"
                  class="flex gap-3"
                >
                  <AppIcon name="timer" class="mt-0.5 size-5! text-[#8a5300]" />
                  <span>{{ item }}</span>
                </li>
              </ul>
            </div>
          </div>
          <p class="text-sm text-desa-secondary">
            Disusun {{ formatDate(insight.generated_at, true) }}. Angka dihitung sistem dengan rumus
            yang sama dengan dasbor; AI hanya menyusun kalimatnya, dan setiap angka di narasi sudah
            dicek terhadap data.
          </p>
        </template>
      </section>

      <!-- Angka yang dipakai narasi (sama dengan dasbor). -->
      <section class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
        <div class="flex flex-col gap-1">
          <h2 class="text-lg font-semibold">Angka {{ data.period.label }}</h2>
          <p class="text-sm text-desa-secondary">
            {{
              insight?.status === 'done'
                ? 'Angka saat ringkasan disusun.'
                : 'Angka terkini, sama dengan panel rekap di dasbor.'
            }}
          </p>
        </div>
        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <li
            v-for="tile in tiles"
            :key="tile.label"
            class="flex flex-col gap-2 rounded-2xl bg-desa-background/50 p-5"
          >
            <div class="flex items-start justify-between gap-3">
              <p class="text-sm font-medium text-desa-secondary">{{ tile.label }}</p>
              <AppIcon :name="tile.icon" class="size-5! text-desa-dark-green" />
            </div>
            <p class="text-[28px] leading-9 font-semibold">
              {{ tile.value }}
              <span v-if="tile.unit" class="text-base font-medium text-desa-secondary">{{
                tile.unit
              }}</span>
            </p>
            <p class="text-sm text-desa-secondary">{{ tile.caption }}</p>
          </li>
        </ul>
        <div class="grid gap-6 md:grid-cols-2">
          <BarList
            label="Kategori laporan terbanyak"
            :items="rank(metrics.complaints.top_categories)"
            empty-text="Belum ada laporan bulan ini."
          />
          <BarList
            label="Layanan surat terbanyak"
            :items="rank(metrics.letters.top_services)"
            bar-class="bg-desa-blue/60"
            empty-text="Belum ada surat bulan ini."
          />
        </div>
      </section>

      <!-- Ketepatan Smart Complaint (target ≥ 80%). -->
      <section v-if="accuracy" class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
        <div class="flex flex-col gap-1">
          <h2 class="text-lg font-semibold">Ketepatan saran AI laporan</h2>
          <p class="text-sm text-desa-secondary">
            Saran Smart Complaint dibandingkan dengan keputusan pengurus, untuk
            {{ accuracy.reviewed }} laporan {{ data.period.label }} yang sudah diputuskan. Target ≥
            80%.
          </p>
        </div>
        <dl class="grid gap-3 sm:grid-cols-3">
          <div
            v-for="item in [
              { label: 'Kategori tepat', value: accuracy.category },
              { label: 'Urgensi tepat', value: accuracy.urgency },
              { label: 'Tingkat penanganan tepat', value: accuracy.level },
            ]"
            :key="item.label"
            class="flex flex-col gap-1 rounded-2xl bg-desa-background/50 p-5"
          >
            <dt class="text-sm font-medium text-desa-secondary">{{ item.label }}</dt>
            <dd
              :class="[
                'text-[28px] leading-9 font-semibold',
                item.value !== null && item.value < 80 && 'text-[#8a5300]',
              ]"
            >
              {{ percent(item.value) }}
            </dd>
          </div>
        </dl>
      </section>
    </template>
  </div>
</template>
