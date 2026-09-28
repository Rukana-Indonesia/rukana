<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import AppLogo from '@/components/AppLogo.vue'
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
          <h1 class="text-2xl leading-[30px] font-semibold">Daftar sebagai Warga</h1>
          <p class="leading-5 font-medium text-desa-secondary">
            <template v-if="village">Desa {{ village.name }}. </template>Ketua RT akan memverifikasi
            bahwa Anda tinggal di RT tersebut.
          </p>
        </div>
      </header>

      <AppAlert v-if="regionsError">{{ regionsError }}</AppAlert>
      <AppAlert v-if="message">{{ message }}</AppAlert>

      <section class="flex flex-col gap-6">
        <AppInput
          v-model="form.name"
          label="Nama Lengkap"
          icon="user"
          autocomplete="name"
          maxlength="100"
          placeholder="Nama sesuai KTP"
          :error="errors.name"
          required
        />
        <AppInput
          v-model="form.phone"
          label="Nomor HP"
          icon="call"
          type="tel"
          inputmode="numeric"
          autocomplete="tel"
          placeholder="081234567890"
          hint="Dipakai untuk masuk. Tidak ada kode OTP."
          :error="errors.phone"
          required
        />

        <div class="grid grid-cols-1 gap-6 min-[400px]:grid-cols-2 min-[400px]:gap-4">
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

        <fieldset class="flex flex-col gap-3">
          <legend class="mb-3 leading-5 font-medium text-desa-secondary">Status Tinggal</legend>
          <div class="grid grid-cols-3 gap-2 sm:gap-3">
            <label
              v-for="opt in residencyOptions"
              :key="opt.value"
              :class="[
                'flex h-14 cursor-pointer items-center justify-center rounded-2xl leading-5 font-medium ring-1 transition-all duration-300',
                'has-[:focus-visible]:ring-2',
                form.residency_status === opt.value
                  ? 'bg-desa-foreshadow text-desa-dark-green ring-desa-dark-green'
                  : 'bg-white text-desa-secondary ring-desa-background hover:bg-desa-foreshadow hover:text-desa-dark-green',
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
          <p v-if="errors.residency_status" class="text-sm font-medium text-desa-red">
            {{ errors.residency_status }}
          </p>
        </fieldset>

        <AppInput
          v-model="form.password"
          label="Kata Sandi"
          icon="key"
          type="password"
          autocomplete="new-password"
          placeholder="Minimal 8 karakter"
          :error="errors.password"
          required
        />
        <AppInput
          v-model="form.password_confirmation"
          label="Ulangi Kata Sandi"
          icon="key"
          type="password"
          autocomplete="new-password"
          placeholder="Ketik ulang kata sandi"
          required
        />
      </section>

      <div class="flex flex-col gap-4">
        <p class="rounded-2xl bg-desa-foreshadow p-4 text-sm leading-5 text-desa-dark-green">
          Rukana tidak meminta NIK atau data KK saat pendaftaran. Data Anda hanya dilihat pengurus
          wilayah Anda.
        </p>
        <AppButton type="submit" block :loading="submitting">Daftar</AppButton>
      </div>
    </form>

    <p class="text-center leading-5 text-desa-secondary">
      Sudah punya akun?
      <RouterLink :to="{ name: 'login' }" class="font-semibold text-desa-dark-green hover:underline"
        >Masuk</RouterLink
      >
    </p>
  </div>
</template>
