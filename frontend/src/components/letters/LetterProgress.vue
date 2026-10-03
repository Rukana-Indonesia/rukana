<script setup>
import { computed } from 'vue'

import AppIcon from '@/components/ui/AppIcon.vue'
import { formatDate } from '@/lib/format'

/**
 * Tahapan surat sesuai alurnya (mis. Diajukan → Diverifikasi RT → Selesai) dengan
 * waktu tiap tahap dari timeline. Dipakai di detail surat dan halaman cek tiket.
 */
const props = defineProps({
  stages: { type: Array, required: true }, // [{ status, label }]
  steps: { type: Array, default: () => [] }, // [{ to_status, created_at }]
  status: { type: String, required: true },
  rejectedLevelLabel: { type: String, default: '' },
})

const reachedAt = computed(() =>
  Object.fromEntries(props.steps.map((step) => [step.to_status, step.created_at])),
)

const items = computed(() => {
  const rejected = props.status === 'rejected'
  // Tahap terakhir yang sudah dicapai (sebelum ditolak, bila ditolak).
  const reached = props.stages.filter((s) => reachedAt.value[s.status]).length
  const list = props.stages.map((stage, i) => ({
    ...stage,
    at: reachedAt.value[stage.status],
    state: i < reached ? 'done' : !rejected && i === reached ? 'current' : 'upcoming',
  }))

  if (!rejected) return list
  return [
    ...list.slice(0, reached),
    {
      status: 'rejected',
      label: props.rejectedLevelLabel ? `Ditolak ${props.rejectedLevelLabel}` : 'Ditolak',
      at: reachedAt.value.rejected,
      state: 'rejected',
    },
  ]
})
</script>

<template>
  <ol class="flex flex-col">
    <li v-for="(item, i) in items" :key="item.status" class="relative flex gap-4 pb-6 last:pb-0">
      <span
        v-if="i < items.length - 1"
        :class="[
          'absolute top-10 bottom-0 left-[19px] w-0.5',
          item.state === 'done' ? 'bg-desa-soft-green' : 'bg-desa-background',
        ]"
        aria-hidden="true"
      />
      <span
        :class="[
          'relative flex size-10 shrink-0 items-center justify-center rounded-full',
          {
            done: 'bg-desa-dark-green text-white',
            current: 'bg-desa-yellow/20 text-[#8a5300] ring-2 ring-desa-yellow',
            upcoming: 'bg-desa-background text-desa-secondary',
            rejected: 'bg-desa-red text-white',
          }[item.state],
        ]"
      >
        <AppIcon
          :name="
            item.state === 'rejected'
              ? 'close-circle'
              : item.state === 'current'
                ? 'timer'
                : 'tick-circle'
          "
          class="size-5!"
        />
      </span>
      <div class="flex min-w-0 flex-col gap-1 pt-2">
        <p
          :class="[
            'leading-5 font-semibold',
            item.state === 'upcoming' && 'text-desa-secondary',
            item.state === 'rejected' && 'text-desa-red',
          ]"
        >
          {{ item.label }}
        </p>
        <p class="text-sm font-medium text-desa-secondary">
          {{
            item.at
              ? formatDate(item.at, true)
              : item.state === 'current'
                ? 'Sedang diproses'
                : 'Menunggu'
          }}
        </p>
      </div>
    </li>
  </ol>
</template>
