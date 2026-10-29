<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { estimateLabel } from '@/lib/letters'
import { useAuthStore } from '@/stores/auth'

// Operator mengelola katalog; kepala desa hanya melihat (backend tetap menolak perubahan).
const auth = useAuthStore()
const canManage = computed(() => auth.hasRole('operator'))

const flowOptions = [
  { value: 'rt', label: 'Sampai RT' },
  { value: 'rt_rw', label: 'RT → RW' },
  { value: 'rt_rw_village', label: 'RT → RW → Desa' },
  { value: 'village', label: 'Langsung Desa' },
]
const typeOptions = [
  { value: 'text', label: 'Teks singkat' },
  { value: 'textarea', label: 'Teks panjang' },
  { value: 'number', label: 'Angka' },
  { value: 'date', label: 'Tanggal' },
  { value: 'select', label: 'Pilihan' },
]

const services = ref([])
const loading = ref(true)
const error = ref('')
const notice = ref('')
let noticeTimer

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/admin/services')
    services.value = data.data
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(noticeTimer))

function notify(message) {
  notice.value = message
  clearTimeout(noticeTimer)
  noticeTimer = setTimeout(() => (notice.value = ''), 5000)
}

/** Data layanan dalam bentuk yang diterima API (PUT mengirim seluruh isian). */
function payloadOf(service) {
  return {
    name: service.name,
    purpose: service.purpose,
    requirements: service.requirements,
    flow: service.flow,
    estimated_days: service.estimated_days || null,
    notes: service.notes || null,
    requires_nik: service.requires_nik,
    is_active: service.is_active,
    form_schema: service.form_schema.map((f) => ({
      key: f.key,
      label: f.label,
      type: f.type,
      required: f.required,
      options: f.type === 'select' ? f.options : undefined,
    })),
  }
}

// Form tambah/ubah layanan.
const form = reactive({
  open: false,
  editing: null,
  data: null,
  errors: {},
  error: '',
  submitting: false,
})

function openForm(service = null) {
  Object.assign(form, {
    open: true,
    editing: service,
    errors: {},
    error: '',
    data: service
      ? {
          ...payloadOf(service),
          requirements: [...service.requirements],
          estimated_days: service.estimated_days ?? '',
          notes: service.notes ?? '',
          form_schema: service.form_schema.map((f) => ({
            ...f,
            required: !!f.required,
            optionsText: (f.options ?? []).join(', '),
          })),
        }
      : {
          name: '',
          purpose: '',
          requirements: ['KTP'],
          flow: 'rt_rw_village',
          estimated_days: '',
          notes: '',
          requires_nik: false,
          is_active: true,
          form_schema: [],
        },
  })
}

function addField() {
  form.data.form_schema.push({ key: '', label: '', type: 'text', required: false, optionsText: '' })
}

async function submitForm() {
  form.submitting = true
  form.errors = {}
  form.error = ''
  const payload = {
    ...form.data,
    estimated_days: form.data.estimated_days || null,
    notes: form.data.notes || null,
    form_schema: form.data.form_schema.map(({ optionsText, ...field }) => ({
      ...field,
      key: field.key || undefined,
      options:
        field.type === 'select'
          ? optionsText
              .split(',')
              .map((o) => o.trim())
              .filter(Boolean)
          : undefined,
    })),
  }
  try {
    const { data } = form.editing
      ? await api.put(`/admin/services/${form.editing.id}`, payload)
      : await api.post('/admin/services', payload)
    form.open = false
    notify(data.message)
    load()
  } catch (err) {
    form.errors = fieldErrors(err)
    form.error = Object.keys(form.errors).length
      ? 'Periksa kembali isian yang ditandai.'
      : errorMessage(err)
  } finally {
    form.submitting = false
  }
}

/** Error pertama untuk satu baris isian tambahan atau persyaratan. */
function rowError(prefix, i) {
  const key = Object.keys(form.errors).find((k) => k.startsWith(`${prefix}.${i}`))
  return key ? form.errors[key] : ''
}

// Aktifkan/nonaktifkan dan hapus.
const confirm = reactive({
  open: false,
  type: 'toggle',
  service: null,
  error: '',
  submitting: false,
})

function openConfirm(type, service) {
  Object.assign(confirm, { open: true, type, service, error: '' })
}

const confirmText = computed(() => {
  const s = confirm.service
  if (confirm.type === 'delete') {
    return {
      title: 'Hapus Layanan',
      description: `${s?.name} belum pernah diajukan, jadi bisa dihapus dari katalog.`,
      submit: 'Hapus',
    }
  }
  return s?.is_active
    ? {
        title: 'Nonaktifkan Layanan',
        description: `${s?.name} tidak tampil di katalog dan tidak bisa diajukan. Surat yang sedang berjalan tetap diproses.`,
        submit: 'Nonaktifkan',
      }
    : {
        title: 'Aktifkan Layanan',
        description: `${s?.name} tampil lagi di katalog dan bisa diajukan warga.`,
        submit: 'Aktifkan',
      }
})

