<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
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

function onRated(rating, message) {
  letter.value = { ...letter.value, rating, can: { ...letter.value.can, rate: false } }
  notice.value = message
}
const actions = computed(() => letter.value?.can.actions ?? [])
const primaryActions = computed(() => actions.value.filter((a) => a !== 'reject'))
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
  note: '',
  noteError: '',
  error: '',
  submitting: false,
})

function openAction(action) {
  Object.assign(dialog, { open: true, action, note: '', noteError: '', error: '' })
}

async function submitAction() {
  dialog.submitting = true
  dialog.error = ''
  dialog.noteError = ''
  try {
    const { data } = await api.post(`/letters/${letter.value.id}/${dialog.action}`, {
      note: dialog.note || undefined,
    })
    letter.value = data.data
    notice.value = data.message
    dialog.open = false
  } catch (err) {
    dialog.noteError = fieldErrors(err).note ?? ''
    if (!dialog.noteError) dialog.error = errorMessage(err)
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
          <AppBadge :variant="statusBadge[letter.status]">{{ letter.status_label }}</AppBadge>
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
          Surat sudah bisa diambil di kantor desa. {{ letter.service?.notes }}
        </AppAlert>

        <div
          v-if="actions.length || letter.can.print || isOwner"
          class="flex flex-col gap-3 sm:flex-row sm:flex-wrap"
        >
          <AppButton v-for="action in primaryActions" :key="action" @click="openAction(action)">
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
            <ul v-if="letter.attachments?.length" class="grid gap-2 sm:grid-cols-2">
              <li v-for="file in letter.attachments" :key="file.id">
                <button
                  type="button"
                  class="flex w-full items-center gap-3 rounded-2xl border border-desa-background p-3 text-left transition-all duration-300 hover:border-desa-dark-green disabled:opacity-60"
                  :disabled="opening === file.url"
                  @click="open(file.url, file.name)"
                >
                  <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-desa-foreshadow"
                  >
                    <AppIcon name="document-text" class="size-5! text-desa-dark-green" />
                  </span>
                  <span class="flex min-w-0 flex-col">
                    <span class="truncate text-sm font-semibold">{{
                      file.label || file.name
                    }}</span>
                    <span class="truncate text-xs text-desa-secondary"
                      >{{ file.name }} · {{ formatSize(file.size_bytes) }}</span
                    >
                  </span>
                </button>
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

    <AppModal
      v-model:open="dialog.open"
      :title="actionText[dialog.action].title"
      :description="actionText[dialog.action].description"
    >
      <form
        id="letter-action"
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitAction"
      >
        <AppAlert v-if="dialog.error">{{ dialog.error }}</AppAlert>
        <AppTextarea
          v-model="dialog.note"
          :label="dialog.action === 'reject' ? 'Alasan penolakan' : 'Catatan (opsional)'"
          maxlength="500"
          :placeholder="dialog.action === 'reject' ? 'Mis. foto KTP tidak terbaca' : ''"
          :error="dialog.noteError"
        />
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
          {{ actionText[dialog.action].button }}
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
  </div>
</template>
