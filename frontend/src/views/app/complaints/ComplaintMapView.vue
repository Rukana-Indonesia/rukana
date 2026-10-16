<script setup>
import { computed, onMounted, reactive, ref, useTemplateRef, watch } from 'vue'
import { useRouter } from 'vue-router'

import ComplaintMap from '@/components/map/ComplaintMap.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { api, errorMessage } from '@/lib/api'
import {
  categoryFilterOptions,
  statusColors,
  statusFilterOptions,
  statusLegend,
} from '@/lib/complaintMap'
import { formatDate } from '@/lib/format'
import { periodOptions, periodRange, regionParams, regionSelectOptions } from '@/lib/recap'

// Peta sebaran laporan (PRD 6.9): titik laporan per kategori, status, dan periode,
// plus daftar titik yang berulang. Backend menentukan scope; filter wilayah di sini
// hanya bisa mempersempitnya.
const router = useRouter()
const mapRef = useTemplateRef('map')
const mapSection = useTemplateRef('mapSection')

const preset = ref('this-month')
const custom = reactive(periodRange('this-month'))
const region = ref('all')
const category = ref('all')
const status = ref('all')

const data = ref(null)
const loading = ref(true)
const error = ref('')
const categories = ref([])
// Pilihan wilayah dari muatan pertama (tanpa filter wilayah), supaya label "Seluruh …" tetap.
const regionSource = ref(null)
const shown = ref(10)

const range = computed(() => (preset.value === 'custom' ? custom : periodRange(preset.value)))
const params = computed(() => ({
  from: range.value.from,
  to: range.value.to,
  ...regionParams(region.value),
  category_id: category.value === 'all' ? undefined : category.value,
  status: status.value === 'all' ? undefined : status.value,
}))

const regionOptions = computed(() => {
  const source = regionSource.value
  return source ? regionSelectOptions({ ...source.scope, ...source.regions }) : []
})
const categoryOptions = computed(() => categoryFilterOptions(categories.value))
const withoutLocation = computed(() =>
  data.value ? data.value.total - data.value.with_location : 0,
)
const visiblePoints = computed(() => data.value?.points.slice(0, shown.value) ?? [])

function open(id) {
  router.push({ name: 'complaint-detail', params: { id } })
}

