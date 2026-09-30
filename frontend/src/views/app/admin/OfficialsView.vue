<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'

import TemporaryPasswordDialog from '@/components/TemporaryPasswordDialog.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatDate, formatPhone, initials } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

// Operator mengelola akun; kepala desa hanya melihat (tombol disembunyikan, backend tetap menolak).
const auth = useAuthStore()
const canManage = computed(() => auth.hasRole('operator'))

const officials = ref([])
const rws = ref([])
const loading = ref(true)
const error = ref('')
const notice = ref('')
let noticeTimer

const roleFilters = [
  { value: 'all', label: 'Semua' },
  { value: 'village', label: 'Perangkat Desa' },
  { value: 'rw', label: 'Pengurus RW' },
  { value: 'rt', label: 'Pengurus RT' },
]
const roleOptions = [
  { value: 'rt', label: 'Pengurus RT' },
  { value: 'rw', label: 'Pengurus RW' },
  { value: 'operator', label: 'Operator Desa' },
  { value: 'kepala_desa', label: 'Kepala Desa' },
]
const roleFilter = ref('all')

const filtered = computed(() =>
  officials.value.filter((o) => {
    if (roleFilter.value === 'all') return true
    if (roleFilter.value === 'village') return ['operator', 'kepala_desa'].includes(o.role)
    return o.role === roleFilter.value
  }),
)
const rwOptions = computed(() =>
  rws.value.map((rw) => ({ value: rw.id, label: rw.name ? `${rw.label} · ${rw.name}` : rw.label })),
)
const rtOptions = computed(() =>
  rws.value.flatMap((rw) =>
    rw.rts.map((rt) => ({ value: rt.id, label: `${rt.label} / ${rw.label}` })),
  ),
)

function regionOf(official) {
  if (official.role === 'rt') return `${official.rt?.label} / ${official.rw?.label}`
  if (official.role === 'rw') return official.rw?.label
  return `Desa ${auth.user?.village?.name ?? ''}`
}

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/admin/officials')
    officials.value = data.data
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

async function loadRegions() {
  try {
    const { data } = await api.get('/regions')
    rws.value = data.data.rws
  } catch {
    // Dicoba lagi saat form dibuka.
  }
}

onMounted(() => {
  load()
  if (canManage.value) loadRegions()
})
onBeforeUnmount(() => clearTimeout(noticeTimer))

function notify(message) {
  notice.value = message
  clearTimeout(noticeTimer)
  noticeTimer = setTimeout(() => (notice.value = ''), 5000)
}

// Form tambah/ubah akun.
const form = reactive({
  open: false,
  editing: null,
  name: '',
  phone: '',
  role: 'rt',
  rw_id: '',
  rt_id: '',
  errors: {},
  error: '',
  submitting: false,
})
const temp = reactive({ open: false, user: null, password: '' })

function openForm(official = null) {
  if (!rws.value.length) loadRegions()
  Object.assign(form, {
    open: true,
    editing: official,
    name: official?.name ?? '',
    phone: official ? formatPhone(official.phone) : '',
    role: official?.role ?? 'rt',
    rw_id: official?.rw?.id ?? '',
    rt_id: official?.rt?.id ?? '',
    errors: {},
    error: '',
  })
}

async function submitForm() {
  form.submitting = true
  form.errors = {}
  form.error = ''
  const payload = {
    name: form.name,
    phone: form.phone,
    role: form.role,
    rw_id: form.role === 'rw' ? form.rw_id : undefined,
    rt_id: form.role === 'rt' ? form.rt_id : undefined,
  }
  try {
    const { data } = form.editing
      ? await api.put(`/admin/officials/${form.editing.id}`, payload)
      : await api.post('/admin/officials', payload)
    form.open = false
    if (data.meta?.temporary_password) {
      Object.assign(temp, { open: true, user: data.data, password: data.meta.temporary_password })
    } else {
      notify(data.message)
    }
    load()
  } catch (err) {
    form.errors = fieldErrors(err)
    if (!Object.keys(form.errors).length) form.error = errorMessage(err)
  } finally {
    form.submitting = false
  }
}

// Konfirmasi reset kata sandi / nonaktifkan / aktifkan.
const confirm = reactive({ open: false, type: 'reset', user: null, error: '', submitting: false })

