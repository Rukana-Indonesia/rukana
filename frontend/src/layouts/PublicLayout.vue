<script setup>
import { RouterLink, RouterView } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="flex min-h-dvh flex-col">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4">
        <RouterLink :to="{ name: 'home' }" aria-label="Rukana, ke beranda">
          <AppLogo />
        </RouterLink>
        <nav class="flex items-center gap-1 text-sm font-medium">
          <RouterLink
            v-if="auth.isLoggedIn"
            :to="{ name: 'dashboard' }"
            class="rounded-lg bg-brand-600 px-3 py-2 text-white hover:bg-brand-700"
            >Buka aplikasi</RouterLink
          >
          <template v-else>
            <RouterLink
              :to="{ name: 'login' }"
              class="rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100"
              >Masuk</RouterLink
            >
            <RouterLink
              :to="{ name: 'register' }"
              class="rounded-lg bg-brand-600 px-3 py-2 text-white hover:bg-brand-700"
              >Daftar</RouterLink
            >
          </template>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <RouterView />
    </main>

    <footer class="border-t border-slate-200 bg-white py-6 text-center text-xs text-slate-500">
      Rukana · Platform layanan warga tingkat desa
    </footer>
  </div>
</template>
