<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import StarRating from '@/components/ratings/StarRating.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { api, errorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { statusBadge } from '@/lib/letters'
import { useAuthStore } from '@/stores/auth'

// Warga: surat sendiri. Pengurus: antrean kerja + register pengantar wilayahnya
// (scope wilayah ditentukan backend dari akun, bukan dari filter di sini).
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const isWarga = computed(() => auth.hasRole('warga'))
const canAct = computed(() => auth.hasRole('rt', 'rw', 'operator'))
const canFilterRt = computed(() => auth.hasRole('rw', 'operator', 'kepala_desa'))

const tabs = computed(() => [
  ...(canAct.value ? [{ key: 'inbox', label: 'Perlu Tindakan' }] : []),
  { key: 'active', label: 'Berjalan' },
  { key: 'completed', label: 'Selesai' },
  { key: 'rejected', label: 'Ditolak' },
  { key: 'cancelled', label: 'Dibatalkan' },
  { key: 'all', label: 'Semua' },
])

const filters = reactive({
  tab: typeof route.query.tab === 'string' ? route.query.tab : canAct.value ? 'inbox' : 'active',
  search: '',
  rt_id: 0,
  from: '',
  to: '',
})
const letters = ref([])
const counts = ref({})
const inbox = ref(0)
const page = ref({ current: 1, last: 1 })
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
const rtOptions = ref([])

const tabCount = computed(() => {
  const c = counts.value
  const all = Object.values(c).reduce((sum, n) => sum + n, 0)
  return {
    inbox: inbox.value,
    active: all - (c.completed ?? 0) - (c.rejected ?? 0) - (c.cancelled ?? 0),
    completed: c.completed ?? 0,
    rejected: c.rejected ?? 0,
    cancelled: c.cancelled ?? 0,
    all,
  }
})

const intro = computed(() => {
  if (isWarga.value) return 'Pantau posisi surat pengantar Anda. Setiap pengajuan punya kode tiket.'
  return {
    rt: `Verifikasi pengantar dari warga ${auth.user?.rt?.label}/${auth.user?.rw?.label}. Daftar ini juga menjadi register pengantar RT.`,
    rw: `Setujui pengantar yang sudah diverifikasi RT di ${auth.user?.rw?.label}.`,
    operator: 'Terima surat dari RW, tandai siap diambil, dan selesaikan saat warga mengambilnya.',
    kepala_desa: 'Ringkasan pengajuan surat seluruh desa.',
  }[auth.role]
})

function waitingLabel(letter) {
  if (letter.status === 'ready_for_pickup') return 'Siap diambil di kantor desa'
  return letter.current_level_label ? `Menunggu ${letter.current_level_label}` : ''
}

async function load(more = false) {
  if (more) loadingMore.value = true
  else loading.value = true
  error.value = ''
  const tab = filters.tab
  try {
    const { data } = await api.get('/letters', {
      params: {
        inbox: tab === 'inbox' ? 1 : undefined,
        status: ['active', 'completed', 'rejected', 'cancelled'].includes(tab) ? tab : undefined,
        search: filters.search || undefined,
        rt_id: filters.rt_id || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        page: more ? page.value.current + 1 : 1,
      },
    })
    letters.value = more ? [...letters.value, ...data.data] : data.data
    counts.value = data.meta.counts
    inbox.value = data.meta.inbox
    page.value = { current: data.meta.current_page, last: data.meta.last_page }
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

let searchTimer
watch(
  () => filters.search,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => load(), 400)
  },
)
watch(
  () => [filters.tab, filters.rt_id, filters.from, filters.to],
  () => load(),
)
watch(
  () => filters.tab,
  (tab) => router.replace({ query: { ...route.query, tab } }),
)
onBeforeUnmount(() => clearTimeout(searchTimer))

