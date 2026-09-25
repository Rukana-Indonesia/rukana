<script setup>
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
import { navigationFor } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const loggingOut = ref(false)

const menu = computed(() => (auth.isActive ? navigationFor(auth.role) : []))

const regionLabel = computed(() => {
  const u = auth.user
  if (!u) return ''
  return [u.rt?.label, u.rw?.label, u.village?.name].filter(Boolean).join(' · ')
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
  <div class="min-h-dvh">
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
      <div class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-4">
        <RouterLink :to="{ name: 'dashboard' }" aria-label="Rukana, ke beranda">
          <AppLogo />
        </RouterLink>

        <div class="flex min-w-0 items-center gap-3">
          <div class="hidden min-w-0 text-right sm:block">
            <p class="truncate text-sm font-semibold text-slate-800">{{ auth.user?.name }}</p>
            <p class="truncate text-xs text-slate-500">
              {{ auth.user?.role_label
              }}<template v-if="regionLabel"> · {{ regionLabel }}</template>
            </p>
          </div>
          <button
            type="button"
            class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-60"
            :disabled="loggingOut"
            @click="logout"
          >
            Keluar
          </button>
        </div>
      </div>

      <nav
        v-if="menu.length > 1"
        class="mx-auto max-w-6xl overflow-x-auto px-4"
        aria-label="Menu utama"
      >
        <ul class="flex gap-1 pb-2 text-sm font-medium">
          <li v-for="item in menu" :key="item.name">
            <RouterLink
              :to="{ name: item.name }"
              class="block whitespace-nowrap rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100"
              exact-active-class="bg-brand-50 text-brand-800"
              >{{ item.label }}</RouterLink
            >
          </li>
        </ul>
      </nav>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
