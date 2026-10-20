<script setup>
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

// Leaflet hanya dimuat di halaman yang memang menampilkan peta.
const CenterPicker = defineAsyncComponent(() => import('@/components/map/CenterPicker.vue'))

// Titik tengah peta desa dipakai Peta Laporan dan form laporan warga. Operator
// mengubah, kepala desa hanya melihat (backend tetap memeriksa).
const auth = useAuthStore()

const village = ref(null)
const center = ref(null) // { latitude, longitude, zoom }
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const errors = ref({})
const notice = ref('')
const locating = ref(false)
const picker = ref(null)

const canUpdate = computed(() => village.value?.can_update ?? false)
const saved = computed(() => village.value?.map_center)
const dirty = computed(
  () =>
    center.value &&
    saved.value &&
    (center.value.latitude !== saved.value.latitude ||
      center.value.longitude !== saved.value.longitude ||
      center.value.zoom !== saved.value.zoom),
)
const coords = computed(() =>
  center.value ? `${center.value.latitude.toFixed(6)}, ${center.value.longitude.toFixed(6)}` : '',
)

function apply(data) {
  village.value = data
  const { latitude, longitude, zoom } = data.map_center
  center.value = { latitude, longitude, zoom }
}

onMounted(async () => {
  try {
    const { data } = await api.get('/admin/village')
    apply(data.data)
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
})

const canLocate = 'geolocation' in navigator

function locate() {
  locating.value = true
  error.value = ''
  navigator.geolocation.getCurrentPosition(
    ({ coords: c }) => {
      center.value = {
        ...center.value,
        latitude: Number(c.latitude.toFixed(7)),
        longitude: Number(c.longitude.toFixed(7)),
      }
      picker.value?.focus()
      locating.value = false
    },
    () => {
      error.value =
        'Lokasi tidak bisa dibaca. Izinkan akses lokasi di browser, atau pilih titik langsung di peta.'
      locating.value = false
    },
    { enableHighAccuracy: true, timeout: 15000 },
  )
}

async function save() {
  saving.value = true
  error.value = ''
  errors.value = {}
  try {
    const { data } = await api.put('/admin/village', {
      map_latitude: center.value.latitude,
      map_longitude: center.value.longitude,
      map_zoom: center.value.zoom,
    })
    apply(data.data)
    notice.value = data.message
    // Form laporan memakai titik ini lewat profil akun; perbarui tanpa memuat ulang.
    if (auth.user?.village) auth.user.village.map_center = data.data.map_center
  } catch (err) {
    errors.value = fieldErrors(err)
    error.value = Object.values(errors.value)[0] ?? errorMessage(err)
  } finally {
    saving.value = false
  }
}

function reset() {
  const { latitude, longitude, zoom } = saved.value
  center.value = { latitude, longitude, zoom }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <AppAlert v-if="error">{{ error }}</AppAlert>
    <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat pengaturan…
    </div>

    <template v-else-if="village">
      <section class="flex flex-col gap-1 rounded-2xl bg-white p-6 sm:p-8">
        <p class="text-sm font-medium text-desa-secondary">Desa</p>
        <h1 class="text-xl font-semibold sm:text-2xl">{{ village.name }}</h1>
        <p class="text-desa-secondary">
          {{
            [village.district && `Kec. ${village.district}`, village.regency]
              .filter(Boolean)
              .join(', ')
          }}
        </p>
      </section>

      <section class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex min-w-0 flex-col gap-1">
            <h2 class="text-lg font-semibold">Titik tengah peta</h2>
            <p class="leading-relaxed text-desa-secondary">
              Biasanya balai atau kantor desa. Dipakai sebagai posisi awal Peta Laporan dan peta di
              form laporan warga.
            </p>
          </div>
          <AppBadge :variant="saved.configured ? 'success' : 'warning'">
            {{ saved.configured ? 'Sudah diatur' : 'Belum diatur' }}
          </AppBadge>
        </div>

        <AppAlert v-if="!saved.configured" variant="warning">
          Peta masih memakai titik contoh.
          {{
            canUpdate
              ? 'Geser penanda ke balai desa, lalu simpan.'
              : 'Minta operator desa mengaturnya.'
          }}
        </AppAlert>

        <CenterPicker ref="picker" v-model="center" :readonly="!canUpdate" />

        <dl class="grid gap-4 sm:grid-cols-2">
          <div class="flex flex-col gap-1">
            <dt class="text-sm font-medium text-desa-secondary">Koordinat</dt>
            <dd class="font-semibold tracking-wide">{{ coords }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-sm font-medium text-desa-secondary">Perbesaran awal</dt>
            <dd class="font-semibold">{{ center.zoom }}</dd>
          </div>
        </dl>

        <p v-if="canUpdate" class="text-sm text-desa-secondary">
          Ketuk peta atau geser penanda untuk memindah titik. Perbesar atau perkecil peta sampai
          seluruh wilayah desa terlihat; perbesaran itu ikut disimpan.
        </p>

        <div v-if="canUpdate" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
          <AppButton :disabled="!dirty" :loading="saving" @click="save">
            <AppIcon name="tick-circle" />
            Simpan Titik
          </AppButton>
          <AppButton v-if="canLocate" variant="secondary" :loading="locating" @click="locate">
            <AppIcon name="location" />
            Gunakan lokasi saya
          </AppButton>
          <AppButton v-if="dirty" variant="ghost" @click="reset">Batalkan perubahan</AppButton>
        </div>
      </section>
    </template>
  </div>
</template>
