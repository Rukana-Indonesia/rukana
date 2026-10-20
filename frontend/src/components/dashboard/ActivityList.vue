<script setup>
import { RouterLink } from 'vue-router'

import AppBadge from '@/components/ui/AppBadge.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { statusBadge as complaintBadge } from '@/lib/complaints'
import { timeAgo } from '@/lib/format'
import { statusBadge as letterBadge } from '@/lib/letters'

// Daftar ringkas surat dan laporan di dasbor: antrean kerja pengurus, atau pengajuan
// terakhir warga. `items` = [{ type: 'letter' | 'complaint', data }].
defineProps({
  items: { type: Array, required: true },
  showRegion: { type: Boolean, default: false },
})

const kinds = {
  letter: {
    route: 'letter-detail',
    icon: 'document-text',
    iconClass: 'bg-desa-foreshadow text-desa-dark-green',
    badge: letterBadge,
    title: (d) => d.service?.name ?? 'Surat pengantar',
    date: (d) => d.submitted_at,
  },
  complaint: {
    route: 'complaint-detail',
    icon: 'note',
    iconClass: 'bg-desa-orange/10 text-desa-orange',
    badge: complaintBadge,
    title: (d) => d.title,
    date: (d) => d.created_at,
  },
}
</script>

<template>
  <ul class="flex flex-col divide-y divide-desa-background">
    <li v-for="item in items" :key="`${item.type}-${item.data.id}`">
      <RouterLink
        :to="{ name: kinds[item.type].route, params: { id: item.data.id } }"
        class="-mx-2 flex items-center gap-3 rounded-2xl px-2 py-3 transition-all duration-200 hover:bg-desa-background/50 active:scale-[0.99]"
      >
        <span
          :class="[
            'flex size-11 shrink-0 items-center justify-center rounded-2xl',
            kinds[item.type].iconClass,
          ]"
        >
          <AppIcon :name="kinds[item.type].icon" class="size-[22px]!" />
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-1">
          <span class="truncate leading-5 font-semibold">{{
            kinds[item.type].title(item.data)
          }}</span>
          <span class="truncate text-xs font-medium text-desa-secondary">
            {{ item.data.code }}
            <template v-if="showRegion && item.data.rt"> · {{ item.data.rt.label }}</template>
            · {{ timeAgo(kinds[item.type].date(item.data)) }}
          </span>
        </span>
        <AppBadge :variant="kinds[item.type].badge[item.data.status] ?? 'neutral'">{{
          item.data.status_label
        }}</AppBadge>
      </RouterLink>
    </li>
  </ul>
</template>
