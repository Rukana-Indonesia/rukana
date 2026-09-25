<script setup>
import { computed } from 'vue'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

// Isi dasbor per peran (PRD 6.8). Modulnya menyusul di fase berikutnya.
const modules = {
  warga: ['Pengajuan surat saya', 'Laporan saya', 'Pengumuman terbaru'],
  rt: [
    'Akun warga menunggu verifikasi',
    'Pengantar surat menunggu verifikasi',
    'Laporan masuk & belum selesai',
    'Rata-rata rating layanan',
  ],
  rw: [
    'Pengantar surat menunggu persetujuan',
    'Laporan diteruskan & belum selesai',
    'Rata-rata rating layanan',
    'Monthly Insight RW',
  ],
  operator: [
    'Surat menunggu diproses & siap diambil',
    'Laporan eskalasi ke desa',
    'Rekap per RW/RT',
    'Kelola wilayah, akun pengurus & katalog layanan',
  ],
  kepala_desa: ['Rekap per RW/RT', 'Peta sebaran laporan', 'Monthly Insight desa'],
}

const items = computed(() => modules[auth.role] ?? [])
const scopeLabel = computed(() => {
  const u = auth.user
  return { rt: u?.rt?.label, rw: u?.rw?.label, village: `Desa ${u?.village?.name}` }[
    u?.scope?.level
  ]
})
</script>

<template>
  <div>
    <p class="text-sm text-slate-500">{{ auth.user.role_label }}</p>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Halo, {{ auth.user.name }}</h1>
    <p v-if="scopeLabel" class="mt-1 text-sm text-slate-600">
      Wilayah kerja Anda: <span class="font-medium text-slate-800">{{ scopeLabel }}</span>
    </p>

    <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <li
        v-for="item in items"
        :key="item"
        class="rounded-xl bg-white p-5 shadow-xs ring-1 ring-slate-200"
      >
        <p class="font-medium text-slate-800">{{ item }}</p>
        <p class="mt-1 text-xs text-slate-400">Segera hadir</p>
      </li>
    </ul>
  </div>
</template>
