import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { onUnauthorized } from './lib/api'
import { listenForInstallPrompt, registerServiceWorker } from './lib/pwa'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// Sesi habis di tengah pemakaian: kosongkan user dan arahkan ke halaman masuk.
onUnauthorized(() => {
  const auth = useAuthStore()
  if (!auth.user) return
  auth.clear()
  router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
})

listenForInstallPrompt()
registerServiceWorker()

app.mount('#app')