async function submitConfirm() {
  const s = confirm.service
  confirm.submitting = true
  confirm.error = ''
  try {
    const { data } =
      confirm.type === 'delete'
        ? await api.delete(`/admin/services/${s.id}`)
        : await api.put(`/admin/services/${s.id}`, { ...payloadOf(s), is_active: !s.is_active })
    confirm.open = false
    notify(data.message)
    load()
  } catch (err) {
    confirm.error = errorMessage(err)
  } finally {
    confirm.submitting = false
  }
}

const checkbox =
  'size-5 shrink-0 rounded-md border-desa-grey accent-desa-dark-green focus-visible:outline-2 focus-visible:outline-desa-dark-green'
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="sr-only">Katalog Layanan</h1>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="leading-relaxed font-medium text-desa-secondary">
        Layanan aktif tampil di
        <RouterLink
          :to="{ name: 'services' }"
          class="text-desa-dark-green underline-offset-4 hover:underline"
          >katalog publik</RouterLink
        >. Mengubah alur tidak memengaruhi surat yang sudah diajukan.
      </p>
      <AppButton v-if="canManage" class="shrink-0" @click="openForm()">
        <AppIcon name="add-square" />
        Tambah Layanan
      </AppButton>
    </div>

    <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>
    <AppAlert v-if="error">{{ error }}</AppAlert>
    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat katalog…
    </div>

    <ul v-else class="grid gap-[14px] md:grid-cols-2 2xl:grid-cols-3">
      <li
        v-for="service in services"
        :key="service.id"
        :class="[
          'flex flex-col gap-5 rounded-2xl bg-white p-6',
          !service.is_active && 'opacity-75',
        ]"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="flex min-w-0 flex-col gap-1">
            <p class="leading-5 font-semibold">{{ service.name }}</p>
            <p class="line-clamp-2 text-sm leading-5 text-desa-secondary">{{ service.purpose }}</p>
          </div>
          <AppBadge :variant="service.is_active ? 'success' : 'neutral'">{{
            service.is_active ? 'Aktif' : 'Nonaktif'
          }}</AppBadge>
        </div>

        <dl class="grid grid-cols-2 gap-x-3 gap-y-4 rounded-2xl bg-desa-background/50 p-4">
          <div class="flex flex-col gap-1">
            <dt class="text-sm font-medium text-desa-secondary">Alur</dt>
            <dd class="leading-5 font-semibold">{{ service.flow_label }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-sm font-medium text-desa-secondary">Estimasi</dt>
            <dd class="leading-5 font-semibold">{{ estimateLabel(service.estimated_days) }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-sm font-medium text-desa-secondary">Persyaratan</dt>
            <dd class="leading-5 font-semibold">
              {{ service.requirements.length }} dokumen{{ service.requires_nik ? ' + NIK' : '' }}
            </dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-sm font-medium text-desa-secondary">Diajukan</dt>
            <dd class="leading-5 font-semibold">{{ service.letters_count }} kali</dd>
          </div>
        </dl>

        <div v-if="canManage" class="mt-auto flex flex-col gap-3 min-[400px]:flex-row">
          <AppButton variant="secondary" class="flex-1" @click="openForm(service)">
            <AppIcon name="edit" />
            Ubah
          </AppButton>
          <AppButton variant="ghost" class="flex-1" @click="openConfirm('toggle', service)">
            {{ service.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
          </AppButton>
          <AppButton
            v-if="!service.letters_count"
            variant="ghost"
            class="text-desa-red! hover:bg-desa-red/10!"
            aria-label="Hapus layanan"
            @click="openConfirm('delete', service)"
          >
            <AppIcon name="close-circle" />
          </AppButton>
        </div>
      </li>
    </ul>

    <AppModal
      v-model:open="form.open"
      :title="form.editing ? 'Ubah Layanan' : 'Tambah Layanan'"
      description="Isi seperti yang akan dibaca warga di katalog."
    >
      <form
        v-if="form.data"
        id="service-form"
        class="flex flex-col gap-5"
        novalidate
        @submit.prevent="submitForm"
      >
        <AppAlert v-if="form.error">{{ form.error }}</AppAlert>
        <AppInput
          v-model="form.data.name"
          label="Nama layanan"
          icon="document-text"
          maxlength="150"
          placeholder="Mis. Surat Pengantar SKCK"
          :error="form.errors.name"
        />
        <AppTextarea
          v-model="form.data.purpose"
          label="Kegunaan"
          maxlength="1000"
          :error="form.errors.purpose"
        />
        <AppSelect
          v-model="form.data.flow"
          label="Alur"
          icon="grid-5"
          :options="flowOptions"
          :error="form.errors.flow"
        />
        <AppInput
          v-model="form.data.estimated_days"
          label="Estimasi (hari kerja)"
          icon="timer"
          type="number"
          min="1"
          max="60"
          :error="form.errors.estimated_days"
        />

        <fieldset class="flex flex-col gap-3">
          <legend class="mb-3 leading-5 font-medium text-desa-secondary">
            Persyaratan dokumen
          </legend>
          <div v-for="(_, i) in form.data.requirements" :key="i" class="flex items-start gap-2">
            <div class="flex-1">
              <input
                v-model="form.data.requirements[i]"
                :aria-label="`Persyaratan ${i + 1}`"
                maxlength="150"
                class="h-12 w-full rounded-2xl border-[1.5px] border-desa-background px-4 font-medium focus:ring-[1.5px] focus:ring-desa-dark-green focus:outline-none"
              />
              <p v-if="rowError('requirements', i)" class="mt-1 text-sm text-desa-red">
                {{ rowError('requirements', i) }}
              </p>
            </div>
            <button
              type="button"
              class="flex size-12 shrink-0 items-center justify-center rounded-2xl text-desa-secondary hover:text-desa-red"
              :aria-label="`Hapus persyaratan ${i + 1}`"
              @click="form.data.requirements.splice(i, 1)"
            >
              <AppIcon name="close-circle" />
            </button>
          </div>
          <button
            v-if="form.data.requirements.length < 10"
            type="button"
            class="flex w-fit items-center gap-2 text-sm font-medium text-desa-dark-green"
            @click="form.data.requirements.push('')"
          >
            <AppIcon name="add-square" class="size-5!" />
            Tambah persyaratan
          </button>
        </fieldset>

        <label class="flex items-start gap-3">
          <input v-model="form.data.requires_nik" type="checkbox" :class="checkbox" />
          <span class="flex flex-col gap-0.5">
            <span class="leading-5 font-medium">Minta NIK pemohon</span>
            <span class="text-sm text-desa-secondary">Disimpan terenkripsi, tampil tersamar.</span>
          </span>
        </label>

        <fieldset class="flex flex-col gap-3">
          <legend class="mb-3 leading-5 font-medium text-desa-secondary">
            Isian tambahan di form pengajuan
          </legend>
          <div
            v-for="(field, i) in form.data.form_schema"
            :key="i"
            class="flex flex-col gap-3 rounded-2xl border-[1.5px] border-desa-background p-4"
          >
            <div class="flex items-start gap-2">
              <input
                v-model="field.label"
                :aria-label="`Nama isian ${i + 1}`"
                placeholder="Nama isian, mis. Tanggal acara"
                maxlength="100"
                class="h-12 min-w-0 flex-1 rounded-2xl border-[1.5px] border-desa-background px-4 font-medium focus:ring-[1.5px] focus:ring-desa-dark-green focus:outline-none"
              />
              <button
                type="button"
                class="flex size-12 shrink-0 items-center justify-center rounded-2xl text-desa-secondary hover:text-desa-red"
                :aria-label="`Hapus isian ${i + 1}`"
                @click="form.data.form_schema.splice(i, 1)"
              >
                <AppIcon name="close-circle" />
              </button>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <select
                v-model="field.type"
                :aria-label="`Jenis isian ${i + 1}`"
                class="h-12 rounded-2xl border-[1.5px] border-desa-background bg-white px-3 font-medium focus:ring-[1.5px] focus:ring-desa-dark-green focus:outline-none"
              >
                <option v-for="t in typeOptions" :key="t.value" :value="t.value">
                  {{ t.label }}
                </option>
              </select>
              <label class="flex items-center gap-2 text-sm font-medium">
                <input v-model="field.required" type="checkbox" :class="checkbox" />
                Wajib diisi
              </label>
            </div>
            <input
              v-if="field.type === 'select'"
              v-model="field.optionsText"
              :aria-label="`Pilihan untuk isian ${i + 1}`"
              placeholder="Pilihan, pisahkan dengan koma"
              class="h-12 w-full rounded-2xl border-[1.5px] border-desa-background px-4 font-medium focus:ring-[1.5px] focus:ring-desa-dark-green focus:outline-none"
            />
            <p v-if="rowError('form_schema', i)" class="text-sm text-desa-red">
              {{ rowError('form_schema', i) }}
            </p>
          </div>
          <button
            v-if="form.data.form_schema.length < 10"
            type="button"
            class="flex w-fit items-center gap-2 text-sm font-medium text-desa-dark-green"
            @click="addField"
          >
            <AppIcon name="add-square" class="size-5!" />
            Tambah isian
          </button>
        </fieldset>

        <AppTextarea
          v-model="form.data.notes"
          label="Catatan untuk warga (opsional)"
          maxlength="500"
          placeholder="Mis. bawa KTP asli saat mengambil surat"
          :error="form.errors.notes"
        />

        <label class="flex items-center gap-3">
          <input v-model="form.data.is_active" type="checkbox" :class="checkbox" />
          <span class="leading-5 font-medium">Tampilkan di katalog (aktif)</span>
        </label>
      </form>

      <template #footer>
        <AppButton variant="secondary" @click="form.open = false">Batal</AppButton>
        <AppButton type="submit" form="service-form" :loading="form.submitting">Simpan</AppButton>
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
          :variant="confirm.type === 'delete' || confirm.service?.is_active ? 'danger' : 'primary'"
          :loading="confirm.submitting"
          @click="submitConfirm"
        >
          {{ confirmText.submit }}
        </AppButton>
      </template>
    </AppModal>
  </div>
</template>
