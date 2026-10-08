<script setup>
import { computed } from 'vue'

// Bintang tampilan saja (bukan input). Nilai pecahan, mis. 4,3, diisi sebagian.
const props = defineProps({
  score: { type: Number, default: 0 },
  size: { type: String, default: 'size-5' },
})

const percent = computed(() => `${Math.max(0, Math.min(5, props.score)) * 20}%`)
const label = computed(() => `${String(props.score).replace('.', ',')} dari 5 bintang`)
const STAR =
  'M12 2.5l2.94 5.96 6.56.95-4.75 4.63 1.12 6.54L12 17.5l-5.87 3.08 1.12-6.54L2.5 9.41l6.56-.95L12 2.5z'
</script>

<template>
  <span class="relative inline-flex shrink-0" role="img" :aria-label="label">
    <span class="flex gap-0.5 text-desa-background">
      <svg v-for="i in 5" :key="i" :class="size" viewBox="0 0 24 24" aria-hidden="true">
        <path :d="STAR" fill="currentColor" />
      </svg>
    </span>
    <span
      class="absolute inset-y-0 left-0 flex gap-0.5 overflow-hidden text-desa-yellow"
      :style="{ width: percent }"
    >
      <svg
        v-for="i in 5"
        :key="i"
        :class="[size, 'shrink-0']"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <path :d="STAR" fill="currentColor" />
      </svg>
    </span>
  </span>
</template>
