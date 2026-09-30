<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppModal from '@/components/ui/AppModal.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

// Operator mengelola RW/RT; kepala desa hanya melihat.
const auth = useAuthStore()
const canManage = computed(() => auth.hasRole('operator'))

const village = ref(null)
const loading = ref(true)
const error = ref('')
const notice = ref('')
let noticeTimer

const rws = computed(() => village.value?.rws ?? [])
const stats = computed(() => {
  const rts = rws.value.flatMap((rw) => rw.rts)
  return [
    { label: 'RW', value: rws.value.length, icon: 'building-4' },
    { label: 'RT', value: rts.length, icon: 'grid-5' },
    {
      label: 'Warga aktif',
      value: rts.reduce((sum, rt) => sum + rt.residents_active, 0),
      icon: 'profile-2user',
    },
    {
      label: 'Menunggu verifikasi',
      value: rts.reduce((sum, rt) => sum + rt.residents_pending, 0),
      icon: 'user-search',
    },
  ]
})

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/admin/regions')
    village.value = data.data
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

const nextNumber = (items) => Math.max(0, ...items.map((i) => i.number)) + 1
const names = (officials) => officials.map((o) => o.name).join(', ')
const isEmptyRt = (rt) =>
  rt.residents_active + rt.residents_pending === 0 && rt.officials.length === 0

// Satu form untuk tambah/ubah RW dan tambah RT.
const form = reactive({
  open: false,
  kind: 'rw', // rw | rt
  rw: null, // RW yang diubah, atau induk RT baru
  editing: false,
  number: '',
  name: '',
  errors: {},
  error: '',
  submitting: false,
})

const formTitle = computed(() => {
  if (form.kind === 'rt') return `Tambah RT di ${form.rw?.label}`
  return form.editing ? `Ubah ${form.rw?.label}` : 'Tambah RW'
})

function openRwForm(rw = null) {
  Object.assign(form, {
    open: true,
    kind: 'rw',
    rw,
    editing: !!rw,
    number: rw?.number ?? nextNumber(rws.value),
    name: rw?.name ?? '',
    errors: {},
    error: '',
  })
}

function openRtForm(rw) {
  Object.assign(form, {
    open: true,
    kind: 'rt',
    rw,
    editing: false,
    number: nextNumber(rw.rts),
    name: '',
    errors: {},
    error: '',
  })
}

async function submitForm() {
  form.submitting = true
  form.errors = {}
  form.error = ''
  try {
    let response
    if (form.kind === 'rt') {
      response = await api.post(`/admin/rws/${form.rw.id}/rts`, { number: form.number })
    } else {
      const payload = { number: form.number, name: form.name || null }
      response = form.editing
        ? await api.put(`/admin/rws/${form.rw.id}`, payload)
        : await api.post('/admin/rws', payload)
    }
    form.open = false
    notify(response.data.message)
    load()
  } catch (err) {
    form.errors = fieldErrors(err)
    if (!Object.keys(form.errors).length) form.error = errorMessage(err)
  } finally {
    form.submitting = false
  }
}

// Hapus RW/RT kosong. Backend menolak bila masih ada akun atau riwayat layanan.
const removal = reactive({ open: false, kind: 'rt', item: null, error: '', submitting: false })

function openRemoval(kind, item) {
  Object.assign(removal, { open: true, kind, item, error: '' })
}

