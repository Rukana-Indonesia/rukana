<script setup>
import logoIcon from '@/assets/images/logo-icon.svg'

defineProps({
  light: { type: Boolean, default: false },
})
</script>

<template>
  <span class="inline-flex items-center gap-2">
    <img :src="logoIcon" alt="" class="size-[30px] shrink-0" />
    <span
      :class="[
        'text-[22px] leading-none font-semibold tracking-tight',
        light ? 'text-white' : 'text-desa-black',
      ]"
      >Rukana<span class="text-desa-yellow">.</span></span
    >
  </span>
</template>
