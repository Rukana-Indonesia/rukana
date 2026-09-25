<script setup>
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="grid min-h-dvh place-items-center px-4 text-center">
    <div>
      <p class="text-sm font-semibold text-brand-700">404</p>
      <h1 class="mt-1 text-2xl font-bold text-slate-900">Halaman tidak ditemukan</h1>
      <RouterLink
        :to="{ name: 'home' }"
        class="mt-6 inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700"
        >Kembali ke beranda</RouterLink
      >
    </div>
  </div>
</template>