async function submitRemoval() {
  removal.submitting = true
  removal.error = ''
  try {
    const { data } = await api.delete(`/admin/${removal.kind}s/${removal.item.id}`)
    removal.open = false
    notify(data.message)
    load()
  } catch (err) {
    removal.error = errorMessage(err)
  } finally {
    removal.submitting = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <h1 class="sr-only">Wilayah</h1>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="leading-relaxed font-medium text-desa-secondary">
        Daftar RW dan RT
        <template v-if="village">Desa {{ village.name }}</template>
        beserta pengurus dan jumlah warganya.
      </p>
      <AppButton v-if="canManage && village" class="shrink-0" @click="openRwForm()">
        <AppIcon name="add-square" />
        Tambah RW
      </AppButton>
    </div>

    <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>
    <AppAlert v-if="error">{{ error }}</AppAlert>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat data wilayah…
    </div>

    <template v-else-if="village">
      <section class="grid grid-cols-2 gap-[14px] lg:grid-cols-4" aria-label="Ringkasan wilayah">
        <article
          v-for="stat in stats"
          :key="stat.label"
          class="flex items-center justify-between gap-3 rounded-2xl bg-white p-5"
        >
          <div class="flex min-w-0 flex-col gap-1">
            <p class="text-[28px] leading-9 font-semibold">{{ stat.value }}</p>
            <p class="text-sm font-medium text-desa-secondary">{{ stat.label }}</p>
          </div>
          <span
            class="hidden size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow min-[480px]:flex"
          >
            <AppIcon :name="stat.icon" class="text-desa-dark-green" />
          </span>
        </article>
      </section>

      <section
        v-for="rw in rws"
        :key="rw.id"
        class="flex flex-col gap-5 rounded-2xl bg-white p-6"
        :aria-labelledby="`rw-${rw.id}`"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="flex min-w-0 items-center gap-3">
            <span
              class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow"
            >
              <AppIcon name="building-4" class="text-desa-dark-green" />
            </span>
            <div class="flex min-w-0 flex-col gap-1">
              <h2 :id="`rw-${rw.id}`" class="text-lg leading-6 font-semibold">{{ rw.label }}</h2>
              <p class="truncate text-sm font-medium text-desa-secondary">
                {{ rw.name || 'Tanpa nama dusun' }}
              </p>
            </div>
          </div>
          <div v-if="canManage" class="flex shrink-0 gap-2">
            <button
              type="button"
              class="flex size-11 items-center justify-center rounded-2xl border border-desa-background text-desa-secondary transition-all duration-300 hover:border-desa-dark-green hover:text-desa-dark-green"
              :aria-label="`Ubah ${rw.label}`"
              @click="openRwForm(rw)"
            >
              <AppIcon name="edit" class="size-5!" />
            </button>
            <button
              v-if="!rw.rts.length"
              type="button"
              class="flex size-11 items-center justify-center rounded-2xl border border-desa-background text-desa-red transition-all duration-300 hover:border-desa-red"
              :aria-label="`Hapus ${rw.label}`"
              @click="openRemoval('rw', rw)"
            >
              <AppIcon name="close-circle" class="size-5!" />
            </button>
          </div>
        </div>

        <p class="flex items-center gap-2 text-sm font-medium text-desa-secondary">
          <AppIcon name="user-square" class="size-5!" />
          <span v-if="rw.officials.length"
            >Pengurus RW: <span class="text-desa-black">{{ names(rw.officials) }}</span></span
          >
          <span v-else>Belum ada akun pengurus RW</span>
        </p>

        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <li
            v-for="rt in rw.rts"
            :key="rt.id"
            class="flex flex-col gap-3 rounded-2xl border border-desa-background p-4"
          >
            <div class="flex items-center justify-between gap-3">
              <p class="leading-5 font-semibold">{{ rt.label }}</p>
              <button
                v-if="canManage && isEmptyRt(rt)"
                type="button"
                class="flex size-9 items-center justify-center rounded-xl text-desa-red transition-all duration-300 hover:bg-desa-red/10"
                :aria-label="`Hapus ${rt.label}/${rw.label}`"
                @click="openRemoval('rt', { ...rt, label: `${rt.label}/${rw.label}` })"
              >
                <AppIcon name="close-circle" class="size-5!" />
              </button>
            </div>
            <p class="text-sm leading-5 font-medium text-desa-secondary">
              {{
                rt.officials.length ? `Ketua: ${names(rt.officials)}` : 'Belum ada akun ketua RT'
              }}
            </p>
            <div class="flex flex-wrap gap-2">
              <AppBadge variant="success">{{ rt.residents_active }} warga aktif</AppBadge>
              <AppBadge v-if="rt.residents_pending" variant="warning"
                >{{ rt.residents_pending }} menunggu</AppBadge
              >
            </div>
          </li>
          <li v-if="canManage">
            <button
              type="button"
              class="flex size-full min-h-[120px] flex-col items-center justify-center gap-2 rounded-2xl border-[1.5px] border-dashed border-desa-background font-medium text-desa-secondary transition-all duration-300 hover:border-desa-dark-green hover:text-desa-dark-green"
              @click="openRtForm(rw)"
            >
              <AppIcon name="add-square" />
              Tambah RT
            </button>
          </li>
        </ul>
      </section>
    </template>

    <AppModal v-model:open="form.open" :title="formTitle">
      <form id="region-form" class="flex flex-col gap-5" novalidate @submit.prevent="submitForm">
        <AppAlert v-if="form.error">{{ form.error }}</AppAlert>
        <AppInput
          v-model="form.number"
          :label="form.kind === 'rt' ? 'Nomor RT' : 'Nomor RW'"
          icon="grid-5"
          type="number"
          inputmode="numeric"
          min="1"
          max="999"
          :error="form.errors.number"
          required
        />
        <AppInput
          v-if="form.kind === 'rw'"
          v-model="form.name"
          label="Nama dusun (opsional)"
          icon="location"
          maxlength="100"
          placeholder="Mis. Dusun Krajan"
          :error="form.errors.name"
        />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="form.open = false">Batal</AppButton>
        <AppButton type="submit" form="region-form" :loading="form.submitting">Simpan</AppButton>
      </template>
    </AppModal>

    <AppModal
      v-model:open="removal.open"
      :title="`Hapus ${removal.item?.label ?? ''}`"
      description="Wilayah yang dihapus tidak bisa dipilih lagi saat pendaftaran warga."
    >
      <AppAlert v-if="removal.error">{{ removal.error }}</AppAlert>
      <template #footer>
        <AppButton variant="secondary" @click="removal.open = false">Batal</AppButton>
        <AppButton variant="danger" :loading="removal.submitting" @click="submitRemoval">
          Hapus
        </AppButton>
      </template>
    </AppModal>
  </div>
</template>
