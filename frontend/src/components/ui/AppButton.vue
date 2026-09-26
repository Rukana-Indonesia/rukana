<script setup>
const props = defineProps({
  type: { type: String, default: 'button' },
  variant: { type: String, default: 'primary' }, // primary | secondary | ghost | danger
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
})

const variants = {
  primary: 'bg-desa-dark-green text-white hover:bg-[#2a5030] focus-visible:outline-desa-dark-green',
  secondary:
    'bg-white text-desa-dark-green border border-desa-background hover:border-desa-dark-green hover:bg-desa-foreshadow focus-visible:outline-desa-dark-green',
  ghost: 'text-desa-secondary hover:bg-desa-foreshadow hover:text-desa-dark-green',
  danger: 'bg-desa-red text-white hover:bg-[#e8405f] focus-visible:outline-desa-red',
}
</script>

<template>
  <button
    :type="props.type"
    :disabled="props.disabled || props.loading"
    :class="[
      'inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl px-5 leading-5 font-medium transition-all duration-300',
      'focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
      variants[props.variant],
      props.block && 'w-full',
    ]"
  >
    <svg
      v-if="props.loading"
      class="size-5 animate-spin"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
      <path
        d="M22 12a10 10 0 0 0-10-10"
        stroke="currentColor"
        stroke-width="3"
        stroke-linecap="round"
      />
    </svg>
    <slot />
  </button>
</template>
