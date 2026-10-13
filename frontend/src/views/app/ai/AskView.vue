<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppAlert from '@/components/ui/AppAlert.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AppModal from '@/components/ui/AppModal.vue'
import { toolChip } from '@/lib/ai'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { formatDate, timeAgo } from '@/lib/format'

// Ask Rukana (PRD 7.3): tanya data surat, laporan, dan rating. Wilayah data selalu
// dari akun (backend), jadi pertanyaan tentang wilayah lain dijawab "di luar wilayah".
const route = useRoute()
const router = useRouter()

const MAX = 500

const conversations = ref([])
const meta = ref(null)
const active = ref(null) // { id, title, messages }
const loading = ref(true)
const loadingConversation = ref(false)
const error = ref('')

const question = ref('')
const sending = ref(false)
const pending = ref('') // pertanyaan yang sedang dijawab, tampil sebelum balasan datang
const sendError = ref('')
const showHistory = ref(false)

const messagesEl = ref(null)
const deleting = ref(null)
const deleteBusy = ref(false)

const messages = computed(() => active.value?.messages ?? [])
const canSend = computed(
  () => meta.value?.ai_configured && question.value.trim().length >= 3 && !sending.value,
)

async function loadList() {
  const { data } = await api.get('/ask/conversations')
  conversations.value = data.data
  meta.value = data.meta
}

async function openConversation(id) {
  showHistory.value = false
  sendError.value = ''
  if (!id) {
    active.value = null
    return
  }
  if (active.value?.id === id) return
  loadingConversation.value = true
  try {
    const { data } = await api.get(`/ask/conversations/${id}`)
    active.value = data.data
    scrollDown()
  } catch (err) {
    error.value = errorMessage(err)
    router.replace({ query: {} })
  } finally {
    loadingConversation.value = false
  }
}

onMounted(async () => {
  try {
    await loadList()
    if (route.query.c) await openConversation(Number(route.query.c))
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
})

watch(
  () => route.query.c,
  (id) => openConversation(id ? Number(id) : null),
)

function newConversation() {
  router.push({ query: {} })
  question.value = ''
}

async function scrollDown() {
  await nextTick()
  messagesEl.value?.scrollTo({ top: messagesEl.value.scrollHeight, behavior: 'smooth' })
}

async function send(text = question.value) {
  const body = text.trim()
  if (body.length < 3 || sending.value) return
  sending.value = true
  sendError.value = ''
  pending.value = body
  question.value = ''
  scrollDown()

  try {
    const { data } = await api.post(
      '/ask',
      { question: body, conversation_id: active.value?.id },
      { timeout: 180000 },
    )
    const isNew = !active.value
    active.value = {
      ...data.data.conversation,
      messages: [...messages.value, ...data.data.messages],
    }
    conversations.value = [
      data.data.conversation,
      ...conversations.value.filter((c) => c.id !== data.data.conversation.id),
    ]
    if (isNew) router.replace({ query: { c: data.data.conversation.id } })
  } catch (err) {
    sendError.value = fieldErrors(err).question ?? errorMessage(err)
    question.value = body
  } finally {
    sending.value = false
    pending.value = ''
    scrollDown()
  }
}

function onKeydown(event) {
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
    event.preventDefault()
    send()
  }
}

