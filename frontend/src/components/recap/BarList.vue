<script setup>
import { computed } from 'vue'

// Daftar batang horizontal sederhana (gaya sebaran rating): label, batang, jumlah.
const props = defineProps({
  items: { type: Array, required: true }, // [{ label, total }]
  label: { type: String, required: true },
  barClass: { type: String, default: 'bg-desa-soft-green' },
  emptyText: { type: String, default: 'Belum ada data.' },
})

const max = computed(() => Math.max(1, ...props.items.map((item) => item.total)))
</script>

<template>
  <div class="flex flex-col gap-3">
    <h3 class="font-semibold">{{ label }}</h3>
    <p v-if="!items.length" class="text-sm text-desa-secondary">{{ emptyText }}</p>
    <ul v-else class="flex flex-col gap-2.5" :aria-label="label">
      <li v-for="item in items" :key="item.label" class="flex flex-col gap-1 text-sm">
        <span class="flex items-baseline justify-between gap-3">
          <span class="min-w-0 truncate font-medium">{{ item.label }}</span>
          <span class="shrink-0 font-semibold">{{ item.total }}</span>
        </span>
        <span class="h-2 overflow-hidden rounded-full bg-desa-background">
          <span
            :class="['block h-full rounded-full', barClass]"
            :style="{ width: `${(item.total / max) * 100}%` }"
          />
        </span>
      </li>
    </ul>
  </div>
</template>
