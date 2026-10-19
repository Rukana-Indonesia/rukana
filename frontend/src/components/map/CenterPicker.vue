<script setup>
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import './map.css'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

// Pemilih titik tengah peta desa: geser penanda atau ketuk peta, dan perbesaran
// peta saat ini ikut disimpan sebagai perbesaran awal semua peta Rukana.
const model = defineModel({ type: Object, required: true }) // { latitude, longitude, zoom }

const props = defineProps({
  readonly: { type: Boolean, default: false },
})

const el = ref(null)
let map = null
let marker = null

const latLng = () => [model.value.latitude, model.value.longitude]

function set(patch) {
  model.value = { ...model.value, ...patch }
}

function moveTo(to) {
  set({ latitude: Number(to.lat.toFixed(7)), longitude: Number(to.lng.toFixed(7)) })
}

onMounted(() => {
  map = L.map(el.value, { scrollWheelZoom: false }).setView(latLng(), model.value.zoom)
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map)
  map.on('focus', () => map.scrollWheelZoom.enable())
  map.on('blur', () => map.scrollWheelZoom.disable())

  marker = L.marker(latLng(), {
    draggable: !props.readonly,
    keyboard: !props.readonly,
    icon: L.divIcon({
      className: '',
      html: '<span class="block size-6 rounded-full border-4 border-white bg-desa-dark-green shadow-lg"></span>',
      iconSize: [24, 24],
      iconAnchor: [12, 12],
    }),
  }).addTo(map)

  if (!props.readonly) {
    marker.on('dragend', () => moveTo(marker.getLatLng()))
    map.on('click', (e) => moveTo(e.latlng))
    map.on('zoomend', () => set({ zoom: map.getZoom() }))
  }
})

watch(
  () => [model.value.latitude, model.value.longitude],
  () => {
    marker.setLatLng(latLng())
    if (!map.getBounds().contains(latLng())) map.panTo(latLng())
  },
)
watch(
  () => model.value.zoom,
  (zoom) => zoom !== map.getZoom() && map.setZoom(zoom),
)

/** Pusatkan peta ke titik (mis. setelah memakai lokasi GPS). */
function focus() {
  map.setView(latLng(), Math.max(map.getZoom(), 16))
}

defineExpose({ focus })

onBeforeUnmount(() => {
  map?.remove()
  map = null
})
</script>

<template>
  <div
    ref="el"
    class="rukana-map isolate z-0 h-72 w-full overflow-hidden rounded-2xl bg-desa-background sm:h-[420px]"
    role="region"
    aria-label="Peta pemilih titik tengah desa"
  />
</template>
