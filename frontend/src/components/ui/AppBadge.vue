<script setup>
defineProps({
  variant: { type: String, default: 'neutral' }, // neutral | success | warning | danger | info
})

const styles = {
  neutral: 'bg-desa-background text-desa-secondary',
  success: 'bg-desa-foreshadow text-desa-dark-green',
  warning: 'bg-desa-yellow/15 text-[#8a5300]',
  danger: 'bg-desa-red/10 text-[#b0203f]',
  info: 'bg-desa-blue/10 text-desa-blue',
}
</script>

<template>
  <span
    :class="[
      'inline-flex shrink-0 items-center rounded-full px-3 py-1 text-xs leading-4 font-semibold',
      styles[variant],
    ]"
  >
    <slot />
  </span>
</template>
