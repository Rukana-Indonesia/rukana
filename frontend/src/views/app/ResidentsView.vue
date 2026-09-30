<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'

import TemporaryPasswordDialog from '@/components/TemporaryPasswordDialog.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatDate, formatPhone, initials, residencyLabels } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

// Ketua RT melihat warga RT-nya; operator seluruh desa (dibatasi backend, bukan di sini).
const auth = useAuthStore()
const isOperator = computed(() => auth.hasRole('operator'))

const tabs = [
  { status: 'pending', label: 'Menunggu' },
  { status: 'active', label: 'Aktif' },
  { status: 'rejected', label: 'Ditolak' },
  { status: 'suspended', label: 'Dinonaktifkan' },
]
const statusBadge = {
  pending: 'warning',
  active: 'success',
  rejected: 'danger',
  suspended: 'neutral',
}
const rejectReasons = ['Bukan warga RT ini', 'Data tidak sesuai', 'Akun ganda']

const filters = reactive({ status: 'pending', search: '', rt_id: 0 })
const residents = ref([])
const counts = ref({})
const page = ref({ current: 1, last: 1 })
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
const notice = ref('')
const rtOptions = ref([])

const visibleTabs = computed(() =>
  tabs.filter((t) => t.status !== 'suspended' || counts.value.suspended > 0),
)
const intro = computed(() =>
  isOperator.value
    ? 'Verifikasi pendaftaran warga seluruh desa dan bantu reset kata sandi warga yang lupa.'
    : `Pastikan setiap pendaftar benar tinggal di ${auth.user?.rt?.label}/${auth.user?.rw?.label} sebelum memverifikasi akunnya.`,
)

async function load(more = false) {
  if (more) loadingMore.value = true
  else loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/residents', {
      params: {
        status: filters.status,
        search: filters.search || undefined,
        rt_id: filters.rt_id || undefined,
        page: more ? page.value.current + 1 : 1,
      },
    })
    residents.value = more ? [...residents.value, ...data.data] : data.data
    counts.value = data.meta.counts
    page.value = { current: data.meta.current_page, last: data.meta.last_page }
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

let searchTimer
let noticeTimer
watch(
  () => filters.search,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => load(), 400)
  },
)
watch(
  () => [filters.status, filters.rt_id],
  () => load(),
)
onBeforeUnmount(() => {
  clearTimeout(searchTimer)
  clearTimeout(noticeTimer)
})

onMounted(async () => {
  load()
  if (!isOperator.value) return
  try {
    const { data } = await api.get('/regions')
    rtOptions.value = [
      { value: 0, label: 'Semua RT' },
      ...data.data.rws.flatMap((rw) =>
        rw.rts.map((rt) => ({ value: rt.id, label: `${rt.label} / ${rw.label}` })),
      ),
    ]
  } catch {
    // Filter RT opsional; daftar tetap bisa dipakai tanpanya.
  }
})

function notify(message) {
  notice.value = message
  clearTimeout(noticeTimer)
  noticeTimer = setTimeout(() => (notice.value = ''), 5000)
}

// Satu dialog untuk verifikasi, tolak, dan reset kata sandi.
const dialog = reactive({
  open: false,
  type: 'verify',
  user: null,
  reason: '',
  error: '',
  reasonError: '',
  submitting: false,
})
const temp = reactive({ open: false, user: null, password: '' })

const dialogText = computed(() => {
  const u = dialog.user
  const region = `${u?.rt?.label}/${u?.rw?.label}`
  return {
    verify: {
      title: 'Verifikasi Akun Warga',
      description: `Pastikan ${u?.name} benar tinggal di ${region}. Setelah diverifikasi, akunnya bisa dipakai untuk mengajukan surat dan laporan.`,
      submit: 'Ya, Verifikasi',
    },
    reject: {
      title: 'Tolak Pendaftaran',
      description: `Alasan penolakan akan ditampilkan saat ${u?.name} mencoba masuk.`,
      submit: 'Tolak Pendaftaran',
    },
    reset: {
      title: 'Reset Kata Sandi',
      description: `Kata sandi ${u?.name} diganti dengan kata sandi sementara dan semua sesi masuknya diakhiri.`,
      submit: 'Reset Kata Sandi',
    },
  }[dialog.type]
})

