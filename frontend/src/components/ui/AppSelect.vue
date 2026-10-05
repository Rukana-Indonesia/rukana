<script setup>
import { useId } from 'vue'

import AppIcon from './AppIcon.vue'

defineOptions({ inheritAttrs: false })

const model = defineModel({ type: [String, Number], default: '' })

defineProps({
  label: { type: String, required: true },
  options: { type: Array, required: true }, // [{ value, label }] atau grup [{ label, options: [...] }]
  icon: { type: String, default: '' },
  placeholder: { type: String, default: 'Pilih…' },
  error: { type: String, default: '' },
})

const id = useId()
</script>

<template>
  <div class="flex flex-col gap-3">
    <label :for="id" class="leading-5 font-medium text-desa-secondary">{{ label }}</label>
    <div class="relative">
      <select
        :id="id"
        v-model="model"
        v-bind="$attrs"
        :aria-invalid="!!error"
        :class="[
          'h-14 w-full appearance-none rounded-2xl border-[1.5px] bg-white pr-11 text-base leading-5 font-medium',
          'transition-all duration-300 focus:outline-none disabled:bg-desa-background disabled:text-desa-secondary',
          icon ? 'pl-12' : 'pl-4',
          model === '' ? 'text-desa-secondary' : 'text-desa-black',
          error
            ? 'border-desa-red focus:ring-[1.5px] focus:ring-desa-red'
            : 'border-desa-background focus:ring-[1.5px] focus:ring-desa-dark-green',
        ]"
      >
        <option value="" disabled>{{ placeholder }}</option>
        <template v-for="opt in options" :key="opt.options ? `group-${opt.label}` : opt.value">
          <optgroup v-if="opt.options" :label="opt.label">
            <option v-for="child in opt.options" :key="child.value" :value="child.value">
              {{ child.label }}
            </option>
          </optgroup>
          <option v-else :value="opt.value">{{ opt.label }}</option>
        </template>
      </select>
      <AppIcon
        v-if="icon"
        :name="icon"
        class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-desa-secondary"
      />
      <AppIcon
        name="arrow-down"
        class="pointer-events-none absolute top-1/2 right-4 size-5! -translate-y-1/2 text-desa-secondary"
      />
    </div>
    <p v-if="error" class="-mt-1 text-sm font-medium text-desa-red">{{ error }}</p>
  </div>
</template>
