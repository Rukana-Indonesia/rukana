<script setup>
import { RouterLink } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center px-4">
    <div
      class="flex w-full max-w-[486px] flex-col items-center gap-6 rounded-3xl bg-white p-8 text-center"
    >
      <AppLogo />
      <div class="flex flex-col gap-2">
        <p class="text-[48px] leading-none font-semibold text-desa-dark-green">404</p>
        <h1 class="text-2xl font-semibold">Halaman tidak ditemukan</h1>
        <p class="leading-5 text-desa-secondary">Alamat yang Anda buka tidak tersedia.</p>
      </div>
      <RouterLink
        :to="{ name: 'home' }"
        class="flex h-14 w-full items-center justify-center rounded-2xl bg-desa-dark-green font-medium text-white transition-all duration-300 hover:bg-[#2a5030]"
        >Kembali ke beranda</RouterLink
      >
    </div>
  </div>
</template>
