<script setup>
import { computed } from 'vue'

// Ikon Iconsax dari template, dipakai sebagai mask supaya warnanya ikut `currentColor`
// (satu berkas per ikon, warna diatur lewat kelas text-*).
const files = import.meta.glob('../../assets/icons/*.svg', {
  eager: true,
  query: '?url',
  import: 'default',
})
const icons = Object.fromEntries(
  Object.entries(files).map(([path, url]) => [path.split('/').pop().replace('.svg', ''), url]),
)

const props = defineProps({
  name: { type: String, required: true },
})

const style = computed(() => {
  const url = icons[props.name]
  if (!url) return {}
  const mask = `url("${url}") center / contain no-repeat`
  return { mask, WebkitMask: mask }
})
</script>

<template>
  <span class="inline-block size-6 shrink-0 bg-current" :style="style" aria-hidden="true" />
</template>
