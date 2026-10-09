<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { fromLocalInput, targetLevelOptions, toLocalInput } from '@/lib/announcements'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { compressImage } from '@/lib/image'
import { formatSize } from '@/lib/letters'
import { useAuthStore } from '@/stores/auth'

// Buat dan ubah pengumuman. Target RT/RW selalu wilayah akun sendiri (ditentukan
// backend); hanya operator yang memilih target.
const MAX_FILES = 3

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const editId = computed(() => route.params.id ?? null)
const isOperator = computed(() => auth.hasRole('operator'))
const ownTarget = computed(() =>
  auth.hasRole('rt')
    ? `${auth.user?.rt?.label}/${auth.user?.rw?.label}`
    : auth.hasRole('rw')
      ? auth.user?.rw?.label
      : '',
)

const form = reactive({
  title: '',
  body: '',
  target_level: 'village',
  target_rw_id: '',
  target_rt_id: '',
  schedule: 'now', // now | later
  published_at: '',
  expires_on: '',
})
const original = ref(null) // pengumuman yang sedang diubah
const existing = ref([]) // lampiran lama: { id, name, size_bytes, removed }
const newFiles = ref([]) // { id, file, preparing }
const rws = ref([])
const loading = ref(true)
const submitting = ref(false)
const errors = ref({})
const error = ref('')

const alreadyPublished = computed(() => original.value && original.value.state !== 'scheduled')
const fileCount = computed(
  () => existing.value.filter((f) => !f.removed).length + newFiles.value.length,
)
const filesFull = computed(() => fileCount.value >= MAX_FILES)
const rwOptions = computed(() => rws.value.map((rw) => ({ value: rw.id, label: rw.label })))
const rtOptions = computed(() =>
  rws.value.map((rw) => ({
    label: rw.label,
    options: rw.rts.map((rt) => ({ value: rt.id, label: `${rt.label} / ${rw.label}` })),
  })),
)
const attachmentError = computed(
  () =>
    errors.value.attachments ??
    Object.entries(errors.value).find(([key]) => key.startsWith('attachments.'))?.[1] ??
    '',
)
const minDateTime = toLocalInput(new Date().toISOString())
const today = minDateTime.slice(0, 10)

