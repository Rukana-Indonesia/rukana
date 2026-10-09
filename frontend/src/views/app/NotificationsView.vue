<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api, errorMessage } from '@/lib/api'
import { formatDate, timeAgo } from '@/lib/format'
import { useNotificationStore } from '@/stores/notifications'

const store = useNotificationStore()
const router = useRouter()

const filter = ref('all')
const items = ref([])
const page = ref({ current: 1, last: 1 })
const loading = ref(true)
const loadingMore = ref(false)
const markingAll = ref(false)
const error = ref('')

async function load(more = false) {
  if (more) loadingMore.value = true
  else loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/notifications', {
      params: {
        unread: filter.value === 'unread' ? 1 : undefined,
        page: more ? page.value.current + 1 : 1,
      },
    })
    items.value = more ? [...items.value, ...data.data] : data.data
    page.value = { current: data.meta.current_page, last: data.meta.last_page }
    store.unread = data.meta.unread
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function open(item) {
  if (!item.read_at) {
    // Objek di daftar ini bukan objek di store lonceng; tandai keduanya lewat store.
    store.markRead(item)
  }
  if (item.url) router.push(item.url)
}

async function markAll() {
  markingAll.value = true
  try {
    await store.markAllRead()
    const now = new Date().toISOString()
    items.value.forEach((n) => (n.read_at ??= now))
    if (filter.value === 'unread') items.value = []
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    markingAll.value = false
  }
}

watch(filter, () => load())
onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="sr-only">Notifikasi</h1>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex gap-2" role="tablist" aria-label="Saring notifikasi">
        <button
          v-for="tab in [
            { key: 'all', label: 'Semua' },
            { key: 'unread', label: 'Belum dibaca' },
          ]"
          :key="tab.key"
          type="button"
          role="tab"
          :aria-selected="filter === tab.key"
          :class="[
            'flex h-12 items-center gap-2 rounded-2xl px-4 leading-5 font-medium transition-all duration-300',
            filter === tab.key
              ? 'bg-desa-dark-green text-white'
              : 'bg-white text-desa-secondary hover:text-desa-dark-green',
          ]"
          @click="filter = tab.key"
        >
          {{ tab.label }}
          <span
            v-if="tab.key === 'unread' && store.unread"
            :class="[
              'min-w-6 rounded-full px-2 py-0.5 text-xs font-semibold',
              filter === tab.key ? 'bg-white/20 text-white' : 'bg-desa-red text-white',
            ]"
            >{{ store.unread }}</span
          >
        </button>
      </div>
      <AppButton v-if="store.unread" variant="secondary" :loading="markingAll" @click="markAll">
        <AppIcon name="tick-circle" />
        Tandai semua dibaca
      </AppButton>
    </div>

    <AppAlert v-if="error">{{ error }}</AppAlert>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat notifikasi…
    </div>
    <div
      v-else-if="!items.length && !error"
      class="flex flex-col items-center gap-3 rounded-2xl bg-white px-6 py-12 text-center"
    >
      <span class="flex size-16 items-center justify-center rounded-full bg-desa-foreshadow">
        <AppIcon name="notification" class="size-8! text-desa-dark-green" />
      </span>
      <p class="text-lg font-semibold">
        {{ filter === 'unread' ? 'Semua notifikasi sudah dibaca' : 'Belum ada notifikasi' }}
      </p>
      <p class="max-w-sm leading-5 font-medium text-desa-secondary">
        Kabar tentang surat, laporan, dan pengumuman untuk Anda akan muncul di sini.
      </p>
    </div>

    <template v-else>
      <ul class="flex flex-col overflow-hidden rounded-2xl bg-white">
        <li
          v-for="item in items"
          :key="item.id"
          class="border-b border-desa-background last:border-b-0"
        >
          <button
            type="button"
            :class="[
              'flex w-full gap-4 p-5 text-left transition-all duration-300 hover:bg-desa-foreshadow/60 sm:p-6',
              !item.read_at && 'bg-desa-foreshadow/40',
            ]"
            @click="open(item)"
          >
            <span
              class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow"
            >
              <AppIcon :name="item.icon" class="text-desa-dark-green" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col gap-1">
              <span class="flex items-start justify-between gap-3">
                <span class="leading-5 font-semibold">{{ item.title }}</span>
                <span
                  class="shrink-0 text-xs font-medium text-desa-secondary"
                  :title="formatDate(item.created_at, true)"
                  >{{ timeAgo(item.created_at) }}</span
                >
              </span>
              <span class="leading-relaxed text-desa-secondary">{{ item.body }}</span>
            </span>
            <span
              v-if="!item.read_at"
              class="mt-2 size-2.5 shrink-0 rounded-full bg-desa-red"
              aria-label="Belum dibaca"
            />
          </button>
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
