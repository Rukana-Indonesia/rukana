<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api, errorMessage } from '@/lib/api'
import { estimateLabel } from '@/lib/letters'
import { useAuthStore } from '@/stores/auth'

// Katalog dibuka tanpa login supaya warga tahu syaratnya sebelum mengajukan (PRD 6.2).
const auth = useAuthStore()
const services = ref([])
const loading = ref(true)
const error = ref('')
const openSlug = ref(null)

const canSubmit = computed(() => auth.isActive && auth.hasRole('warga'))

function submitLink(service) {
  if (canSubmit.value) return { name: 'letter-create', params: { slug: service.slug } }
  if (auth.isLoggedIn) return { name: 'dashboard' }
  return { name: 'login', query: { redirect: `/app/surat/ajukan/${service.slug}` } }
}

onMounted(async () => {
  try {
    const { data } = await api.get('/services')
    services.value = data.data
    if (location.hash) openSlug.value = location.hash.slice(1)
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="mx-auto flex max-w-4xl flex-col gap-[14px] px-4 py-6 sm:py-10">
    <section class="gradient-vertical flex flex-col gap-3 rounded-3xl p-6 sm:p-10">
      <p class="text-sm font-medium text-desa-lime">— Katalog Layanan</p>
      <h1 class="text-[28px] leading-tight font-semibold text-white sm:text-4xl">
        Syarat & alur surat pengantar
      </h1>
      <p class="max-w-2xl leading-relaxed text-white/90">
        Cek dokumen yang perlu disiapkan dan siapa saja yang memproses sebelum mengajukan, supaya
        tidak bolak-balik.
      </p>
    </section>

    <AppAlert v-if="error">{{ error }}</AppAlert>
    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat katalog…
    </div>

    <ul v-else class="flex flex-col gap-[14px]">
      <li
        v-for="service in services"
        :id="service.slug"
        :key="service.id"
        class="scroll-mt-24 rounded-2xl bg-white"
      >
        <button
          type="button"
          class="flex w-full items-start gap-4 p-5 text-left sm:p-6"
          :aria-expanded="openSlug === service.slug"
          :aria-controls="`${service.slug}-detail`"
          @click="openSlug = openSlug === service.slug ? null : service.slug"
        >
          <span
            class="flex size-12 shrink-0 items-center justify-center rounded-full bg-desa-foreshadow"
          >
            <AppIcon name="document-text" class="text-desa-dark-green" />
          </span>
          <span class="flex min-w-0 flex-1 flex-col gap-1.5">
            <span class="text-lg leading-6 font-semibold">{{ service.name }}</span>
            <span class="text-sm leading-5 text-desa-secondary">{{ service.purpose }}</span>
            <span class="flex flex-wrap gap-2 pt-1">
              <span
                class="rounded-full bg-desa-background px-3 py-1 text-xs font-semibold text-desa-secondary"
                >{{ service.flow_label }}</span
              >
              <span
                v-if="service.estimated_days"
                class="rounded-full bg-desa-background px-3 py-1 text-xs font-semibold text-desa-secondary"
                >{{ estimateLabel(service.estimated_days) }}</span
              >
            </span>
          </span>
          <AppIcon
            name="arrow-down"
            :class="[
              'mt-3 text-desa-secondary transition-transform duration-300',
              openSlug === service.slug && 'rotate-180',
            ]"
          />
        </button>

        <div
          v-if="openSlug === service.slug"
          :id="`${service.slug}-detail`"
          class="flex flex-col gap-5 border-t border-desa-background p-5 sm:p-6"
        >
          <div class="grid gap-5 md:grid-cols-2">
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
              <p v-if="service.requires_nik" class="text-sm text-desa-secondary">
                NIK diisi di form pengajuan dan disamarkan di tampilan.
              </p>
            </div>
            <div class="flex flex-col gap-3">
              <h2 class="font-semibold">Alur proses</h2>
              <ol class="flex flex-wrap items-center gap-2">
                <li class="rounded-full bg-desa-foreshadow px-3 py-1.5 text-sm font-medium">
                  Diajukan
                </li>
                <template v-for="level in service.flow_levels" :key="level">
                  <AppIcon name="arrow-square-right" class="size-4! text-desa-secondary" />
                  <li class="rounded-full bg-desa-foreshadow px-3 py-1.5 text-sm font-medium">
                    {{ level }}
                  </li>
                </template>
              </ol>
              <p class="text-sm leading-5 text-desa-secondary">
                Estimasi: {{ estimateLabel(service.estimated_days) }}
              </p>
            </div>
          </div>
          <AppAlert v-if="service.notes" variant="info">{{ service.notes }}</AppAlert>
          <RouterLink
            :to="submitLink(service)"
            class="inline-flex h-14 items-center justify-center gap-2 rounded-2xl bg-desa-dark-green px-5 font-medium text-white transition-all duration-300 hover:bg-[#2a5030] sm:w-fit"
          >
            <AppIcon name="add-square" />
            {{ auth.isLoggedIn ? 'Ajukan layanan ini' : 'Masuk untuk mengajukan' }}
          </RouterLink>
        </div>
      </li>
    </ul>

    <RouterLink
      :to="{ name: 'ticket-check' }"
      class="flex items-center justify-between gap-3 rounded-2xl bg-white p-5 font-medium text-desa-dark-green transition-all duration-300 hover:ring-[1.5px] hover:ring-desa-dark-green sm:p-6"
    >
      <span class="flex items-center gap-3">
        <AppIcon name="ticket" />
        Sudah mengajukan? Cek status dengan kode tiket
      </span>
      <AppIcon name="arrow-square-right" />
    </RouterLink>
  </div>
</template>
