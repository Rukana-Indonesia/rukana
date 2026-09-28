<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
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
  <div class="mx-auto flex max-w-[560px] flex-col gap-6 rounded-3xl bg-white p-6 sm:p-8">
    <span class="flex size-[72px] items-center justify-center rounded-2xl bg-desa-yellow/15">
      <AppIcon name="timer" class="size-9! text-desa-yellow" />
    </span>
    <div class="flex flex-col gap-3">
      <span
        class="w-fit rounded-full bg-desa-yellow px-3 py-1.5 text-xs font-semibold text-white uppercase"
        >{{ auth.user.status_label }}</span
      >
      <h1 class="text-2xl font-semibold">Akun Anda sedang diverifikasi</h1>
      <p class="leading-relaxed text-desa-secondary">
        Ketua {{ auth.user.rt?.label }}/{{ auth.user.rw?.label }} akan memastikan Anda tinggal di
        wilayah tersebut. Setelah diverifikasi, Anda bisa mengajukan surat pengantar dan membuat
        laporan.
      </p>
      <p class="leading-relaxed text-desa-secondary">
        Ingin lebih cepat? Sampaikan langsung ke ketua RT bahwa Anda sudah mendaftar di Rukana.
      </p>
    </div>

    <AppAlert v-if="message" variant="info">{{ message }}</AppAlert>

    <AppButton variant="secondary" class="w-full sm:w-fit" :loading="checking" @click="check">
      Periksa status lagi
    </AppButton>
  </div>
</template>
