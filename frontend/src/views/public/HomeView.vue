<script setup>
import { RouterLink } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const features = [
  {
    title: 'Surat pengantar',
    body: 'Ajukan pengantar RT → RW → desa dari HP dan pantau posisinya lewat kode tiket.',
  },
  {
    title: 'Laporan warga',
    body: 'Laporkan lampu mati, jalan rusak, atau sampah menumpuk langsung ke pengurus yang tepat.',
  },
  {
    title: 'Pengumuman resmi',
    body: 'Informasi dari desa, RW, dan RT yang ditujukan ke wilayah Anda.',
  },
]
</script>

<template>
  <section class="bg-gradient-to-b from-brand-50 to-slate-50">
    <div class="mx-auto max-w-5xl px-4 py-14 sm:py-20">
      <p class="text-sm font-semibold text-brand-700">Layanan warga tingkat desa</p>
      <h1 class="mt-2 max-w-2xl text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
        Warga bicara, Rukana mencatat, RT/RW/Desa bertindak.
      </h1>
      <p class="mt-4 max-w-xl text-base text-slate-600">
        Urus surat pengantar, laporkan masalah lingkungan, dan baca pengumuman desa cukup dari
        browser HP. Tanpa instal aplikasi.
      </p>
      <div class="mt-8 flex flex-wrap gap-3">
        <RouterLink
          v-if="auth.isLoggedIn"
          :to="{ name: 'dashboard' }"
          class="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700"
          >Buka aplikasi</RouterLink
        >
        <template v-else>
          <RouterLink
            :to="{ name: 'register' }"
            class="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700"
            >Daftar sebagai warga</RouterLink
          >
          <RouterLink
            :to="{ name: 'login' }"
            class="inline-flex min-h-11 items-center rounded-lg bg-white px-5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
            >Masuk</RouterLink
          >
        </template>
      </div>
    </div>
  </section>

  <section class="mx-auto grid max-w-5xl gap-4 px-4 py-10 sm:grid-cols-3">
    <article
      v-for="f in features"
      :key="f.title"
      class="rounded-xl bg-white p-5 shadow-xs ring-1 ring-slate-200"
    >
      <h2 class="font-semibold text-slate-900">{{ f.title }}</h2>
      <p class="mt-1.5 text-sm text-slate-600">{{ f.body }}</p>
    </article>
  </section>
</template>
