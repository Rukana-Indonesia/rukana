<script setup>
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import './map.css'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { statusColors } from '@/lib/complaintMap'
import { formatDate } from '@/lib/format'

// Peta Leaflet + OpenStreetMap untuk titik laporan (PRD 6.9). Penanda berupa
// lingkaran berwarna per status (tanpa gambar ikon), titik berulang dilingkari putus-putus.
const props = defineProps({
  points: { type: Array, required: true },
  hotspots: { type: Array, default: () => [] },
  center: { type: Object, required: true }, // { latitude, longitude, zoom }
})

const emit = defineEmits(['open'])

const el = ref(null)
let map = null
let markers = null
let rings = null
const byId = new Map()

/** Isi popup dibangun lewat DOM (textContent), bukan HTML, karena judul berasal dari warga. */
function popup(point) {
  const root = document.createElement('div')
  root.className = 'flex w-56 flex-col gap-2'
  const line = (text, className) => {
    const node = document.createElement('p')
    node.className = className
    node.textContent = text
    root.append(node)
    return node
  }

  line(point.code, 'text-xs font-medium text-desa-secondary')
  line(point.title, 'leading-5 font-semibold text-desa-black')
  line(
    point.category.parent && point.category.parent !== point.category.name
      ? `${point.category.parent} · ${point.category.name}`
      : point.category.name,
    'text-sm text-desa-secondary',
  )
  const status = line(point.status_label, 'flex items-center gap-2 text-sm font-semibold')
  const dot = document.createElement('span')
  dot.className = 'size-2.5 shrink-0 rounded-full'
  dot.style.background = statusColors[point.status]
  status.prepend(dot)
  line(`${point.region} · ${formatDate(point.created_at)}`, 'text-sm text-desa-secondary')

  const button = document.createElement('button')
  button.type = 'button'
  button.className =
    'mt-1 h-10 rounded-xl bg-desa-dark-green px-4 text-sm font-medium text-white hover:bg-desa-dark-green/90'
  button.textContent = 'Buka laporan'
  button.addEventListener('click', () => emit('open', point.id))
  root.append(button)

  return root
}

function draw() {
  markers.clearLayers()
  rings.clearLayers()
  byId.clear()

  for (const point of props.points) {
    const marker = L.circleMarker([point.latitude, point.longitude], {
      radius: point.is_active ? 9 : 7,
      color: '#ffffff',
      weight: 2,
      fillColor: statusColors[point.status],
      fillOpacity: 0.95,
    })
      .bindPopup(() => popup(point), { minWidth: 224, maxWidth: 260 })
      .bindTooltip(point.code, { direction: 'top', offset: [0, -8] })
    markers.addLayer(marker)
    byId.set(point.id, marker)
  }

  for (const spot of props.hotspots) {
    rings.addLayer(
      L.circle([spot.latitude, spot.longitude], {
        radius: 70,
        color: '#fa7139',
        weight: 2,
        dashArray: '6 6',
        fill: true,
        fillOpacity: 0.08,
        interactive: false,
      }),
    )
  }

  fitAll()
}

function fitBounds(latLngs) {
  if (latLngs.length === 1) {
    map.setView(latLngs[0], Math.max(map.getZoom(), 17))
  } else if (latLngs.length > 1) {
    map.fitBounds(L.latLngBounds(latLngs), { padding: [36, 36], maxZoom: 18 })
  }
}

/** Tampilkan semua titik; tanpa titik, kembali ke titik tengah desa. */
function fitAll() {
  if (props.points.length) {
    fitBounds(props.points.map((p) => [p.latitude, p.longitude]))
  } else {
    map.setView([props.center.latitude, props.center.longitude], props.center.zoom)
  }
}

/** Arahkan peta ke beberapa laporan (mis. satu titik berulang); satu laporan → buka popup-nya. */
function focus(ids) {
  const found = ids.map((id) => byId.get(id)).filter(Boolean)
  fitBounds(found.map((marker) => marker.getLatLng()))
  if (found.length === 1) found[0].openPopup()
}

defineExpose({ focus, fitAll })

onMounted(() => {
  map = L.map(el.value, {
    // Gulir halaman tidak ikut memperbesar peta; perbesar lewat tombol atau cubit.
    scrollWheelZoom: false,
    attributionControl: true,
  })
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map)
  map.on('focus', () => map.scrollWheelZoom.enable())
  map.on('blur', () => map.scrollWheelZoom.disable())

  rings = L.layerGroup().addTo(map)
  markers = L.featureGroup().addTo(map)
  draw()
})

watch(() => [props.points, props.hotspots], draw)

onBeforeUnmount(() => {
  map?.remove()
  map = null
})
</script>

<template>
  <div
    ref="el"
    class="rukana-map isolate z-0 h-[420px] w-full overflow-hidden rounded-2xl bg-desa-background sm:h-[520px]"
    role="region"
    aria-label="Peta sebaran laporan"
  />
</template>
