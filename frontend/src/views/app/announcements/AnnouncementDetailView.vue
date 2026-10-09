<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AuthImage from '@/components/AuthImage.vue'
import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppModal from '@/components/ui/AppModal.vue'
import { shareText, stateBadge, whatsappShareUrl } from '@/lib/announcements'
import { api, errorMessage, openFile } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { formatSize } from '@/lib/letters'

const route = useRoute()
const router = useRouter()

const announcement = ref(null)
const loading = ref(true)
const error = ref('')
// Pesan dari form (dibuat/disimpan) dibawa lewat history state.
const notice = ref(window.history.state?.notice ?? '')
const copied = ref(false)
const confirmDelete = ref(false)
const deleting = ref(false)
const deleteError = ref('')

const images = computed(() => announcement.value?.attachments.filter((a) => a.is_image) ?? [])
const files = computed(() => announcement.value?.attachments.filter((a) => !a.is_image) ?? [])

async function load() {
  error.value = ''
  try {
    const { data } = await api.get(`/announcements/${route.params.id}`)
    announcement.value = data.data
  } catch (err) {
    error.value =
      err.response?.status === 404
        ? 'Pengumuman ini sudah dihapus atau tidak ditemukan.'
        : errorMessage(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function open(file) {
  error.value = ''
  try {
    await openFile(file.url, file.name)
  } catch (err) {
    error.value = errorMessage(err, 'Lampiran tidak bisa dibuka. Coba lagi.')
  }
}

async function copyText() {
  try {
    await navigator.clipboard.writeText(shareText(announcement.value))
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    copied.value = false
  }
}

async function remove() {
  deleting.value = true
  deleteError.value = ''
  try {
    const { data } = await api.delete(`/announcements/${announcement.value.id}`)
    router.push({
      name: 'announcements',
      query: { tab: 'kelola' },
      state: { notice: data.message },
    })
  } catch (err) {
    deleteError.value = errorMessage(err)
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <RouterLink
      :to="{ name: 'announcements' }"
      class="flex w-fit items-center gap-2 font-medium text-desa-secondary transition-all duration-300 hover:text-desa-dark-green"
    >
      <AppIcon name="arrow-left" />
      Pengumuman
    </RouterLink>

    <AppAlert v-if="error">{{ error }}</AppAlert>
    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat pengumuman…
    </div>

    <template v-else-if="announcement">
      <AppAlert v-if="notice" variant="success">{{ notice }}</AppAlert>

      <div class="flex flex-col gap-[14px] xl:flex-row xl:items-start">
        <article class="flex min-w-0 flex-1 flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8">
          <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
              <AppBadge variant="success">
                <AppIcon name="location" class="mr-1 size-3.5!" />
                {{ announcement.target.label }}
              </AppBadge>
              <AppBadge
                v-if="announcement.state !== 'active'"
                :variant="stateBadge[announcement.state]"
                >{{ announcement.state_label }}</AppBadge
              >
            </div>
            <h1 class="text-xl leading-7 font-semibold sm:text-2xl">{{ announcement.title }}</h1>
            <p class="text-sm text-desa-secondary">
              {{ announcement.author?.name }} · {{ announcement.author?.role_label }}
            </p>
          </div>

          <p class="leading-relaxed whitespace-pre-line">{{ announcement.body }}</p>

          <ul v-if="images.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <li v-for="(image, i) in images" :key="image.id">
              <button
                type="button"
                class="block aspect-square w-full overflow-hidden rounded-2xl bg-desa-background ring-desa-dark-green transition-all duration-300 hover:ring-[1.5px]"
                :aria-label="`Buka gambar ${i + 1}`"
                @click="open(image)"
              >
                <AuthImage :src="image.url" :alt="image.name" />
              </button>
            </li>
          </ul>
          <ul v-if="files.length" class="flex flex-col gap-2">
            <li v-for="file in files" :key="file.id">
              <button
                type="button"
                class="flex w-full items-center gap-3 rounded-2xl border border-desa-background p-4 text-left transition-all duration-300 hover:border-desa-dark-green"
                @click="open(file)"
              >
                <AppIcon name="document-text" class="text-desa-dark-green" />
                <span class="min-w-0 flex-1 truncate font-medium">{{ file.name }}</span>
                <span class="text-sm text-desa-secondary">{{ formatSize(file.size_bytes) }}</span>
              </button>
            </li>
          </ul>
        </article>

        <aside class="flex flex-col gap-6 rounded-2xl bg-white p-6 sm:p-8 xl:w-[380px]">
          <dl class="flex flex-col gap-4">
            <div class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">
                {{ announcement.state === 'scheduled' ? 'Dijadwalkan terbit' : 'Diterbitkan' }}
              </dt>
              <dd class="leading-5 font-semibold">
                {{ formatDate(announcement.published_at, true) }}
              </dd>
            </div>
            <div class="flex flex-col gap-1">
              <dt class="text-sm font-medium text-desa-secondary">Berlaku sampai</dt>
              <dd class="leading-5 font-semibold">
                {{
                  announcement.expires_at
                    ? formatDate(announcement.expires_at, true)
                    : 'Tanpa batas waktu'
                }}
              </dd>
            </div>
          </dl>

          <div class="flex flex-col gap-3 border-t border-desa-background pt-6">
            <a
              :href="whatsappShareUrl(announcement)"
              target="_blank"
              rel="noopener"
              class="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-desa-dark-green px-5 font-medium text-white transition-all duration-300 hover:bg-[#2a5030]"
            >
              <AppIcon name="call" />
              Bagikan ke WhatsApp
            </a>
            <AppButton variant="secondary" @click="copyText">
              <AppIcon :name="copied ? 'tick-circle' : 'document-text'" />
              {{ copied ? 'Teks tersalin' : 'Salin teks pengumuman' }}
            </AppButton>
            <template v-if="announcement.can.manage">
              <RouterLink
                :to="{ name: 'announcement-edit', params: { id: announcement.id } }"
                class="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl border border-desa-background bg-white px-5 font-medium text-desa-dark-green transition-all duration-300 hover:border-desa-dark-green hover:bg-desa-foreshadow"
              >
                <AppIcon name="edit" />
                Ubah
              </RouterLink>
              <AppButton variant="ghost" class="text-desa-red!" @click="confirmDelete = true">
                <AppIcon name="close-circle" />
                Hapus
              </AppButton>
            </template>
          </div>
        </aside>
      </div>
    </template>

    <AppModal
      v-model:open="confirmDelete"
      title="Hapus pengumuman?"
      description="Pengumuman tidak lagi terlihat oleh warga, dan notifikasinya ikut dihapus."
    >
      <AppAlert v-if="deleteError">{{ deleteError }}</AppAlert>
      <template #footer>
        <AppButton variant="secondary" data-close @click="confirmDelete = false">Batal</AppButton>
        <AppButton variant="danger" :loading="deleting" @click="remove">Hapus</AppButton>
      </template>
    </AppModal>
  </div>
</template>
