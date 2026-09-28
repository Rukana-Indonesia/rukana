<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
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
  <div class="flex flex-col gap-6">
    <form
      class="flex flex-col gap-8 rounded-3xl bg-white p-6 sm:p-8"
      novalidate
      @submit.prevent="submit"
    >
      <header class="flex flex-col items-center gap-6 sm:gap-8">
        <RouterLink :to="{ name: 'home' }" aria-label="Rukana, ke beranda">
          <AppLogo />
        </RouterLink>
        <div class="flex flex-col gap-2 text-center">
          <h1 class="text-2xl leading-[30px] font-semibold">Halo🙌🏻, Selamat Datang!</h1>
          <p class="leading-5 font-medium text-desa-secondary">
            Masuk dengan nomor HP yang Anda daftarkan
          </p>
        </div>
      </header>

      <AppAlert v-if="message">{{ message }}</AppAlert>

      <section class="flex flex-col gap-6 sm:gap-8">
        <AppInput
          v-model="form.phone"
          label="Nomor HP"
          icon="call"
          type="tel"
          inputmode="numeric"
          autocomplete="tel"
          placeholder="081234567890"
          :error="errors.phone"
          required
        />
        <AppInput
          v-model="form.password"
          label="Kata Sandi"
          icon="key"
          type="password"
          autocomplete="current-password"
          placeholder="Ketik kata sandi Anda"
          :error="errors.password"
          required
        />
        <label class="flex items-center gap-3 leading-5 font-medium text-desa-secondary">
          <input
            v-model="form.remember"
            type="checkbox"
            class="size-5 rounded accent-desa-dark-green"
          />
          Ingat saya di perangkat ini
        </label>
      </section>

      <div class="flex flex-col gap-4">
        <AppButton type="submit" block :loading="submitting">Masuk</AppButton>
        <p class="text-center text-sm leading-5 text-desa-secondary">
          Lupa kata sandi? Hubungi ketua RT atau operator desa untuk mengatur ulang.
        </p>
      </div>
    </form>

    <p class="text-center leading-5 text-desa-secondary">
      Belum punya akun?
      <RouterLink
        :to="{ name: 'register' }"
        class="font-semibold text-desa-dark-green hover:underline"
        >Daftar sebagai warga</RouterLink
      >
    </p>

    <details v-if="devAccounts.length" class="rounded-2xl bg-white p-4 text-sm">
      <summary class="cursor-pointer font-medium text-desa-secondary">
        Akun dummy (mode pengembangan)
      </summary>
      <ul class="mt-3 flex flex-col gap-1">
        <li v-for="[label, phone] in devAccounts" :key="phone">
          <button
            type="button"
            class="w-full rounded-xl px-3 py-2 text-left font-medium transition-all duration-300 hover:bg-desa-foreshadow hover:text-desa-dark-green"
            @click="useDevAccount(phone)"
          >
            {{ label }} <span class="text-desa-secondary">· {{ phone }}</span>
          </button>
        </li>
      </ul>
    </details>
  </div>
</template>
