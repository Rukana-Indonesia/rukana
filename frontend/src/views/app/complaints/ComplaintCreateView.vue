<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatCoords, mapLink } from '@/lib/complaints'
import { compressImage } from '@/lib/image'

const MAX_PHOTOS = 3
const MAX_BYTES = 5 * 1024 * 1024

const router = useRouter()

const categories = ref([])
const loadError = ref('')

const form = reactive({
  category_id: '',
  title: '',
  description: '',
  location_text: '',
  occurred_at: '',
  point: null, // { latitude, longitude, accuracy }
  photos: [], // [{ id, file, preview, preparing }]
  errors: {},
  error: '',
  submitting: false,
})
// Satu kunci per form: submit ulang (sinyal putus, tombol ditekan dua kali) tidak membuat tiket ganda.
const idempotencyKey = newKey()

function newKey() {
  return crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

const categoryOptions = computed(() =>
  categories.value.map((parent) => ({
    label: parent.name,
    options: parent.children.map((child) => ({ value: child.id, label: child.name })),
  })),
)

onMounted(async () => {
  try {
    const { data } = await api.get('/complaint-categories')
    categories.value = data.data
  } catch (err) {
    loadError.value = errorMessage(err)
  }
})

// Foto ---------------------------------------------------------------------

const photosFull = computed(() => form.photos.length >= MAX_PHOTOS)
const preparing = computed(() => form.photos.some((p) => p.preparing))

async function addPhotos(event) {
  const picked = [...(event.target.files ?? [])]
  event.target.value = ''
  form.error = ''

  for (const original of picked) {
    if (photosFull.value) {
      form.error = `Maksimal ${MAX_PHOTOS} foto per laporan.`
      break
    }
    if (!original.type.startsWith('image/')) {
      form.error = `${original.name} bukan foto.`
      continue
    }
    const item = reactive({ id: newKey(), file: original, preview: '', preparing: true })
    form.photos.push(item)
    item.file = await compressImage(original)
    item.preview = URL.createObjectURL(item.file)
    item.preparing = false

    if (item.file.size > MAX_BYTES) {
      removePhoto(item.id)
      form.error = `${original.name} lebih dari 5 MB. Pilih foto lain.`
    }
  }
}

function removePhoto(id) {
  const item = form.photos.find((p) => p.id === id)
  if (item?.preview) URL.revokeObjectURL(item.preview)
  form.photos = form.photos.filter((p) => p.id !== id)
}

onBeforeUnmount(() => form.photos.forEach((p) => p.preview && URL.revokeObjectURL(p.preview)))

// Lokasi -------------------------------------------------------------------

const locating = ref(false)
const locationError = ref('')
const canLocate = 'geolocation' in navigator

function locate() {
  locating.value = true
  locationError.value = ''
  navigator.geolocation.getCurrentPosition(
    ({ coords }) => {
      form.point = {
        latitude: Number(coords.latitude.toFixed(7)),
        longitude: Number(coords.longitude.toFixed(7)),
        accuracy: Math.round(coords.accuracy),
      }
      locating.value = false
    },
    (err) => {
      locationError.value =
        err.code === err.PERMISSION_DENIED
          ? 'Izin lokasi ditolak. Tulis patokan lokasi di bawah, atau izinkan lokasi di pengaturan browser.'
          : 'Lokasi tidak ditemukan. Coba lagi di tempat terbuka, atau tulis patokan lokasi.'
      locating.value = false
    },
    { enableHighAccuracy: true, timeout: 15_000, maximumAge: 60_000 },
  )
}

// Waktu kejadian: input datetime-local (waktu HP), dikirim sebagai ISO dengan zona waktu.
const nowLocal = computed(() => {
  const d = new Date()
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 16)
})

// Kirim --------------------------------------------------------------------

