<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { errorMessage } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const checking = ref(false)
const message = ref('')

async function check() {
  checking.value = true
  message.value = ''
  try {
    await auth.fetchUser()
    if (auth.isActive) router.replace({ name: 'dashboard' })
    else message.value = 'Akun Anda masih menunggu verifikasi.'
  } catch (error) {
    message.value = errorMessage(error)
  } finally {
    checking.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-md rounded-xl bg-white p-6 shadow-xs ring-1 ring-slate-200">
    <p class="text-sm font-semibold text-amber-700">{{ auth.user.status_label }}</p>
    <h1 class="mt-1 text-xl font-bold text-slate-900">Akun Anda sedang diverifikasi</h1>
    <p class="mt-2 text-sm text-slate-600">
      Ketua {{ auth.user.rt?.label }}/{{ auth.user.rw?.label }} akan memastikan Anda tinggal di
      wilayah tersebut. Setelah diverifikasi, Anda bisa mengajukan surat pengantar dan membuat
      laporan.
    </p>
    <p class="mt-2 text-sm text-slate-600">
      Ingin lebih cepat? Sampaikan langsung ke ketua RT bahwa Anda sudah mendaftar di Rukana.
    </p>

    <AppAlert v-if="message" variant="info" class="mt-4">{{ message }}</AppAlert>

    <AppButton variant="secondary" class="mt-5" :loading="checking" @click="check">
      Periksa status lagi
    </AppButton>
  </div>
</template>
