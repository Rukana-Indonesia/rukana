<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import ActivityList from '@/components/dashboard/ActivityList.vue'
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
const letterItems = ref([])
const complaintItems = ref([])
const activityLoaded = ref(false)

// Isi dasbor per peran (PRD 6.8). `attention` = antrean kerja: kartunya diberi
// penanda bila angkanya lebih dari nol.
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
    {
      label: 'Warga menunggu verifikasi',
      icon: 'user-search',
      key: 'pending',
      to: 'residents',
      attention: true,
    },
    {
      label: 'Pengantar menunggu verifikasi',
      icon: 'document-text',
      key: 'lettersInbox',
      to: 'letters',
      query: { tab: 'inbox' },
      attention: true,
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
      attention: true,
    },
    {
      label: 'Laporan perlu ditangani RW',
      icon: 'note',
      key: 'complaintsInbox',
      to: 'complaints',
      query: { tab: 'inbox' },
      attention: true,
    },
    { label: 'Rata-rata rating layanan', icon: 'tick-circle', key: 'ratingAverage' },
    {
      label: 'Ringkasan bulanan RW',
      icon: 'status-up',
      to: 'insights',
      cta: 'Buka ringkasan',
      body: 'Narasi kondisi RW bulan ini, disusun AI.',
    },
  ],
  operator: [
    {
      label: 'Warga menunggu verifikasi',
      icon: 'user-search',
      key: 'pending',
      to: 'residents',
      attention: true,
    },
    {
      label: 'Surat menunggu diproses',
      icon: 'receipt-search',
      key: 'lettersVillage',
      to: 'letters',
      query: { tab: 'inbox' },
      attention: true,
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
      attention: true,
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

// Menu pintas ala aplikasi di HP (di layar lebar sudah ada sidebar). `badge` = kunci
// angka yang ditampilkan sebagai lencana merah.
const tones = {
  green: 'bg-desa-foreshadow text-desa-dark-green',
  orange: 'bg-desa-orange/10 text-desa-orange',
  blue: 'bg-desa-blue/10 text-desa-blue',
  yellow: 'bg-desa-yellow/15 text-[#b86e00]',
}
const announce = { label: 'Pengumuman', icon: 'sms', to: 'announcements', tone: 'yellow' }
const ask = { label: 'Ask Rukana', icon: 'message-question', to: 'ask', tone: 'blue' }
const map = { label: 'Peta', icon: 'map', to: 'complaint-map', tone: 'green' }
const insight = { label: 'Ringkasan', icon: 'status-up', to: 'insights', tone: 'orange' }
const shortcutsByRole = {
  warga: [
    { label: 'Ajukan Surat', icon: 'document-text', to: 'letter-create', tone: 'green' },
    { label: 'Lapor Masalah', icon: 'note', to: 'complaint-create', tone: 'orange' },
    announce,
    { label: 'Notifikasi', icon: 'notification', to: 'notifications', tone: 'blue' },
  ],
  rt: [
    {
      label: 'Verifikasi Warga',
      icon: 'user-search',
      to: 'residents',
      tone: 'green',
      badge: 'pending',
    },
    {
      label: 'Surat Masuk',
      icon: 'document-text',
      to: 'letters',
      query: { tab: 'inbox' },
      tone: 'blue',
      badge: 'lettersInbox',
    },
    {
      label: 'Laporan Masuk',
      icon: 'note',
      to: 'complaints',
      query: { tab: 'inbox' },
      tone: 'orange',
      badge: 'complaintsInbox',
    },
    { label: 'Buat Pengumuman', icon: 'add-square', to: 'announcement-create', tone: 'yellow' },
    map,
    ask,
    announce,
    { label: 'Notifikasi', icon: 'notification', to: 'notifications', tone: 'green' },
  ],
  rw: [
    {
      label: 'Surat Masuk',
      icon: 'document-text',
      to: 'letters',
      query: { tab: 'inbox' },
      tone: 'green',
      badge: 'lettersInbox',
    },
    {
      label: 'Laporan Masuk',
      icon: 'note',
      to: 'complaints',
      query: { tab: 'inbox' },
      tone: 'orange',
      badge: 'complaintsInbox',
    },
    { label: 'Buat Pengumuman', icon: 'add-square', to: 'announcement-create', tone: 'yellow' },
    map,
    insight,
    ask,
    announce,
    { label: 'Notifikasi', icon: 'notification', to: 'notifications', tone: 'green' },
  ],
  operator: [
    {
      label: 'Verifikasi Warga',
      icon: 'user-search',
      to: 'residents',
      tone: 'green',
      badge: 'pending',
    },
    {
      label: 'Surat Masuk',
      icon: 'receipt-search',
      to: 'letters',
      query: { tab: 'inbox' },
      tone: 'blue',
      badge: 'lettersInbox',
    },
    {
      label: 'Laporan Eskalasi',
      icon: 'note',
      to: 'complaints',
      query: { tab: 'inbox' },
      tone: 'orange',
      badge: 'complaintsInbox',
    },
    { label: 'Buat Pengumuman', icon: 'add-square', to: 'announcement-create', tone: 'yellow' },
    map,
    insight,
    ask,
    { label: 'Pengaturan Desa', icon: 'setting-2', to: 'village-settings', tone: 'green' },
  ],
  kepala_desa: [
    map,
    insight,
    ask,
    announce,
    { label: 'Surat', icon: 'document-text', to: 'letters', tone: 'green' },
    { label: 'Laporan', icon: 'note', to: 'complaints', tone: 'orange' },
    { label: 'Akun Pengurus', icon: 'profile-2user', to: 'officials', tone: 'blue' },
    { label: 'Wilayah', icon: 'location', to: 'regions', tone: 'yellow' },
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
const shortcuts = computed(() => shortcutsByRole[auth.role] ?? [])
const intro = computed(() => hero[auth.role] ?? hero.warga)
const scopeLabel = computed(() => {
  const u = auth.user
  return { rt: u?.rt?.label, rw: u?.rw?.label, village: `Desa ${u?.village?.name}` }[
    u?.scope?.level
  ]
})

const greeting = (() => {
  const hour = new Date().getHours()
  if (hour < 11) return 'Selamat pagi'
  if (hour < 15) return 'Selamat siang'
  if (hour < 18) return 'Selamat sore'
  return 'Selamat malam'
})()
const today = new Intl.DateTimeFormat('id-ID', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
}).format(new Date())

// Pengurus yang memproses surat/laporan melihat antreannya (yang paling lama menunggu
// di atas); warga dan kepala desa melihat yang terbaru.
const queueMode = computed(() => auth.hasRole('rt', 'rw', 'operator'))
const activity = computed(() => {
  const all = [
    ...letterItems.value.map((data) => ({ type: 'letter', data, at: data.submitted_at })),
    ...complaintItems.value.map((data) => ({ type: 'complaint', data, at: data.created_at })),
  ]
  const direction = queueMode.value ? 1 : -1
  return all.sort((a, b) => direction * (new Date(a.at) - new Date(b.at))).slice(0, 5)
})
const activityTitle = computed(() =>
  queueMode.value
    ? 'Perlu ditindaklanjuti'
    : auth.role === 'warga'
      ? 'Pengajuan terakhir'
      : 'Aktivitas terbaru',
)

const needsAction = (item) => item.attention && counts.value[item.key] > 0
const badgeText = (n) => (n > 99 ? '99+' : n)

async function loadResidents() {
  if (!auth.hasRole('rt', 'operator')) return
  const { data } = await api.get('/residents', { params: { status: 'pending', per_page: 1 } })
  counts.value = { ...counts.value, pending: data.meta.counts.pending }
}

// Satu panggilan sekaligus untuk angka (meta.counts selalu seluruh wilayah) dan
// daftar aktivitas (antrean atau yang terbaru).
const listParams = () => ({ per_page: 3, inbox: queueMode.value ? 1 : undefined })

async function loadLetters() {
  const { data } = await api.get('/letters', { params: listParams() })
  const c = data.meta.counts
  const all = Object.values(c).reduce((sum, n) => sum + n, 0)
  letterItems.value = data.data
  counts.value = {
    ...counts.value,
    lettersActive: all - c.completed - c.rejected - (c.cancelled ?? 0),
    lettersInbox: data.meta.inbox,
    lettersVillage: c.rw_approved + c.village_received,
    lettersReady: c.ready_for_pickup,
  }
}

async function loadComplaints() {
  const { data } = await api.get('/complaints', { params: listParams() })
  const c = data.meta.counts
  const all = Object.values(c).reduce((sum, n) => sum + n, 0)
  complaintItems.value = data.data
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
    ratingAverage: summary.total ? `dari ${summary.total} rating` : 'Belum ada rating',
  }
}

// Kartu yang gagal dimuat tetap tampil tanpa angka.
onMounted(async () => {
  await Promise.allSettled([loadResidents(), loadLetters(), loadComplaints(), loadAnnouncements()])
  activityLoaded.value = true
})
</script>

<template>
  <div class="flex flex-col gap-4 sm:gap-[14px]">
    <div class="flex flex-col gap-4 sm:gap-[14px] xl:flex-row">
      <!-- Kartu sambutan bergaya kartu gradien di dasbor template. -->
      <section
        class="gradient-vertical relative flex flex-col gap-5 overflow-hidden rounded-3xl p-5 sm:p-6 xl:w-[calc(389/1000*100%)] xl:shrink-0 xl:gap-6 xl:rounded-2xl"
      >
        <span
          class="pointer-events-none absolute -top-12 -right-12 size-44 rounded-full bg-white/10"
          aria-hidden="true"
        />
        <span
          class="pointer-events-none absolute top-16 -right-4 size-20 rounded-full bg-desa-lime/15"
          aria-hidden="true"
        />

        <div class="relative flex items-center gap-4 xl:flex-col xl:items-start">
          <span
            class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-orange sm:size-16 xl:size-[72px]"
          >
            <AppIcon name="building-4" class="size-6! text-white sm:size-8! xl:size-9!" />
          </span>
          <div class="flex min-w-0 flex-col gap-0.5">
            <p class="text-sm font-medium text-desa-lime">{{ greeting }},</p>
            <h1 class="truncate text-xl leading-7 font-semibold text-white sm:text-2xl">
              {{ auth.user.name }}
            </h1>
            <p class="text-xs font-medium text-white/75 sm:text-sm">{{ today }}</p>
          </div>
        </div>

        <div class="relative hidden flex-col gap-3 sm:flex">
          <p class="text-xl font-semibold text-white xl:text-2xl">{{ intro.title }}</p>
          <p class="leading-relaxed text-white/90">{{ intro.body }}</p>
        </div>

        <div class="relative mt-auto flex items-center gap-3 rounded-2xl bg-white p-3 sm:p-4">
          <span
            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow"
          >
            <AppIcon name="location" class="size-5! text-desa-dark-green" />
          </span>
          <div class="flex min-w-0 flex-col gap-0.5">
            <span class="text-xs font-medium text-desa-secondary sm:text-sm">{{
              intro.eyebrow
            }}</span>
            <span class="truncate leading-5 font-semibold text-desa-dark-green">{{
              scopeLabel ?? 'Rukana'
            }}</span>
          </div>
        </div>
      </section>

      <!-- Menu pintas ala aplikasi; di layar lebar fungsinya diambil alih sidebar. -->
      <nav
        v-if="shortcuts.length"
        class="rounded-3xl bg-white px-3 py-4 lg:hidden"
        aria-label="Menu pintas"
      >
        <ul class="grid grid-cols-4 gap-x-1 gap-y-4">
          <li v-for="shortcut in shortcuts" :key="shortcut.label">
            <RouterLink
              :to="{ name: shortcut.to, query: shortcut.query }"
              class="flex flex-col items-center gap-2 text-center transition-transform duration-200 active:scale-95"
            >
              <span
                :class="[
                  'relative flex size-14 items-center justify-center rounded-2xl',
                  tones[shortcut.tone],
                ]"
              >
                <AppIcon :name="shortcut.icon" class="size-7!" />
                <span
                  v-if="shortcut.badge && counts[shortcut.badge] > 0"
                  class="absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-desa-red px-1.5 text-[11px] leading-none font-semibold text-white ring-2 ring-white"
                >
                  {{ badgeText(counts[shortcut.badge]) }}
                  <span class="sr-only">menunggu</span>
                </span>
              </span>
              <span class="text-xs leading-4 font-medium text-desa-black">{{
                shortcut.label
              }}</span>
            </RouterLink>
          </li>
        </ul>
      </nav>

      <section class="grid flex-1 grid-cols-2 gap-3 sm:gap-[14px]" aria-label="Ringkasan">
        <component
          :is="item.to ? RouterLink : 'article'"
          v-for="item in items"
          :key="item.label"
          :to="item.to ? { name: item.to, query: item.query } : undefined"
          :class="[
            'flex flex-col gap-3 rounded-2xl bg-white p-4 sm:p-6',
            item.to &&
              'ring-desa-dark-green transition-all duration-300 hover:ring-[1.5px] active:scale-[0.98]',
          ]"
        >
          <div class="flex items-start justify-between gap-2">
            <span
              class="flex size-10 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow sm:size-12"
            >
              <AppIcon :name="item.icon" class="size-5! text-desa-dark-green sm:size-6!" />
            </span>
            <span
              v-if="needsAction(item)"
              class="mt-1 size-2.5 rounded-full bg-desa-orange ring-4 ring-desa-orange/15"
              title="Perlu tindakan"
            >
              <span class="sr-only">Perlu tindakan</span>
            </span>
          </div>

          <div
            v-if="item.key && counts[item.key] !== undefined"
            class="mt-auto flex flex-col gap-1"
          >
            <p class="text-2xl leading-8 font-semibold sm:text-[32px] sm:leading-10">
              {{ counts[item.key] }}
            </p>
            <p class="line-clamp-2 text-sm leading-5 font-medium text-desa-secondary">
              {{ item.label }}
            </p>
            <p
              v-if="item.to"
              class="hidden items-center gap-1 pt-1 text-sm font-medium text-desa-dark-green sm:flex"
            >
              Lihat daftar
              <AppIcon name="arrow-square-right" class="size-[18px]!" />
            </p>
            <p v-else class="text-xs font-medium text-desa-secondary sm:text-sm">
              {{ captions[item.key] }}
            </p>
          </div>
          <div v-else-if="item.cta" class="mt-auto flex flex-col gap-1">
            <p class="line-clamp-2 leading-5 font-semibold">{{ item.label }}</p>
            <p class="hidden leading-6 text-desa-secondary sm:block">{{ item.body }}</p>
            <p class="flex items-center gap-1 pt-1 text-sm font-medium text-desa-dark-green">
              {{ item.cta }}
              <AppIcon name="arrow-square-right" class="size-[18px]!" />
            </p>
          </div>
          <div v-else class="mt-auto flex flex-col gap-1">
            <p class="text-2xl leading-8 font-semibold text-desa-grey sm:text-[32px] sm:leading-10">
              —
            </p>
            <p class="line-clamp-2 text-sm leading-5 font-medium text-desa-secondary">
              {{ item.label }}
            </p>
          </div>
        </component>
      </section>
    </div>

    <!-- Antrean kerja pengurus, atau pengajuan terakhir warga. -->
    <section
      class="flex flex-col gap-3 rounded-2xl bg-white p-5 sm:p-8"
      aria-labelledby="dashboard-activity"
    >
      <div class="flex items-center justify-between gap-3">
        <h2 id="dashboard-activity" class="text-lg font-semibold">{{ activityTitle }}</h2>
        <RouterLink
          :to="{ name: 'letters', query: queueMode ? { tab: 'inbox' } : undefined }"
          class="flex shrink-0 items-center gap-1 text-sm font-medium text-desa-dark-green hover:underline"
        >
          Semua surat
          <AppIcon name="arrow-square-right" class="size-[18px]!" />
        </RouterLink>
      </div>
      <p v-if="!activityLoaded" class="py-2 font-medium text-desa-secondary">Memuat…</p>
      <div
        v-else-if="!activity.length"
        class="flex items-center gap-4 rounded-2xl bg-desa-background/50 p-4"
      >
        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-white">
          <AppIcon name="tick-circle" class="text-desa-dark-green" />
        </span>
        <p class="leading-5 text-desa-secondary">
          <template v-if="queueMode"
            >Semua beres — tidak ada surat atau laporan yang menunggu.</template
          >
          <template v-else-if="auth.role === 'warga'"
            >Belum ada pengajuan. Ajukan surat atau laporkan masalah lewat menu di atas.</template
          >
          <template v-else>Belum ada surat atau laporan.</template>
        </p>
      </div>
      <ActivityList v-else :items="activity" :show-region="auth.isStaff" />
    </section>

    <!-- Rekap per periode dan wilayah, plus ekspor untuk RW/operator (PRD 6.8, 6.10). -->
    <ServiceRecap v-if="auth.isStaff" />

    <!-- Pengumuman terbaru untuk wilayah akun ini (PRD 6.8); di HP digeser ke samping. -->
    <section
      class="flex flex-col gap-4 overflow-hidden rounded-2xl bg-white p-5 sm:gap-5 sm:p-8"
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
      <ul
        v-else
        class="hide-scrollbar -mx-5 flex snap-x snap-mandatory scroll-px-5 gap-3 overflow-x-auto px-5 sm:-mx-8 sm:scroll-px-8 sm:px-8 lg:mx-0 lg:grid lg:grid-cols-3 lg:overflow-visible lg:px-0"
      >
        <li
          v-for="item in announcements"
          :key="item.id"
          :class="[
            'shrink-0 snap-start lg:w-auto',
            announcements.length > 1 ? 'w-[82%] sm:w-[45%]' : 'w-full',
          ]"
        >
          <RouterLink
            :to="{ name: 'announcement-detail', params: { id: item.id } }"
            class="flex h-full flex-col gap-2 rounded-2xl border border-desa-background p-4 transition-all duration-300 hover:border-desa-dark-green active:scale-[0.98]"
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
