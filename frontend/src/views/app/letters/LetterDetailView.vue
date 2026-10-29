<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import RatingCard from '@/components/ratings/RatingCard.vue'
import TicketProgress from '@/components/TicketProgress.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { api, errorMessage, fieldErrors, openFile } from '@/lib/api'
import { formatDate, formatPhone } from '@/lib/format'
import { actionText, cancelReasons, formatSize, rejectReasons, statusBadge } from '@/lib/letters'
import { useAuthStore } from '@/stores/auth'

// Tombol aksi hanya mengikuti `can.actions` dari backend; backend tetap memeriksa ulang.
const auth = useAuthStore()
const route = useRoute()

const letter = ref(null)
const loading = ref(true)
const error = ref('')
const notice = ref(route.query.baru ? 'new' : '')
const nik = ref('')
const nikLoading = ref(false)
const copied = ref(false)

const isOwner = computed(() => letter.value?.applicant?.id === auth.user?.id)
const officialAttachment = computed(
  () => letter.value?.attachments?.find((a) => a.is_official) ?? null,
)

function onRated(rating, message) {
  letter.value = { ...letter.value, rating, can: { ...letter.value.can, rate: false } }
  notice.value = message
}
const actions = computed(() => letter.value?.can.actions ?? [])
const otherPrimaryActions = computed(() =>
  actions.value.filter((a) => a !== 'reject' && a !== 'ready'),
)
const statusLabel = computed(() => {
  if (letter.value?.status === 'ready_for_pickup') {
    return officialAttachment.value ? 'Siap Diunduh' : 'Siap Diambil di Loket'
  }
  return letter.value?.status_label ?? ''
})
const history = computed(() => [...(letter.value?.steps ?? [])].reverse())
const ticketUrl = computed(
  () => `${window.location.origin}/cek-tiket?kode=${letter.value?.code ?? ''}`,
)
const shareText = computed(
  () =>
    `Kode tiket surat pengantar saya: ${letter.value?.code}. ` +
    `Cek statusnya di ${ticketUrl.value}`,
)

