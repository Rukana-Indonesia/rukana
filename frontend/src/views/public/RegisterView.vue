<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const form = reactive({
  name: '',
  phone: '',
  password: '',
  password_confirmation: '',
  rw_id: '',
  rt_id: '',
  residency_status: 'tetap',
})
const errors = ref({})
const message = ref('')
const submitting = ref(false)

const village = ref(null)
const regionsError = ref('')

const residencyOptions = [
  { value: 'tetap', label: 'Tetap' },
  { value: 'kontrak', label: 'Kontrak' },
  { value: 'kos', label: 'Kos' },
]

const rwOptions = computed(
  () =>
    village.value?.rws.map((rw) => ({
      value: rw.id,
      label: rw.name ? `${rw.label} — ${rw.name}` : rw.label,
    })) ?? [],
)
const rtOptions = computed(() => {
  const rw = village.value?.rws.find((r) => r.id === form.rw_id)
  return rw?.rts.map((rt) => ({ value: rt.id, label: rt.label })) ?? []
})

// Ganti RW → pilihan RT lama tidak berlaku lagi.
watch(
  () => form.rw_id,
  () => {
    form.rt_id = ''
  },
)

onMounted(async () => {
  try {
    const { data } = await api.get('/regions')
    village.value = data.data
  } catch (error) {
    regionsError.value = errorMessage(error, 'Daftar RW/RT gagal dimuat.')
  }
})

async function submit() {
  submitting.value = true
  errors.value = {}
  message.value = ''
  try {
    await auth.register(form)
    router.replace({ name: 'pending' })
  } catch (error) {
    errors.value = fieldErrors(error)
    if (!Object.keys(errors.value).length) message.value = errorMessage(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-md px-4 py-10">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Daftar sebagai warga</h1>
    <p class="mt-1 text-sm text-slate-600">
      <template v-if="village">Desa {{ village.name }}. </template>Setelah mendaftar, ketua RT Anda
      akan memverifikasi bahwa Anda tinggal di RT tersebut.
    </p>

    <AppAlert v-if="regionsError" class="mt-6">{{ regionsError }}</AppAlert>

    <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <AppAlert v-if="message">{{ message }}</AppAlert>

      <AppInput
        v-model="form.name"
        label="Nama lengkap"
        autocomplete="name"
        maxlength="100"
        :error="errors.name"
        required
      />
      <AppInput
        v-model="form.phone"
        label="Nomor HP"
        type="tel"
        inputmode="numeric"
        autocomplete="tel"
        placeholder="081234567890"
        hint="Dipakai untuk masuk. Tidak ada kode OTP."
        :error="errors.phone"
        required
      />

      <div class="grid grid-cols-2 gap-3">
        <AppSelect
          v-model="form.rw_id"
          label="RW"
          :options="rwOptions"
          placeholder="Pilih RW"
          :disabled="!village"
          :error="errors.rw_id"
          required
        />
        <AppSelect
          v-model="form.rt_id"
          label="RT"
          :options="rtOptions"
          placeholder="Pilih RT"
          :disabled="!form.rw_id"
          :error="errors.rt_id"
          required
        />
      </div>

      <fieldset>
        <legend class="mb-1.5 text-sm font-medium text-slate-700">Status tinggal</legend>
        <div class="grid grid-cols-3 gap-2">
          <label
            v-for="opt in residencyOptions"
            :key="opt.value"
            :class="[
              'flex min-h-11 cursor-pointer items-center justify-center rounded-lg border text-sm font-medium',
              form.residency_status === opt.value
                ? 'border-brand-600 bg-brand-50 text-brand-800'
                : 'border-slate-300 bg-white text-slate-700',
            ]"
          >
            <input
              v-model="form.residency_status"
              type="radio"
              :value="opt.value"
              class="sr-only"
            />
            {{ opt.label }}
          </label>
        </div>
        <p v-if="errors.residency_status" class="mt-1.5 text-sm text-red-600">
          {{ errors.residency_status }}
        </p>
      </fieldset>

      <AppInput
        v-model="form.password"
        label="Kata sandi"
        type="password"
        autocomplete="new-password"
        hint="Minimal 8 karakter."
        :error="errors.password"
        required
      />
      <AppInput
        v-model="form.password_confirmation"
        label="Ulangi kata sandi"
        type="password"
        autocomplete="new-password"
        required
      />

      <p class="text-xs text-slate-500">
        Rukana tidak meminta NIK atau data KK saat pendaftaran. Data Anda hanya dilihat pengurus
        wilayah Anda.
      </p>

      <AppButton type="submit" block :loading="submitting">Daftar</AppButton>
    </form>

    <p class="mt-6 text-sm text-slate-600">
      Sudah punya akun?
      <RouterLink :to="{ name: 'login' }" class="font-semibold text-brand-700 hover:underline"
        >Masuk</RouterLink
      >
    </p>
  </div>
</template>
