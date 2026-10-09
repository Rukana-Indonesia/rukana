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
import { statusBadge, urgencyBadge } from '@/lib/complaints'
import { formatDate } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

// Warga: laporan sendiri. Pengurus: antrean (laporan yang sedang dipegang tingkatnya)
// dan semua laporan di wilayahnya. Scope wilayah ditentukan backend dari akun.
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const isWarga = computed(() => auth.hasRole('warga'))
const canAct = computed(() => auth.hasRole('rt', 'rw', 'operator'))
const canFilterRt = computed(() => auth.hasRole('rw', 'operator', 'kepala_desa'))

const tabs = computed(() => [
  ...(canAct.value ? [{ key: 'inbox', label: 'Perlu Tindakan' }] : []),
  { key: 'active', label: 'Berjalan' },
  { key: 'resolved', label: 'Selesai' },
  { key: 'rejected', label: 'Ditolak' },
  { key: 'all', label: 'Semua' },
])

const filters = reactive({
  tab: typeof route.query.tab === 'string' ? route.query.tab : canAct.value ? 'inbox' : 'active',
  search: '',
  category_id: 0,
  rt_id: 0,
  from: '',
  to: '',
})
const complaints = ref([])
const counts = ref({})
const inbox = ref(0)
const page = ref({ current: 1, last: 1 })
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
const rtOptions = ref([])
const categoryOptions = ref([])

const tabCount = computed(() => {
  const c = counts.value
  const all = Object.values(c).reduce((sum, n) => sum + n, 0)
  return {
    inbox: inbox.value,
    active: all - (c.resolved ?? 0) - (c.rejected ?? 0),
    resolved: c.resolved ?? 0,
    rejected: c.rejected ?? 0,
    all,
  }
})

const intro = computed(() => {
  if (isWarga.value)
    return 'Laporkan masalah di lingkungan Anda dan pantau penanganannya. Setiap laporan punya kode tiket.'
  return {
    rt: `Laporan dari warga ${auth.user?.rt?.label}/${auth.user?.rw?.label}. Verifikasi, tangani, atau teruskan ke RW/desa.`,
    rw: `Laporan di ${auth.user?.rw?.label}. Tab "Perlu Tindakan" berisi laporan yang diteruskan RT ke RW.`,
    operator: 'Laporan seluruh desa. Tab "Perlu Tindakan" berisi laporan yang diteruskan ke desa.',
    kepala_desa: 'Ringkasan laporan warga seluruh desa.',
  }[auth.role]
})

function holderLabel(complaint) {
  if (complaint.status === 'resolved' || complaint.status === 'rejected') return ''
  return complaint.status === 'forwarded'
    ? `Diteruskan ke ${complaint.handling_level_label}`
    : `Dipegang ${complaint.handling_level_label}`
}

