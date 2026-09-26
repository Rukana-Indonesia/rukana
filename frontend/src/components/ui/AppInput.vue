<script setup>
import { useId } from 'vue'

import AppIcon from './AppIcon.vue'

defineOptions({ inheritAttrs: false })

const model = defineModel({ type: [String, Number], default: '' })

defineProps({
  label: { type: String, required: true },
  icon: { type: String, default: '' },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
})

const id = useId()
</script>

<template>
  <div class="flex flex-col gap-3">
    <label :for="id" class="leading-5 font-medium text-desa-secondary">{{ label }}</label>
    <div class="relative">
      <input
        :id="id"
        v-model="model"
        v-bind="$attrs"
        :aria-invalid="!!error"
        :aria-describedby="error || hint ? `${id}-desc` : undefined"
        :class="[
          'peer h-14 w-full rounded-2xl border-[1.5px] bg-white pr-4 text-base leading-5 font-medium text-desa-black',
          'transition-all duration-300 placeholder:font-medium placeholder:text-desa-secondary focus:outline-none',
          icon ? 'pl-12' : 'pl-4',
          error
            ? 'border-desa-red text-desa-red focus:ring-[1.5px] focus:ring-desa-red'
            : 'border-desa-background focus:ring-[1.5px] focus:ring-desa-dark-green',
        ]"
      />
      <AppIcon
        v-if="icon"
        :name="icon"
        :class="[
          'pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 transition-all duration-300',
          error
            ? 'text-desa-red'
            : 'text-desa-black peer-placeholder-shown:text-desa-secondary peer-focus:text-desa-dark-green',
        ]"
      />
    </div>
    <p v-if="error" :id="`${id}-desc`" class="-mt-1 text-sm font-medium text-desa-red">
      {{ error }}
    </p>
    <p v-else-if="hint" :id="`${id}-desc`" class="-mt-1 text-sm text-desa-secondary">
      {{ hint }}
    </p>
  </div>
</template>
