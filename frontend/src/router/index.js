import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/*
 * meta:
 *   guest   — hanya untuk pengunjung yang belum masuk (login, daftar)
 *   auth    — wajib masuk
 *   active  — wajib akun aktif (sudah diverifikasi RT)
 *   roles   — daftar peran yang boleh membuka
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
          meta: { active: true, title: 'Beranda' },
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