async function confirmDelete() {
  deleteBusy.value = true
  try {
    await api.delete(`/ask/conversations/${deleting.value.id}`)
    conversations.value = conversations.value.filter((c) => c.id !== deleting.value.id)
    if (active.value?.id === deleting.value.id) newConversation()
    deleting.value = null
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-[14px]">
    <section
      class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-6 sm:p-8"
    >
      <div class="flex items-center gap-4">
        <span
          class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-desa-foreshadow text-desa-dark-green"
        >
          <AppIcon name="message-question" class="size-7!" />
        </span>
        <div class="flex flex-col gap-1">
          <h1 class="text-xl font-semibold sm:text-2xl">Ask Rukana</h1>
          <p class="text-desa-secondary">
            Tanya data surat, laporan, dan rating dalam bahasa sehari-hari.
          </p>
        </div>
      </div>
      <AppBadge v-if="meta" variant="success">Data {{ meta.scope_label }}</AppBadge>
    </section>

    <AppAlert v-if="error">{{ error }}</AppAlert>
    <AppAlert v-if="meta && !meta.ai_configured" variant="warning">
      Fitur AI belum dikonfigurasi, jadi pertanyaan belum bisa dijawab. Minta admin mengisi
      <code>ANTHROPIC_API_KEY</code> di server.
    </AppAlert>

    <div v-if="loading" class="rounded-2xl bg-white p-6 font-medium text-desa-secondary">
      Memuat…
    </div>

    <div v-else class="flex flex-col gap-[14px] lg:flex-row lg:items-start">
      <!-- Riwayat percakapan: kolom kiri di layar lebar, bisa dibuka-tutup di HP. -->
      <aside class="flex flex-col gap-3 rounded-2xl bg-white p-4 sm:p-5 lg:w-[300px] lg:shrink-0">
        <AppButton block @click="newConversation">
          <AppIcon name="add-square" />
          Percakapan baru
        </AppButton>
        <button
          type="button"
          class="flex items-center justify-between rounded-2xl px-3 py-2 font-medium text-desa-secondary lg:hidden"
          :aria-expanded="showHistory"
          @click="showHistory = !showHistory"
        >
          Riwayat ({{ conversations.length }})
          <AppIcon
            name="arrow-down"
            :class="['size-5! transition-transform', showHistory && 'rotate-180']"
          />
        </button>
        <ul :class="['flex-col gap-1', showHistory ? 'flex' : 'hidden lg:flex']">
          <li v-if="!conversations.length" class="px-3 py-2 text-sm text-desa-secondary">
            Belum ada percakapan.
          </li>
          <li v-for="c in conversations" :key="c.id" class="group flex items-center gap-1">
            <RouterLink
              :to="{ query: { c: c.id } }"
              :class="[
                'flex min-w-0 flex-1 flex-col rounded-2xl px-3 py-2.5 transition-all duration-300',
                active?.id === c.id
                  ? 'bg-desa-foreshadow text-desa-dark-green'
                  : 'hover:bg-desa-background',
              ]"
            >
              <span class="truncate font-medium">{{ c.title }}</span>
              <span class="text-xs text-desa-secondary">{{ timeAgo(c.updated_at) }}</span>
            </RouterLink>
            <button
              type="button"
              class="rounded-xl p-2 text-desa-secondary transition-all duration-300 hover:bg-desa-red/10 hover:text-desa-red"
              :title="`Hapus “${c.title}”`"
              @click="deleting = c"
            >
              <AppIcon name="trash" class="size-5!" />
              <span class="sr-only">Hapus percakapan</span>
            </button>
          </li>
        </ul>
      </aside>

      <!-- Percakapan aktif. -->
      <section class="flex min-w-0 flex-1 flex-col rounded-2xl bg-white">
        <div
          ref="messagesEl"
          class="flex max-h-[62vh] min-h-[320px] flex-col gap-5 overflow-y-auto p-5 sm:p-8"
          aria-live="polite"
        >
          <p v-if="loadingConversation" class="font-medium text-desa-secondary">
            Memuat percakapan…
          </p>

          <!-- Kosong: contoh pertanyaan. -->
          <div v-else-if="!messages.length && !pending" class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold">Mau tahu apa hari ini?</h2>
            <p class="text-desa-secondary">
              Jawaban hanya memakai data {{ meta?.scope_label }}. Contoh pertanyaan:
            </p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="example in meta?.examples ?? []"
                :key="example"
                type="button"
                class="rounded-2xl border border-desa-background px-4 py-3 text-left text-sm font-medium transition-all duration-300 hover:border-desa-dark-green hover:bg-desa-foreshadow disabled:opacity-60"
                :disabled="!meta?.ai_configured || sending"
                @click="send(example)"
              >
                {{ example }}
              </button>
            </div>
          </div>

          <template v-for="m in messages" :key="m.id">
            <div v-if="m.role === 'user'" class="flex justify-end">
              <p
                class="max-w-[85%] rounded-2xl rounded-br-md bg-desa-dark-green px-4 py-3 whitespace-pre-line text-white"
              >
                {{ m.content }}
              </p>
            </div>
            <div v-else class="flex flex-col items-start gap-2">
              <div class="flex items-center gap-2 text-sm font-semibold text-desa-dark-green">
                <AppIcon name="magic-star" class="size-5!" />
                Rukana
                <span class="font-normal text-desa-secondary">{{
                  formatDate(m.created_at, true)
                }}</span>
              </div>
              <p
                class="max-w-[92%] rounded-2xl rounded-tl-md bg-desa-background px-4 py-3 leading-relaxed whitespace-pre-line"
              >
                {{ m.content }}
              </p>
              <ul v-if="m.tools.length" class="flex flex-wrap gap-1.5" aria-label="Sumber data">
                <li v-for="(tool, i) in m.tools" :key="i">
                  <AppBadge :variant="tool.status === 'ok' ? 'neutral' : 'warning'">
                    {{ toolChip(tool) }}
                  </AppBadge>
                </li>
              </ul>
            </div>
          </template>

          <template v-if="pending">
            <div class="flex justify-end">
              <p
                class="max-w-[85%] rounded-2xl rounded-br-md bg-desa-dark-green px-4 py-3 whitespace-pre-line text-white"
              >
                {{ pending }}
              </p>
            </div>
            <p class="flex items-center gap-3 text-desa-secondary">
              <span
                class="size-5 shrink-0 animate-spin rounded-full border-2 border-desa-foreshadow border-t-desa-dark-green"
                aria-hidden="true"
              />
              Rukana sedang mencari datanya…
            </p>
          </template>
        </div>

        <form
          class="flex flex-col gap-3 border-t border-desa-background p-4 sm:p-5"
          @submit.prevent="send()"
        >
          <AppAlert v-if="sendError">{{ sendError }}</AppAlert>
          <div class="flex items-end gap-3">
            <label for="ask-question" class="sr-only">Pertanyaan</label>
            <textarea
              id="ask-question"
              v-model="question"
              rows="2"
              :maxlength="MAX"
              :disabled="!meta?.ai_configured"
              placeholder="Mis. Berapa laporan yang belum selesai bulan ini?"
              class="min-h-14 w-full resize-none rounded-2xl border-[1.5px] border-desa-background bg-white px-4 py-3.5 leading-6 font-medium transition-all duration-300 placeholder:text-desa-secondary focus:border-desa-dark-green focus:outline-none disabled:bg-desa-background"
              @keydown="onKeydown"
            />
            <AppButton
              type="submit"
              :disabled="!canSend"
              :loading="sending"
              class="size-14 shrink-0 px-0!"
            >
              <AppIcon name="send-2" />
              <span class="sr-only">Kirim</span>
            </AppButton>
          </div>
          <p class="flex justify-between gap-3 text-xs text-desa-secondary">
            <span
              >Enter untuk kirim, Shift+Enter untuk baris baru. Jawaban AI bisa keliru; cek angka
              penting di dasbor.</span
            >
            <span class="shrink-0">{{ question.length }}/{{ MAX }}</span>
          </p>
        </form>
      </section>
    </div>

    <AppModal
      :open="!!deleting"
      title="Hapus percakapan?"
      :description="deleting ? `“${deleting.title}” akan dihapus dari riwayat Anda.` : ''"
      @update:open="(v) => !v && (deleting = null)"
    >
      <template #footer>
        <AppButton variant="secondary" @click="deleting = null">Batal</AppButton>
        <AppButton variant="danger" :loading="deleteBusy" @click="confirmDelete">Hapus</AppButton>
      </template>
    </AppModal>
  </div>
</template>
