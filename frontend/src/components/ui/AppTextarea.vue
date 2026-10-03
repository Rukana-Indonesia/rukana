<script setup>
import { useId } from 'vue'

defineOptions({ inheritAttrs: false })

const model = defineModel({ type: String, default: '' })

defineProps({
  label: { type: String, required: true },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  rows: { type: [Number, String], default: 3 },
})

const id = useId()
</script>

<template>
  <div class="flex flex-col gap-3">
    <label :for="id" class="leading-5 font-medium text-desa-secondary">{{ label }}</label>
    <textarea
      :id="id"
      v-model="model"
      v-bind="$attrs"
      :rows="rows"
      :aria-invalid="!!error"
      :aria-describedby="error || hint ? `${id}-desc` : undefined"
      :class="[
        'w-full resize-y rounded-2xl border-[1.5px] bg-white px-4 py-4 text-base leading-6 font-medium text-desa-black',
        'transition-all duration-300 placeholder:font-medium placeholder:text-desa-secondary focus:outline-none',
        error
          ? 'border-desa-red focus:ring-[1.5px] focus:ring-desa-red'
          : 'border-desa-background focus:ring-[1.5px] focus:ring-desa-dark-green',
      ]"
    />
    <p v-if="error" :id="`${id}-desc`" class="-mt-1 text-sm font-medium text-desa-red">
      {{ error }}
    </p>
    <p v-else-if="hint" :id="`${id}-desc`" class="-mt-1 text-sm text-desa-secondary">
      {{ hint }}
    </p>
  </div>
</template>