async function submit() {
  form.submitting = true
  form.errors = {}
  form.error = ''

  const body = new FormData()
  body.append('category_id', form.category_id)
  body.append('title', form.title)
  body.append('description', form.description)
  body.append('idempotency_key', idempotencyKey)
  if (form.location_text) body.append('location_text', form.location_text)
  if (form.point) {
    body.append('latitude', form.point.latitude)
    body.append('longitude', form.point.longitude)
  }
  if (form.occurred_at) body.append('occurred_at', new Date(form.occurred_at).toISOString())
  form.photos.forEach((item) => body.append('photos[]', item.file))

  try {
    const { data } = await api.post('/complaints', body)
    router.push({ name: 'complaint-detail', params: { id: data.data.id }, query: { baru: 1 } })
  } catch (err) {
    form.errors = fieldErrors(err)
    const photoError = Object.entries(form.errors).find(([key]) => key.startsWith('photos'))
    form.error = photoError?.[1] ?? (Object.keys(form.errors).length ? '' : errorMessage(err))
    if (Object.keys(form.errors).length) form.error ||= 'Periksa kembali isian yang ditandai.'
  } finally {
    form.submitting = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <RouterLink
      :to="{ name: 'complaints' }"
      class="flex w-fit items-center gap-2 font-medium text-desa-secondary transition-all duration-300 hover:text-desa-dark-green"
    >
      <AppIcon name="arrow-left" />
      Kembali ke Laporan Saya
    </RouterLink>

    <AppAlert v-if="loadError">{{ loadError }}</AppAlert>

    <div class="flex flex-col gap-[14px] xl:flex-row xl:items-start">
      <form
        class="flex flex-1 flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8"
        novalidate
        @submit.prevent="submit"
      >
        <div class="flex flex-col gap-1">
          <p class="text-sm font-medium text-desa-secondary">Laporan baru</p>
          <h1 class="text-xl font-semibold sm:text-2xl">Laporkan masalah lingkungan</h1>
        </div>

        <AppSelect
          v-model="form.category_id"
          label="Kategori"
          icon="grid-5"
          placeholder="Pilih kategori"
          :options="categoryOptions"
          :error="form.errors.category_id"
        />

        <AppInput
          v-model="form.title"
          label="Judul"
          icon="note"
          maxlength="150"
          placeholder="Mis. Lampu jalan mati depan masjid"
          :error="form.errors.title"
          required
        />

        <AppTextarea
          v-model="form.description"
          label="Ceritakan masalahnya"
          rows="4"
          maxlength="2000"
          placeholder="Sejak kapan, seberapa parah, dan siapa yang terdampak."
          :error="form.errors.description"
          required
        />

        <!-- Foto: kamera langsung atau galeri. Diperkecil di HP sebelum diunggah. -->
        <fieldset class="flex flex-col gap-3">
          <legend class="mb-3 leading-5 font-medium text-desa-secondary">
            Foto (opsional, maks. {{ MAX_PHOTOS }})
          </legend>
          <div class="grid grid-cols-2 gap-3">
            <label
              v-for="source in [
                { icon: 'camera', label: 'Ambil Foto', capture: 'environment' },
                { icon: 'gallery', label: 'Dari Galeri', capture: undefined },
              ]"
              :key="source.label"
              :class="[
                'flex h-14 items-center justify-center gap-2 rounded-2xl border-[1.5px] font-medium transition-all duration-300',
                photosFull
                  ? 'cursor-not-allowed border-desa-background text-desa-grey'
                  : 'cursor-pointer border-desa-background text-desa-dark-green focus-within:outline-2 focus-within:outline-desa-dark-green hover:border-desa-dark-green',
              ]"
            >
              <AppIcon :name="source.icon" />
              {{ source.label }}
              <input
                type="file"
                class="sr-only"
                accept="image/*"
                :capture="source.capture"
                :multiple="!source.capture"
                :disabled="photosFull"
                @change="addPhotos"
              />
            </label>
          </div>
          <ul v-if="form.photos.length" class="grid grid-cols-3 gap-3">
            <li
              v-for="item in form.photos"
              :key="item.id"
              class="relative aspect-square overflow-hidden rounded-2xl bg-desa-background"
            >
              <img
                v-if="item.preview"
                :src="item.preview"
                alt="Pratinjau foto"
                class="size-full object-cover"
              />
              <span
                v-else
                class="flex size-full items-center justify-center text-sm text-desa-secondary"
                >Menyiapkan…</span
              >
              <button
                type="button"
                class="absolute top-2 right-2 flex size-8 items-center justify-center rounded-full bg-white/90 text-desa-secondary hover:text-desa-red"
                aria-label="Hapus foto"
                @click="removePhoto(item.id)"
              >
                <AppIcon name="close-circle" class="size-5!" />
              </button>
            </li>
          </ul>
        </fieldset>

        <!-- Lokasi: titik dari GPS browser dan/atau patokan yang diketik. -->
        <fieldset class="flex flex-col gap-3">
          <legend class="mb-3 leading-5 font-medium text-desa-secondary">Lokasi</legend>
          <div
            v-if="form.point"
            class="flex items-center gap-3 rounded-2xl bg-desa-foreshadow px-4 py-3"
          >
            <AppIcon name="location" class="text-desa-dark-green" />
            <div class="flex min-w-0 flex-1 flex-col">
              <span class="leading-5 font-semibold">Titik lokasi tersimpan</span>
              <a
                :href="mapLink(form.point)"
                target="_blank"
                rel="noopener"
                class="truncate text-sm text-desa-dark-green underline-offset-4 hover:underline"
                >{{ formatCoords(form.point) }} · akurasi ± {{ form.point.accuracy }} m</a
              >
            </div>
            <button
              type="button"
              class="flex size-9 shrink-0 items-center justify-center rounded-lg text-desa-secondary hover:text-desa-red"
              aria-label="Hapus titik lokasi"
              @click="form.point = null"
            >
              <AppIcon name="close-circle" class="size-5!" />
            </button>
          </div>
          <AppButton v-else-if="canLocate" variant="secondary" :loading="locating" @click="locate">
            <AppIcon name="location" />
            Gunakan lokasi saya
          </AppButton>
          <AppAlert v-if="locationError" variant="warning">{{ locationError }}</AppAlert>
          <AppInput
            v-model="form.location_text"
            :label="form.point ? 'Patokan lokasi (opsional)' : 'Patokan lokasi'"
            icon="building-4"
            maxlength="255"
            placeholder="Mis. Gang Melati, dekat pos ronda"
            :error="form.errors.location_text || form.errors.latitude"
          />
        </fieldset>

        <AppInput
          v-model="form.occurred_at"
          label="Waktu kejadian (opsional)"
          icon="calendar-2"
          type="datetime-local"
          :max="nowLocal"
          :error="form.errors.occurred_at"
        />

        <AppAlert v-if="form.error">{{ form.error }}</AppAlert>

        <AppButton type="submit" :loading="form.submitting" :disabled="preparing" block>
          <AppIcon name="ticket" />
          Kirim Laporan
        </AppButton>
      </form>

      <aside
        class="flex flex-col gap-5 rounded-2xl bg-white p-6 xl:sticky xl:top-[120px] xl:w-[340px]"
      >
        <div class="flex flex-col gap-3">
          <h2 class="font-semibold">Alur penanganan</h2>
          <ol class="flex flex-col gap-2 leading-5 text-desa-secondary">
            <li>1. Laporan masuk ke ketua RT Anda.</li>
            <li>2. RT memeriksa, lalu menangani sendiri atau meneruskan ke RW/desa.</li>
            <li>3. Anda bisa memantau status dan membaca tanggapan pengurus di sini.</li>
          </ol>
        </div>
        <AppAlert variant="info">
          Nama dan nomor HP Anda hanya terlihat oleh pengurus yang menangani laporan ini, tidak oleh
          warga lain.
        </AppAlert>
      </aside>
    </div>
  </div>
</template>
