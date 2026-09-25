<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import { errorMessage, fieldErrors } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const forced = auth.user.must_change_password
const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const errors = ref({})
const message = ref('')
const submitting = ref(false)

async function submit() {
  submitting.value = true
  errors.value = {}
  message.value = ''
  try {
    await auth.changePassword(form)
    router.replace({ name: auth.isActive ? 'dashboard' : 'pending' })
  } catch (error) {
    errors.value = fieldErrors(error)
    if (!Object.keys(errors.value).length) message.value = errorMessage(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-sm">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Ganti kata sandi</h1>
    <AppAlert v-if="forced" variant="warning" class="mt-4">
      Akun Anda memakai kata sandi sementara. Ganti dulu sebelum melanjutkan.
    </AppAlert>

    <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <AppAlert v-if="message">{{ message }}</AppAlert>
      <AppInput
        v-model="form.current_password"
        label="Kata sandi saat ini"
        type="password"
        autocomplete="current-password"
        :error="errors.current_password"
        required
      />
      <AppInput
        v-model="form.password"
        label="Kata sandi baru"
        type="password"
        autocomplete="new-password"
        hint="Minimal 8 karakter."
        :error="errors.password"
        required
      />
      <AppInput
        v-model="form.password_confirmation"
        label="Ulangi kata sandi baru"
        type="password"
        autocomplete="new-password"
        required
      />
      <AppButton type="submit" block :loading="submitting">Simpan kata sandi</AppButton>
    </form>
  </div>
</template>
