<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import { errorMessage, fieldErrors } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ phone: '', password: '', remember: true })
const errors = ref({})
const message = ref('')
const submitting = ref(false)

// Akun dummy hasil seeder, hanya tampil saat pengembangan. Kata sandi semua: "password".
const devAccounts = import.meta.env.DEV
  ? [
      ['Operator Desa', '081100000001'],
      ['Kepala Desa', '081100000002'],
      ['Ketua RW 03', '081100000300'],
      ['Ketua RT 01/RW 03', '081100000301'],
      ['Warga RT 01/RW 03', '081200030101'],
      ['Warga belum diverifikasi', '081200030109'],
    ]
  : []

function useDevAccount(phone) {
  form.phone = phone
  form.password = 'password'
}

async function submit() {
  submitting.value = true
  errors.value = {}
  message.value = ''
  try {
    await auth.login(form)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
    router.replace(redirect?.startsWith('/') ? redirect : { name: 'dashboard' })
  } catch (error) {
    errors.value = fieldErrors(error)
    if (!Object.keys(errors.value).length) message.value = errorMessage(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-sm px-4 py-10">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Masuk</h1>
    <p class="mt-1 text-sm text-slate-600">Gunakan nomor HP yang Anda daftarkan.</p>

    <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <AppAlert v-if="message">{{ message }}</AppAlert>

      <AppInput
        v-model="form.phone"
        label="Nomor HP"
        type="tel"
        inputmode="numeric"
        autocomplete="tel"
        placeholder="081234567890"
        :error="errors.phone"
        required
      />
      <AppInput
        v-model="form.password"
        label="Kata sandi"
        type="password"
        autocomplete="current-password"
        :error="errors.password"
        required
      />

      <label class="flex items-center gap-2 text-sm text-slate-600">
        <input
          v-model="form.remember"
          type="checkbox"
          class="size-4 rounded border-slate-300 accent-brand-600"
        />
        Ingat saya di perangkat ini
      </label>

      <AppButton type="submit" block :loading="submitting">Masuk</AppButton>
    </form>

    <p class="mt-4 text-sm text-slate-600">
      Lupa kata sandi? Hubungi ketua RT atau operator desa untuk mengatur ulang.
    </p>
    <p class="mt-6 text-sm text-slate-600">
      Belum punya akun?
      <RouterLink :to="{ name: 'register' }" class="font-semibold text-brand-700 hover:underline"
        >Daftar sebagai warga</RouterLink
      >
    </p>

    <details
      v-if="devAccounts.length"
      class="mt-8 rounded-lg bg-amber-50 p-3 text-sm ring-1 ring-amber-200"
    >
      <summary class="cursor-pointer font-medium text-amber-900">
        Akun dummy (mode pengembangan)
      </summary>
      <ul class="mt-2 space-y-1">
        <li v-for="[label, phone] in devAccounts" :key="phone">
          <button
            type="button"
            class="w-full rounded px-2 py-1 text-left text-amber-900 hover:bg-amber-100"
            @click="useDevAccount(phone)"
          >
            {{ label }} <span class="text-amber-700">· {{ phone }}</span>
          </button>
        </li>
      </ul>
    </details>
  </div>
</template>
