<script setup>
import { computed, ref } from 'vue'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatDate } from '@/lib/format'

import StarRating from './StarRating.vue'

// Rating kepuasan 1–5 setelah surat/laporan selesai (PRD 6.7). Form hanya muncul
// bila backend mengizinkan (`can.rate`); rating yang sudah ada tampil untuk pemilik
// tiket dan pengurus di wilayahnya.
const props = defineProps({
  rating: { type: Object, default: null },
  canRate: { type: Boolean, default: false },
  endpoint: { type: String, required: true }, // mis. '/letters/12/rating'
  isMine: { type: Boolean, default: false },
  subject: { type: String, default: 'layanan' }, // 'surat' | 'laporan'
})
const emit = defineEmits(['rated'])

const LABELS = { 1: 'Sangat buruk', 2: 'Buruk', 3: 'Cukup', 4: 'Baik', 5: 'Sangat baik' }
const STAR =
  'M12 2.5l2.94 5.96 6.56.95-4.75 4.63 1.12 6.54L12 17.5l-5.87 3.08 1.12-6.54L2.5 9.41l6.56-.95L12 2.5z'

const score = ref(0)
const hover = ref(0)
const comment = ref('')
const errors = ref({})
const error = ref('')
const submitting = ref(false)

const shown = computed(() => hover.value || score.value)

async function submit() {
  errors.value = {}
  error.value = ''
  if (!score.value) {
    errors.value = { score: 'Pilih jumlah bintang (1–5).' }
    return
  }
  submitting.value = true
  try {
    const { data } = await api.post(props.endpoint, {
      score: score.value,
      comment: comment.value || undefined,
    })
    emit('rated', data.data, data.message)
  } catch (err) {
    errors.value = fieldErrors(err)
    if (!Object.keys(errors.value).length) error.value = errorMessage(err)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section
    v-if="rating || canRate"
    class="flex flex-col gap-4 rounded-2xl bg-white p-6 sm:p-8"
    aria-labelledby="rating-title"
  >
    <template v-if="rating">
      <h2 id="rating-title" class="text-lg font-semibold">
        {{ isMine ? 'Rating Anda' : 'Rating dari warga' }}
      </h2>
      <div class="flex flex-wrap items-center gap-3">
        <StarRating :score="rating.score" size="size-7" />
        <span class="font-semibold">{{ LABELS[rating.score] }}</span>
        <span class="text-sm text-desa-secondary">· {{ formatDate(rating.created_at) }}</span>
      </div>
      <p v-if="rating.comment" class="leading-relaxed whitespace-pre-line text-desa-secondary">
        “{{ rating.comment }}”
      </p>
    </template>

    <form v-else class="flex flex-col gap-5" novalidate @submit.prevent="submit">
      <div class="flex flex-col gap-1">
        <h2 id="rating-title" class="text-lg font-semibold">Bagaimana pelayanannya?</h2>
        <p class="leading-5 text-desa-secondary">
          {{ subject === 'laporan' ? 'Laporan Anda' : 'Surat Anda' }} sudah selesai. Rating Anda
          membantu pengurus memperbaiki layanan.
        </p>
      </div>

      <fieldset class="flex flex-col gap-2">
        <legend class="sr-only">Rating 1 sampai 5 bintang</legend>
        <div class="flex flex-wrap items-center gap-3">
          <div class="flex gap-1" @mouseleave="hover = 0">
            <label
              v-for="n in 5"
              :key="n"
              class="cursor-pointer rounded-xl p-1 transition-transform duration-200 hover:scale-110 has-focus-visible:outline-2 has-focus-visible:outline-desa-dark-green"
              @mouseenter="hover = n"
            >
              <input v-model="score" type="radio" name="score" :value="n" class="sr-only" />
              <span class="sr-only">{{ n }} bintang, {{ LABELS[n] }}</span>
              <svg
                :class="['size-10', n <= shown ? 'text-desa-yellow' : 'text-desa-background']"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path :d="STAR" fill="currentColor" />
              </svg>
            </label>
          </div>
          <span v-if="shown" class="font-semibold text-desa-dark-green">{{ LABELS[shown] }}</span>
        </div>
        <p v-if="errors.score" class="text-sm font-medium text-desa-red">{{ errors.score }}</p>
      </fieldset>

      <AppTextarea
        v-model="comment"
        label="Komentar (opsional)"
        maxlength="500"
        placeholder="Mis. prosesnya cepat dan petugasnya ramah"
        :error="errors.comment"
      />

      <AppAlert v-if="error">{{ error }}</AppAlert>
      <AppButton type="submit" :loading="submitting" class="self-start">Kirim rating</AppButton>
    </form>
  </section>
</template>
