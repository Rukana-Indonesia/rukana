<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import RatingSummary from '@/components/ratings/RatingSummary.vue'
import ServiceRecap from '@/components/recap/ServiceRecap.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
// Angka yang sudah tersedia, per `key` kartu. Kartu tanpa angka tampil "Segera hadir".
const counts = ref({})
// Keterangan di bawah angka untuk kartu yang tidak menautkan ke daftar.
const captions = ref({})
const announcements = ref([])
const announcementsLoaded = ref(false)

// Isi dasbor per peran (PRD 6.8). Angkanya menyusul saat modulnya dibangun.
const modules = {
  warga: [
    {
      label: 'Pengajuan surat berjalan',
      icon: 'document-text',
      key: 'lettersActive',
      to: 'letters',
    },
    {
      label: 'Laporan berjalan',
      icon: 'note',
      key: 'complaintsActive',
      to: 'complaints',
      query: { tab: 'active' },
    },
    {
      label: 'Pengumuman tayang',
      icon: 'sms',
      key: 'announcements',
      to: 'announcements',
    },
  ],
  rt: [
    { label: 'Warga menunggu verifikasi', icon: 'user-search', key: 'pending', to: 'residents' },
    {
      label: 'Pengantar menunggu verifikasi',
      icon: 'document-text',
      key: 'lettersInbox',
      to: 'letters',
      query: { tab: 'inbox' },
    },
    {
      label: 'Laporan belum selesai',
      icon: 'note',
      key: 'complaintsActive',
      to: 'complaints',
      query: { tab: 'active' },
    },
    { label: 'Rata-rata rating layanan', icon: 'tick-circle', key: 'ratingAverage' },
  ],
  rw: [
    {
      label: 'Pengantar menunggu persetujuan',
      icon: 'document-text',
      key: 'lettersInbox',
      to: 'letters',
      query: { tab: 'inbox' },
    },
    {
      label: 'Laporan perlu ditangani RW',
      icon: 'note',
      key: 'complaintsInbox',
      to: 'complaints',
      query: { tab: 'inbox' },
    },
    { label: 'Rata-rata rating layanan', icon: 'tick-circle', key: 'ratingAverage' },
    { label: 'Monthly Insight RW', icon: 'chart-square' },
  ],
  operator: [
    { label: 'Warga menunggu verifikasi', icon: 'user-search', key: 'pending', to: 'residents' },
    {
      label: 'Surat menunggu diproses',
      icon: 'receipt-search',
      key: 'lettersVillage',
      to: 'letters',
      query: { tab: 'inbox' },
    },
    {
      label: 'Surat siap diambil',
      icon: 'tick-circle',
      key: 'lettersReady',
      to: 'letters',
      query: { tab: 'inbox' },
    },
    {
      label: 'Laporan eskalasi ke desa',
      icon: 'note',
      key: 'complaintsInbox',
      to: 'complaints',
      query: { tab: 'inbox' },
    },
  ],
  kepala_desa: [
    { label: 'Surat sedang berjalan', icon: 'document-text', key: 'lettersActive', to: 'letters' },
    {
      label: 'Laporan belum selesai',
      icon: 'note',
      key: 'complaintsActive',
      to: 'complaints',
      query: { tab: 'active' },
    },
    {
      label: 'Peta sebaran laporan',
      icon: 'map',
      to: 'complaint-map',
      cta: 'Buka peta',
      body: 'Titik laporan dan lokasi masalah yang berulang.',
    },
    {
      label: 'Ringkasan bulanan desa',
      icon: 'status-up',
      to: 'insights',
      cta: 'Buka ringkasan',
      body: 'Narasi kondisi desa bulan ini, disusun AI.',
    },
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

async function loadResidents() {
  if (!auth.hasRole('rt', 'operator')) return
  const { data } = await api.get('/residents', { params: { status: 'pending', per_page: 1 } })
  counts.value = { ...counts.value, pending: data.meta.counts.pending }
}

async function loadLetters() {
  const { data } = await api.get('/letters', { params: { per_page: 1 } })
  const c = data.meta.counts
  const all = Object.values(c).reduce((sum, n) => sum + n, 0)
  counts.value = {
    ...counts.value,
    lettersActive: all - c.completed - c.rejected,
    lettersInbox: data.meta.inbox,
    lettersVillage: c.rw_approved + c.village_received,
    lettersReady: c.ready_for_pickup,
  }
}

async function loadComplaints() {
  const { data } = await api.get('/complaints', { params: { per_page: 1 } })
  const c = data.meta.counts
  const all = Object.values(c).reduce((sum, n) => sum + n, 0)
  counts.value = {
    ...counts.value,
    complaintsActive: all - c.resolved - c.rejected,
    complaintsInbox: data.meta.inbox,
  }
}

async function loadAnnouncements() {
  try {
    const { data } = await api.get('/announcements', { params: { per_page: 3 } })
    announcements.value = data.data
    counts.value = { ...counts.value, announcements: data.meta.total }
  } finally {
    announcementsLoaded.value = true
  }
}

/** Angka kartu rating diambil dari panel rekap, supaya tidak dua kali memanggil API. */
function onRatings(summary) {
  counts.value = {
    ...counts.value,
    ratingAverage:
      summary.average === null
        ? '—'
        : summary.average.toLocaleString('id-ID', { minimumFractionDigits: 1 }),
  }
  captions.value = {
    ...captions.value,
    ratingAverage: summary.total
      ? `dari ${summary.total} rating · rincian di bawah`
      : 'Belum ada rating',
  }
}

// Kartu yang gagal dimuat tetap tampil tanpa angka.
onMounted(() =>
  Promise.allSettled([loadResidents(), loadLetters(), loadComplaints(), loadAnnouncements()]),
)
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
        <component
          :is="item.to ? RouterLink : 'article'"
          v-for="item in items"
          :key="item.label"
          :to="item.to ? { name: item.to, query: item.query } : undefined"
          :class="[
            'flex flex-col gap-3 rounded-2xl bg-white p-6',
            item.to && 'ring-desa-dark-green transition-all duration-300 hover:ring-[1.5px]',
          ]"
        >
          <div class="flex items-start justify-between gap-3">
            <p class="font-medium text-desa-secondary">{{ item.label }}</p>
            <span
              class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow"
            >
              <AppIcon :name="item.icon" class="text-desa-dark-green" />
            </span>
          </div>
          <div
            v-if="item.key && counts[item.key] !== undefined"
            class="mt-auto flex flex-col gap-1.5"
          >
            <p class="text-[32px] leading-10 font-semibold">{{ counts[item.key] }}</p>
            <p
              v-if="item.to"
              class="flex items-center gap-1 text-sm font-medium text-desa-dark-green"
            >
              Lihat daftar
              <AppIcon name="arrow-square-right" class="size-[18px]!" />
            </p>
            <p v-else class="text-sm font-medium text-desa-secondary">{{ captions[item.key] }}</p>
          </div>
          <div v-else-if="item.cta" class="mt-auto flex flex-col gap-1.5">
            <p class="leading-6 text-desa-black">{{ item.body }}</p>
            <p class="flex items-center gap-1 text-sm font-medium text-desa-dark-green">
              {{ item.cta }}
              <AppIcon name="arrow-square-right" class="size-[18px]!" />
            </p>
          </div>
          <div v-else class="mt-auto flex flex-col gap-1.5">
            <p class="text-[32px] leading-10 font-semibold text-desa-grey">—</p>
            <p class="flex items-center gap-1 text-sm font-medium text-desa-secondary">
              <AppIcon name="timer" class="size-[18px]!" />
              Segera hadir
            </p>
          </div>
        </component>
      </section>
    </div>

    <!-- Rekap per periode dan wilayah, plus ekspor untuk RW/operator (PRD 6.8, 6.10). -->
    <ServiceRecap v-if="auth.isStaff" />

    <!-- Pengumuman terbaru untuk wilayah akun ini (PRD 6.8). -->
    <section
      class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8"
      aria-labelledby="latest-announcements"
    >
      <div class="flex items-center justify-between gap-3">
        <h2 id="latest-announcements" class="text-lg font-semibold">Pengumuman terbaru</h2>
        <RouterLink
          :to="{ name: 'announcements' }"
          class="flex shrink-0 items-center gap-1 text-sm font-medium text-desa-dark-green hover:underline"
        >
          Lihat semua
          <AppIcon name="arrow-square-right" class="size-[18px]!" />
        </RouterLink>
      </div>
      <p v-if="!announcementsLoaded" class="font-medium text-desa-secondary">Memuat…</p>
      <p v-else-if="!announcements.length" class="text-desa-secondary">
        Belum ada pengumuman yang sedang tayang.
      </p>
      <ul v-else class="grid gap-3 lg:grid-cols-3">
        <li v-for="item in announcements" :key="item.id">
          <RouterLink
            :to="{ name: 'announcement-detail', params: { id: item.id } }"
            class="flex h-full flex-col gap-2 rounded-2xl border border-desa-background p-4 transition-all duration-300 hover:border-desa-dark-green"
          >
            <div class="flex flex-wrap items-center gap-2">
              <AppBadge variant="success">{{ item.target.label }}</AppBadge>
              <span class="text-xs font-medium text-desa-secondary">{{
                formatDate(item.published_at)
              }}</span>
            </div>
            <p class="line-clamp-2 leading-5 font-semibold">{{ item.title }}</p>
            <p class="line-clamp-2 text-sm text-desa-secondary">{{ item.body }}</p>
          </RouterLink>
        </li>
      </ul>
    </section>

    <RatingSummary v-if="auth.isStaff" @loaded="onRatings" />
  </div>
</template>
