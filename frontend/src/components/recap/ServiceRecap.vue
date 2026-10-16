<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { api, downloadFile, errorMessage } from '@/lib/api'
import {
  complaintStatusLabels,
  decimal,
  letterStatusLabels,
  periodOptions,
  periodRange,
  recapTiles,
  regionParams,
  regionSelectOptions,
} from '@/lib/recap'
import { useAuthStore } from '@/stores/auth'

import BarList from './BarList.vue'

// Rekap surat, laporan, dan rating per periode untuk wilayah akun (PRD 6.8), plus
// ekspor Excel/PDF untuk RW dan operator (PRD 6.10). Backend menentukan scope;
// filter wilayah di sini hanya bisa mempersempitnya.
const auth = useAuthStore()
const canExport = computed(() => auth.hasRole('rw', 'operator'))

const preset = ref('this-month')
const custom = reactive(periodRange('this-month'))
const region = ref('all')

const summary = ref(null)
const loading = ref(true)
const error = ref('')
// Daftar RW/RT untuk pilihan filter, dari muatan pertama (tanpa filter wilayah).
const regionSource = ref(null)
const expanded = ref(new Set())

const exporting = ref('')
const exportError = ref('')

const range = computed(() => (preset.value === 'custom' ? custom : periodRange(preset.value)))
const params = computed(() => ({
  from: range.value.from,
  to: range.value.to,
  ...regionParams(region.value),
}))

const regionOptions = computed(() => {
  const source = regionSource.value
  return source ? regionSelectOptions({ ...source.scope, rws: source.rws, rts: source.rts }) : []
})

const tiles = computed(() => (summary.value ? recapTiles(summary.value) : []))

const statusItems = (counts, labels) =>
  Object.entries(labels).map(([key, label]) => ({ label, total: counts[key] ?? 0 }))
const rankItems = (items) => items.map((item) => ({ label: item.name, total: item.total }))

const letterStatus = computed(() =>
  statusItems(summary.value.letters.by_status, letterStatusLabels),
)
const complaintStatus = computed(() =>
  statusItems(summary.value.complaints.by_status, complaintStatusLabels),
)
const escalatedStatus = computed(() =>
  statusItems(summary.value.complaints.escalated_village.by_status, complaintStatusLabels).filter(
    (item) => item.total > 0,
  ),
)

// Tabel wilayah: desa → per RW (bisa dibuka per RT), RW → per RT. RT tidak punya rincian.
const regionRows = computed(() => {
  const s = summary.value
  if (!s) return []
  if (s.scope.level === 'village') {
    return s.rws.flatMap((rw) => [
      { ...rw, key: `rw-${rw.id}`, name: rw.label, group: true },
      ...(expanded.value.has(rw.id)
        ? s.rts
            .filter((rt) => rt.rw.id === rw.id)
            .map((rt) => ({ ...rt, key: `rt-${rt.id}`, name: rt.label, child: true }))
        : []),
    ])
  }
  if (s.scope.level === 'rw') {
    return s.rts.map((rt) => ({ ...rt, key: `rt-${rt.id}`, name: rt.label }))
  }
  return []
})

function toggle(id) {
  const next = new Set(expanded.value)
  if (!next.delete(id)) next.add(id)
  expanded.value = next
}

let requestId = 0
async function load() {
  if (!range.value.from || !range.value.to) return
  const id = ++requestId
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/recap', { params: params.value })
    if (id !== requestId) return
    summary.value = data.data
    if (region.value === 'all') regionSource.value = data.data
  } catch (err) {
    if (id !== requestId) return
    error.value = errorMessage(err, 'Rekap tidak bisa dimuat.')
  } finally {
    if (id === requestId) loading.value = false
  }
}

async function exportAs(format) {
  exporting.value = format
  exportError.value = ''
  try {
    await downloadFile('/recap/export', { ...params.value, format }, `rekap-rukana.${format}`)
  } catch (err) {
    exportError.value = errorMessage(err, 'Ekspor gagal. Coba lagi.')
  } finally {
    exporting.value = ''
  }
}

// Saat beralih ke "Pilih tanggal", mulai dari rentang yang sedang tampil.
watch(preset, (value, old) => {
  if (value === 'custom' && old !== 'custom') Object.assign(custom, periodRange(old))
})
watch(params, load, { deep: true })
onMounted(load)
</script>

