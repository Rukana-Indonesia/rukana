<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import { stateBadge, whatsappShareUrl } from '@/lib/announcements'
import { api, errorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

// Semua peran membaca pengumuman untuk wilayahnya. RT/RW/operator juga punya tab
// "Kelola" berisi pengumuman buatan tingkatnya, termasuk yang terjadwal/kedaluwarsa.
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const canCreate = computed(() => auth.hasRole('rt', 'rw', 'operator'))
const states = [
  { key: '', label: 'Semua' },
  { key: 'active', label: 'Tayang' },
  { key: 'scheduled', label: 'Terjadwal' },
  { key: 'expired', label: 'Kedaluwarsa' },
]

const filters = reactive({
  tab: canCreate.value && route.query.tab === 'kelola' ? 'kelola' : 'feed',
  state: '',
  search: '',
})
const items = ref([])
const counts = ref({})
const page = ref({ current: 1, last: 1 })
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
// Pesan setelah menghapus pengumuman (dibawa lewat history state).
const notice = ref(window.history.state?.notice ?? '')

const intro = computed(() => {
  if (filters.tab === 'kelola') {
    return {
      rt: `Pengumuman untuk warga ${auth.user?.rt?.label}/${auth.user?.rw?.label} yang Anda kelola.`,
      rw: `Pengumuman untuk warga ${auth.user?.rw?.label} yang Anda kelola.`,
      operator: 'Semua pengumuman di desa. Operator bisa mengubah atau menghapus pengumuman RT/RW.',
    }[auth.role]
  }
  return auth.hasRole('operator', 'kepala_desa')
    ? 'Pengumuman yang sedang tayang di seluruh desa.'
    : 'Pengumuman resmi dari desa, RW, dan RT untuk Anda.'
})

async function load(more = false) {
  if (more) loadingMore.value = true
  else loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/announcements', {
      params: {
        manage: filters.tab === 'kelola' ? 1 : undefined,
        state: filters.tab === 'kelola' ? filters.state || undefined : undefined,
        search: filters.search || undefined,
        page: more ? page.value.current + 1 : 1,
      },
    })
    items.value = more ? [...items.value, ...data.data] : data.data
    counts.value = data.meta.counts ?? {}
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
  () => [filters.tab, filters.state],
  () => load(),
)
watch(
  () => filters.tab,
  (tab) =>
    router.replace({ query: { ...route.query, tab: tab === 'kelola' ? 'kelola' : undefined } }),
)
onBeforeUnmount(() => clearTimeout(searchTimer))
onMounted(load)

