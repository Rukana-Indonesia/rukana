<script setup>
import { useId } from 'vue'

defineOptions({ inheritAttrs: false })

const model = defineModel({ type: [String, Number], default: '' })

defineProps({
  label: { type: String, required: true },
  options: { type: Array, required: true }, // [{ value, label }]
  placeholder: { type: String, default: 'Pilih…' },
  error: { type: String, default: '' },
})

const id = useId()
</script>

<template>
  <div>
    <label :for="id" class="mb-1.5 block text-sm font-medium text-slate-700">{{ label }}</label>
    <select
      :id="id"
      v-model="model"
      v-bind="$attrs"
      :aria-invalid="!!error"
      :class="[
        'block min-h-11 w-full rounded-lg border bg-white px-3 text-base text-slate-900 shadow-xs',
        'focus:outline-2 focus:outline-offset-0 disabled:bg-slate-100 disabled:text-slate-400 sm:text-sm',
        error
          ? 'border-red-400 focus:outline-red-500'
          : 'border-slate-300 focus:border-brand-600 focus:outline-brand-600',
      ]"
    >
      <option value="" disabled>{{ placeholder }}</option>
      <option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>
    <p v-if="error" class="mt-1.5 text-sm text-red-600">{{ error }}</p>
  </div>
</template>