function openDialog(type, user) {
  Object.assign(dialog, { open: true, type, user, reason: '', error: '', reasonError: '' })
}

async function submitDialog() {
  const { type, user } = dialog
  dialog.submitting = true
  dialog.error = ''
  dialog.reasonError = ''
  try {
    if (type === 'reset') {
      const { data } = await api.post(`/users/${user.id}/reset-password`)
      dialog.open = false
      Object.assign(temp, { open: true, user, password: data.data.temporary_password })
      return
    }
    const { data } = await api.post(
      `/residents/${user.id}/${type}`,
      type === 'reject' ? { reason: dialog.reason } : {},
    )
    dialog.open = false
    notify(data.message)
    load()
  } catch (err) {
    dialog.reasonError = fieldErrors(err).reason ?? ''
    if (!dialog.reasonError) dialog.error = errorMessage(err)
  } finally {
    dialog.submitting = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="sr-only">Data Warga</h1>
    <p class="leading-relaxed font-medium text-desa-secondary">{{ intro }}</p>

    <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>

    <div
      class="hide-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0"
      role="tablist"
      aria-label="Status akun"
    >
      <button
        v-for="tab in visibleTabs"
        :key="tab.status"
        type="button"
        role="tab"
        :aria-selected="filters.status === tab.status"
        :class="[
          'flex h-12 shrink-0 items-center gap-2 rounded-2xl px-4 leading-5 font-medium transition-all duration-300',
          filters.status === tab.status
            ? 'bg-desa-dark-green text-white'
            : 'bg-white text-desa-secondary hover:text-desa-dark-green',
        ]"
        @click="filters.status = tab.status"
      >
        {{ tab.label }}
        <span
          :class="[
            'min-w-6 rounded-full px-2 py-0.5 text-xs font-semibold',
            filters.status === tab.status
              ? 'bg-white/20 text-white'
              : tab.status === 'pending' && counts.pending
                ? 'bg-desa-orange text-white'
                : 'bg-desa-background text-desa-secondary',
          ]"
          >{{ counts[tab.status] ?? 0 }}</span
        >
      </button>
    </div>

    <div
      :class="[
        'grid gap-4 rounded-2xl bg-white p-4 sm:p-6',
        isOperator && 'md:grid-cols-[2fr_1fr]',
      ]"
    >
      <AppInput
        v-model="filters.search"
        label="Cari warga"
        icon="user-search"
        type="search"
        placeholder="Nama atau no. HP"
      />
      <AppSelect
        v-if="isOperator && rtOptions.length"
        v-model="filters.rt_id"
        label="Wilayah"
        icon="location"
        :options="rtOptions"
      />
    </div>

    <AppAlert v-if="error">{{ error }}</AppAlert>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat data warga…
    </div>

    <div
      v-else-if="!residents.length && !error"
      class="flex flex-col items-center gap-3 rounded-2xl bg-white px-6 py-12 text-center"
    >
      <span class="flex size-16 items-center justify-center rounded-full bg-desa-foreshadow">
        <AppIcon name="profile-2user" class="size-8! text-desa-dark-green" />
      </span>
      <p class="text-lg font-semibold">
        {{
          filters.search
            ? 'Tidak ada warga yang cocok'
            : filters.status === 'pending'
              ? 'Tidak ada pendaftaran yang menunggu'
              : 'Belum ada data'
        }}
      </p>
      <p class="max-w-sm leading-5 font-medium text-desa-secondary">
        {{
          filters.status === 'pending' && !filters.search
            ? 'Pendaftaran warga baru akan muncul di sini untuk diverifikasi.'
            : 'Coba ubah kata kunci atau pilih status lain.'
        }}
      </p>
    </div>

    <template v-else>
      <ul class="grid gap-[14px] md:grid-cols-2 2xl:grid-cols-3">
        <li
          v-for="resident in residents"
          :key="resident.id"
          class="flex flex-col gap-5 rounded-2xl bg-white p-6"
        >
          <div class="flex items-start gap-3">
            <span
              class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow font-semibold text-desa-dark-green"
              aria-hidden="true"
              >{{ initials(resident.name) }}</span
            >
            <div class="flex min-w-0 flex-1 flex-col gap-1">
              <p class="truncate leading-5 font-semibold">{{ resident.name }}</p>
              <a
                :href="`tel:+${resident.phone}`"
                class="w-fit text-sm font-medium text-desa-secondary hover:text-desa-dark-green"
                >{{ formatPhone(resident.phone) }}</a
              >
            </div>
            <AppBadge :variant="statusBadge[resident.status]">{{ resident.status_label }}</AppBadge>
          </div>

          <dl class="grid grid-cols-2 gap-x-3 gap-y-4 rounded-2xl bg-desa-background/50 p-4">
            <div class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">Wilayah</dt>
              <dd class="leading-5 font-semibold">
                {{ resident.rt?.label }} / {{ resident.rw?.label }}
              </dd>
            </div>
            <div class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">Status tinggal</dt>
              <dd class="leading-5 font-semibold">
                {{ residencyLabels[resident.residency_status] ?? '—' }}
              </dd>
            </div>
            <div class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">Mendaftar</dt>
              <dd class="leading-5 font-semibold">{{ formatDate(resident.created_at) }}</dd>
            </div>
            <div v-if="resident.verified_at" class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">
                {{ resident.status === 'rejected' ? 'Ditolak' : 'Diverifikasi' }}
              </dt>
              <dd class="leading-5 font-semibold">{{ formatDate(resident.verified_at) }}</dd>
            </div>
          </dl>

          <AppAlert
            v-if="resident.status === 'rejected' && resident.rejection_reason"
            variant="warning"
          >
            Alasan: {{ resident.rejection_reason }}
          </AppAlert>

          <div
            v-if="resident.status === 'pending'"
            class="mt-auto flex flex-col gap-3 min-[400px]:flex-row"
          >
            <AppButton class="flex-1" @click="openDialog('verify', resident)">
              <AppIcon name="tick-circle" />
              Verifikasi
            </AppButton>
            <AppButton variant="secondary" class="flex-1" @click="openDialog('reject', resident)">
              <AppIcon name="close-circle" />
              Tolak
            </AppButton>
          </div>
          <AppButton
            v-else-if="resident.status === 'active'"
            variant="secondary"
            class="mt-auto"
            block
            @click="openDialog('reset', resident)"
          >
            <AppIcon name="key" />
            Reset Kata Sandi
          </AppButton>
        </li>
      </ul>

      <AppButton
        v-if="page.current < page.last"
        variant="secondary"
        class="self-center"
        :loading="loadingMore"
        @click="load(true)"
      >
        Muat lebih banyak
      </AppButton>
    </template>

    <AppModal
      v-model:open="dialog.open"
      :title="dialogText.title"
      :description="dialogText.description"
    >
      <form
        id="resident-dialog"
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitDialog"
      >
        <AppAlert v-if="dialog.error">{{ dialog.error }}</AppAlert>
        <template v-if="dialog.type === 'reject'">
          <AppInput
            v-model="dialog.reason"
            label="Alasan penolakan"
            icon="note"
            maxlength="255"
            placeholder="Mis. bukan warga RT ini"
            :error="dialog.reasonError"
            required
          />
          <div class="flex flex-wrap gap-2">
            <button
              v-for="reason in rejectReasons"
              :key="reason"
              type="button"
              class="rounded-full border border-desa-background px-3 py-1.5 text-sm font-medium text-desa-secondary transition-all duration-300 hover:border-desa-dark-green hover:text-desa-dark-green"
              @click="dialog.reason = reason"
            >
              {{ reason }}
            </button>
          </div>
        </template>
      </form>

      <template #footer>
        <AppButton variant="secondary" @click="dialog.open = false">Batal</AppButton>
        <AppButton
          type="submit"
          form="resident-dialog"
          :variant="dialog.type === 'reject' ? 'danger' : 'primary'"
          :loading="dialog.submitting"
        >
          {{ dialogText.submit }}
        </AppButton>
      </template>
    </AppModal>

    <TemporaryPasswordDialog v-model:open="temp.open" :user="temp.user" :password="temp.password" />
  </div>
</template>
