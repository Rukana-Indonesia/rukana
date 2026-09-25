<script setup>
import { useId } from 'vue'

defineOptions({ inheritAttrs: false })

const model = defineModel({ type: [String, Number], default: '' })

defineProps({
  label: { type: String, required: true },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
})

const id = useId()
</script>

<template>
  <div>
    <label :for="id" class="mb-1.5 block text-sm font-medium text-slate-700">{{ label }}</label>
    <input
      :id="id"
      v-model="model"
      v-bind="$attrs"
      :aria-invalid="!!error"
      :aria-describedby="error || hint ? `${id}-desc` : undefined"
      :class="[
        'block min-h-11 w-full rounded-lg border bg-white px-3 text-base text-slate-900 shadow-xs',
        'placeholder:text-slate-400 focus:outline-2 focus:outline-offset-0 sm:text-sm',
        error
          ? 'border-red-400 focus:outline-red-500'
          : 'border-slate-300 focus:border-brand-600 focus:outline-brand-600',
      ]"
    />
    <p v-if="error" :id="`${id}-desc`" class="mt-1.5 text-sm text-red-600">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-desc`" class="mt-1.5 text-sm text-slate-500">{{ hint }}</p>
  </div>
</template>