async function load(more = false) {
  if (more) loadingMore.value = true
  else loading.value = true
  error.value = ''
  const tab = filters.tab
  try {
    const { data } = await api.get('/complaints', {
      params: {
        inbox: tab === 'inbox' ? 1 : undefined,
        status: ['active', 'resolved', 'rejected'].includes(tab) ? tab : undefined,
        search: filters.search || undefined,
        category_id: filters.category_id || undefined,
        rt_id: filters.rt_id || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        page: more ? page.value.current + 1 : 1,
      },
    })
    complaints.value = more ? [...complaints.value, ...data.data] : data.data
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
  () => [filters.tab, filters.category_id, filters.rt_id, filters.from, filters.to],
  () => load(),
)
watch(
  () => filters.tab,
  (tab) => router.replace({ query: { ...route.query, tab } }),
)
onBeforeUnmount(() => clearTimeout(searchTimer))

async function loadFilters() {
  if (isWarga.value) return
  const [categories, regions] = await Promise.allSettled([
    api.get('/complaint-categories'),
    canFilterRt.value ? api.get('/regions') : Promise.resolve(null),
  ])
  if (categories.status === 'fulfilled') {
    categoryOptions.value = [
      { value: 0, label: 'Semua kategori' },
      ...categories.value.data.data.map((parent) => ({
        label: parent.name,
        options: [
          { value: parent.id, label: `Semua ${parent.name}` },
          ...parent.children.map((child) => ({ value: child.id, label: child.name })),
        ],
      })),
    ]
  }
  if (regions.status === 'fulfilled' && regions.value) {
    const rws = regions.value.data.data.rws.filter(
      (rw) => !auth.hasRole('rw') || rw.id === auth.user?.rw?.id,
    )
    rtOptions.value = [
      { value: 0, label: 'Semua RT' },
      ...rws.flatMap((rw) =>
        rw.rts.map((rt) => ({ value: rt.id, label: `${rt.label} / ${rw.label}` })),
      ),
    ]
  }
}

onMounted(() => {
  load()
  loadFilters()
})
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="sr-only">{{ isWarga ? 'Laporan Saya' : 'Laporan Warga' }}</h1>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="leading-relaxed font-medium text-desa-secondary">{{ intro }}</p>
      <RouterLink
        v-if="isWarga"
        :to="{ name: 'complaint-create' }"
        class="inline-flex h-14 shrink-0 items-center justify-center gap-2 rounded-2xl bg-desa-dark-green px-5 font-medium text-white transition-all duration-300 hover:bg-[#2a5030]"
      >
        <AppIcon name="add-square" />
        Buat Laporan
      </RouterLink>
    </div>

    <div
      class="hide-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0"
      role="tablist"
      aria-label="Status laporan"
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
        canFilterRt ? 'xl:grid-cols-5' : 'xl:grid-cols-4',
      ]"
    >
      <AppInput
        v-model="filters.search"
        label="Cari"
        icon="receipt-search"
        type="search"
        placeholder="Kode tiket atau judul"
      />
      <AppSelect
        v-if="categoryOptions.length"
        v-model="filters.category_id"
        label="Kategori"
        icon="grid-5"
        :options="categoryOptions"
      />
      <AppSelect
        v-if="canFilterRt && rtOptions.length"
        v-model="filters.rt_id"
        label="Wilayah"
        icon="location"
        :options="rtOptions"
      />
      <AppInput v-model="filters.from" label="Dilaporkan dari" icon="calendar-2" type="date" />
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
      Memuat laporan…
    </div>

    <div
      v-else-if="!complaints.length && !error"
      class="flex flex-col items-center gap-3 rounded-2xl bg-white px-6 py-12 text-center"
    >
      <span class="flex size-16 items-center justify-center rounded-full bg-desa-foreshadow">
        <AppIcon name="note" class="size-8! text-desa-dark-green" />
      </span>
      <p class="text-lg font-semibold">
        {{
          filters.tab === 'inbox'
            ? 'Tidak ada laporan yang menunggu'
            : isWarga && filters.tab === 'active'
              ? 'Belum ada laporan yang berjalan'
              : 'Belum ada laporan'
        }}
      </p>
      <p class="max-w-sm leading-5 font-medium text-desa-secondary">
        {{
          isWarga
            ? 'Lampu jalan mati, sampah menumpuk, atau jalan rusak? Laporkan dari sini.'
            : 'Laporan baru dari warga akan muncul di sini.'
        }}
      </p>
      <RouterLink
        v-if="isWarga"
        :to="{ name: 'complaint-create' }"
        class="mt-2 font-medium text-desa-dark-green underline-offset-4 hover:underline"
        >Buat laporan</RouterLink
      >
    </div>

    <template v-else>
      <ul class="grid gap-[14px] md:grid-cols-2 2xl:grid-cols-3">
        <li v-for="complaint in complaints" :key="complaint.id">
          <RouterLink
            :to="{ name: 'complaint-detail', params: { id: complaint.id } }"
            class="flex h-full flex-col gap-4 rounded-2xl bg-white p-6 ring-desa-dark-green transition-all duration-300 hover:ring-[1.5px]"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="flex min-w-0 flex-col gap-1">
                <p class="text-sm font-semibold tracking-wide text-desa-dark-green">
                  {{ complaint.code }}
                </p>
                <p class="line-clamp-2 leading-5 font-semibold">{{ complaint.title }}</p>
              </div>
              <AppBadge :variant="statusBadge[complaint.status]">{{
                complaint.status_label
              }}</AppBadge>
            </div>

            <div class="flex flex-wrap gap-2">
              <AppBadge>{{ complaint.category?.name }}</AppBadge>
              <AppBadge v-if="complaint.urgency" :variant="urgencyBadge[complaint.urgency]"
                >Urgensi {{ complaint.urgency_label }}</AppBadge
              >
              <AppBadge v-if="complaint.photo_count">{{ complaint.photo_count }} foto</AppBadge>
            </div>

            <dl class="grid grid-cols-2 gap-x-3 gap-y-4 rounded-2xl bg-desa-background/50 p-4">
              <div v-if="!isWarga" class="col-span-2 flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Pelapor</dt>
                <dd class="truncate leading-5 font-semibold">
                  {{ complaint.reporter?.name ?? 'Warga' }}
                  <span class="font-medium text-desa-secondary"
                    >· {{ complaint.rt?.label }}/{{ complaint.rw?.label }}</span
                  >
                </dd>
              </div>
              <div class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Dilaporkan</dt>
                <dd class="leading-5 font-semibold">{{ formatDate(complaint.created_at) }}</dd>
              </div>
              <div class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Lokasi</dt>
                <dd class="truncate leading-5 font-semibold">
                  {{
                    complaint.location.text || (complaint.location.latitude ? 'Titik peta' : '—')
                  }}
                </dd>
              </div>
            </dl>

            <p
              v-if="holderLabel(complaint)"
              class="mt-auto flex items-center gap-2 text-sm font-medium text-desa-secondary"
            >
              <AppIcon name="timer" class="size-[18px]!" />
              {{ holderLabel(complaint) }}
            </p>
            <p
              v-else-if="complaint.status === 'rejected'"
              class="mt-auto line-clamp-2 text-sm font-medium text-[#b0203f]"
            >
              Ditolak {{ complaint.handling_level_label }}: {{ complaint.rejection_reason }}
            </p>
            <p
              v-else-if="complaint.can.rate"
              class="mt-auto flex items-center gap-2 text-sm font-semibold text-[#8a5300]"
            >
              <StarRating :score="0" size="size-4" />
              Beri rating penanganan
            </p>
            <p
              v-else-if="complaint.rating"
              class="mt-auto flex items-center gap-2 text-sm font-medium text-desa-secondary"
            >
              <StarRating :score="complaint.rating.score" size="size-4" />
              Rating {{ complaint.rating.score }}/5
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
