<script setup>
defineProps({
  variant: { type: String, default: 'error' }, // error | info | success | warning
})

const styles = {
  error: 'bg-red-50 text-red-800 ring-red-200',
  info: 'bg-sky-50 text-sky-800 ring-sky-200',
  success: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
  warning: 'bg-amber-50 text-amber-900 ring-amber-200',
}
</script>

<template>
  <div
    :role="variant === 'error' ? 'alert' : 'status'"
    :class="['rounded-lg px-4 py-3 text-sm ring-1 ring-inset', styles[variant]]"
  >
    <slot />
  </div>
</template>