onMounted(async () => {
  load()
  if (!canFilterRt.value) return
  try {
    const { data } = await api.get('/regions')
    const rws = data.data.rws.filter((rw) => !auth.hasRole('rw') || rw.id === auth.user?.rw?.id)
    rtOptions.value = [
      { value: 0, label: 'Semua RT' },
      ...rws.flatMap((rw) =>
        rw.rts.map((rt) => ({ value: rt.id, label: `${rt.label} / ${rw.label}` })),
      ),
    ]
  } catch {
    // Filter RT opsional.
  }
})
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="sr-only">{{ isWarga ? 'Surat Saya' : 'Surat Pengantar' }}</h1>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="leading-relaxed font-medium text-desa-secondary">{{ intro }}</p>
      <RouterLink
        v-if="isWarga"
        :to="{ name: 'letter-create' }"
        class="inline-flex h-14 shrink-0 items-center justify-center gap-2 rounded-2xl bg-desa-dark-green px-5 font-medium text-white transition-all duration-300 hover:bg-[#2a5030]"
      >
        <AppIcon name="add-square" />
        Ajukan Surat
      </RouterLink>
    </div>

    <div
      class="hide-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0"
      role="tablist"
      aria-label="Status surat"
    >
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        role="tab"
        :aria-selected="filters.tab === tab.key"
        :class="[
          'flex h-12 shrink-0 items-center gap-2 rounded-2xl px-4 leading-5 font-medium transition-all duration-300',
          filters.tab === tab.key
            ? 'bg-desa-dark-green text-white'
            : 'bg-white text-desa-secondary hover:text-desa-dark-green',
        ]"
        @click="filters.tab = tab.key"
      >
        {{ tab.label }}
        <span
          :class="[
            'min-w-6 rounded-full px-2 py-0.5 text-xs font-semibold',
            filters.tab === tab.key
              ? 'bg-white/20 text-white'
              : tab.key === 'inbox' && tabCount.inbox
                ? 'bg-desa-orange text-white'
                : 'bg-desa-background text-desa-secondary',
          ]"
          >{{ tabCount[tab.key] }}</span
        >
      </button>
    </div>

    <div
      v-if="!isWarga"
      :class="[
        'grid gap-4 rounded-2xl bg-white p-4 sm:grid-cols-2 sm:p-6',
        canFilterRt ? 'xl:grid-cols-4' : 'xl:grid-cols-3',
      ]"
    >
      <AppInput
        v-model="filters.search"
        label="Cari"
        icon="receipt-search"
        type="search"
        placeholder="Kode tiket atau nama"
      />
      <AppSelect
        v-if="canFilterRt && rtOptions.length"
        v-model="filters.rt_id"
        label="Wilayah"
        icon="location"
        :options="rtOptions"
      />
      <AppInput v-model="filters.from" label="Diajukan dari" icon="calendar-2" type="date" />
      <AppInput
        v-model="filters.to"
        label="Sampai"
        icon="calendar-2"
        type="date"
        :min="filters.from"
      />
    </div>

    <AppAlert v-if="error">{{ error }}</AppAlert>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat surat…
    </div>

    <div
      v-else-if="!letters.length && !error"
      class="flex flex-col items-center gap-3 rounded-2xl bg-white px-6 py-12 text-center"
    >
      <span class="flex size-16 items-center justify-center rounded-full bg-desa-foreshadow">
        <AppIcon name="document-text" class="size-8! text-desa-dark-green" />
      </span>
      <p class="text-lg font-semibold">
        {{
          filters.tab === 'inbox'
            ? 'Tidak ada pengantar yang menunggu'
            : isWarga && filters.tab === 'active'
              ? 'Belum ada pengajuan yang berjalan'
              : 'Belum ada surat'
        }}
      </p>
      <p class="max-w-sm leading-5 font-medium text-desa-secondary">
        {{
          isWarga
            ? 'Pilih layanan, isi keperluan, lalu pantau statusnya di sini.'
            : 'Pengajuan baru dari warga akan muncul di sini.'
        }}
      </p>
      <RouterLink
        v-if="isWarga"
        :to="{ name: 'letter-create' }"
        class="mt-2 font-medium text-desa-dark-green underline-offset-4 hover:underline"
        >Ajukan surat pengantar</RouterLink
      >
    </div>

    <template v-else>
      <ul class="grid gap-[14px] md:grid-cols-2 2xl:grid-cols-3">
        <li v-for="letter in letters" :key="letter.id">
          <RouterLink
            :to="{ name: 'letter-detail', params: { id: letter.id } }"
            class="flex h-full flex-col gap-4 rounded-2xl bg-white p-6 ring-desa-dark-green transition-all duration-300 hover:ring-[1.5px]"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="flex min-w-0 flex-col gap-1">
                <p class="text-sm font-semibold tracking-wide text-desa-dark-green">
                  {{ letter.code }}
                </p>
                <p class="leading-5 font-semibold">{{ letter.service?.name }}</p>
              </div>
              <AppBadge :variant="statusBadge[letter.status]">{{ letter.status_label }}</AppBadge>
            </div>

            <dl class="grid grid-cols-2 gap-x-3 gap-y-4 rounded-2xl bg-desa-background/50 p-4">
              <div v-if="!isWarga" class="col-span-2 flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Pemohon</dt>
                <dd class="truncate leading-5 font-semibold">
                  {{ letter.applicant?.name }}
                  <span class="font-medium text-desa-secondary"
                    >· {{ letter.rt?.label }}/{{ letter.rw?.label }}</span
                  >
                </dd>
              </div>
              <div class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Diajukan</dt>
                <dd class="leading-5 font-semibold">{{ formatDate(letter.submitted_at) }}</dd>
              </div>
              <div class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Alur</dt>
                <dd class="leading-5 font-semibold">{{ letter.flow_label }}</dd>
              </div>
            </dl>

            <p
              v-if="waitingLabel(letter)"
              class="mt-auto flex items-center gap-2 text-sm font-medium text-desa-secondary"
            >
              <AppIcon name="timer" class="size-[18px]!" />
              {{ waitingLabel(letter) }}
            </p>
            <p
              v-else-if="letter.status === 'rejected'"
              class="mt-auto line-clamp-2 text-sm font-medium text-[#b0203f]"
            >
              Ditolak {{ letter.rejected_level_label }}: {{ letter.rejection_reason }}
            </p>
            <p
              v-else-if="letter.can.rate"
              class="mt-auto flex items-center gap-2 text-sm font-semibold text-[#8a5300]"
            >
              <StarRating :score="0" size="size-4" />
              Beri rating layanan
            </p>
            <p
              v-else-if="letter.rating"
              class="mt-auto flex items-center gap-2 text-sm font-medium text-desa-secondary"
            >
              <StarRating :score="letter.rating.score" size="size-4" />
              Rating {{ letter.rating.score }}/5
            </p>
          </RouterLink>
        </li>
      </ul>

      <AppButton
        v-if="page.current < page.last"
        variant="secondary"
        class="self-center"
        :loading="loadingMore"
        @click="load(true)"
      >
        Muat lebih banyak
      </AppButton>
    </template>
  </div>
</template>
