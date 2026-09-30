<script setup>
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'

import AppIcon from './AppIcon.vue'

// Di HP tampil sebagai bottom sheet, di layar lebar sebagai dialog di tengah.
const open = defineModel('open', { type: Boolean, default: false })

defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
})

const id = useId()
const panel = ref(null)
let returnFocus = null

function onKeydown(event) {
  if (event.key === 'Escape') open.value = false
}

watch(
  open,
  async (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : ''
    if (isOpen) {
      returnFocus = document.activeElement
      window.addEventListener('keydown', onKeydown)
      await nextTick()
      panel.value?.querySelector('input, select, textarea, button:not([data-close])')?.focus()
    } else {
      window.removeEventListener('keydown', onKeydown)
      returnFocus?.focus?.()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
      enter-active-class="transition-opacity duration-200"
      leave-active-class="transition-opacity duration-200"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-[60] flex items-end justify-center bg-desa-black/40 sm:items-center sm:p-6"
        @click.self="open = false"
      >
        <div
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="`${id}-title`"
          class="flex max-h-[90dvh] w-full flex-col gap-6 overflow-y-auto rounded-t-3xl bg-white p-6 pb-[max(1.5rem,env(safe-area-inset-bottom))] sm:max-w-[520px] sm:rounded-3xl sm:p-8"
        >
          <div class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-2">
              <h2 :id="`${id}-title`" class="text-xl font-semibold">{{ title }}</h2>
              <p v-if="description" class="leading-5 font-medium text-desa-secondary">
                {{ description }}
              </p>
            </div>
            <button
              type="button"
              data-close
              class="flex size-11 shrink-0 items-center justify-center rounded-2xl border border-desa-background text-desa-secondary transition-all duration-300 hover:border-desa-secondary"
              aria-label="Tutup"
              @click="open = false"
            >
              <AppIcon name="close-square" />
            </button>
          </div>

          <slot />

          <div v-if="$slots.footer" class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
