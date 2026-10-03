<script setup>
import { RouterLink } from 'vue-router'

import AppIcon from '@/components/ui/AppIcon.vue'
import wargaImage from '@/assets/images/warga.png'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const features = [
  {
    icon: 'document-text',
    title: 'Surat Pengantar',
    body: 'Ajukan pengantar RT → RW → desa dari HP dan pantau posisinya lewat kode tiket.',
  },
  {
    icon: 'note',
    title: 'Laporan Warga',
    body: 'Laporkan lampu mati, jalan rusak, atau sampah menumpuk langsung ke pengurus yang tepat.',
  },
  {
    icon: 'notification',
    title: 'Pengumuman Resmi',
    body: 'Informasi dari desa, RW, dan RT yang ditujukan ke wilayah Anda.',
  },
]

const steps = [
  { icon: 'user-square', title: 'Daftar', body: 'Isi nama, nomor HP, dan RT domisili.' },
  { icon: 'tick-circle', title: 'Diverifikasi RT', body: 'Ketua RT memastikan Anda warganya.' },
  { icon: 'ticket', title: 'Ajukan & pantau', body: 'Setiap pengajuan dapat kode tiket.' },
]

// Bisa dibuka tanpa akun.
const shortcuts = [
  {
    name: 'services',
    icon: 'document-text',
    title: 'Katalog Layanan',
    body: 'Lihat syarat dan alur tiap surat sebelum mengajukan.',
  },
  {
    name: 'ticket-check',
    icon: 'ticket',
    title: 'Cek Status Tiket',
    body: 'Pantau posisi surat dengan kode tiket, tanpa masuk.',
  },
]

const primary =
  'inline-flex h-14 items-center justify-between gap-3 rounded-2xl bg-white px-5 font-medium text-desa-dark-green transition-all duration-300 hover:bg-desa-foreshadow'
const outline =
  'inline-flex h-14 items-center justify-center rounded-2xl px-5 font-medium text-white ring-1 ring-white/40 transition-all duration-300 hover:bg-white/10'
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-[14px] px-4 py-6 sm:py-10">
    <!-- Kartu hero bergaya kartu "Dari Desa untuk Warga" di dasbor template. -->
    <section
      class="gradient-vertical flex flex-col gap-8 overflow-hidden rounded-3xl p-6 sm:p-10 lg:flex-row lg:items-end lg:justify-between lg:p-14"
    >
      <div class="flex max-w-2xl flex-col gap-4 sm:gap-5">
        <p class="text-sm font-medium text-desa-lime">— Layanan Warga Tingkat Desa</p>
        <h1 class="text-[28px] leading-tight font-semibold text-white sm:text-4xl lg:text-5xl">
          Warga bicara, Rukana mencatat, RT/RW/Desa bertindak.
        </h1>
        <p class="leading-relaxed text-white/90">
          Urus surat pengantar, laporkan masalah lingkungan, dan baca pengumuman desa cukup dari
          browser HP. Tanpa instal aplikasi.
        </p>
        <img :src="wargaImage" alt="" class="h-12 w-fit object-contain sm:h-14" />
      </div>

      <div class="flex flex-col gap-3 sm:flex-row lg:flex-col">
        <RouterLink v-if="auth.isLoggedIn" :to="{ name: 'dashboard' }" :class="primary">
          Buka aplikasi
          <AppIcon name="arrow-square-right" />
        </RouterLink>
        <template v-else>
          <RouterLink :to="{ name: 'register' }" :class="primary">
            Daftar sebagai warga
            <AppIcon name="add-square" />
          </RouterLink>
          <RouterLink :to="{ name: 'login' }" :class="outline">Sudah punya akun? Masuk</RouterLink>
        </template>
      </div>
    </section>

    <section class="grid gap-[14px] md:grid-cols-3" aria-label="Fitur">
      <article
        v-for="f in features"
        :key="f.title"
        class="flex flex-col gap-4 rounded-2xl bg-white p-6"
      >
        <span class="flex size-12 items-center justify-center rounded-full bg-desa-foreshadow">
          <AppIcon :name="f.icon" class="text-desa-dark-green" />
        </span>
        <div class="flex flex-col gap-2">
          <h2 class="text-xl leading-[25px] font-semibold">{{ f.title }}</h2>
          <p class="leading-relaxed text-desa-secondary">{{ f.body }}</p>
        </div>
      </article>
    </section>

    <section class="grid gap-[14px] sm:grid-cols-2" aria-label="Tanpa login">
      <RouterLink
        v-for="link in shortcuts"
        :key="link.name"
        :to="{ name: link.name }"
        class="flex items-center gap-4 rounded-2xl bg-white p-6 ring-desa-dark-green transition-all duration-300 hover:ring-[1.5px]"
      >
        <span
          class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-desa-foreshadow"
        >
          <AppIcon :name="link.icon" class="size-7! text-desa-dark-green" />
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-1">
          <span class="leading-5 font-semibold">{{ link.title }}</span>
          <span class="text-sm leading-5 text-desa-secondary">{{ link.body }}</span>
        </span>
        <AppIcon name="arrow-square-right" class="text-desa-dark-green" />
      </RouterLink>
    </section>

    <section class="flex flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8">
      <h2 class="text-xl leading-[25px] font-semibold">Cara mulai</h2>
      <ol class="grid gap-4 sm:grid-cols-3">
        <li v-for="(s, i) in steps" :key="s.title" class="flex items-center gap-3">
          <span
            class="flex size-[72px] shrink-0 items-center justify-center rounded-2xl bg-desa-foreshadow"
          >
            <AppIcon :name="s.icon" class="size-9! text-desa-dark-green" />
          </span>
          <div class="flex flex-col gap-1.5">
            <p class="leading-5 font-semibold">{{ i + 1 }}. {{ s.title }}</p>
            <p class="text-sm leading-5 text-desa-secondary">{{ s.body }}</p>
          </div>
        </li>
      </ol>
    </section>
  </div>
</template>
