<script setup>
import { computed, defineAsyncComponent, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import SmartComplaintCard from '@/components/ai/SmartComplaintCard.vue'
import AuthImage from '@/components/AuthImage.vue'
import RatingCard from '@/components/ratings/RatingCard.vue'
import TicketProgress from '@/components/TicketProgress.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { api, errorMessage, fieldErrors, openFile } from '@/lib/api'
import {
  actionText,
  formatCoords,
  mapLink,
  rejectReasons,
  statusBadge,
  urgencyBadge,
  urgencyOptions,
} from '@/lib/complaints'
import { statusColors } from '@/lib/complaintMap'
import { formatDate, formatPhone, whatsappLink } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

// Leaflet hanya dimuat kalau laporan punya titik lokasi.
const LocationMap = defineAsyncComponent(() => import('@/components/map/LocationMap.vue'))

// Tombol aksi hanya mengikuti `can` dari backend; backend tetap memeriksa ulang.
const auth = useAuthStore()
const route = useRoute()

const complaint = ref(null)
const loading = ref(true)
const error = ref('')
const notice = ref(route.query.baru ? 'new' : '')
const copied = ref(false)

const actions = computed(() => complaint.value?.can.actions ?? [])
const primaryActions = computed(() => actions.value.filter((a) => a !== 'reject'))
const history = computed(() => [...(complaint.value?.steps ?? [])].reverse())
const resolution = computed(
  () => complaint.value?.steps.findLast((step) => step.action === 'resolved')?.note ?? '',
)
const ticketUrl = computed(
  () => `${window.location.origin}/cek-tiket?kode=${complaint.value?.code ?? ''}`,
)
const shareText = computed(
  () =>
    `Kode tiket laporan saya: ${complaint.value?.code}. ` + `Cek statusnya di ${ticketUrl.value}`,
)

function onAiUpdated(data, message) {
  complaint.value = data
  if (message) notice.value = message
}

function onRated(rating, message) {
  complaint.value = { ...complaint.value, rating, can: { ...complaint.value.can, rate: false } }
  notice.value = message
}

async function load() {
  error.value = ''
  try {
    const { data } = await api.get(`/complaints/${route.params.id}`)
    complaint.value = data.data
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function openPhoto(photo, index) {
  error.value = ''
  try {
    await openFile(photo.url, `${complaint.value.code}-foto-${index + 1}.jpg`)
  } catch (err) {
    error.value = errorMessage(err, 'Foto tidak bisa dibuka. Coba lagi.')
  }
}

async function copyCode() {
  try {
    await navigator.clipboard.writeText(complaint.value.code)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    copied.value = false
  }
}

// Aksi status ------------------------------------------------------------------

const dialog = reactive({
  open: false,
  action: 'verify',
  note: '',
  target: '',
  errors: {},
  error: '',
  submitting: false,
})
const noteRequired = computed(() => ['reject', 'resolve'].includes(dialog.action))
const targetOptions = computed(() => complaint.value?.can.forward_to ?? [])

function openAction(action) {
  Object.assign(dialog, {
    open: true,
    action,
    note: '',
    target: targetOptions.value.at(-1)?.value ?? '',
    errors: {},
    error: '',
  })
}

async function submitAction() {
  dialog.submitting = true
  dialog.errors = {}
  dialog.error = ''
  try {
    const { data } = await api.post(`/complaints/${complaint.value.id}/${dialog.action}`, {
      note: dialog.note || undefined,
      target: dialog.action === 'forward' ? dialog.target : undefined,
    })
    complaint.value = data.data
    notice.value = data.message
    dialog.open = false
  } catch (err) {
    dialog.errors = fieldErrors(err)
    if (!Object.keys(dialog.errors).length) dialog.error = errorMessage(err)
    // Laporan sudah dipindahkan pengurus lain: muat ulang supaya tombolnya sesuai.
    if (err.response?.status === 409) load()
  } finally {
    dialog.submitting = false
  }
}

// Kategori & urgensi -------------------------------------------------------------

const classify = reactive({
  open: false,
  category_id: '',
  urgency: '',
  options: [],
  errors: {},
  error: '',
  submitting: false,
})

async function openClassify() {
  Object.assign(classify, {
    open: true,
    category_id: complaint.value.category.id,
    urgency: complaint.value.urgency ?? 'none',
    errors: {},
    error: '',
  })
  if (classify.options.length) return
  try {
    const { data } = await api.get('/complaint-categories')
    classify.options = data.data.map((parent) => ({
      label: parent.name,
      options: parent.children.map((child) => ({ value: child.id, label: child.name })),
    }))
  } catch (err) {
    classify.error = errorMessage(err)
  }
}

async function submitClassify() {
  classify.submitting = true
  classify.errors = {}
  classify.error = ''
  try {
    const { data } = await api.put(`/complaints/${complaint.value.id}/classification`, {
      category_id: classify.category_id,
      urgency: classify.urgency === 'none' ? null : classify.urgency,
    })
    complaint.value = data.data
    notice.value = data.message
    classify.open = false
  } catch (err) {
    classify.errors = fieldErrors(err)
    if (!Object.keys(classify.errors).length) classify.error = errorMessage(err)
  } finally {
    classify.submitting = false
  }
}

// Tanggapan ------------------------------------------------------------------------

const reply = reactive({ body: '', internal: false, error: '', submitting: false })

async function sendReply() {
  reply.submitting = true
  reply.error = ''
  try {
    const { data } = await api.post(`/complaints/${complaint.value.id}/comments`, {
      body: reply.body,
      is_internal: reply.internal,
    })
    complaint.value = data.data
    reply.body = ''
  } catch (err) {
    reply.error = fieldErrors(err).body ?? errorMessage(err)
  } finally {
    reply.submitting = false
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
      {{ auth.hasRole('warga') ? 'Laporan Saya' : 'Laporan Warga' }}
    </RouterLink>

    <AppAlert v-if="error">{{ error }}</AppAlert>
    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat laporan…
    </div>

    <template v-else-if="complaint">
      <AppAlert v-if="notice === 'new'" variant="success">
        Laporan terkirim ke ketua {{ complaint.rt?.label }}. Simpan kode tiket
        <strong>{{ complaint.code }}</strong> untuk mengecek status tanpa masuk.
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
              {{ complaint.code }}
              <AppIcon :name="copied ? 'tick-circle' : 'document-text'" class="size-4!" />
              <span class="sr-only">{{ copied ? 'Tersalin' : 'Salin kode' }}</span>
            </button>
            <h1 class="text-xl leading-7 font-semibold sm:text-2xl">{{ complaint.title }}</h1>
            <p class="text-sm text-desa-secondary">
              Dilaporkan {{ formatDate(complaint.created_at, true) }}
              <template v-if="!['resolved', 'rejected'].includes(complaint.status)">
                · Dipegang {{ complaint.handling_level_label }}
              </template>
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <AppBadge v-if="complaint.urgency" :variant="urgencyBadge[complaint.urgency]"
              >Urgensi {{ complaint.urgency_label }}</AppBadge
            >
            <AppBadge :variant="statusBadge[complaint.status]">{{
              complaint.status_label
            }}</AppBadge>
          </div>
        </div>

        <AppAlert v-if="complaint.status === 'rejected'" variant="warning">
          <strong>Ditolak {{ complaint.handling_level_label }}:</strong>
          {{ complaint.rejection_reason }}
        </AppAlert>
        <AppAlert v-else-if="complaint.status === 'resolved'" variant="success">
          <strong>Selesai ditangani {{ complaint.handling_level_label }}:</strong>
          {{ resolution }}
        </AppAlert>

        <div
          v-if="actions.length || complaint.can.classify || complaint.is_mine"
          class="flex flex-col gap-3 sm:flex-row sm:flex-wrap"
        >
          <AppButton
            v-for="action in primaryActions"
            :key="action"
            :variant="action === 'forward' ? 'secondary' : 'primary'"
            @click="openAction(action)"
          >
            <AppIcon :name="actionText[action].icon" />
            {{ actionText[action].button }}
          </AppButton>
          <AppButton v-if="complaint.can.classify" variant="secondary" @click="openClassify">
            <AppIcon name="edit" />
            Kategori & Urgensi
          </AppButton>
          <AppButton
            v-if="actions.includes('reject')"
            variant="secondary"
            @click="openAction('reject')"
          >
            <AppIcon name="close-circle" />
            Tolak
          </AppButton>
          <a
            v-if="complaint.is_mine"
            :href="`https://wa.me/?text=${encodeURIComponent(shareText)}`"
            target="_blank"
            rel="noopener"
            class="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl px-5 font-medium text-desa-secondary transition-all duration-300 hover:bg-desa-foreshadow hover:text-desa-dark-green"
          >
            <AppIcon name="call" />
            Bagikan kode lewat WhatsApp
          </a>
        </div>
      </section>

      <!-- Saran AI hanya dikirim backend untuk pengurus dan perangkat desa. -->
      <SmartComplaintCard
        v-if="'ai_label' in complaint"
        :complaint="complaint"
        @updated="onAiUpdated"
      />

      <RatingCard
        :rating="complaint.rating"
        :can-rate="complaint.can.rate"
        :endpoint="`/complaints/${complaint.id}/rating`"
        :is-mine="complaint.is_mine"
        subject="laporan"
        @rated="onRated"
      />

      <div class="flex flex-col gap-[14px] xl:flex-row xl:items-start">
        <div class="flex min-w-0 flex-1 flex-col gap-[14px]">
          <!-- Isi laporan. -->
          <section class="flex flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8">
            <h2 class="text-lg font-semibold">Isi laporan</h2>
            <p class="leading-relaxed whitespace-pre-line">{{ complaint.description }}</p>

            <ul v-if="complaint.photos?.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <li v-for="(photo, i) in complaint.photos" :key="photo.id">
                <button
                  type="button"
                  class="block aspect-square w-full overflow-hidden rounded-2xl ring-desa-dark-green transition-all duration-300 hover:ring-[1.5px]"
                  :aria-label="`Buka foto ${i + 1}`"
                  @click="openPhoto(photo, i)"
                >
                  <AuthImage :src="photo.url" :alt="`Foto laporan ${i + 1}`" />
                </button>
              </li>
            </ul>

            <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
              <div v-if="complaint.reporter && !complaint.is_mine" class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Pelapor</dt>
                <dd class="leading-5 font-semibold">{{ complaint.reporter.name }}</dd>
                <dd class="flex flex-wrap gap-x-3 text-sm font-medium">
                  <a
                    :href="`tel:+${complaint.reporter.phone}`"
                    class="text-desa-secondary hover:text-desa-dark-green"
                    >{{ formatPhone(complaint.reporter.phone) }}</a
                  >
                  <a
                    :href="
                      whatsappLink(
                        complaint.reporter.phone,
                        `Halo, terkait laporan ${complaint.code} di Rukana: `,
                      )
                    "
                    target="_blank"
                    rel="noopener"
                    class="text-desa-dark-green hover:underline"
                    >WhatsApp</a
                  >
                </dd>
              </div>
              <div v-else-if="!complaint.is_mine" class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Pelapor</dt>
                <dd class="leading-5 text-desa-secondary">
                  Hanya terlihat oleh pengurus yang menangani.
                </dd>
              </div>
              <div class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Wilayah</dt>
                <dd class="leading-5 font-semibold">
                  {{ complaint.rt?.label }} / {{ complaint.rw?.label }}
                </dd>
              </div>
              <div class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Kategori</dt>
                <dd class="leading-5 font-semibold">
                  {{ complaint.category?.parent_name }} · {{ complaint.category?.name }}
                </dd>
                <dd v-if="complaint.reported_category" class="text-sm text-desa-secondary">
                  Dikoreksi dari pilihan pelapor: {{ complaint.reported_category.name }}
                </dd>
              </div>
              <div class="flex flex-col gap-1">
                <dt class="text-sm font-medium text-desa-secondary">Waktu kejadian</dt>
                <dd class="leading-5 font-semibold">
                  {{ complaint.occurred_at ? formatDate(complaint.occurred_at, true) : '—' }}
                </dd>
              </div>
              <div class="flex flex-col gap-1 sm:col-span-2">
                <dt class="text-sm font-medium text-desa-secondary">Lokasi</dt>
                <dd v-if="complaint.location.text" class="leading-5 font-semibold">
                  {{ complaint.location.text }}
                </dd>
                <dd v-if="complaint.location.latitude !== null" class="flex flex-col gap-2">
                  <LocationMap
                    :point="complaint.location"
                    :color="statusColors[complaint.status]"
                    class="mt-1"
                  />
                  <a
                    :href="mapLink(complaint.location)"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-desa-dark-green underline-offset-4 hover:underline"
                  >
                    <AppIcon name="location" class="size-4!" />
                    Buka di OpenStreetMap ({{ formatCoords(complaint.location) }})
                  </a>
                </dd>
              </div>
            </dl>
          </section>

          <!-- Tanggapan pengurus dan pelapor. -->
          <section class="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
            <h2 class="text-lg font-semibold">Tanggapan</h2>
            <ul v-if="complaint.comments?.length" class="flex flex-col gap-3">
              <li
                v-for="comment in complaint.comments"
                :key="comment.id"
                :class="[
                  'flex flex-col gap-1 rounded-2xl px-4 py-3',
                  comment.is_internal
                    ? 'bg-desa-yellow/15'
                    : comment.author?.is_reporter
                      ? 'bg-desa-background/60'
                      : 'bg-desa-foreshadow',
                ]"
              >
                <p class="flex flex-wrap items-center gap-x-2 text-sm">
                  <span class="font-semibold">{{
                    comment.is_mine ? 'Anda' : comment.author?.name
                  }}</span>
                  <span class="text-desa-secondary">{{ comment.author?.role_label }}</span>
                  <span class="text-desa-secondary"
                    >· {{ formatDate(comment.created_at, true) }}</span
                  >
                  <AppBadge v-if="comment.is_internal" variant="warning">Catatan internal</AppBadge>
                </p>
                <p class="leading-relaxed whitespace-pre-line">{{ comment.body }}</p>
              </li>
            </ul>
            <p v-else class="text-desa-secondary">
              {{
                complaint.is_mine
                  ? 'Belum ada tanggapan. Pengurus akan menanggapi di sini.'
                  : 'Belum ada tanggapan.'
              }}
            </p>

            <form
              v-if="complaint.can.comment || complaint.can.comment_internal"
              class="flex flex-col gap-3 border-t border-desa-background pt-5"
              novalidate
              @submit.prevent="sendReply"
            >
              <AppTextarea
                v-model="reply.body"
                :label="
                  reply.internal
                    ? 'Catatan internal'
                    : complaint.is_mine
                      ? 'Balas pengurus'
                      : 'Tanggapan untuk pelapor'
                "
                maxlength="1000"
                :placeholder="
                  reply.internal
                    ? 'Hanya terlihat oleh pengurus yang menangani.'
                    : complaint.is_mine
                      ? 'Mis. tambahan informasi lokasi'
                      : 'Mis. petugas akan datang besok pagi'
                "
                :error="reply.error"
              />
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <label
                  v-if="complaint.can.comment_internal"
                  class="flex items-center gap-2 font-medium text-desa-secondary"
                >
                  <input
                    v-model="reply.internal"
                    type="checkbox"
                    class="size-5 accent-desa-dark-green"
                  />
                  Catatan internal (tidak terlihat pelapor)
                </label>
                <AppButton type="submit" :loading="reply.submitting" :disabled="!reply.body.trim()">
                  <AppIcon name="sms" />
                  Kirim
                </AppButton>
              </div>
            </form>
          </section>
        </div>

        <!-- Tahapan + riwayat. -->
        <aside class="flex flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8 xl:w-[380px]">
          <div class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold">Tahapan</h2>
            <TicketProgress
              :stages="complaint.stages"
              :steps="complaint.steps"
              :status="complaint.status"
              :rejected-level-label="
                complaint.status === 'rejected' ? complaint.handling_level_label : ''
              "
            />
          </div>
          <div class="flex flex-col gap-4 border-t border-desa-background pt-6">
            <h2 class="text-lg font-semibold">Riwayat</h2>
            <ul class="flex flex-col gap-4">
              <li v-for="step in history" :key="step.id" class="flex flex-col gap-1">
                <p class="leading-5 font-semibold">
                  {{ step.action_label }}
                  <span class="font-medium text-desa-secondary">
                    ·
                    {{
                      step.action === 'forwarded'
                        ? `${step.from_level_label} → ${step.to_level_label}`
                        : (step.from_level_label ?? step.to_level_label)
                    }}
                  </span>
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
        id="complaint-action"
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitAction"
      >
        <AppAlert v-if="dialog.error">{{ dialog.error }}</AppAlert>
        <fieldset v-if="dialog.action === 'forward'" class="flex flex-col gap-3">
          <legend class="mb-3 leading-5 font-medium text-desa-secondary">Teruskan ke</legend>
          <label
            v-for="option in targetOptions"
            :key="option.value"
            :class="[
              'flex h-14 cursor-pointer items-center gap-3 rounded-2xl border-[1.5px] px-4 font-medium transition-all duration-300',
              dialog.target === option.value
                ? 'border-desa-dark-green bg-desa-foreshadow text-desa-dark-green'
                : 'border-desa-background',
            ]"
          >
            <input
              v-model="dialog.target"
              type="radio"
              name="target"
              :value="option.value"
              class="size-5 accent-desa-dark-green"
            />
            {{ option.value === 'village' ? 'Desa (operator desa)' : option.label }}
          </label>
          <p v-if="dialog.errors.target" class="text-sm font-medium text-desa-red">
            {{ dialog.errors.target }}
          </p>
        </fieldset>
        <AppTextarea
          v-model="dialog.note"
          :label="
            dialog.action === 'reject'
              ? 'Alasan penolakan'
              : dialog.action === 'resolve'
                ? 'Tindak lanjut'
                : 'Catatan (opsional)'
          "
          maxlength="1000"
          :placeholder="
            dialog.action === 'resolve'
              ? 'Mis. lampu sudah diganti oleh petugas PLN'
              : dialog.action === 'forward'
                ? 'Mis. perlu anggaran desa untuk perbaikan'
                : ''
          "
          :required="noteRequired"
          :error="dialog.errors.note"
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
          form="complaint-action"
          :variant="dialog.action === 'reject' ? 'danger' : 'primary'"
          :loading="dialog.submitting"
        >
          {{ actionText[dialog.action].button }}
        </AppButton>
      </template>
    </AppModal>

    <AppModal
      v-model:open="classify.open"
      title="Kategori & Urgensi"
      description="Koreksi kategori bila pilihan pelapor kurang tepat. Pilihan awal pelapor tetap tercatat."
    >
      <form
        id="complaint-classify"
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitClassify"
      >
        <AppAlert v-if="classify.error">{{ classify.error }}</AppAlert>
        <AppSelect
          v-model="classify.category_id"
          label="Kategori"
          icon="grid-5"
          :options="classify.options"
          :error="classify.errors.category_id"
        />
        <AppSelect
          v-model="classify.urgency"
          label="Urgensi"
          icon="timer"
          :options="urgencyOptions"
          :error="classify.errors.urgency"
        />
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="classify.open = false">Batal</AppButton>
        <AppButton type="submit" form="complaint-classify" :loading="classify.submitting">
          Simpan
        </AppButton>
      </template>
    </AppModal>
  </div>
</template>
