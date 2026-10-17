<script setup>
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import './map.css'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

// Peta kecil satu titik lokasi laporan (Leaflet + OpenStreetMap). Di form laporan
// (`editable`) titiknya bisa digeser atau dipindah dengan mengetuk peta.
const props = defineProps({
  point: { type: Object, required: true }, // { latitude, longitude, accuracy? }
  color: { type: String, default: '#34613a' },
  editable: { type: Boolean, default: false },
})

const emit = defineEmits(['update:point'])

const el = ref(null)
let map = null
let marker = null
let halo = null

const latLng = () => [props.point.latitude, props.point.longitude]

function move(to) {
  emit('update:point', {
    latitude: Number(to.lat.toFixed(7)),
    longitude: Number(to.lng.toFixed(7)),
    accuracy: null,
  })
}

function draw() {
  marker.setLatLng(latLng())
  halo.setLatLng(latLng()).setRadius(props.point.accuracy ?? 0)
  if (!map.getBounds().contains(latLng())) map.panTo(latLng())
}

onMounted(() => {
  map = L.map(el.value, { scrollWheelZoom: false, attributionControl: true }).setView(latLng(), 17)
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map)
  map.on('focus', () => map.scrollWheelZoom.enable())
  map.on('blur', () => map.scrollWheelZoom.disable())

  // Lingkar akurasi GPS; kosong (radius 0) setelah titik digeser manual.
  halo = L.circle(latLng(), {
    radius: props.point.accuracy ?? 0,
    color: props.color,
    weight: 1,
    fillOpacity: 0.1,
    interactive: false,
  }).addTo(map)

  // Penanda bulat (divIcon) supaya bisa digeser tanpa berkas gambar ikon bawaan Leaflet.
  marker = L.marker(latLng(), {
    draggable: props.editable,
    keyboard: props.editable,
    icon: L.divIcon({
      className: '',
      html: `<span class="block size-5 rounded-full border-[3px] border-white shadow-md" style="background:${props.color}"></span>`,
      iconSize: [20, 20],
      iconAnchor: [10, 10],
    }),
  }).addTo(map)

  if (props.editable) {
    marker.on('dragend', () => move(marker.getLatLng()))
    map.on('click', (e) => move(e.latlng))
  }
})

watch(() => [props.point.latitude, props.point.longitude, props.point.accuracy], draw)

onBeforeUnmount(() => {
  map?.remove()
  map = null
})
</script>

<template>
  <div
    ref="el"
    class="rukana-map isolate z-0 h-56 w-full overflow-hidden rounded-2xl bg-desa-background sm:h-72"
    role="region"
    aria-label="Peta lokasi laporan"
  />
</template>