<template>
  <section
    class="flex flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8"
    aria-labelledby="recap-title"
  >
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-start">
      <div class="flex flex-col gap-1">
        <h2 id="recap-title" class="text-lg font-semibold">Rekap layanan</h2>
        <p class="text-sm text-desa-secondary">
          <template v-if="summary">{{ summary.scope.label }} · {{ summary.period.label }}</template>
          <template v-else>Surat, laporan, dan rating per periode.</template>
        </p>
      </div>
      <div v-if="canExport" class="flex flex-wrap gap-3">
        <AppButton
          variant="secondary"
          :loading="exporting === 'xlsx'"
          :disabled="!!exporting || loading"
          @click="exportAs('xlsx')"
        >
          <AppIcon v-if="exporting !== 'xlsx'" name="document-download" />
          Unduh Excel
        </AppButton>
        <AppButton
          variant="secondary"
          :loading="exporting === 'pdf'"
          :disabled="!!exporting || loading"
          @click="exportAs('pdf')"
        >
          <AppIcon v-if="exporting !== 'pdf'" name="document-download" />
          Unduh PDF
        </AppButton>
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
    </div>

    <AppAlert v-if="exportError">{{ exportError }}</AppAlert>
    <AppAlert v-if="error">{{ error }}</AppAlert>
    <p v-else-if="loading && !summary" class="font-medium text-desa-secondary">Memuat rekap…</p>

    <div
      v-if="summary"
      :class="['flex flex-col gap-6 transition-opacity', loading && 'opacity-60']"
      :aria-busy="loading"
    >
      <ul class="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 lg:grid-cols-3">
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

      <div class="grid gap-6 lg:grid-cols-2">
        <BarList label="Status surat" :items="letterStatus" bar-class="bg-desa-dark-green" />
        <BarList label="Status laporan" :items="complaintStatus" bar-class="bg-desa-orange" />
        <BarList
          label="Layanan surat terbanyak"
          :items="rankItems(summary.letters.top_services)"
          empty-text="Belum ada surat pada periode ini."
        />
        <BarList
          label="Kategori laporan terbanyak"
          :items="rankItems(summary.complaints.top_categories)"
          bar-class="bg-desa-yellow"
          empty-text="Belum ada laporan pada periode ini."
        />
        <BarList
          v-if="summary.scope.level === 'village'"
          label="Laporan eskalasi ke desa"
          :items="escalatedStatus"
          bar-class="bg-desa-red"
          empty-text="Tidak ada laporan yang dieskalasi ke desa pada periode ini."
        />
      </div>

      <div v-if="regionRows.length" class="flex flex-col gap-3">
        <h3 class="font-semibold">
          {{ summary.scope.level === 'village' ? 'Per RW dan RT' : 'Per RT' }}
        </h3>
        <div class="overflow-x-auto rounded-2xl border border-desa-background">
          <table class="w-full min-w-[720px] text-left text-sm">
            <thead class="bg-desa-background/60 text-desa-secondary">
              <tr>
                <th scope="col" class="px-4 py-3 font-medium">Wilayah</th>
                <th scope="col" class="px-3 py-3 text-right font-medium">Surat masuk</th>
                <th scope="col" class="px-3 py-3 text-right font-medium">Surat selesai</th>
                <th scope="col" class="px-3 py-3 text-right font-medium">Laporan masuk</th>
                <th scope="col" class="px-3 py-3 text-right font-medium">Laporan berjalan</th>
                <th scope="col" class="px-3 py-3 font-medium">Kategori terbanyak</th>
                <th scope="col" class="px-4 py-3 text-right font-medium">Rating</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in regionRows"
                :key="row.key"
                :class="['border-t border-desa-background', row.child && 'bg-desa-background/30']"
              >
                <th scope="row" class="px-4 py-3 font-semibold">
                  <button
                    v-if="row.group"
                    type="button"
                    class="flex items-center gap-2 hover:text-desa-dark-green"
                    :aria-expanded="expanded.has(row.id)"
                    @click="toggle(row.id)"
                  >
                    <AppIcon
                      name="arrow-down"
                      :class="[
                        'size-4! transition-transform',
                        !expanded.has(row.id) && '-rotate-90',
                      ]"
                    />
                    {{ row.name }}
                  </button>
                  <span v-else :class="row.child && 'pl-6 font-medium'">{{ row.name }}</span>
                </th>
                <td class="px-3 py-3 text-right">{{ row.letters.total }}</td>
                <td class="px-3 py-3 text-right">{{ row.letters.completed }}</td>
                <td class="px-3 py-3 text-right">{{ row.complaints.total }}</td>
                <td class="px-3 py-3 text-right">{{ row.complaints.active }}</td>
                <td class="px-3 py-3">
                  <template v-if="row.top_category"
                    >{{ row.top_category.name }}
                    <span class="text-desa-secondary"
                      >({{ row.top_category.total }})</span
                    ></template
                  >
                  <span v-else class="text-desa-secondary">—</span>
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                  {{ decimal(row.ratings.average) }}
                  <span v-if="row.ratings.total" class="text-desa-secondary"
                    >({{ row.ratings.total }})</span
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="summary.scope.level === 'village'" class="text-sm text-desa-secondary lg:hidden">
          Geser tabel ke samping untuk melihat semua kolom. Ketuk nama RW untuk melihat RT-nya.
        </p>
      </div>

      <p class="text-sm text-desa-secondary">
        Surat dan laporan dihitung dari yang masuk pada periode ini; rating dari yang diberikan pada
        periode ini.<template v-if="canExport"> Berkas ekspor berisi angka yang sama.</template>
      </p>
    </div>
  </section>
</template>
