<script setup>
import { computed } from 'vue'

import AppIcon from '@/components/ui/AppIcon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

// Isi dasbor per peran (PRD 6.8). Angkanya menyusul saat modulnya dibangun.
const modules = {
  warga: [
    { label: 'Pengajuan surat saya', icon: 'document-text' },
    { label: 'Laporan saya', icon: 'note' },
    { label: 'Pengumuman terbaru', icon: 'notification' },
  ],
  rt: [
    { label: 'Warga menunggu verifikasi', icon: 'user-search' },
    { label: 'Pengantar menunggu verifikasi', icon: 'document-text' },
    { label: 'Laporan belum selesai', icon: 'note' },
    { label: 'Rata-rata rating layanan', icon: 'tick-circle' },
  ],
  rw: [
    { label: 'Pengantar menunggu persetujuan', icon: 'document-text' },
    { label: 'Laporan diteruskan ke RW', icon: 'note' },
    { label: 'Rata-rata rating layanan', icon: 'tick-circle' },
    { label: 'Monthly Insight RW', icon: 'chart-square' },
  ],
  operator: [
    { label: 'Surat menunggu diproses', icon: 'receipt-search' },
    { label: 'Surat siap diambil', icon: 'tick-circle' },
    { label: 'Laporan eskalasi ke desa', icon: 'note' },
    { label: 'Rekap per RW/RT', icon: 'grid-5' },
  ],
  kepala_desa: [
    { label: 'Rekap per RW/RT', icon: 'grid-5' },
    { label: 'Peta sebaran laporan', icon: 'location' },
    { label: 'Monthly Insight desa', icon: 'chart-square' },
  ],
}

const hero = {
  warga: {
    eyebrow: 'Layanan Warga',
    title: 'Urus surat & laporan dari HP',
    body: 'Ajukan surat pengantar dan laporkan masalah lingkungan, lalu pantau statusnya di sini.',
  },
  rt: {
    eyebrow: 'Pengurus RT',
    title: 'Layani warga RT Anda',
    body: 'Verifikasi akun warga, periksa pengantar surat, dan tindak lanjuti laporan.',
  },
  rw: {
    eyebrow: 'Pengurus RW',
    title: 'Pantau seluruh RT di RW Anda',
    body: 'Setujui pengantar surat dan tangani laporan yang diteruskan dari RT.',
  },
  operator: {
    eyebrow: 'Operator Desa',
    title: 'Kelola layanan desa',
    body: 'Terima surat dari RW, tangani laporan eskalasi, dan kelola wilayah serta akun pengurus.',
  },
  kepala_desa: {
    eyebrow: 'Kepala Desa',
    title: 'Gambaran kondisi desa',
    body: 'Lihat rekap layanan seluruh RW/RT dan ringkasan bulanan desa.',
  },
}

const items = computed(() => modules[auth.role] ?? [])
const intro = computed(() => hero[auth.role] ?? hero.warga)
const scopeLabel = computed(() => {
  const u = auth.user
  return { rt: u?.rt?.label, rw: u?.rw?.label, village: `Desa ${u?.village?.name}` }[
    u?.scope?.level
  ]
})
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="text-xl font-semibold sm:text-2xl">Halo, {{ auth.user.name }} 👋🏻</h1>

    <div class="flex flex-col gap-[14px] xl:flex-row">
      <!-- Kartu sambutan bergaya kartu gradien di dasbor template. -->
      <section
        class="gradient-vertical flex flex-col gap-6 rounded-2xl p-6 xl:w-[calc(389/1000*100%)] xl:shrink-0"
      >
        <span
          class="flex size-16 items-center justify-center rounded-full bg-desa-orange sm:size-[72px]"
        >
          <AppIcon name="building-4" class="size-8! text-white sm:size-9!" />
        </span>
        <div class="flex flex-col gap-3">
          <p class="text-sm font-medium text-desa-lime">— {{ intro.eyebrow }}</p>
          <p class="text-2xl font-semibold text-white">{{ intro.title }}</p>
          <p class="leading-relaxed text-white">{{ intro.body }}</p>
        </div>
        <div
          v-if="scopeLabel"
          class="mt-auto flex items-center justify-between gap-3 rounded-2xl bg-white p-4"
        >
          <div class="flex min-w-0 flex-col gap-1">
            <span class="text-sm font-medium text-desa-secondary">Wilayah kerja</span>
            <span class="truncate leading-5 font-semibold text-desa-dark-green">{{
              scopeLabel
            }}</span>
          </div>
          <AppIcon name="location" class="text-desa-dark-green" />
        </div>
      </section>

      <section
        class="grid flex-1 grid-cols-1 gap-[14px] min-[480px]:grid-cols-2"
        aria-label="Ringkasan"
      >
        <article
          v-for="item in items"
          :key="item.label"
          class="flex flex-col gap-3 rounded-2xl bg-white p-6"
        >
          <div class="flex items-start justify-between gap-3">
            <p class="font-medium text-desa-secondary">{{ item.label }}</p>
            <span
              class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow"
            >
              <AppIcon :name="item.icon" class="text-desa-dark-green" />
            </span>
          </div>
          <div class="mt-auto flex flex-col gap-1.5">
            <p class="text-[32px] leading-10 font-semibold text-desa-grey">—</p>
            <p class="flex items-center gap-1 text-sm font-medium text-desa-secondary">
              <AppIcon name="timer" class="size-[18px]!" />
              Segera hadir
            </p>
          </div>
        </article>
      </section>
    </div>
  </div>
</template>