const confirmText = computed(() => {
  const name = confirm.user?.name
  return {
    reset: {
      title: 'Reset Kata Sandi',
      description: `Kata sandi ${name} diganti dengan kata sandi sementara dan semua sesi masuknya diakhiri.`,
      submit: 'Reset Kata Sandi',
    },
    suspend: {
      title: 'Nonaktifkan Akun',
      description: `${name} tidak bisa masuk sampai akunnya diaktifkan kembali. Riwayat kerjanya tetap tersimpan.`,
      submit: 'Nonaktifkan',
    },
    activate: {
      title: 'Aktifkan Akun',
      description: `${name} bisa masuk kembali dengan kata sandi terakhirnya.`,
      submit: 'Aktifkan',
    },
  }[confirm.type]
})

function openConfirm(type, user) {
  Object.assign(confirm, { open: true, type, user, error: '' })
}

async function submitConfirm() {
  const { type, user } = confirm
  confirm.submitting = true
  confirm.error = ''
  try {
    if (type === 'reset') {
      const { data } = await api.post(`/users/${user.id}/reset-password`)
      confirm.open = false
      Object.assign(temp, { open: true, user, password: data.data.temporary_password })
    } else {
      const { data } = await api.post(`/admin/officials/${user.id}/${type}`)
      confirm.open = false
      notify(data.message)
    }
    load()
  } catch (err) {
    confirm.error = errorMessage(err)
  } finally {
    confirm.submitting = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="sr-only">Akun Pengurus</h1>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="leading-relaxed font-medium text-desa-secondary">
        Akun pengurus RT/RW dan perangkat desa. Akun baru memakai kata sandi sementara yang wajib
        diganti saat pertama masuk.
      </p>
      <AppButton v-if="canManage" class="shrink-0" @click="openForm()">
        <AppIcon name="add-square" />
        Tambah Akun
      </AppButton>
    </div>

    <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>

    <div class="hide-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0">
      <button
        v-for="option in roleFilters"
        :key="option.value"
        type="button"
        :aria-pressed="roleFilter === option.value"
        :class="[
          'flex h-12 shrink-0 items-center rounded-2xl px-4 leading-5 font-medium transition-all duration-300',
          roleFilter === option.value
            ? 'bg-desa-dark-green text-white'
            : 'bg-white text-desa-secondary hover:text-desa-dark-green',
        ]"
        @click="roleFilter = option.value"
      >
        {{ option.label }}
      </button>
    </div>

    <AppAlert v-if="error">{{ error }}</AppAlert>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat akun pengurus…
    </div>

    <p
      v-else-if="!filtered.length && !error"
      class="rounded-2xl bg-white px-6 py-12 text-center font-medium text-desa-secondary"
    >
      Belum ada akun di kelompok ini.
    </p>

    <ul v-else class="grid gap-[14px] md:grid-cols-2 2xl:grid-cols-3">
      <li
        v-for="official in filtered"
        :key="official.id"
        class="flex flex-col gap-5 rounded-2xl bg-white p-6"
      >
        <div class="flex items-start gap-3">
          <span
            class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow font-semibold text-desa-dark-green"
            aria-hidden="true"
            >{{ initials(official.name) }}</span
          >
          <div class="flex min-w-0 flex-1 flex-col gap-1">
            <p class="truncate leading-5 font-semibold">{{ official.name }}</p>
            <a
              :href="`tel:+${official.phone}`"
              class="w-fit text-sm font-medium text-desa-secondary hover:text-desa-dark-green"
              >{{ formatPhone(official.phone) }}</a
            >
          </div>
          <AppBadge v-if="official.id === auth.user.id" variant="info">Akun Anda</AppBadge>
        </div>

        <div class="flex flex-col gap-3 rounded-2xl bg-desa-background/50 p-4">
          <div class="flex items-center gap-2">
            <AppIcon name="user-square" class="size-5! text-desa-dark-green" />
            <p class="leading-5 font-semibold">{{ official.role_label }}</p>
            <p class="truncate text-sm font-medium text-desa-secondary">
              · {{ regionOf(official) }}
            </p>
          </div>
          <p class="flex items-center gap-2 text-sm font-medium text-desa-secondary">
            <AppIcon name="timer" class="size-5!" />
            {{
              official.last_login_at
                ? `Terakhir masuk ${formatDate(official.last_login_at, true)}`
                : 'Belum pernah masuk'
            }}
          </p>
          <div
            v-if="official.status !== 'active' || official.must_change_password"
            class="flex flex-wrap gap-2"
          >
            <AppBadge v-if="official.status !== 'active'" variant="danger">{{
              official.status_label
            }}</AppBadge>
            <AppBadge v-if="official.must_change_password" variant="warning"
              >Kata sandi sementara</AppBadge
            >
          </div>
        </div>

        <div
          v-if="canManage && official.id !== auth.user.id"
          class="mt-auto grid grid-cols-2 gap-3 min-[400px]:grid-cols-3"
        >
          <AppButton variant="secondary" @click="openForm(official)">
            <AppIcon name="edit" class="size-5!" />
            Ubah
          </AppButton>
          <AppButton variant="secondary" @click="openConfirm('reset', official)">
            <AppIcon name="key" class="size-5!" />
            Reset
          </AppButton>
          <AppButton
            v-if="official.status === 'active'"
            variant="ghost"
            class="col-span-2 text-desa-red! min-[400px]:col-span-1"
            @click="openConfirm('suspend', official)"
          >
            Nonaktifkan
          </AppButton>
          <AppButton
            v-else
            variant="ghost"
            class="col-span-2 min-[400px]:col-span-1"
            @click="openConfirm('activate', official)"
          >
            Aktifkan
          </AppButton>
        </div>
      </li>
    </ul>

    <AppModal
      v-model:open="form.open"
      :title="form.editing ? 'Ubah Akun Pengurus' : 'Tambah Akun Pengurus'"
      :description="
        form.editing
          ? 'Perubahan peran atau wilayah langsung mengubah data yang bisa diakses akun ini.'
          : 'Kata sandi sementara dibuat otomatis setelah akun disimpan.'
      "
    >
      <form id="official-form" class="flex flex-col gap-5" novalidate @submit.prevent="submitForm">
        <AppAlert v-if="form.error">{{ form.error }}</AppAlert>
        <AppInput
          v-model="form.name"
          label="Nama lengkap"
          icon="user"
          autocomplete="off"
          placeholder="Mis. Ujang Suryana"
          :error="form.errors.name"
          required
        />
        <AppInput
          v-model="form.phone"
          label="No. HP"
          icon="call"
          type="tel"
          inputmode="tel"
          autocomplete="off"
          placeholder="081234567890"
          hint="Dipakai untuk masuk ke Rukana."
          :error="form.errors.phone"
          required
        />
        <AppSelect
          v-model="form.role"
          label="Peran"
          icon="crown"
          :options="roleOptions"
          :error="form.errors.role"
        />
        <AppSelect
          v-if="form.role === 'rw'"
          v-model="form.rw_id"
          label="RW"
          icon="location"
          placeholder="Pilih RW"
          :options="rwOptions"
          :error="form.errors.rw_id"
        />
        <AppSelect
          v-if="form.role === 'rt'"
          v-model="form.rt_id"
          label="RT"
          icon="location"
          placeholder="Pilih RT"
          :options="rtOptions"
          :error="form.errors.rt_id"
        />
      </form>

      <template #footer>
        <AppButton variant="secondary" @click="form.open = false">Batal</AppButton>
        <AppButton type="submit" form="official-form" :loading="form.submitting">
          {{ form.editing ? 'Simpan Perubahan' : 'Buat Akun' }}
        </AppButton>
      </template>
    </AppModal>

    <AppModal
      v-model:open="confirm.open"
      :title="confirmText.title"
      :description="confirmText.description"
    >
      <AppAlert v-if="confirm.error">{{ confirm.error }}</AppAlert>
      <template #footer>
        <AppButton variant="secondary" @click="confirm.open = false">Batal</AppButton>
        <AppButton
          :variant="confirm.type === 'suspend' ? 'danger' : 'primary'"
          :loading="confirm.submitting"
          @click="submitConfirm"
        >
          {{ confirmText.submit }}
        </AppButton>
      </template>
    </AppModal>

    <TemporaryPasswordDialog v-model:open="temp.open" :user="temp.user" :password="temp.password" />
  </div>
</template>
