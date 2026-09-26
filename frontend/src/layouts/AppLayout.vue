<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { navigationFor } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const loggingOut = ref(false)
// Di layar < lg sidebar menjadi drawer yang dibuka lewat tombol menu.
const drawerOpen = ref(false)
const desktopQuery = window.matchMedia('(min-width: 64rem)')
const isDesktop = ref(desktopQuery.matches)
const syncDesktop = (event) => (isDesktop.value = event.matches)

const menu = computed(() => (auth.isActive ? navigationFor(auth.role) : []))

const regionLabel = computed(() => {
  const u = auth.user
  if (!u) return ''
  return [u.rt?.label, u.rw?.label, u.village?.name && `Desa ${u.village.name}`]
    .filter(Boolean)
    .join(' · ')
})

const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join(''),
)

watch(
  () => route.fullPath,
  () => (drawerOpen.value = false),
)

function onKeydown(event) {
  if (event.key === 'Escape') drawerOpen.value = false
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  desktopQuery.addEventListener('change', syncDesktop)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  desktopQuery.removeEventListener('change', syncDesktop)
})

async function logout() {
  loggingOut.value = true
  try {
    await auth.logout()
  } finally {
    loggingOut.value = false
    router.push({ name: 'login' })
  }
}
</script>

<template>
  <div class="flex min-h-dvh">
    <!-- Latar gelap saat drawer terbuka (HP/tablet). -->
    <Transition
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
      enter-active-class="transition-opacity duration-300"
      leave-active-class="transition-opacity duration-300"
    >
      <div
        v-if="drawerOpen"
        class="fixed inset-0 z-40 bg-desa-black/40 lg:hidden"
        aria-hidden="true"
        @click="drawerOpen = false"
      />
    </Transition>

    <aside
      id="Sidebar"
      :class="[
        'fixed inset-y-0 left-0 z-50 flex w-[280px] flex-col gap-6 border-r border-desa-foreshadow bg-white px-6 pt-6 transition-transform duration-300 lg:z-20 lg:translate-x-0 lg:pt-[30px]',
        drawerOpen ? 'translate-x-0' : '-translate-x-full',
      ]"
      aria-label="Navigasi"
      :inert="!drawerOpen && !isDesktop"
    >
      <div class="flex h-11 items-center justify-between">
        <RouterLink :to="{ name: 'dashboard' }" aria-label="Rukana, ke dasbor">
          <AppLogo />
        </RouterLink>
        <button
          type="button"
          class="flex size-11 items-center justify-center rounded-2xl border border-desa-background text-desa-secondary transition-all duration-300 hover:border-desa-secondary lg:hidden"
          aria-label="Tutup menu"
          @click="drawerOpen = false"
        >
          <AppIcon name="close-square" />
        </button>
      </div>

      <div class="hide-scrollbar flex flex-1 flex-col gap-6 overflow-y-auto">
        <nav class="flex flex-col gap-2" aria-label="Menu utama">
          <p class="text-sm font-medium text-desa-secondary">Menu Utama</p>
          <ul class="flex flex-col gap-1">
            <li v-for="item in menu" :key="item.name">
              <RouterLink
                :to="{ name: item.name }"
                class="group flex h-14 items-center gap-2 rounded-2xl p-4 text-desa-secondary transition-all duration-300 hover:bg-desa-foreshadow hover:text-desa-dark-green"
                exact-active-class="bg-desa-foreshadow font-medium text-desa-dark-green!"
              >
                <AppIcon :name="item.icon" />
                <span class="flex-1 leading-5">{{ item.label }}</span>
              </RouterLink>
            </li>
            <li>
              <RouterLink
                :to="{ name: 'change-password' }"
                class="group flex h-14 items-center gap-2 rounded-2xl p-4 text-desa-secondary transition-all duration-300 hover:bg-desa-foreshadow hover:text-desa-dark-green"
                exact-active-class="bg-desa-foreshadow font-medium text-desa-dark-green!"
              >
                <AppIcon name="key" />
                <span class="flex-1 leading-5">Kata Sandi</span>
              </RouterLink>
            </li>
          </ul>
        </nav>
      </div>

      <!-- Kartu wilayah kerja, menggantikan kartu "Beralih ke Pro" di template. -->
      <div class="mb-6 flex items-center justify-between gap-3 rounded-2xl bg-desa-black p-5">
        <div class="flex min-w-0 flex-col gap-1">
          <p class="text-sm font-medium text-desa-soft-green">{{ auth.user?.role_label }}</p>
          <p class="truncate leading-5 font-semibold text-white">
            {{ regionLabel || 'Rukana' }}
          </p>
        </div>
        <span
          class="flex size-11 shrink-0 items-center justify-center rounded-full bg-desa-soft-green"
        >
          <AppIcon name="location" class="text-desa-black" />
        </span>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col lg:pl-[280px]">
      <header
        id="Top-Bar"
        class="sticky top-0 z-30 flex h-[72px] shrink-0 items-center gap-3 border-b border-desa-foreshadow bg-white px-4 sm:px-6 lg:h-[96px] lg:gap-4"
      >
        <button
          type="button"
          class="flex size-11 shrink-0 items-center justify-center rounded-2xl border border-desa-background text-desa-secondary transition-all duration-300 hover:border-desa-secondary lg:hidden"
          aria-label="Buka menu"
          :aria-expanded="drawerOpen"
          aria-controls="Sidebar"
          @click="drawerOpen = true"
        >
          <AppIcon name="menu" />
        </button>

        <p class="min-w-0 flex-1 truncate text-lg font-semibold lg:text-2xl">
          {{ route.meta.title ?? 'Rukana' }}
        </p>

        <button
          type="button"
          class="hidden size-11 shrink-0 items-center justify-center rounded-2xl border border-desa-background text-desa-secondary transition-all duration-300 hover:border-desa-secondary sm:flex lg:size-14"
          aria-label="Notifikasi (segera hadir)"
          title="Notifikasi segera hadir"
        >
          <AppIcon name="notification" />
        </button>

        <div class="flex items-center gap-3 lg:gap-4">
          <span
            class="flex size-11 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow font-semibold text-desa-dark-green lg:size-14"
            aria-hidden="true"
            >{{ initials }}</span
          >
          <div class="hidden w-[150px] min-w-0 flex-col gap-1.5 md:flex">
            <p class="truncate leading-5 font-semibold">{{ auth.user?.name }}</p>
            <p class="truncate text-sm font-medium text-desa-secondary">
              {{ auth.user?.role_label }}
            </p>
          </div>
          <button
            type="button"
            class="flex size-11 shrink-0 items-center justify-center rounded-2xl text-desa-red transition-all duration-300 hover:bg-desa-red/10 disabled:opacity-60"
            aria-label="Keluar"
            title="Keluar"
            :disabled="loggingOut"
            @click="logout"
          >
            <AppIcon name="logout" />
          </button>
        </div>
      </header>

      <main id="Content" class="flex-1 p-4 pb-8 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
