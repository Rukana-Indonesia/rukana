<script setup>
defineProps({
  variant: { type: String, default: 'error' }, // error | info | success | warning
})

const styles = {
  error: 'bg-desa-red/10 text-[#b0203f] ring-desa-red/30',
  info: 'bg-desa-blue/5 text-desa-blue ring-desa-blue/20',
  success: 'bg-desa-foreshadow text-desa-dark-green ring-desa-soft-green/40',
  warning: 'bg-desa-yellow/15 text-[#8a5300] ring-desa-yellow/40',
}
</script>

<template>
  <div
    :role="variant === 'error' ? 'alert' : 'status'"
    :class="[
      'rounded-2xl px-4 py-3 text-sm leading-5 font-medium ring-1 ring-inset',
      styles[variant],
    ]"
  >
    <slot />
  </div>
</template>
