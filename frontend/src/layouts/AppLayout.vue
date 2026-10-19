<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
import NotificationBell from '@/components/NotificationBell.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppModal from '@/components/ui/AppModal.vue'
import { initials } from '@/lib/format'
import { bottomTabsFor, navigationFor } from '@/lib/navigation'
import { usePwaInstall } from '@/lib/pwa'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const loggingOut = ref(false)
const { canPrompt, showIosHint, install } = usePwaInstall()

// Di layar < lg tampil seperti aplikasi: bilah atas ringkas, tab di bawah, dan
// lembar "Lainnya" berisi seluruh menu. Sidebar hanya untuk layar lebar.
const moreOpen = ref(false)
const createOpen = ref(false)

const groups = computed(() => (auth.isActive ? navigationFor(auth.role) : []))
const tabs = computed(() => (auth.isActive ? bottomTabsFor(auth.role) : []))

const regionLabel = computed(() => {
  const u = auth.user
  if (!u) return ''
  return [u.rt?.label, u.rw?.label, u.village?.name && `Desa ${u.village.name}`]
    .filter(Boolean)
    .join(' · ')
})

const userInitials = computed(() => initials(auth.user?.name))

/** Menu disorot juga untuk halaman turunannya (mis. detail surat di menu Surat). */
const isCurrent = (item) => route.name === item.name || route.meta.section === item.name

// "Lainnya" menyala bila halaman ini tidak punya tab sendiri.
const moreActive = computed(() => !tabs.value.some((tab) => !tab.action && isCurrent(tab)))

// Halaman turunan (detail, form) memakai tombol kembali di bilah atas HP.
const parent = computed(() => route.meta.section ?? null)

function goBack() {
  if (window.history.state?.back) router.back()
  else router.push({ name: parent.value })
}

watch(
  () => route.fullPath,
  () => {
    moreOpen.value = false
    createOpen.value = false
  },
)

async function logout() {
  loggingOut.value = true
  try {
    await auth.logout()
  } finally {
    loggingOut.value = false
    router.push({ name: 'login' })
  }
}

const createOptions = [
  {
    to: { name: 'letter-create' },
    icon: 'document-text',
    title: 'Ajukan Surat Pengantar',
    description: 'Domisili, SKTM, usaha, dan surat lain dari RT/RW.',
  },
  {
    to: { name: 'complaint-create' },
    icon: 'note',
    title: 'Laporkan Masalah',
    description: 'Jalan rusak, sampah, lampu mati, keamanan, dan lainnya.',
  },
]
</script>

