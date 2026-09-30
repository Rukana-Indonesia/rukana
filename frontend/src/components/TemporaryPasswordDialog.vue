<script setup>
import { computed, ref, watch } from 'vue'

import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppModal from '@/components/ui/AppModal.vue'
import { formatPhone, whatsappLink } from '@/lib/format'

// Kata sandi sementara hanya dikirim backend sekali; tampilkan lalu biarkan hilang saat ditutup.
const open = defineModel('open', { type: Boolean, default: false })

const props = defineProps({
  user: { type: Object, default: null }, // { name, phone }
  password: { type: String, default: '' },
})

const copied = ref(false)
watch(open, () => (copied.value = false))

const message = computed(
  () =>
    `Halo ${props.user?.name}, kata sandi sementara akun Rukana Anda: ${props.password}\n` +
    `Masuk di ${window.location.origin}/masuk dengan no. HP ${formatPhone(props.user?.phone)}, ` +
    'lalu segera ganti kata sandinya.',
)

async function copy() {
  try {
    await navigator.clipboard.writeText(props.password)
    copied.value = true
  } catch {
    copied.value = false
  }
}
</script>

<template>
  <AppModal
    v-model:open="open"
    title="Kata Sandi Sementara"
    description="Sampaikan langsung ke pemilik akun. Kata sandi ini hanya ditampilkan sekali dan wajib diganti saat masuk."
  >
    <div class="flex flex-col gap-4 rounded-2xl bg-desa-foreshadow p-5">
      <div class="flex flex-col gap-1">
        <p class="font-semibold">{{ user?.name }}</p>
        <p class="text-sm font-medium text-desa-secondary">{{ formatPhone(user?.phone) }}</p>
      </div>
      <div class="flex items-center justify-between gap-3 rounded-2xl bg-white p-4">
        <code class="text-2xl font-semibold tracking-[0.2em] break-all text-desa-dark-green">{{
          password
        }}</code>
        <button
          type="button"
          class="flex h-11 shrink-0 items-center gap-2 rounded-2xl px-3 text-sm font-medium text-desa-dark-green transition-all duration-300 hover:bg-desa-foreshadow"
          @click="copy"
        >
          <AppIcon :name="copied ? 'tick-circle' : 'document-text'" class="size-5!" />
          {{ copied ? 'Tersalin' : 'Salin' }}
        </button>
      </div>
    </div>

    <template #footer>
      <a
        :href="whatsappLink(user?.phone, message)"
        target="_blank"
        rel="noopener"
        class="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl border border-desa-background px-5 leading-5 font-medium text-desa-dark-green transition-all duration-300 hover:border-desa-dark-green hover:bg-desa-foreshadow"
      >
        <AppIcon name="call" />
        Kirim lewat WhatsApp
      </a>
      <AppButton @click="open = false">Selesai</AppButton>
    </template>
  </AppModal>
</template>