async function load() {
  error.value = ''
  try {
    const [regions, current] = await Promise.all([
      isOperator.value ? api.get('/regions') : Promise.resolve(null),
      editId.value ? api.get(`/announcements/${editId.value}`) : Promise.resolve(null),
    ])
    rws.value = regions?.data.data.rws ?? []

    if (current) {
      const a = current.data.data
      if (!a.can.manage) {
        error.value = 'Anda tidak bisa mengubah pengumuman ini.'
        return
      }
      original.value = a
      Object.assign(form, {
        title: a.title,
        body: a.body,
        target_level: a.target.level,
        target_rw_id: a.target.level === 'rw' ? a.target.rw_id : '',
        target_rt_id: a.target.level === 'rt' ? a.target.rt_id : '',
        schedule: a.state === 'scheduled' ? 'later' : 'now',
        published_at: a.state === 'scheduled' ? toLocalInput(a.published_at) : '',
        expires_on: a.expires_at ? toLocalInput(a.expires_at).slice(0, 10) : '',
      })
      existing.value = a.attachments.map((f) => ({ ...f, removed: false }))
    }
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)

let nextId = 0
async function addFiles(event) {
  const picked = [...event.target.files].slice(0, MAX_FILES - fileCount.value)
  event.target.value = ''
  for (const file of picked) {
    const item = reactive({ id: ++nextId, file, preparing: true })
    newFiles.value.push(item)
    item.file = await compressImage(file)
    item.preparing = false
  }
}

function removeNew(id) {
  newFiles.value = newFiles.value.filter((f) => f.id !== id)
}

async function submit() {
  errors.value = {}
  error.value = ''
  if (form.schedule === 'later' && !form.published_at) {
    errors.value = { published_at: 'Pilih tanggal dan jam terbit.' }
    return
  }

  const data = new FormData()
  data.append('title', form.title)
  data.append('body', form.body)
  if (isOperator.value) {
    data.append('target_level', form.target_level)
    if (form.target_level === 'rw') data.append('target_rw_id', form.target_rw_id)
    if (form.target_level === 'rt') data.append('target_rt_id', form.target_rt_id)
  }
  if (!alreadyPublished.value) {
    // "Terbitkan sekarang" saat mengubah pengumuman terjadwal = majukan ke sekarang.
    const publish = form.schedule === 'later' ? fromLocalInput(form.published_at) : ''
    if (publish || original.value) data.append('published_at', publish || new Date().toISOString())
  }
  data.append('expires_at', fromLocalInput(form.expires_on, true))
  newFiles.value.forEach((item) => data.append('attachments[]', item.file))
  existing.value.filter((f) => f.removed).forEach((f) => data.append('remove_attachments[]', f.id))
  if (editId.value) data.append('_method', 'PUT')

  submitting.value = true
  try {
    const { data: res } = await api.post(
      editId.value ? `/announcements/${editId.value}` : '/announcements',
      data,
    )
    router.push({
      name: 'announcement-detail',
      params: { id: res.data.id },
      state: { notice: res.message },
    })
  } catch (err) {
    errors.value = fieldErrors(err)
    if (!Object.keys(errors.value).length) error.value = errorMessage(err)
    else window.scrollTo({ top: 0, behavior: 'smooth' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <RouterLink
      :to="
        editId
          ? { name: 'announcement-detail', params: { id: editId } }
          : { name: 'announcements', query: { tab: 'kelola' } }
      "
      class="flex w-fit items-center gap-2 font-medium text-desa-secondary transition-all duration-300 hover:text-desa-dark-green"
    >
      <AppIcon name="arrow-left" />
      {{ editId ? 'Kembali ke pengumuman' : 'Pengumuman' }}
    </RouterLink>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat…
    </div>
    <AppAlert v-else-if="error && !original && editId">{{ error }}</AppAlert>

    <form
      v-else
      class="flex flex-col gap-[14px] xl:flex-row xl:items-start"
      novalidate
      @submit.prevent="submit"
    >
      <section class="flex min-w-0 flex-1 flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8">
        <div class="flex flex-col gap-1">
          <h1 class="text-xl font-semibold">
            {{ editId ? 'Ubah pengumuman' : 'Pengumuman baru' }}
          </h1>
          <p class="leading-5 text-desa-secondary">
            Tulis singkat dan jelas: apa, kapan, di mana. Setelah terbit, warga di wilayah target
            mendapat notifikasi.
          </p>
        </div>

        <AppAlert v-if="error">{{ error }}</AppAlert>

        <AppInput
          v-model="form.title"
          label="Judul"
          maxlength="150"
          placeholder="Mis. Posyandu Balita RW 04"
          :error="errors.title"
        />
        <AppTextarea
          v-model="form.body"
          label="Isi pengumuman"
          rows="8"
          maxlength="5000"
          placeholder="Mis. Sabtu, 3 Oktober pukul 08.00 di Balai RW 04. Bawa buku KIA."
          :hint="`${form.body.length}/5000 karakter`"
          :error="errors.body"
        />

        <div class="flex flex-col gap-3">
          <p class="leading-5 font-medium text-desa-secondary">Lampiran (opsional)</p>
          <ul v-if="existing.length || newFiles.length" class="flex flex-col gap-2">
            <li
              v-for="file in existing"
              :key="`old-${file.id}`"
              :class="[
                'flex items-center gap-3 rounded-xl px-3 py-2',
                file.removed ? 'bg-desa-red/5' : 'bg-desa-background/60',
              ]"
            >
              <AppIcon
                :name="file.is_image ? 'gallery' : 'document-text'"
                class="size-5! text-desa-secondary"
              />
              <span
                :class="[
                  'min-w-0 flex-1 truncate text-sm font-medium',
                  file.removed && 'text-desa-secondary line-through',
                ]"
                >{{ file.name }}</span
              >
              <span class="shrink-0 text-xs text-desa-secondary">{{
                file.removed ? 'Akan dihapus' : formatSize(file.size_bytes)
              }}</span>
              <button
                type="button"
                class="shrink-0 rounded-lg px-2 py-1 text-sm font-medium text-desa-secondary hover:text-desa-red"
                :disabled="file.removed && filesFull"
                @click="file.removed = !file.removed"
              >
                {{ file.removed ? 'Batal' : 'Hapus' }}
              </button>
            </li>
            <li
              v-for="item in newFiles"
              :key="item.id"
              class="flex items-center gap-3 rounded-xl bg-desa-background/60 px-3 py-2"
            >
              <AppIcon name="add-square" class="size-5! text-desa-dark-green" />
              <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ item.file.name }}</span>
              <span class="shrink-0 text-xs text-desa-secondary">{{
                item.preparing ? 'Menyiapkan…' : formatSize(item.file.size)
              }}</span>
              <button
                type="button"
                class="flex size-8 shrink-0 items-center justify-center rounded-lg text-desa-secondary hover:text-desa-red"
                :aria-label="`Hapus ${item.file.name}`"
                @click="removeNew(item.id)"
              >
                <AppIcon name="close-circle" class="size-5!" />
              </button>
            </li>
          </ul>
          <label
            :class="[
              'flex min-h-14 items-center justify-center gap-2 rounded-2xl border-[1.5px] border-dashed px-4 font-medium transition-all duration-300',
              filesFull
                ? 'cursor-not-allowed border-desa-background text-desa-grey'
                : 'cursor-pointer border-desa-background text-desa-dark-green hover:border-desa-dark-green focus-within:outline-2 focus-within:outline-desa-dark-green',
            ]"
          >
            <AppIcon name="gallery" />
            {{ filesFull ? `Maksimal ${MAX_FILES} lampiran` : 'Tambah poster, foto, atau PDF' }}
            <input
              type="file"
              class="sr-only"
              accept="image/*,application/pdf"
              multiple
              :disabled="filesFull"
              @change="addFiles"
            />
          </label>
          <p v-if="attachmentError" class="-mt-1 text-sm font-medium text-desa-red">
            {{ attachmentError }}
          </p>
          <p v-else class="-mt-1 text-sm text-desa-secondary">
            Foto otomatis diperkecil. Maksimal {{ MAX_FILES }} berkas, 5 MB per berkas.
          </p>
        </div>
      </section>

      <aside class="flex flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8 xl:w-[380px]">
        <div class="flex flex-col gap-4">
          <h2 class="text-lg font-semibold">Target</h2>
          <template v-if="isOperator">
            <AppSelect
              v-model="form.target_level"
              label="Untuk"
              icon="location"
              :options="targetLevelOptions"
              :error="errors.target_level"
            />
            <AppSelect
              v-if="form.target_level === 'rw'"
              v-model="form.target_rw_id"
              label="RW tujuan"
              placeholder="Pilih RW"
              :options="rwOptions"
              :error="errors.target_rw_id"
            />
            <AppSelect
              v-if="form.target_level === 'rt'"
              v-model="form.target_rt_id"
              label="RT tujuan"
              placeholder="Pilih RT"
              :options="rtOptions"
              :error="errors.target_rt_id"
            />
          </template>
          <div v-else class="flex items-center gap-3 rounded-2xl bg-desa-foreshadow p-4">
            <AppIcon name="location" class="text-desa-dark-green" />
            <p class="leading-5">
              Untuk warga <strong class="text-desa-dark-green">{{ ownTarget }}</strong>
            </p>
          </div>
        </div>

        <div class="flex flex-col gap-4 border-t border-desa-background pt-6">
          <h2 class="text-lg font-semibold">Jadwal</h2>
          <p v-if="alreadyPublished" class="leading-5 text-desa-secondary">
            Sudah terbit {{ formatDate(original.published_at, true) }}.
          </p>
          <fieldset v-else class="flex flex-col gap-2">
            <legend class="sr-only">Waktu terbit</legend>
            <label
              v-for="opt in [
                { value: 'now', label: 'Terbitkan sekarang' },
                { value: 'later', label: 'Jadwalkan' },
              ]"
              :key="opt.value"
              :class="[
                'flex h-14 cursor-pointer items-center gap-3 rounded-2xl border-[1.5px] px-4 font-medium transition-all duration-300 has-focus-visible:outline-2 has-focus-visible:outline-desa-dark-green',
                form.schedule === opt.value
                  ? 'border-desa-dark-green bg-desa-foreshadow text-desa-dark-green'
                  : 'border-desa-background text-desa-secondary',
              ]"
            >
              <input v-model="form.schedule" type="radio" :value="opt.value" class="sr-only" />
              <span
                :class="[
                  'flex size-5 items-center justify-center rounded-full border-2',
                  form.schedule === opt.value ? 'border-desa-dark-green' : 'border-desa-grey',
                ]"
              >
                <span
                  v-if="form.schedule === opt.value"
                  class="size-2.5 rounded-full bg-desa-dark-green"
                />
              </span>
              {{ opt.label }}
            </label>
          </fieldset>
          <AppInput
            v-if="!alreadyPublished && form.schedule === 'later'"
            v-model="form.published_at"
            label="Terbit pada"
            type="datetime-local"
            :min="minDateTime"
            :error="errors.published_at"
          />
          <AppInput
            v-model="form.expires_on"
            label="Berlaku sampai (opsional)"
            type="date"
            :min="today"
            hint="Kosongkan bila tidak ada batas. Setelah tanggal ini, pengumuman tidak tampil lagi."
            :error="errors.expires_at"
          />
        </div>

        <AppButton type="submit" block :loading="submitting">
          <AppIcon name="tick-circle" />
          {{
            editId
              ? 'Simpan perubahan'
              : form.schedule === 'later'
                ? 'Jadwalkan pengumuman'
                : 'Terbitkan pengumuman'
          }}
        </AppButton>
      </aside>
    </form>
  </div>
</template>
