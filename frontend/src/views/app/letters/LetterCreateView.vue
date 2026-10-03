<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { compressImage } from '@/lib/image'
import { estimateLabel, formatSize } from '@/lib/letters'

const MAX_FILES = 5
const MAX_BYTES = 5 * 1024 * 1024

const route = useRoute()
const router = useRouter()

const services = ref([])
const loading = ref(true)
const loadError = ref('')

const service = computed(() => services.value.find((s) => s.slug === route.params.slug) ?? null)

const form = reactive({
  purpose: '',
  nik: '',
  fields: {},
  files: [], // [{ id, label, file, preparing }]
  errors: {},
  error: '',
  submitting: false,
})
// Satu kunci per form: submit ulang (sinyal putus, tombol ditekan dua kali) tidak membuat tiket ganda.
let idempotencyKey = newKey()

function newKey() {
  return crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function resetForm() {
  Object.assign(form, { purpose: '', nik: '', fields: {}, files: [], errors: {}, error: '' })
  for (const field of service.value?.form_schema ?? []) form.fields[field.key] = ''
  idempotencyKey = newKey()
}

watch(service, resetForm)

onMounted(async () => {
  try {
    const { data } = await api.get('/services')
    services.value = data.data
    resetForm()
  } catch (err) {
    loadError.value = errorMessage(err)
  } finally {
    loading.value = false
  }
})

const filesFull = computed(() => form.files.length >= MAX_FILES)
const preparing = computed(() => form.files.some((f) => f.preparing))

function filesFor(label) {
  return form.files.filter((f) => f.label === label)
}

async function addFiles(label, event) {
  const picked = [...(event.target.files ?? [])]
  event.target.value = ''
  form.error = ''

  for (const original of picked) {
    if (filesFull.value) {
      form.error = `Maksimal ${MAX_FILES} lampiran per pengajuan.`
      break
    }
    const item = reactive({ id: newKey(), label, file: original, preparing: true })
    form.files.push(item)
    item.file = await compressImage(original)
    item.preparing = false

    if (item.file.size > MAX_BYTES) {
      removeFile(item.id)
      form.error = `${original.name} lebih dari 5 MB. Pilih berkas yang lebih kecil.`
    }
  }
}

function removeFile(id) {
  form.files = form.files.filter((f) => f.id !== id)
}

function fieldOptions(field) {
  return field.options.map((option) => ({ value: option, label: option }))
}

async function submit() {
  form.submitting = true
  form.errors = {}
  form.error = ''

  const body = new FormData()
  body.append('service_type_id', service.value.id)
  body.append('purpose', form.purpose)
  body.append('idempotency_key', idempotencyKey)
  if (service.value.requires_nik) body.append('nik', form.nik)
  for (const [key, value] of Object.entries(form.fields)) {
    if (value !== '' && value !== null) body.append(`form_data[${key}]`, value)
  }
  form.files.forEach((item, i) => {
    body.append(`attachments[${i}][file]`, item.file)
    if (item.label) body.append(`attachments[${i}][label]`, item.label)
  })

  try {
    const { data } = await api.post('/letters', body)
    router.push({ name: 'letter-detail', params: { id: data.data.id }, query: { baru: 1 } })
  } catch (err) {
    form.errors = fieldErrors(err)
    const fileError = Object.entries(form.errors).find(([key]) => key.startsWith('attachments'))
    form.error = fileError?.[1] ?? (Object.keys(form.errors).length ? '' : errorMessage(err))
    if (Object.keys(form.errors).length) {
      form.error ||= 'Periksa kembali isian yang ditandai.'
    }
  } finally {
    form.submitting = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <RouterLink
      :to="service ? { name: 'letter-create' } : { name: 'letters' }"
      class="flex w-fit items-center gap-2 font-medium text-desa-secondary transition-all duration-300 hover:text-desa-dark-green"
    >
      <AppIcon name="arrow-left" />
      {{ service ? 'Pilih layanan lain' : 'Kembali ke Surat Saya' }}
    </RouterLink>

    <AppAlert v-if="loadError">{{ loadError }}</AppAlert>
    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat layanan…
    </div>

    <!-- Langkah 1: pilih layanan. -->
    <template v-else-if="!route.params.slug">
      <h1 class="text-xl font-semibold">Pilih layanan surat</h1>
      <ul class="grid gap-[14px] md:grid-cols-2">
        <li v-for="item in services" :key="item.id">
          <RouterLink
            :to="{ name: 'letter-create', params: { slug: item.slug } }"
            class="flex h-full flex-col gap-3 rounded-2xl bg-white p-6 ring-desa-dark-green transition-all duration-300 hover:ring-[1.5px]"
          >
            <div class="flex items-start gap-3">
              <span
                class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow"
              >
                <AppIcon name="document-text" class="text-desa-dark-green" />
              </span>
              <div class="flex min-w-0 flex-col gap-1">
                <p class="leading-5 font-semibold">{{ item.name }}</p>
                <p class="text-sm leading-5 text-desa-secondary">{{ item.purpose }}</p>
              </div>
            </div>
            <p class="mt-auto text-sm font-medium text-desa-secondary">
              {{ item.flow_label }} · {{ estimateLabel(item.estimated_days) }}
            </p>
          </RouterLink>
        </li>
      </ul>
    </template>

    <div
      v-else-if="!service"
      class="flex flex-col items-center gap-3 rounded-2xl bg-white px-6 py-12 text-center"
    >
      <p class="text-lg font-semibold">Layanan tidak ditemukan</p>
      <p class="max-w-sm leading-5 font-medium text-desa-secondary">
        Layanan ini mungkin sedang tidak dibuka. Pilih layanan lain dari katalog.
      </p>
    </div>

    <!-- Langkah 2: isi form. -->
    <div v-else class="flex flex-col gap-[14px] xl:flex-row xl:items-start">
      <form
        class="flex flex-1 flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8"
        novalidate
        @submit.prevent="submit"
      >
        <div class="flex flex-col gap-1">
          <p class="text-sm font-medium text-desa-secondary">Pengajuan baru</p>
          <h1 class="text-xl font-semibold sm:text-2xl">{{ service.name }}</h1>
        </div>

        <AppTextarea
          v-model="form.purpose"
          label="Keperluan"
          placeholder="Mis. persyaratan pendaftaran sekolah anak"
          maxlength="500"
          :error="form.errors.purpose"
          required
        />

        <AppInput
          v-if="service.requires_nik"
          v-model="form.nik"
          label="NIK (sesuai KTP)"
          icon="user-square"
          inputmode="numeric"
          autocomplete="off"
          maxlength="19"
          placeholder="16 digit angka"
          hint="Disimpan terenkripsi dan hanya ditampilkan tersamar, mis. 3204********0001."
          :error="form.errors.nik"
          required
        />

        <template v-for="field in service.form_schema" :key="field.key">
          <AppTextarea
            v-if="field.type === 'textarea'"
            v-model="form.fields[field.key]"
            :label="field.required ? field.label : `${field.label} (opsional)`"
            maxlength="1000"
            :error="form.errors[`form_data.${field.key}`]"
          />
          <AppSelect
            v-else-if="field.type === 'select'"
            v-model="form.fields[field.key]"
            :label="field.required ? field.label : `${field.label} (opsional)`"
            :options="fieldOptions(field)"
            :error="form.errors[`form_data.${field.key}`]"
          />
          <AppInput
            v-else
            v-model="form.fields[field.key]"
            :label="field.required ? field.label : `${field.label} (opsional)`"
            :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
            :inputmode="field.type === 'number' ? 'numeric' : undefined"
            :maxlength="field.type === 'text' ? 255 : undefined"
            :error="form.errors[`form_data.${field.key}`]"
          />
        </template>

        <fieldset v-if="service.requirements.length" class="flex flex-col gap-3">
          <legend class="mb-3 leading-5 font-medium text-desa-secondary">
            Lampiran (opsional, foto atau PDF, maks. {{ MAX_FILES }} berkas)
          </legend>
          <div
            v-for="req in service.requirements"
            :key="req"
            class="flex flex-col gap-3 rounded-2xl border-[1.5px] border-desa-background p-4"
          >
            <div class="flex items-center justify-between gap-3">
              <p class="leading-5 font-semibold">{{ req }}</p>
              <label
                :class="[
                  'flex h-11 shrink-0 items-center gap-2 rounded-2xl px-3 text-sm font-medium transition-all duration-300',
                  filesFull
                    ? 'cursor-not-allowed text-desa-grey'
                    : 'cursor-pointer text-desa-dark-green hover:bg-desa-foreshadow focus-within:outline-2 focus-within:outline-desa-dark-green',
                ]"
              >
                <AppIcon name="add-square" class="size-5!" />
                Unggah
                <input
                  type="file"
                  class="sr-only"
                  accept="image/*,application/pdf"
                  :disabled="filesFull"
                  multiple
                  @change="addFiles(req, $event)"
                />
              </label>
            </div>
            <ul v-if="filesFor(req).length" class="flex flex-col gap-2">
              <li
                v-for="item in filesFor(req)"
                :key="item.id"
                class="flex items-center gap-3 rounded-xl bg-desa-background/60 px-3 py-2"
              >
                <AppIcon name="document-text" class="size-5! text-desa-secondary" />
                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{
                  item.file.name
                }}</span>
                <span class="shrink-0 text-xs text-desa-secondary">{{
                  item.preparing ? 'Menyiapkan…' : formatSize(item.file.size)
                }}</span>
                <button
                  type="button"
                  class="flex size-8 shrink-0 items-center justify-center rounded-lg text-desa-secondary hover:text-desa-red"
                  :aria-label="`Hapus ${item.file.name}`"
                  @click="removeFile(item.id)"
                >
                  <AppIcon name="close-circle" class="size-5!" />
                </button>
              </li>
            </ul>
          </div>
        </fieldset>

        <AppAlert v-if="form.error">{{ form.error }}</AppAlert>

        <AppButton type="submit" :loading="form.submitting" :disabled="preparing" block>
          <AppIcon name="ticket" />
          Kirim Pengajuan
        </AppButton>
      </form>

      <aside
        class="flex flex-col gap-5 rounded-2xl bg-white p-6 xl:sticky xl:top-[120px] xl:w-[340px]"
      >
        <div class="flex flex-col gap-3">
          <h2 class="font-semibold">Persyaratan</h2>
          <ul v-if="service.requirements.length" class="flex flex-col gap-2">
            <li
              v-for="req in service.requirements"
              :key="req"
              class="flex items-start gap-2 leading-5"
            >
              <AppIcon name="tick-circle" class="size-5! text-desa-soft-green" />
              {{ req }}
            </li>
          </ul>
          <p v-else class="text-desa-secondary">Tidak ada dokumen khusus.</p>
        </div>
        <div class="flex flex-col gap-2">
          <h2 class="font-semibold">Alur</h2>
          <p class="leading-5 text-desa-secondary">
            Diajukan → {{ service.flow_levels.join(' → ') }}
          </p>
          <p class="text-sm text-desa-secondary">
            Estimasi {{ estimateLabel(service.estimated_days) }}
          </p>
        </div>
        <AppAlert v-if="service.notes" variant="info">{{ service.notes }}</AppAlert>
      </aside>
    </div>
  </div>
</template>
