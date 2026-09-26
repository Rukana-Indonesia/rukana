<script setup>
import { RouterLink, RouterView } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import wargaImage from '@/assets/images/warga.png'

const highlights = [
  { icon: 'document-text', text: 'Pengantar surat RT → RW → desa tanpa bolak-balik' },
  { icon: 'note', text: 'Laporan warga sampai ke pengurus yang tepat' },
  { icon: 'notification', text: 'Pengumuman resmi untuk wilayah Anda' },
]
</script>

<template>
  <!-- Pola halaman masuk template: formulir di kiri, banner gradien di kanan (desktop saja). -->
  <main class="flex min-h-dvh">
    <div class="flex flex-1 flex-col items-center justify-center px-4 py-8 sm:px-6 lg:py-12">
      <div class="w-full max-w-[486px]">
        <RouterView />
      </div>
    </div>

    <section
      class="sticky top-0 hidden h-dvh w-full max-w-[560px] shrink-0 py-3 pr-3 lg:flex xl:max-w-[634px]"
      aria-hidden="true"
    >
      <div
        class="gradient-vertical relative flex w-full flex-col justify-between overflow-hidden rounded-3xl p-10 xl:p-14"
      >
        <RouterLink :to="{ name: 'home' }" class="w-fit" tabindex="-1">
          <AppLogo light />
        </RouterLink>

        <div class="flex flex-col gap-6">
          <p class="text-sm font-medium text-desa-lime">— Layanan Warga Tingkat Desa</p>
          <h2 class="text-4xl leading-tight font-semibold text-white xl:text-[44px]">
            Warga bicara, Rukana mencatat, desa bertindak.
          </h2>
          <img :src="wargaImage" alt="" class="h-14 w-fit object-contain" />
          <ul class="flex flex-col gap-3">
            <li
              v-for="item in highlights"
              :key="item.text"
              class="flex items-center gap-3 rounded-2xl bg-white/10 p-4 text-white backdrop-blur-sm"
            >
              <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white">
                <AppIcon :name="item.icon" class="text-desa-dark-green" />
              </span>
              <span class="leading-5 font-medium">{{ item.text }}</span>
            </li>
          </ul>
        </div>

        <p class="text-sm text-white/70">Cukup buka browser, tanpa instal aplikasi.</p>
      </div>
    </section>
  </main>
</template>
