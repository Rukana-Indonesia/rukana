<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppIcon from '@/components/ui/AppIcon.vue'
import { errorMessage } from '@/lib/api'
import { timeAgo } from '@/lib/format'
import { useNotificationStore } from '@/stores/notifications'

// Lonceng di top bar. Di HP panelnya selebar layar tepat di bawah top bar.
const store = useNotificationStore()
const route = useRoute()
const router = useRouter()

const open = ref(false)
const root = ref(null)
const error = ref('')

async function toggle() {
  open.value = !open.value
  if (!open.value) return
  error.value = ''
  try {
    await store.loadLatest()
  } catch (err) {
    error.value = errorMessage(err, 'Notifikasi tidak bisa dimuat.')
  }
}

async function openNotification(notification) {
  store.markRead(notification)
  open.value = false
  if (notification.url) router.push(notification.url)
}

async function markAll() {
  try {
    await store.markAllRead()
  } catch (err) {
    error.value = errorMessage(err)
  }
}

function onDocumentClick(event) {
  if (open.value && !root.value?.contains(event.target)) open.value = false
}
function onKeydown(event) {
  if (event.key === 'Escape') open.value = false
}

watch(
  () => route.fullPath,
  () => (open.value = false),
)
onMounted(() => {
  store.start()
  document.addEventListener('click', onDocumentClick)
  window.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  store.stop()
  document.removeEventListener('click', onDocumentClick)
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="root" class="sm:relative">
    <button
      type="button"
      :class="[
        'relative flex size-11 shrink-0 items-center justify-center rounded-2xl border text-desa-secondary transition-all duration-300 hover:border-desa-secondary lg:size-14',
        open ? 'border-desa-dark-green text-desa-dark-green' : 'border-desa-background',
      ]"
      :aria-label="store.unread ? `Notifikasi, ${store.unread} belum dibaca` : 'Notifikasi'"
      :aria-expanded="open"
      aria-haspopup="true"
      @click="toggle"
    >
      <AppIcon name="notification" />
      <span
        v-if="store.unread"
        class="absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-desa-red px-1.5 text-[11px] leading-none font-semibold text-white ring-2 ring-white"
        aria-hidden="true"
        >{{ store.unread > 99 ? '99+' : store.unread }}</span
      >
    </button>

    <Transition
      enter-from-class="opacity-0 -translate-y-1"
      leave-to-class="opacity-0 -translate-y-1"
      enter-active-class="transition duration-200"
      leave-active-class="transition duration-150"
    >
      <div
        v-if="open"
        class="fixed inset-x-4 top-[80px] z-40 flex max-h-[calc(100dvh-96px)] flex-col overflow-hidden rounded-2xl border border-desa-background bg-white shadow-[0_12px_40px_rgba(8,15,9,0.14)] sm:absolute sm:inset-x-auto sm:top-full sm:right-0 sm:mt-3 sm:max-h-[70dvh] sm:w-[400px]"
        role="dialog"
        aria-label="Notifikasi"
      >
        <div
          class="flex items-center justify-between gap-3 border-b border-desa-background px-5 py-4"
        >
          <p class="font-semibold">Notifikasi</p>
          <button
            v-if="store.unread"
            type="button"
            class="text-sm font-medium text-desa-dark-green hover:underline"
            @click="markAll"
          >
            Tandai semua dibaca
          </button>
        </div>

        <p v-if="error" class="px-5 py-4 text-sm font-medium text-desa-red">{{ error }}</p>
        <p
          v-else-if="store.loadingLatest && !store.latest.length"
          class="px-5 py-6 text-sm font-medium text-desa-secondary"
        >
          Memuat…
        </p>
        <div
          v-else-if="!store.latest.length"
          class="flex flex-col items-center gap-2 px-5 py-10 text-center"
        >
          <span class="flex size-12 items-center justify-center rounded-full bg-desa-foreshadow">
            <AppIcon name="notification" class="text-desa-dark-green" />
          </span>
          <p class="font-semibold">Belum ada notifikasi</p>
          <p class="text-sm text-desa-secondary">
            Kabar tentang surat, laporan, dan pengumuman akan muncul di sini.
          </p>
        </div>
        <ul v-else class="hide-scrollbar flex-1 overflow-y-auto">
          <li v-for="item in store.latest" :key="item.id">
            <button
              type="button"
              :class="[
                'flex w-full gap-3 px-5 py-4 text-left transition-all duration-300 hover:bg-desa-foreshadow/60',
                !item.read_at && 'bg-desa-foreshadow/40',
              ]"
              @click="openNotification(item)"
            >
              <span
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow"
              >
                <AppIcon :name="item.icon" class="size-5! text-desa-dark-green" />
              </span>
              <span class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="flex items-start justify-between gap-2">
                  <span class="leading-5 font-semibold">{{ item.title }}</span>
                  <span
                    v-if="!item.read_at"
                    class="mt-1.5 size-2 shrink-0 rounded-full bg-desa-red"
                    aria-label="Belum dibaca"
                  />
                </span>
                <span class="line-clamp-2 text-sm leading-5 text-desa-secondary">{{
                  item.body
                }}</span>
                <span class="text-xs font-medium text-desa-secondary">{{
                  timeAgo(item.created_at)
                }}</span>
              </span>
            </button>
          </li>
        </ul>

        <RouterLink
          :to="{ name: 'notifications' }"
          class="border-t border-desa-background px-5 py-4 text-center text-sm font-medium text-desa-dark-green hover:bg-desa-foreshadow/60"
        >
          Lihat semua notifikasi
        </RouterLink>
      </div>
    </Transition>
  </div>
</template>