<template>
  <div class="flex min-h-dvh">
    <aside
      id="Sidebar"
      class="fixed inset-y-0 left-0 z-20 hidden w-[280px] flex-col gap-6 border-r border-desa-foreshadow bg-white px-6 pt-[30px] lg:flex"
      aria-label="Navigasi"
    >
      <RouterLink
        :to="{ name: 'dashboard' }"
        class="flex h-11 items-center"
        aria-label="Rukana, ke dasbor"
      >
        <AppLogo />
      </RouterLink>

      <nav
        class="hide-scrollbar flex flex-1 flex-col gap-5 overflow-y-auto"
        aria-label="Menu utama"
      >
        <section v-for="group in groups" :key="group.title" class="flex flex-col gap-1">
          <p class="px-4 pb-1 text-xs font-semibold tracking-wider text-desa-secondary uppercase">
            {{ group.title }}
          </p>
          <ul class="flex flex-col gap-1">
            <li v-for="item in group.items" :key="item.name">
              <RouterLink
                :to="{ name: item.name }"
                :class="[
                  'group flex h-12 items-center gap-2 rounded-2xl px-4 text-desa-secondary transition-all duration-300 hover:bg-desa-foreshadow hover:text-desa-dark-green',
                  isCurrent(item) && 'bg-desa-foreshadow font-medium text-desa-dark-green!',
                ]"
                :aria-current="isCurrent(item) ? 'page' : undefined"
              >
                <AppIcon :name="item.icon" />
                <span class="flex-1 leading-5">{{ item.label }}</span>
              </RouterLink>
            </li>
          </ul>
        </section>
      </nav>

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
        class="sticky top-0 z-30 border-b border-desa-foreshadow bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur lg:bg-white"
      >
        <div class="flex h-16 items-center gap-2 px-4 sm:px-6 lg:h-[96px] lg:gap-4">
          <button
            v-if="parent"
            type="button"
            class="-ml-2 flex size-11 shrink-0 items-center justify-center rounded-2xl text-desa-black transition-all duration-300 active:bg-desa-foreshadow lg:hidden"
            aria-label="Kembali"
            @click="goBack"
          >
            <AppIcon name="arrow-left" />
          </button>
          <RouterLink
            v-else
            :to="{ name: 'dashboard' }"
            class="shrink-0 lg:hidden"
            aria-label="Rukana, ke beranda"
          >
            <img src="@/assets/images/logo-icon.svg" alt="" class="size-8" />
          </RouterLink>

          <p class="min-w-0 flex-1 truncate text-lg font-semibold lg:text-2xl">
            {{ route.meta.title ?? 'Rukana' }}
          </p>

          <NotificationBell v-if="auth.isActive" />

          <div class="flex items-center gap-3 lg:gap-4">
            <button
              type="button"
              class="flex size-10 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow text-sm font-semibold text-desa-dark-green lg:pointer-events-none lg:size-14 lg:text-base"
              :aria-label="`Akun ${auth.user?.name ?? ''}, buka menu`"
              :tabindex="auth.isActive ? 0 : -1"
              @click="auth.isActive && (moreOpen = true)"
            >
              {{ userInitials }}
            </button>
            <div class="hidden w-[150px] min-w-0 flex-col gap-1.5 lg:flex">
              <p class="truncate leading-5 font-semibold">{{ auth.user?.name }}</p>
              <p class="truncate text-sm font-medium text-desa-secondary">
                {{ auth.user?.role_label }}
              </p>
            </div>
            <!-- Di HP tombol keluar ada di lembar "Lainnya"; akun yang belum aktif tidak punya tab. -->
            <button
              type="button"
              :class="[
                'size-11 shrink-0 items-center justify-center rounded-2xl text-desa-red transition-all duration-300 hover:bg-desa-red/10 disabled:opacity-60',
                auth.isActive ? 'hidden lg:flex' : 'flex',
              ]"
              aria-label="Keluar"
              title="Keluar"
              :disabled="loggingOut"
              @click="logout"
            >
              <AppIcon name="logout" />
            </button>
          </div>
        </div>
      </header>

      <main
        id="Content"
        :class="[
          'flex-1 p-4 sm:p-6 lg:pb-8',
          tabs.length ? 'pb-[calc(6rem+env(safe-area-inset-bottom))]' : 'pb-8',
        ]"
      >
        <RouterView />
      </main>
    </div>

    <!-- Tab bawah ala aplikasi (HP/tablet). -->
    <nav
      v-if="tabs.length"
      class="fixed inset-x-0 bottom-0 z-40 border-t border-desa-foreshadow bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden"
      aria-label="Navigasi bawah"
    >
      <ul class="mx-auto grid h-16 max-w-xl grid-cols-5">
        <li v-for="tab in tabs" :key="tab.name" class="flex">
          <button
            v-if="tab.action"
            type="button"
            class="flex flex-1 flex-col items-center justify-center gap-1"
            aria-haspopup="dialog"
            @click="createOpen = true"
          >
            <span
              class="-mt-6 flex size-14 items-center justify-center rounded-full bg-desa-dark-green text-white shadow-lg ring-4 ring-white transition-transform duration-200 active:scale-95"
            >
              <AppIcon :name="tab.icon" class="size-7!" />
            </span>
            <span class="text-[11px] leading-none font-medium text-desa-secondary">{{
              tab.label
            }}</span>
          </button>
          <RouterLink
            v-else
            :to="{ name: tab.name }"
            class="flex flex-1 flex-col items-center justify-center gap-1"
            :aria-current="isCurrent(tab) ? 'page' : undefined"
          >
            <span
              :class="[
                'flex h-8 w-14 items-center justify-center rounded-full transition-all duration-300',
                isCurrent(tab) ? 'bg-desa-foreshadow text-desa-dark-green' : 'text-desa-secondary',
              ]"
            >
              <AppIcon :name="tab.icon" class="size-[22px]!" />
            </span>
            <span
              :class="[
                'text-[11px] leading-none',
                isCurrent(tab)
                  ? 'font-semibold text-desa-dark-green'
                  : 'font-medium text-desa-secondary',
              ]"
              >{{ tab.label }}</span
            >
          </RouterLink>
        </li>
        <li class="flex">
          <button
            type="button"
            class="flex flex-1 flex-col items-center justify-center gap-1"
            aria-haspopup="dialog"
            @click="moreOpen = true"
          >
            <span
              :class="[
                'flex h-8 w-14 items-center justify-center rounded-full transition-all duration-300',
                moreActive ? 'bg-desa-foreshadow text-desa-dark-green' : 'text-desa-secondary',
              ]"
            >
              <AppIcon name="menu" class="size-[22px]!" />
            </span>
            <span
              :class="[
                'text-[11px] leading-none',
                moreActive
                  ? 'font-semibold text-desa-dark-green'
                  : 'font-medium text-desa-secondary',
              ]"
              >Lainnya</span
            >
          </button>
        </li>
      </ul>
    </nav>

    <!-- Lembar "Lainnya": profil, seluruh menu per kelompok, pasang aplikasi, keluar. -->
    <AppModal v-model:open="moreOpen" title="Menu">
      <div class="flex items-center gap-3 rounded-2xl bg-desa-black p-4">
        <span
          class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-soft-green font-semibold text-desa-black"
          aria-hidden="true"
          >{{ userInitials }}</span
        >
        <div class="flex min-w-0 flex-col gap-1">
          <p class="truncate leading-5 font-semibold text-white">{{ auth.user?.name }}</p>
          <p class="truncate text-sm font-medium text-desa-soft-green">
            {{ auth.user?.role_label }}<template v-if="regionLabel"> · {{ regionLabel }}</template>
          </p>
        </div>
      </div>

      <nav class="flex flex-col gap-5" aria-label="Semua menu">
        <section v-for="group in groups" :key="group.title" class="flex flex-col gap-2">
          <p class="text-xs font-semibold tracking-wider text-desa-secondary uppercase">
            {{ group.title }}
          </p>
          <ul class="grid grid-cols-3 gap-2">
            <li v-for="item in group.items" :key="item.name">
              <RouterLink
                :to="{ name: item.name }"
                :class="[
                  'flex h-full min-h-[88px] flex-col items-center justify-center gap-2 rounded-2xl border p-2 text-center transition-all duration-200 active:scale-[0.97]',
                  isCurrent(item)
                    ? 'border-desa-dark-green bg-desa-foreshadow text-desa-dark-green'
                    : 'border-desa-background text-desa-black',
                ]"
                :aria-current="isCurrent(item) ? 'page' : undefined"
              >
                <span
                  class="flex size-10 items-center justify-center rounded-xl bg-desa-foreshadow text-desa-dark-green"
                >
                  <AppIcon :name="item.icon" />
                </span>
                <span class="text-xs leading-4 font-medium">{{ item.label }}</span>
              </RouterLink>
            </li>
          </ul>
        </section>
      </nav>

      <div class="flex flex-col gap-2">
        <button
          v-if="canPrompt"
          type="button"
          class="flex min-h-14 items-center gap-3 rounded-2xl bg-desa-foreshadow px-4 text-left font-medium text-desa-dark-green"
          @click="install"
        >
          <AppIcon name="document-download" />
          <span class="flex-1">Pasang Rukana di HP</span>
        </button>
        <p
          v-else-if="showIosHint"
          class="rounded-2xl bg-desa-foreshadow px-4 py-3 text-sm leading-5 text-desa-dark-green"
        >
          Pasang Rukana di iPhone: buka di Safari, ketuk <strong>Bagikan</strong>, lalu pilih
          <strong>Tambah ke Layar Utama</strong>.
        </p>
        <button
          type="button"
          class="flex min-h-14 items-center gap-3 rounded-2xl px-4 text-left font-medium text-desa-red transition-all duration-300 hover:bg-desa-red/10 disabled:opacity-60"
          :disabled="loggingOut"
          @click="logout"
        >
          <AppIcon name="logout" />
          <span class="flex-1">Keluar</span>
        </button>
      </div>
    </AppModal>

    <!-- Tombol "Buat" warga: ajukan surat atau laporkan masalah. -->
    <AppModal v-model:open="createOpen" title="Mau mengurus apa?">
      <ul class="flex flex-col gap-3">
        <li v-for="option in createOptions" :key="option.title">
          <RouterLink
            :to="option.to"
            class="flex items-center gap-4 rounded-2xl border border-desa-background p-4 transition-all duration-200 hover:border-desa-dark-green active:scale-[0.98]"
          >
            <span
              class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-desa-foreshadow text-desa-dark-green"
            >
              <AppIcon :name="option.icon" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col gap-1">
              <span class="leading-5 font-semibold">{{ option.title }}</span>
              <span class="text-sm leading-5 text-desa-secondary">{{ option.description }}</span>
            </span>
            <AppIcon name="arrow-square-right" class="shrink-0 text-desa-secondary" />
          </RouterLink>
        </li>
      </ul>
    </AppModal>
  </div>
</template>
