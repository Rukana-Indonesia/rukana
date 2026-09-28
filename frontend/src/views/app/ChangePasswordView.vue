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
  <div class="mx-auto flex max-w-[560px] flex-col gap-6 rounded-3xl bg-white p-6 sm:p-8">
    <div class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">Ganti Kata Sandi</h1>
      <p class="leading-5 font-medium text-desa-secondary">
        Gunakan minimal 8 karakter yang mudah Anda ingat.
      </p>
    </div>
    <AppAlert v-if="forced" variant="warning">
      Akun Anda memakai kata sandi sementara. Ganti dulu sebelum melanjutkan.
    </AppAlert>

    <form class="flex flex-col gap-6" novalidate @submit.prevent="submit">
      <AppAlert v-if="message">{{ message }}</AppAlert>
      <AppInput
        v-model="form.current_password"
        label="Kata Sandi Saat Ini"
        icon="key"
        type="password"
        autocomplete="current-password"
        placeholder="Ketik kata sandi saat ini"
        :error="errors.current_password"
        required
      />
      <AppInput
        v-model="form.password"
        label="Kata Sandi Baru"
        icon="key"
        type="password"
        autocomplete="new-password"
        placeholder="Minimal 8 karakter"
        :error="errors.password"
        required
      />
      <AppInput
        v-model="form.password_confirmation"
        label="Ulangi Kata Sandi Baru"
        icon="key"
        type="password"
        autocomplete="new-password"
        placeholder="Ketik ulang kata sandi baru"
        required
      />
      <AppButton type="submit" block :loading="submitting">Simpan Kata Sandi</AppButton>
    </form>
  </div>
</template>
