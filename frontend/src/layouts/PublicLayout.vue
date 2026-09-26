<script setup>
import { RouterLink, RouterView } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const linkPrimary =
  'inline-flex h-11 items-center rounded-2xl bg-desa-dark-green px-4 text-sm font-medium text-white transition-all duration-300 hover:bg-[#2a5030] sm:h-12 sm:px-5'
const linkGhost =
  'inline-flex h-11 items-center rounded-2xl border border-desa-background px-4 text-sm font-medium text-desa-dark-green transition-all duration-300 hover:border-desa-dark-green sm:h-12 sm:px-5'
</script>

<template>
  <div class="flex min-h-dvh flex-col">
    <header class="sticky top-0 z-30 border-b border-desa-foreshadow bg-white/95 backdrop-blur">
      <div class="mx-auto flex h-[72px] max-w-6xl items-center justify-between gap-3 px-4 sm:h-20">
        <RouterLink :to="{ name: 'home' }" aria-label="Rukana, ke beranda">
          <AppLogo />
        </RouterLink>
        <nav class="flex items-center gap-2">
          <RouterLink v-if="auth.isLoggedIn" :to="{ name: 'dashboard' }" :class="linkPrimary"
            >Buka aplikasi</RouterLink
          >
          <template v-else>
            <RouterLink :to="{ name: 'login' }" :class="linkGhost">Masuk</RouterLink>
            <RouterLink :to="{ name: 'register' }" :class="linkPrimary">Daftar</RouterLink>
          </template>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <RouterView />
    </main>

    <footer class="border-t border-desa-foreshadow bg-white py-6">
      <div
        class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 text-sm text-desa-secondary sm:flex-row"
      >
        <AppLogo />
        <p>Platform layanan warga tingkat desa</p>
      </div>
    </footer>
  </div>
</template>
