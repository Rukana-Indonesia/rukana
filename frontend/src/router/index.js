import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/*
 * meta:
 *   guest   — hanya untuk pengunjung yang belum masuk (login, daftar)
 *   auth    — wajib masuk
 *   active  — wajib akun aktif (sudah diverifikasi RT)
 *   roles   — daftar peran yang boleh membuka
 *   section — nama route menu yang ikut disorot (mis. detail surat → menu Surat)
 *
 * Guard ini hanya untuk kenyamanan navigasi. Otorisasi sebenarnya di backend.
 */
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    {
      path: '/',
      component: () => import('@/layouts/PublicLayout.vue'),
      children: [
        { path: '', name: 'home', component: () => import('@/views/public/HomeView.vue') },
        {
          path: 'layanan',
          name: 'services',
          component: () => import('@/views/public/ServicesView.vue'),
          meta: { title: 'Katalog Layanan' },
        },
        {
          path: 'cek-tiket',
          name: 'ticket-check',
          component: () => import('@/views/public/TicketCheckView.vue'),
          meta: { title: 'Cek Status Tiket' },
        },
      ],
    },
    {
      path: '/',
      component: () => import('@/layouts/AuthLayout.vue'),
      children: [
        {
          path: 'masuk',
          name: 'login',
          component: () => import('@/views/public/LoginView.vue'),
          meta: { guest: true, title: 'Masuk' },
        },
        {
          path: 'daftar',
          name: 'register',
          component: () => import('@/views/public/RegisterView.vue'),
          meta: { guest: true, title: 'Daftar' },
        },
      ],
    },
    {
      path: '/app',
      component: () => import('@/layouts/AppLayout.vue'),
      meta: { auth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/app/DashboardView.vue'),
          meta: { active: true, title: 'Dasbor' },
        },
        {
          path: 'surat',
          name: 'letters',
          component: () => import('@/views/app/letters/LettersView.vue'),
          meta: { active: true, title: 'Surat Pengantar' },
        },
        {
          path: 'surat/ajukan/:slug?',
          name: 'letter-create',
          component: () => import('@/views/app/letters/LetterCreateView.vue'),
          meta: { active: true, roles: ['warga'], title: 'Ajukan Surat', section: 'letters' },
        },
        {
          path: 'surat/:id(\\d+)',
          name: 'letter-detail',
          component: () => import('@/views/app/letters/LetterDetailView.vue'),
          meta: { active: true, title: 'Detail Surat', section: 'letters' },
        },
        {
          path: 'laporan',
          name: 'complaints',
          component: () => import('@/views/app/complaints/ComplaintsView.vue'),
          meta: { active: true, title: 'Laporan Warga' },
        },
        {
          path: 'laporan/buat',
          name: 'complaint-create',
          component: () => import('@/views/app/complaints/ComplaintCreateView.vue'),
          meta: { active: true, roles: ['warga'], title: 'Buat Laporan', section: 'complaints' },
        },
        {
          path: 'laporan/:id(\\d+)',
          name: 'complaint-detail',
          component: () => import('@/views/app/complaints/ComplaintDetailView.vue'),
          meta: { active: true, title: 'Detail Laporan', section: 'complaints' },
        },
        {
          path: 'peta-laporan',
          name: 'complaint-map',
          component: () => import('@/views/app/complaints/ComplaintMapView.vue'),
          meta: {
            active: true,
            roles: ['rt', 'rw', 'operator', 'kepala_desa'],
            title: 'Peta Sebaran Laporan',
          },
        },
        {
          path: 'pengumuman',
          name: 'announcements',
          component: () => import('@/views/app/announcements/AnnouncementsView.vue'),
          meta: { active: true, title: 'Pengumuman' },
        },
        {
          path: 'pengumuman/buat',
          name: 'announcement-create',
          component: () => import('@/views/app/announcements/AnnouncementFormView.vue'),
          meta: {
            active: true,
            roles: ['rt', 'rw', 'operator'],
            title: 'Buat Pengumuman',
            section: 'announcements',
          },
        },
        {
          path: 'pengumuman/:id(\\d+)',
          name: 'announcement-detail',
          component: () => import('@/views/app/announcements/AnnouncementDetailView.vue'),
          meta: { active: true, title: 'Pengumuman', section: 'announcements' },
        },
        {
          path: 'pengumuman/:id(\\d+)/ubah',
          name: 'announcement-edit',
          component: () => import('@/views/app/announcements/AnnouncementFormView.vue'),
          meta: {
            active: true,
            roles: ['rt', 'rw', 'operator'],
            title: 'Ubah Pengumuman',
            section: 'announcements',
          },
        },
        {
          path: 'ask',
          name: 'ask',
          component: () => import('@/views/app/ai/AskView.vue'),
          meta: {
            active: true,
            roles: ['rt', 'rw', 'operator', 'kepala_desa'],
            title: 'Ask Rukana',
          },
        },
        {
          path: 'ringkasan-bulanan',
          name: 'insights',
          component: () => import('@/views/app/ai/InsightView.vue'),
          meta: {
            active: true,
            roles: ['rw', 'operator', 'kepala_desa'],
            title: 'Ringkasan Bulanan',
          },
        },
        {
          path: 'notifikasi',
          name: 'notifications',
          component: () => import('@/views/app/NotificationsView.vue'),
          meta: { active: true, title: 'Notifikasi' },
        },
        {
          path: 'layanan',
          name: 'services-admin',
          component: () => import('@/views/app/admin/ServicesAdminView.vue'),
          meta: { active: true, roles: ['operator', 'kepala_desa'], title: 'Katalog Layanan' },
        },
        {
          path: 'warga',
          name: 'residents',
          component: () => import('@/views/app/ResidentsView.vue'),
          meta: { active: true, roles: ['rt', 'operator'], title: 'Data Warga' },
        },
        {
          path: 'pengurus',
          name: 'officials',
          component: () => import('@/views/app/admin/OfficialsView.vue'),
          meta: { active: true, roles: ['operator', 'kepala_desa'], title: 'Akun Pengurus' },
        },
        {
          path: 'wilayah',
          name: 'regions',
          component: () => import('@/views/app/admin/RegionsView.vue'),
          meta: { active: true, roles: ['operator', 'kepala_desa'], title: 'Wilayah' },
        },
        {
          path: 'pengaturan-desa',
          name: 'village-settings',
          component: () => import('@/views/app/admin/VillageSettingsView.vue'),
          meta: { active: true, roles: ['operator', 'kepala_desa'], title: 'Pengaturan Desa' },
        },
        {
          path: 'menunggu-verifikasi',
          name: 'pending',
          component: () => import('@/views/app/PendingView.vue'),
          meta: { title: 'Menunggu verifikasi' },
        },
        {
          path: 'kata-sandi',
          name: 'change-password',
          component: () => import('@/views/app/ChangePasswordView.vue'),
          meta: { title: 'Ganti kata sandi' },
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { title: 'Halaman tidak ditemukan' },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.init()

  if (to.meta.guest && auth.isLoggedIn) return { name: 'dashboard' }

  if (to.meta.auth && !auth.isLoggedIn) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (!auth.isLoggedIn) return true

  // Akun pengurus baru dibuat operator dengan kata sandi sementara.
  if (auth.user.must_change_password && to.name !== 'change-password') {
    return { name: 'change-password' }
  }

  if (to.meta.active && !auth.isActive) return { name: 'pending' }
  if (to.name === 'pending' && auth.isActive) return { name: 'dashboard' }

  if (to.meta.roles && !auth.hasRole(...to.meta.roles)) return { name: 'dashboard' }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Rukana` : 'Rukana — Layanan Warga Desa'
})

export default router
