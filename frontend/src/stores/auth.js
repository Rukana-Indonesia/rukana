import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { api } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  let loading = null

  const isLoggedIn = computed(() => user.value !== null)
  const isActive = computed(() => user.value?.status === 'active')
  const role = computed(() => user.value?.role ?? null)
  const isStaff = computed(() => isLoggedIn.value && role.value !== 'warga')

  /** Muat user dari sesi yang ada (sekali per pemuatan halaman). */
  function init() {
    loading ??= fetchUser()
    return loading
  }

  async function fetchUser() {
    try {
      const { data } = await api.get('/auth/me', { skipAuthRedirect: true })
      user.value = data.data
    } catch (error) {
      if (error.response?.status !== 401) throw error
      user.value = null
    }
  }

  async function login(credentials) {
    const { data } = await api.post('/auth/login', credentials)
    user.value = data.data
  }

  async function register(payload) {
    const { data } = await api.post('/auth/register', payload)
    user.value = data.data
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } finally {
      clear()
    }
  }

  async function changePassword(payload) {
    const { data } = await api.put('/auth/password', payload)
    if (user.value) user.value.must_change_password = false
    return data.message
  }

  function clear() {
    user.value = null
  }

  /** Apakah user memegang salah satu peran ini. */
  function hasRole(...roles) {
    return roles.includes(role.value)
  }

  return {
    user,
    isLoggedIn,
    isActive,
    role,
    isStaff,
    init,
    fetchUser,
    login,
    register,
    logout,
    changePassword,
    clear,
    hasRole,
  }
})