const stateCount = (key) =>
  key ? (counts.value[key] ?? 0) : Object.values(counts.value).reduce((sum, n) => sum + n, 0)
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="sr-only">Pengumuman</h1>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="leading-relaxed font-medium text-desa-secondary">{{ intro }}</p>
      <RouterLink
        v-if="canCreate"
        :to="{ name: 'announcement-create' }"
        class="inline-flex h-14 shrink-0 items-center justify-center gap-2 rounded-2xl bg-desa-dark-green px-5 font-medium text-white transition-all duration-300 hover:bg-[#2a5030]"
      >
        <AppIcon name="add-square" />
        Buat Pengumuman
      </RouterLink>
    </div>

    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
      <div class="flex flex-col gap-3">
        <div v-if="canCreate" class="flex gap-2" role="tablist" aria-label="Jenis daftar">
          <button
            v-for="tab in [
              { key: 'feed', label: 'Untuk Saya' },
              { key: 'kelola', label: 'Kelola' },
            ]"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="filters.tab === tab.key"
            :class="[
              'flex h-12 items-center rounded-2xl px-4 leading-5 font-medium transition-all duration-300',
              filters.tab === tab.key
                ? 'bg-desa-dark-green text-white'
                : 'bg-white text-desa-secondary hover:text-desa-dark-green',
            ]"
            @click="filters.tab = tab.key"
          >
            {{ tab.label }}
          </button>
        </div>
        <div
          v-if="filters.tab === 'kelola'"
          class="hide-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0"
          aria-label="Status pengumuman"
        >
          <button
            v-for="state in states"
            :key="state.key"
            type="button"
            :aria-pressed="filters.state === state.key"
            :class="[
              'flex h-10 shrink-0 items-center gap-2 rounded-xl border px-3 text-sm font-medium transition-all duration-300',
              filters.state === state.key
                ? 'border-desa-dark-green bg-desa-foreshadow text-desa-dark-green'
                : 'border-desa-background bg-white text-desa-secondary hover:text-desa-dark-green',
            ]"
            @click="filters.state = state.key"
          >
            {{ state.label }}
            <span class="text-xs font-semibold">{{ stateCount(state.key) }}</span>
          </button>
        </div>
      </div>
      <div class="lg:w-80">
        <AppInput
          v-model="filters.search"
          label="Cari pengumuman"
          icon="receipt-search"
          type="search"
          placeholder="Judul atau isi"
        />
      </div>
    </div>

    <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>
    <AppAlert v-if="error">{{ error }}</AppAlert>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat pengumuman…
    </div>

    <div
      v-else-if="!items.length && !error"
      class="flex flex-col items-center gap-3 rounded-2xl bg-white px-6 py-12 text-center"
    >
      <span class="flex size-16 items-center justify-center rounded-full bg-desa-foreshadow">
        <AppIcon name="sms" class="size-8! text-desa-dark-green" />
      </span>
      <p class="text-lg font-semibold">
        {{ filters.search ? 'Tidak ada pengumuman yang cocok' : 'Belum ada pengumuman' }}
      </p>
      <p class="max-w-sm leading-5 font-medium text-desa-secondary">
        {{
          canCreate
            ? 'Buat pengumuman untuk warga, lalu bagikan ke grup WhatsApp dengan satu tombol.'
            : 'Pengumuman dari desa, RW, dan RT Anda akan muncul di sini.'
        }}
      </p>
    </div>

    <template v-else>
      <ul class="grid gap-[14px] md:grid-cols-2 2xl:grid-cols-3">
        <li
          v-for="item in items"
          :key="item.id"
          class="flex flex-col gap-4 rounded-2xl bg-white p-6 ring-desa-dark-green transition-all duration-300 focus-within:ring-[1.5px] hover:ring-[1.5px]"
        >
          <div class="flex flex-wrap items-center gap-2">
            <AppBadge variant="success">
              <AppIcon name="location" class="mr-1 size-3.5!" />
              {{ item.target.label }}
            </AppBadge>
            <AppBadge v-if="filters.tab === 'kelola'" :variant="stateBadge[item.state]">{{
              item.state_label
            }}</AppBadge>
            <AppBadge v-if="item.attachment_count">{{ item.attachment_count }} lampiran</AppBadge>
          </div>

          <RouterLink
            :to="{ name: 'announcement-detail', params: { id: item.id } }"
            class="flex flex-1 flex-col gap-2 focus:outline-none"
          >
            <p class="line-clamp-2 text-lg leading-6 font-semibold">{{ item.title }}</p>
            <p class="line-clamp-3 leading-relaxed whitespace-pre-line text-desa-secondary">
              {{ item.body }}
            </p>
          </RouterLink>

          <div
            class="flex flex-wrap items-center justify-between gap-3 border-t border-desa-background pt-4"
          >
            <p class="text-sm text-desa-secondary">
              <template v-if="item.state === 'scheduled'"
                >Terbit {{ formatDate(item.published_at, true) }}</template
              >
              <template v-else>{{ formatDate(item.published_at) }}</template>
              <template v-if="item.author"> · {{ item.author.role_label }}</template>
            </p>
            <a
              v-if="item.state === 'active'"
              :href="whatsappShareUrl(item)"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1.5 text-sm font-medium text-desa-dark-green hover:underline"
            >
              <AppIcon name="call" class="size-4!" />
              Bagikan ke WhatsApp
            </a>
          </div>
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