function focus(ids) {
  mapRef.value?.focus(ids)
  mapSection.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

let requestId = 0
async function load() {
  if (!range.value.from || !range.value.to) return
  const id = ++requestId
  loading.value = true
  error.value = ''
  try {
    const { data: body } = await api.get('/complaint-map', { params: params.value })
    if (id !== requestId) return
    data.value = body.data
    shown.value = 10
    if (region.value === 'all') regionSource.value = body.data
  } catch (err) {
    if (id !== requestId) return
    error.value = errorMessage(err, 'Peta tidak bisa dimuat.')
  } finally {
    if (id === requestId) loading.value = false
  }
}

async function loadCategories() {
  try {
    const { data: body } = await api.get('/complaint-categories')
    categories.value = body.data
  } catch {
    // Filter kategori cukup disembunyikan; peta tetap bisa dipakai.
  }
}

watch(preset, (value, old) => {
  if (value === 'custom' && old !== 'custom') Object.assign(custom, periodRange(old))
})
watch(params, load, { deep: true })
onMounted(() => {
  load()
  loadCategories()
})
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <section class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
      <div class="flex items-center gap-4">
        <span
          class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-desa-foreshadow text-desa-dark-green"
        >
          <AppIcon name="map" class="size-7!" />
        </span>
        <div class="flex flex-col gap-1">
          <h1 class="text-xl font-semibold sm:text-2xl">Peta Sebaran Laporan</h1>
          <p class="text-desa-secondary">
            {{
              data
                ? `${data.scope.label} · ${data.period.label}`
                : 'Titik laporan warga per kategori, status, dan periode.'
            }}
          </p>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <AppSelect v-model="preset" label="Periode" icon="calendar-2" :options="periodOptions" />
        <template v-if="preset === 'custom'">
          <AppInput v-model="custom.from" label="Dari tanggal" type="date" :max="custom.to" />
          <AppInput v-model="custom.to" label="Sampai tanggal" type="date" :min="custom.from" />
        </template>
        <AppSelect
          v-if="regionOptions.length"
          v-model="region"
          label="Wilayah"
          icon="location"
          :options="regionOptions"
        />
        <AppSelect
          v-if="categories.length"
          v-model="category"
          label="Kategori"
          icon="grid-5"
          :options="categoryOptions"
        />
        <AppSelect
          v-model="status"
          label="Status"
          icon="tick-circle"
          :options="statusFilterOptions"
        />
      </div>
    </section>

    <AppAlert v-if="error">{{ error }}</AppAlert>
    <div v-if="loading && !data" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat peta…
    </div>

    <template v-if="data">
      <section
        ref="mapSection"
        :class="[
          'flex flex-col gap-4 rounded-2xl bg-white p-4 transition-opacity sm:p-6',
          loading && 'opacity-60',
        ]"
        :aria-busy="loading"
      >
        <div class="flex flex-wrap items-center justify-between gap-3 px-2 sm:px-0">
          <p class="font-semibold">
            {{ data.with_location }}
            <span class="font-medium text-desa-secondary">
              dari {{ data.total }} laporan punya titik lokasi
            </span>
          </p>
          <button
            v-if="data.points.length"
            type="button"
            class="text-sm font-medium text-desa-dark-green underline-offset-4 hover:underline"
            @click="mapRef?.fitAll()"
          >
            Tampilkan semua titik
          </button>
        </div>

        <ComplaintMap
          ref="map"
          :points="data.points"
          :hotspots="data.hotspots"
          :center="data.center"
          @open="open"
        />

        <ul
          class="flex flex-wrap gap-x-5 gap-y-2 px-2 sm:px-0"
          aria-label="Keterangan warna status"
        >
          <li
            v-for="item in statusLegend"
            :key="item.value"
            class="flex items-center gap-2 text-sm text-desa-secondary"
          >
            <span
              class="size-3 rounded-full ring-2 ring-white"
              :style="{ background: item.color }"
            />
            {{ item.label }}
          </li>
          <li class="flex items-center gap-2 text-sm text-desa-secondary">
            <span class="size-3 rounded-full border-2 border-dashed border-desa-orange" />
            Titik berulang
          </li>
        </ul>

        <AppAlert v-if="data.truncated" variant="warning">
          Hanya {{ data.points.length }} laporan terbaru yang ditampilkan. Persempit periode atau
          wilayah untuk melihat sisanya.
        </AppAlert>
        <p v-if="withoutLocation > 0" class="px-2 text-sm text-desa-secondary sm:px-0">
          {{ withoutLocation }} laporan tidak tampil di peta karena pelapor hanya mengisi patokan
          lokasi, tanpa titik peta.
        </p>
      </section>

      <div class="grid gap-[14px] lg:grid-cols-2">
        <section
          class="flex flex-col gap-4 rounded-2xl bg-white p-6 sm:p-8"
          aria-labelledby="hotspot-title"
        >
          <div class="flex flex-col gap-1">
            <h2 id="hotspot-title" class="text-lg font-semibold">Titik masalah berulang</h2>
            <p class="text-sm text-desa-secondary">
              Lokasi dengan 2 laporan atau lebih dalam jarak sekitar 100 m.
            </p>
          </div>
          <p v-if="!data.hotspots.length" class="text-desa-secondary">
            Belum ada titik berulang pada periode dan filter ini.
          </p>
          <ul v-else class="flex flex-col gap-3">
            <li v-for="(spot, i) in data.hotspots" :key="i">
              <button
                type="button"
                class="flex w-full items-center gap-4 rounded-2xl border border-desa-background p-4 text-left transition-colors hover:border-desa-dark-green"
                @click="focus(spot.ids)"
              >
                <span
                  class="flex size-12 shrink-0 flex-col items-center justify-center rounded-2xl bg-desa-orange/10 text-desa-orange"
                >
                  <span class="text-lg leading-5 font-semibold">{{ spot.total }}</span>
                  <span class="text-[11px] leading-3">laporan</span>
                </span>
                <span class="flex min-w-0 flex-1 flex-col gap-1">
                  <span class="truncate font-semibold">{{ spot.top_category }}</span>
                  <span class="text-sm text-desa-secondary">
                    {{ spot.regions.join(', ') }} ·
                    {{ spot.active ? `${spot.active} belum selesai` : 'semua sudah ditutup' }}
                  </span>
                </span>
                <AppIcon name="map" class="size-5! text-desa-dark-green" />
              </button>
            </li>
          </ul>
        </section>

        <section
          class="flex flex-col gap-4 rounded-2xl bg-white p-6 sm:p-8"
          aria-labelledby="points-title"
        >
          <div class="flex flex-col gap-1">
            <h2 id="points-title" class="text-lg font-semibold">Laporan di peta</h2>
            <p class="text-sm text-desa-secondary">Ketuk laporan untuk melihat titiknya.</p>
          </div>
          <p v-if="!data.points.length" class="text-desa-secondary">
            Tidak ada laporan bertitik lokasi pada periode dan filter ini.
          </p>
          <ul v-else class="flex flex-col divide-y divide-desa-background">
            <li v-for="point in visiblePoints" :key="point.id">
              <button
                type="button"
                class="flex w-full items-center gap-3 py-3 text-left hover:text-desa-dark-green"
                @click="focus([point.id])"
              >
                <span
                  class="size-3 shrink-0 rounded-full"
                  :style="{ background: statusColors[point.status] }"
                />
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span class="truncate font-semibold">{{ point.title }}</span>
                  <span class="truncate text-sm text-desa-secondary">
                    {{ point.code }} · {{ point.category.name }} · {{ point.region }}
                  </span>
                </span>
                <span class="shrink-0 text-right text-sm text-desa-secondary">
                  {{ point.status_label }}<br />{{ formatDate(point.created_at) }}
                </span>
              </button>
            </li>
          </ul>
          <button
            v-if="data.points.length > shown"
            type="button"
            class="self-start text-sm font-medium text-desa-dark-green underline-offset-4 hover:underline"
            @click="shown += 10"
          >
            Tampilkan {{ Math.min(10, data.points.length - shown) }} lagi
          </button>
        </section>
      </div>
    </template>
  </div>
</template>