async function load() {
  error.value = ''
  try {
    const { data } = await api.get(`/letters/${route.params.id}`)
    letter.value = data.data
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function revealNik() {
  nikLoading.value = true
  try {
    const { data } = await api.get(`/letters/${letter.value.id}/nik`)
    nik.value = data.data.nik
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    nikLoading.value = false
  }
}

const opening = ref(null)

async function open(path, filename) {
  opening.value = path
  error.value = ''
  try {
    await openFile(path, filename)
  } catch (err) {
    error.value = errorMessage(err, 'Berkas tidak bisa dibuka. Coba lagi.')
  } finally {
    opening.value = null
  }
}

const previewFile = reactive({
  open: false,
  loading: false,
  title: '',
  subtitle: '',
  url: '',
  blobUrl: '',
  isImage: false,
  isPdf: false,
  name: '',
})

async function viewAttachment(file) {
  if (previewFile.blobUrl) {
    URL.revokeObjectURL(previewFile.blobUrl)
    previewFile.blobUrl = ''
  }
  const isImage = file.mime?.startsWith('image/') || /\.(jpe?g|png|webp|gif)$/i.test(file.name)
  const isPdf = file.mime === 'application/pdf' || /\.pdf$/i.test(file.name)

  Object.assign(previewFile, {
    open: true,
    loading: true,
    title: `Pratinjau: ${file.label || file.name}`,
    subtitle: `${file.name} · ${formatSize(file.size_bytes)}`,
    url: file.url,
    blobUrl: '',
    isImage,
    isPdf,
    name: file.name,
  })

  try {
    const { data } = await api.get(file.url, { baseURL: '', responseType: 'blob' })
    previewFile.blobUrl = URL.createObjectURL(data)
  } catch (err) {
    error.value = errorMessage(err, 'Gagal memuat pratinjau berkas.')
    previewFile.open = false
  } finally {
    previewFile.loading = false
  }
}

function closeAttachmentPreview() {
  previewFile.open = false
  if (previewFile.blobUrl) {
    URL.revokeObjectURL(previewFile.blobUrl)
    previewFile.blobUrl = ''
  }
}

onBeforeUnmount(() => {
  if (previewFile.blobUrl) URL.revokeObjectURL(previewFile.blobUrl)
})

async function copyCode() {
  try {
    await navigator.clipboard.writeText(letter.value.code)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    copied.value = false
  }
}

// Satu dialog konfirmasi untuk semua aksi.
const dialog = reactive({
  open: false,
  action: 'verify',
  readyMode: 'upload', // 'upload' | 'pickup'
  filePreview: '',
  note: '',
  noteError: '',
  file: null,
  fileError: '',
  error: '',
  submitting: false,
})

const dialogTitle = computed(() => {
  if (dialog.action === 'ready') {
    return dialog.readyMode === 'upload'
      ? 'Unggah & Kirim Salinan Digital (SKU)'
      : 'Tandai Siap Diambil di Kelurahan'
  }
  return actionText[dialog.action]?.title ?? 'Konfirmasi'
})

const dialogDescription = computed(() => {
  if (dialog.action === 'ready') {
    return dialog.readyMode === 'upload'
      ? 'Unggah hasil scan surat fisik yang sudah dicetak, ditandatangani, dan dicap stempel basah. Berkas akan langsung dikirim ke akun warga agar bisa diunduh.'
      : 'Beri tahu pemohon bahwa surat fisik bertanda tangan dan cap basah asli sudah selesai dan siap diambil langsung di kantor kelurahan.'
  }
  return actionText[dialog.action]?.description ?? ''
})

const dialogButtonText = computed(() => {
  if (dialog.action === 'ready') {
    return dialog.readyMode === 'upload' ? 'Unggah & Kirim ke Warga' : 'Tandai Siap Diambil'
  }
  return actionText[dialog.action]?.button ?? 'Simpan'
})

function openAction(action) {
  if (dialog.filePreview) URL.revokeObjectURL(dialog.filePreview)
  Object.assign(dialog, {
    open: true,
    action,
    readyMode: 'upload',
    filePreview: '',
    note: '',
    noteError: '',
    file: null,
    fileError: '',
    error: '',
  })
}

function openReadyAction(mode) {
  if (dialog.filePreview) URL.revokeObjectURL(dialog.filePreview)
  Object.assign(dialog, {
    open: true,
    action: 'ready',
    readyMode: mode,
    filePreview: '',
    note: '',
    noteError: '',
    file: null,
    fileError: '',
    error: '',
  })
}

function onDialogFileChange(e) {
  const file = e.target.files?.[0] ?? null
  dialog.file = file
  dialog.fileError = ''
  if (dialog.filePreview) {
    URL.revokeObjectURL(dialog.filePreview)
    dialog.filePreview = ''
  }
  if (file && (file.type.startsWith('image/') || file.name.toLowerCase().endsWith('.pdf'))) {
    try {
      dialog.filePreview = URL.createObjectURL(file)
    } catch {
      dialog.filePreview = ''
    }
  }
}

async function submitAction() {
  dialog.submitting = true
  dialog.error = ''
  dialog.noteError = ''
  dialog.fileError = ''

  if (dialog.action === 'ready' && dialog.readyMode === 'upload' && !dialog.file) {
    dialog.fileError = 'Silakan pilih berkas scan surat resmi (PDF atau foto) terlebih dahulu.'
    dialog.submitting = false
    return
  }

  const body = new FormData()
  if (dialog.note) body.append('note', dialog.note)
  if (dialog.file && dialog.action === 'ready' && dialog.readyMode === 'upload') {
    body.append('file', dialog.file)
  }

  try {
    const { data } = await api.post(`/letters/${letter.value.id}/${dialog.action}`, body)
    letter.value = data.data
    notice.value = data.message
    dialog.open = false
    if (dialog.filePreview) {
      URL.revokeObjectURL(dialog.filePreview)
      dialog.filePreview = ''
    }
  } catch (err) {
    const errors = fieldErrors(err)
    dialog.noteError = errors.note ?? ''
    dialog.fileError = errors.file ?? ''
    if (!dialog.noteError && !dialog.fileError) dialog.error = errorMessage(err)
    // Surat sudah dipindahkan pengurus lain: muat ulang supaya tombolnya sesuai.
    if (err.response?.status === 409) load()
  } finally {
    dialog.submitting = false
  }
}

// Pembatalan oleh pemohon: dialog sendiri karena alasannya opsional dan nadanya berbeda.
const cancelDialog = reactive({ open: false, reason: '', error: '', submitting: false })

function openCancel() {
  Object.assign(cancelDialog, { open: true, reason: '', error: '' })
}

async function submitCancel() {
  cancelDialog.submitting = true
  cancelDialog.error = ''
  try {
    const { data } = await api.post(`/letters/${letter.value.id}/cancel`, {
      reason: cancelDialog.reason || undefined,
    })
    letter.value = data.data
    notice.value = data.message
    cancelDialog.open = false
  } catch (err) {
    cancelDialog.error = errorMessage(err)
    // Surat baru saja diselesaikan/ditolak pengurus: muat ulang supaya tombolnya hilang.
    if (err.response?.status === 403 || err.response?.status === 409) load()
  } finally {
    cancelDialog.submitting = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <RouterLink
      :to="{ name: 'letters' }"
      class="hidden w-fit items-center gap-2 font-medium text-desa-secondary transition-all duration-300 hover:text-desa-dark-green lg:flex"
    >
      <AppIcon name="arrow-left" />
      {{ auth.hasRole('warga') ? 'Surat Saya' : 'Surat Pengantar' }}
    </RouterLink>

    <AppAlert v-if="error">{{ error }}</AppAlert>
    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat surat…
    </div>

    <template v-else-if="letter">
      <AppAlert v-if="notice === 'new'" variant="success">
        Pengajuan terkirim. Simpan kode tiket <strong>{{ letter.code }}</strong> untuk mengecek
        status tanpa masuk, atau bagikan ke keluarga.
      </AppAlert>
      <AppAlert v-else-if="notice" variant="success">{{ notice }}</AppAlert>

      <!-- Ringkasan + aksi. -->
      <section class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex min-w-0 flex-col gap-1">
            <button
              type="button"
              class="flex w-fit items-center gap-2 text-sm font-semibold tracking-wide text-desa-dark-green"
              title="Salin kode tiket"
              @click="copyCode"
            >
              {{ letter.code }}
              <AppIcon :name="copied ? 'tick-circle' : 'document-text'" class="size-4!" />
              <span class="sr-only">{{ copied ? 'Tersalin' : 'Salin kode' }}</span>
            </button>
            <h1 class="text-xl leading-7 font-semibold sm:text-2xl">{{ letter.service?.name }}</h1>
            <p class="text-sm text-desa-secondary">
              Diajukan {{ formatDate(letter.submitted_at, true) }} · Alur {{ letter.flow_label }}
            </p>
          </div>
          <AppBadge :variant="statusBadge[letter.status]">{{ statusLabel }}</AppBadge>
        </div>

        <AppAlert v-if="letter.status === 'rejected'" variant="warning">
          <strong>Ditolak {{ letter.rejected_level_label }}:</strong> {{ letter.rejection_reason }}
          <template v-if="isOwner">
            <br />Perbaiki sesuai alasan di atas, lalu ajukan ulang.
          </template>
        </AppAlert>
        <AppAlert v-else-if="letter.status === 'cancelled'" variant="info">
          Pengajuan ini dibatalkan {{ isOwner ? 'oleh Anda' : 'oleh pemohon' }} dan tidak diproses
          lagi.
          <template v-if="isOwner"> Anda bisa mengajukan surat baru kapan saja.</template>
        </AppAlert>
        <AppAlert v-else-if="letter.status === 'ready_for_pickup' && isOwner" variant="success">
          <strong>Surat sudah selesai!</strong>
          <template v-if="officialAttachment">
            Salinan resmi digital telah dikirim oleh kelurahan dan siap diunduh di bawah ini. Anda
            juga tetap dapat mengambil berkas fisik asli bertanda tangan dan cap basah di kantor
            desa/kelurahan bila diperlukan.
          </template>
          <template v-else>
            Surat fisik bertanda tangan dan cap basah asli sudah siap diambil langsung di kantor
            desa/kelurahan. Silakan datang ke loket pelayanan pada jam kerja.
          </template>
        </AppAlert>

        <!-- Kartu download berkas surat resmi yang sudah diterbitkan desa -->
        <div
          v-if="officialAttachment"
          class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-2xl bg-desa-foreshadow border border-desa-dark-green/20 p-5"
        >
          <div class="flex items-center gap-3 min-w-0">
            <span
              class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-desa-dark-green text-white"
            >
              <AppIcon name="document-text" class="size-6!" />
            </span>
            <div class="flex min-w-0 flex-col">
              <p class="leading-5 font-semibold text-desa-dark-green truncate">
                Salinan Surat Resmi ({{ letter.service?.name }})
              </p>
              <p class="text-sm text-desa-secondary truncate">
                {{ officialAttachment.name }} · {{ formatSize(officialAttachment.size_bytes) }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <AppButton variant="secondary" @click="viewAttachment(officialAttachment)">
              <AppIcon name="receipt-search" />
              Pratinjau
            </AppButton>
            <AppButton
              :loading="opening === officialAttachment.url"
              @click="open(officialAttachment.url, officialAttachment.name)"
            >
              <AppIcon name="document-download" />
              Unduh Surat Resmi
            </AppButton>
          </div>
        </div>

        <div
          v-if="actions.length || letter.can.print || isOwner"
          class="flex flex-col gap-3 sm:flex-row sm:flex-wrap"
        >
          <!-- Jika aksi ready tersedia untuk desa, pisahkan jadi 2 tombol jelas -->
          <!-- <template v-if="actions.includes('ready')">
            <AppButton @click="openReadyAction('upload')">
              <AppIcon name="document-download" />
              Unggah & Kirim Salinan Digital
            </AppButton>
            <AppButton variant="secondary" @click="openReadyAction('pickup')">
              <AppIcon name="ticket" />
              Siap Diambil di Kelurahan (Fisik)
            </AppButton>
          </template> -->

          <!-- Aksi primary lainnya (verify, approve, receive, pickup) -->
          <AppButton
            v-for="action in otherPrimaryActions"
            :key="action"
            @click="openAction(action)"
          >
            <AppIcon :name="actionText[action].icon" />
            {{ actionText[action].button }}
          </AppButton>
          <AppButton
            v-if="actions.includes('reject')"
            variant="secondary"
            @click="openAction('reject')"
          >
            <AppIcon name="close-circle" />
            Tolak
          </AppButton>
          <AppButton
            v-if="letter.can.print"
            variant="secondary"
            :loading="opening === `/api/letters/${letter.id}/pdf`"
            @click="open(`/api/letters/${letter.id}/pdf`, `Pengantar-${letter.code}.pdf`)"
          >
            <AppIcon name="receipt-search" />
            Cetak Pengantar (PDF)
          </AppButton>
          <RouterLink
            v-if="isOwner && ['rejected', 'cancelled'].includes(letter.status)"
            :to="{ name: 'letter-create', params: { slug: letter.service?.slug } }"
            class="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-desa-dark-green px-5 font-medium text-white transition-all duration-300 hover:bg-[#2a5030]"
          >
            <AppIcon name="add-square" />
            Ajukan Ulang
          </RouterLink>
          <a
            v-if="isOwner"
            :href="`https://wa.me/?text=${encodeURIComponent(shareText)}`"
            target="_blank"
            rel="noopener"
            class="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl px-5 font-medium text-desa-secondary transition-all duration-300 hover:bg-desa-foreshadow hover:text-desa-dark-green"
          >
            <AppIcon name="call" />
            Bagikan kode lewat WhatsApp
          </a>
          <AppButton
            v-if="letter.can.cancel"
            variant="ghost"
            class="text-desa-red! hover:bg-desa-red/10!"
            @click="openCancel"
          >
            <AppIcon name="close-circle" />
            Batalkan Pengajuan
          </AppButton>
        </div>
      </section>

      <!-- Bagian Penerbitan Surat Khusus Operator Desa (Ambil Langsung vs Upload Terpisah) -->
      <section
        v-if="actions.includes('ready')"
        class="flex flex-col gap-5 rounded-2xl border-2 border-desa-dark-green/20 bg-white p-6 shadow-xs sm:p-8"
      >
        <div class="flex items-center gap-3">
          <span
            class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-desa-foreshadow text-desa-dark-green"
          >
            <AppIcon name="document-text" class="size-6!" />
          </span>
          <div>
            <h2 class="text-lg font-semibold text-slate-800">Penerbitan Surat (Kelurahan)</h2>
            <p class="text-sm text-desa-secondary">
              Pilih metode penyerahan surat untuk pemohon sesuai kebutuhan:
            </p>
          </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <!-- Pilihan 1: Upload Salinan Digital (Kirim ke Warga) -->
          <div
            class="flex flex-col justify-between gap-4 rounded-2xl border border-desa-background bg-desa-background/30 p-5 transition-all duration-200 hover:border-desa-dark-green/50 hover:bg-desa-foreshadow/20"
          >
            <div class="flex flex-col gap-2">
              <div class="flex items-center gap-2 text-desa-dark-green font-semibold">
                <AppIcon name="document-download" class="size-5!" />
                <span>1. Kirim Salinan Digital (Upload)</span>
              </div>
              <p class="text-sm text-desa-secondary leading-relaxed">
                Scan surat fisik yang sudah dicetak, ditandatangani, dan dicap stempel basah. Berkas
                akan langsung dikirim ke akun pemohon agar warga dapat mengunduhnya secara mandiri.
              </p>
            </div>
            <AppButton @click="openReadyAction('upload')">
              <AppIcon name="document-download" />
              Unggah & Kirim ke Warga
            </AppButton>
          </div>

          <!-- Pilihan 2: Ambil Langsung di Kantor Kelurahan (Fisik) -->
          <div
            class="flex flex-col justify-between gap-4 rounded-2xl border border-desa-background bg-desa-background/30 p-5 transition-all duration-200 hover:border-desa-dark-green/50 hover:bg-desa-foreshadow/20"
          >
            <div class="flex flex-col gap-2">
              <div class="flex items-center gap-2 text-slate-800 font-semibold">
                <AppIcon name="ticket" class="size-5! text-desa-secondary" />
                <span>2. Ambil Langsung di Kelurahan (Fisik)</span>
              </div>
              <p class="text-sm text-desa-secondary leading-relaxed">
                Surat fisik asli disimpan di loket pelayanan kelurahan. Warga akan diberi tahu untuk
                datang langsung mengambil berkas fisik bertanda tangan dan cap basah asli.
              </p>
            </div>
            <AppButton variant="secondary" @click="openReadyAction('pickup')">
              <AppIcon name="ticket" />
              Tandai Siap Diambil di Loket
            </AppButton>
          </div>
        </div>
      </section>

      <RatingCard
        :rating="letter.rating"
        :can-rate="letter.can.rate"
        :endpoint="`/letters/${letter.id}/rating`"
        :is-mine="isOwner"
        subject="surat"
        @rated="onRated"
      />

      <div class="flex flex-col gap-[14px] xl:flex-row xl:items-start">
        <!-- Isi pengajuan. -->
        <section class="flex flex-1 flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8">
          <h2 class="text-lg font-semibold">Isi pengajuan</h2>
          <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
            <div v-if="!isOwner" class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">Pemohon</dt>
              <dd class="leading-5 font-semibold">{{ letter.applicant?.name }}</dd>
              <dd>
                <a
                  :href="`tel:+${letter.applicant?.phone}`"
                  class="text-sm font-medium text-desa-secondary hover:text-desa-dark-green"
                  >{{ formatPhone(letter.applicant?.phone) }}</a
                >
              </dd>
            </div>
            <div class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">Wilayah</dt>
              <dd class="leading-5 font-semibold">
                {{ letter.rt?.label }} / {{ letter.rw?.label }}
              </dd>
            </div>
            <div class="flex flex-col gap-1 sm:col-span-2">
              <dt class="text-sm font-medium text-desa-secondary">Keperluan</dt>
              <dd class="leading-relaxed font-semibold whitespace-pre-line">
                {{ letter.purpose }}
              </dd>
            </div>
            <div v-if="letter.nik_masked" class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">NIK</dt>
              <dd class="flex flex-wrap items-center gap-3 leading-5 font-semibold tracking-wide">
                {{ nik || letter.nik_masked }}
                <button
                  v-if="letter.can.view_nik && !nik"
                  type="button"
                  class="text-sm font-medium tracking-normal text-desa-dark-green underline-offset-4 hover:underline disabled:opacity-60"
                  :disabled="nikLoading"
                  @click="revealNik"
                >
                  Tampilkan NIK
                </button>
              </dd>
              <dd v-if="letter.can.view_nik" class="text-xs text-desa-secondary">
                Setiap pembukaan NIK dicatat di log audit.
              </dd>
            </div>
            <div v-for="field in letter.form_data" :key="field.key" class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">{{ field.label }}</dt>
              <dd class="leading-5 font-semibold">{{ field.value }}</dd>
            </div>
          </dl>

          <div class="flex flex-col gap-3">
            <h3 class="font-semibold">Lampiran</h3>
            <ul v-if="letter.attachments?.length" class="grid gap-3 sm:grid-cols-2">
              <li
                v-for="file in letter.attachments"
                :key="file.id"
                class="flex items-center justify-between gap-3 rounded-2xl border border-desa-background bg-desa-background/30 p-3 transition-all duration-200 hover:border-desa-dark-green/40 hover:bg-desa-foreshadow/30"
              >
                <div
                  class="flex items-center gap-3 min-w-0 cursor-pointer"
                  title="Klik untuk pratinjau"
                  @click="viewAttachment(file)"
                >
                  <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-desa-foreshadow text-desa-dark-green transition-transform hover:scale-105"
                  >
                    <AppIcon name="document-text" class="size-5!" />
                  </span>
                  <span class="flex min-w-0 flex-col">
                    <span class="truncate text-sm font-semibold text-slate-800">{{
                      file.label || file.name
                    }}</span>
                    <span class="truncate text-xs text-desa-secondary"
                      >{{ file.name }} · {{ formatSize(file.size_bytes) }}</span
                    >
                  </span>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                  <button
                    type="button"
                    class="inline-flex h-9 items-center gap-1 rounded-xl px-2.5 text-xs font-semibold text-desa-dark-green hover:bg-desa-dark-green/10 cursor-pointer"
                    title="Lihat Pratinjau"
                    @click="viewAttachment(file)"
                  >
                    <AppIcon name="receipt-search" class="size-4!" />
                    Pratinjau
                  </button>
                  <button
                    type="button"
                    class="inline-flex h-9 items-center gap-1 rounded-xl px-2.5 text-xs font-medium text-desa-secondary hover:bg-desa-background hover:text-slate-800 cursor-pointer"
                    title="Unduh Berkas"
                    :disabled="opening === file.url"
                    @click="open(file.url, file.name)"
                  >
                    <AppIcon name="document-download" class="size-4!" />
                  </button>
                </div>
              </li>
            </ul>
            <p v-else class="text-desa-secondary">Tidak ada lampiran.</p>
          </div>
        </section>

        <!-- Tahapan + riwayat. -->
        <aside class="flex flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8 xl:w-[380px]">
          <div class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold">Tahapan</h2>
            <TicketProgress
              :stages="letter.stages"
              :steps="letter.steps"
              :status="letter.status"
              :rejected-level-label="letter.rejected_level_label ?? ''"
            />
          </div>
          <div class="flex flex-col gap-4 border-t border-desa-background pt-6">
            <h2 class="text-lg font-semibold">Riwayat</h2>
            <ul class="flex flex-col gap-4">
              <li v-for="step in history" :key="step.id" class="flex flex-col gap-1">
                <p class="leading-5 font-semibold">
                  {{ step.action_label }}
                  <span v-if="step.level_label" class="font-medium text-desa-secondary"
                    >· {{ step.level_label }}</span
                  >
                </p>
                <p class="text-sm text-desa-secondary">
                  {{ formatDate(step.created_at, true) }}
                  <template v-if="step.actor"> · {{ step.actor.name }}</template>
                </p>
                <p v-if="step.note" class="rounded-xl bg-desa-background/60 px-3 py-2 text-sm">
                  {{ step.note }}
                </p>
              </li>
            </ul>
          </div>
        </aside>
      </div>
    </template>

    <AppModal v-model:open="dialog.open" :title="dialogTitle" :description="dialogDescription">
      <form
        id="letter-action"
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitAction"
      >
        <AppAlert v-if="dialog.error">{{ dialog.error }}</AppAlert>

        <!-- Khusus Aksi Ready Mode: Upload Digital -->
        <div
          v-if="dialog.action === 'ready' && dialog.readyMode === 'upload'"
          class="flex flex-col gap-3 rounded-2xl bg-desa-foreshadow/60 p-4 border border-desa-dark-green/30"
        >
          <div class="flex items-center justify-between gap-2">
            <label class="text-sm font-semibold text-desa-dark-green">
              Berkas Scan Surat Resmi (Wajib)
            </label>
            <span class="text-xs text-desa-secondary">PDF atau Foto maks. 5 MB</span>
          </div>

          <input
            type="file"
            accept="application/pdf,image/*"
            class="block w-full text-sm text-desa-secondary file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-white file:text-desa-dark-green file:border file:border-desa-dark-green/30 hover:file:bg-desa-foreshadow cursor-pointer"
            @change="onDialogFileChange"
          />

          <!-- Preview Berkas yang Dipilih -->
          <div
            v-if="dialog.file"
            class="flex items-center gap-3 rounded-xl bg-white p-2.5 border border-desa-dark-green/20"
          >
            <div
              v-if="dialog.filePreview && dialog.file.type.startsWith('image/')"
              class="relative size-12 shrink-0 overflow-hidden rounded-lg border border-desa-background"
            >
              <img
                :src="dialog.filePreview"
                :alt="dialog.file.name"
                class="size-full object-cover"
              />
            </div>
            <div
              v-else
              class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-desa-foreshadow text-desa-dark-green font-bold text-xs"
            >
              {{ dialog.file.name.toLowerCase().endsWith('.pdf') ? 'PDF' : 'FILE' }}
            </div>
            <div class="flex min-w-0 flex-col">
              <p class="truncate text-sm font-semibold text-slate-800">{{ dialog.file.name }}</p>
              <p class="text-xs text-desa-secondary">{{ formatSize(dialog.file.size) }}</p>
            </div>
          </div>

          <p class="text-xs text-desa-secondary">
            Berkas ini akan terkirim langsung ke akun pemohon agar warga dapat mengunduh salinan
            digital dari aplikasi.
          </p>
          <p v-if="dialog.fileError" class="text-xs font-semibold text-desa-red">
            {{ dialog.fileError }}
          </p>
        </div>

        <!-- Khusus Aksi Ready Mode: Ambil Fisik di Kelurahan -->
        <div
          v-else-if="dialog.action === 'ready' && dialog.readyMode === 'pickup'"
          class="flex items-start gap-3 rounded-xl bg-desa-background/50 p-4 border border-desa-background"
        >
          <AppIcon name="ticket" class="size-6! text-desa-dark-green shrink-0 mt-0.5" />
          <div class="flex flex-col gap-1 text-sm text-desa-secondary">
            <p class="font-semibold text-slate-800">Penyerahan Berkas Fisik Asli</p>
            <p class="text-xs">
              Warga akan menerima pemberitahuan bahwa surat fisik asli sudah selesai dan siap
              diambil langsung di loket pelayanan kelurahan.
            </p>
          </div>
        </div>

        <div class="flex flex-col gap-1">
          <AppTextarea
            v-model="dialog.note"
            :label="
              dialog.action === 'reject'
                ? 'Alasan penolakan'
                : dialog.action === 'ready' && dialog.readyMode === 'pickup'
                  ? 'Catatan Pengambilan (opsional)'
                  : 'Catatan (opsional)'
            "
            maxlength="500"
            :placeholder="
              dialog.action === 'reject'
                ? 'Mis. surat pengantar RT belum lengkap atau tidak terbaca'
                : dialog.action === 'receive'
                  ? 'Mis. Surat diterima, estimasi selesai besok siang'
                  : dialog.action === 'ready' && dialog.readyMode === 'pickup'
                    ? 'Mis. Silakan ambil di loket pelayanan pada jam kerja (08.00 - 15.00 WIB) membawa KTP asli.'
                    : 'Mis. Surat resmi selesai diproses.'
            "
            :error="dialog.noteError"
          />
          <p v-if="dialog.action === 'receive'" class="text-xs text-desa-secondary">
            Tuliskan perkiraan waktu selesai agar pemohon dapat melihat estimasi proses surat.
          </p>
        </div>
        <div v-if="dialog.action === 'reject'" class="flex flex-wrap gap-2">
          <button
            v-for="reason in rejectReasons"
            :key="reason"
            type="button"
            class="rounded-full border border-desa-background px-3 py-1.5 text-sm font-medium text-desa-secondary transition-all duration-300 hover:border-desa-dark-green hover:text-desa-dark-green"
            @click="dialog.note = reason"
          >
            {{ reason }}
          </button>
        </div>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="dialog.open = false">Batal</AppButton>
        <AppButton
          type="submit"
          form="letter-action"
          :variant="dialog.action === 'reject' ? 'danger' : 'primary'"
          :loading="dialog.submitting"
        >
          {{ dialogButtonText }}
        </AppButton>
      </template>
    </AppModal>

    <AppModal
      v-model:open="cancelDialog.open"
      title="Batalkan Pengajuan?"
      description="Pengurus tidak akan memproses surat ini lagi. Pembatalan tidak bisa diurungkan, tetapi Anda bisa mengajukan surat baru kapan saja."
    >
      <form
        id="letter-cancel"
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitCancel"
      >
        <AppAlert v-if="cancelDialog.error">{{ cancelDialog.error }}</AppAlert>
        <AppTextarea
          v-model="cancelDialog.reason"
          label="Alasan (opsional)"
          maxlength="500"
          placeholder="Mis. sudah tidak diperlukan"
        />
        <div class="flex flex-wrap gap-2">
          <button
            v-for="reason in cancelReasons"
            :key="reason"
            type="button"
            class="rounded-full border border-desa-background px-3 py-1.5 text-sm font-medium text-desa-secondary transition-all duration-300 hover:border-desa-dark-green hover:text-desa-dark-green"
            @click="cancelDialog.reason = reason"
          >
            {{ reason }}
          </button>
        </div>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="cancelDialog.open = false">Tidak jadi</AppButton>
        <AppButton
          type="submit"
          form="letter-cancel"
          variant="danger"
          :loading="cancelDialog.submitting"
        >
          Ya, Batalkan
        </AppButton>
      </template>
    </AppModal>

    <!-- Modal Pratinjau Lampiran / Berkas Resmi -->
    <AppModal
      :open="previewFile.open"
      :title="previewFile.title"
      :description="previewFile.subtitle"
      @update:open="
        (val) => {
          if (!val) closeAttachmentPreview()
        }
      "
    >
      <div v-if="previewFile.loading" class="flex flex-col items-center justify-center py-12 gap-3">
        <span class="text-sm font-medium text-desa-secondary">Memuat pratinjau berkas…</span>
      </div>

      <div v-else-if="previewFile.blobUrl" class="flex flex-col items-center justify-center">
        <!-- Gambar -->
        <div
          v-if="previewFile.isImage"
          class="max-h-[70vh] w-full overflow-auto rounded-xl bg-desa-background/40 p-2 flex items-center justify-center"
        >
          <img
            :src="previewFile.blobUrl"
            :alt="previewFile.name"
            class="max-h-[65vh] max-w-full rounded-lg object-contain shadow-xs"
          />
        </div>

        <!-- PDF -->
        <div
          v-else-if="previewFile.isPdf"
          class="w-full h-[65vh] rounded-xl overflow-hidden border border-desa-background"
        >
          <iframe :src="previewFile.blobUrl" class="size-full" title="Pratinjau PDF" />
        </div>

        <!-- Berkas lain -->
        <div v-else class="py-8 text-center text-desa-secondary font-medium">
          Format berkas tidak mendukung pratinjau langsung di peramban. Silakan unduh berkas untuk
          membukanya.
        </div>
      </div>

      <template #footer>
        <div class="flex w-full items-center justify-between gap-3">
          <a
            v-if="previewFile.blobUrl"
            :href="previewFile.blobUrl"
            :download="previewFile.name"
            class="text-sm font-medium text-desa-dark-green hover:underline inline-flex items-center gap-1.5"
          >
            <AppIcon name="document-download" class="size-4!" />
            Unduh Berkas
          </a>
          <AppButton variant="secondary" @click="closeAttachmentPreview">Tutup</AppButton>
        </div>
      </template>
    </AppModal>
  </div>
</template>
