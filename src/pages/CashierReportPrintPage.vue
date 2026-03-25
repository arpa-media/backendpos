<template>
  <div class="min-h-screen bg-gray-50" :style="pageStyle">
    <div class="no-print sticky top-0 z-10 border-b bg-white/90 backdrop-blur">
      <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-4 py-3">
        <div>
          <div class="text-sm font-semibold text-gray-900">Cashier Report Print</div>
          <div class="text-xs text-gray-500">{{ dateText || '-' }}</div>
        </div>
        <div class="flex items-center gap-2">
          <RouterLink :to="backRoute" class="rounded-xl border px-3 py-2 text-sm font-medium text-gray-700">
            Back
          </RouterLink>
          <button class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white" @click="printNow">
            Print
          </button>
        </div>
      </div>
    </div>

    <div class="mx-auto flex w-full max-w-5xl flex-col items-center px-4 py-6" :style="pageBodyStyle">
      <div class="no-print mb-4 w-full max-w-xl rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3 text-xs text-blue-700">
        Preview mengikuti printer setting aktif: <strong>{{ profileLabel }}</strong>
        <span class="ml-2">· {{ printProfile.charsPerLine }} chars/line</span>
      </div>

      <div
        v-if="banner"
        class="no-print mb-4 w-full max-w-xl rounded-xl border px-4 py-3 text-sm"
        :class="banner.type === 'error'
          ? 'border-red-200 bg-red-50 text-red-700'
          : 'border-green-200 bg-green-50 text-green-700'"
      >
        {{ banner.message }}
      </div>

      <div
        ref="printRoot"
        data-print-root="true"
        class="border border-gray-200 bg-white shadow-sm print:border-0 print:shadow-none"
        :style="cardStyle"
      >
        <div class="text-center">
          <div class="text-lg font-extrabold text-gray-900">ITEM SOLD REPORT</div>
          <div class="mt-1 text-sm font-semibold text-gray-700">Per tanggal load cashier report</div>
          <div class="mt-1 text-xs text-gray-500">Date: {{ dateText }}</div>
        </div>

        <div class="my-4 border-t border-dashed border-gray-300"></div>

        <div class="space-y-1 text-xs text-gray-700">
          <div class="flex justify-between">
            <span>Item variants</span>
            <span class="font-extrabold">{{ summary.item_count }}</span>
          </div>
          <div class="flex justify-between">
            <span>Sold total</span>
            <span class="font-extrabold">{{ summary.qty_total }}</span>
          </div>
          <div class="flex justify-between">
            <span>Grand total</span>
            <span class="font-extrabold">Rp {{ money(summary.grand_total) }}</span>
          </div>
        </div>

        <div class="my-4 border-t border-dashed border-gray-300"></div>

        <div v-if="items.length === 0" class="text-sm text-gray-500">No item sold.</div>

        <div v-else class="space-y-3">
          <div class="grid grid-cols-[1fr_auto_auto] gap-3 border-b border-gray-200 pb-2 text-[11px] font-extrabold uppercase tracking-wide text-gray-500">
            <div>Item</div>
            <div class="text-right">Qty</div>
            <div class="text-right">Total</div>
          </div>

          <div v-for="(it, idx) in items" :key="`${it.item}-${it.variant}-${idx}`" class="grid grid-cols-[1fr_auto_auto] gap-3 text-xs text-gray-800">
            <div class="min-w-0">
              <div class="truncate font-semibold text-gray-900">{{ itemLine(it) }}</div>
            </div>
            <div class="shrink-0 text-right font-extrabold text-gray-900">{{ it.qty }}</div>
            <div class="shrink-0 text-right font-semibold text-gray-700">Rp {{ money(it.total) }}</div>
          </div>
        </div>

        <div class="mt-8 text-center text-xs text-gray-500">======== End Report ========</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api, extractApiError } from '../lib/api'
import { resolvePrintProfile, wrapHtmlWithPrintProfile } from '../lib/printProfile'
import { renderItemSoldReportText } from '../lib/thermalText'
import { maybeAutoPrintCurrentPage, triggerPrintSurface } from '../lib/printRuntime'

const route = useRoute()
const dateText = computed(() => String(route.query?.date || ''))

const banner = ref(null)
const printRoot = ref(null)
const summary = ref({ item_count: 0, qty_total: 0, grand_total: 0 })
const items = ref([])
const printProfile = computed(() => resolvePrintProfile({ jobKey: 'cashier_report' }))

const pageStyle = computed(() => ({ ...(printProfile.value?.cssVars || {}) }))
const pageBodyStyle = computed(() => ({ width: '100%', maxWidth: `${Math.max(440, (printProfile.value?.maxPreviewWidthPx || 0) + 96)}px` }))
const cardStyle = computed(() => ({ ...(printProfile.value?.cardStyle || {}), minHeight: 'auto' }))
const profileLabel = computed(() => `${printProfile.value?.paperWidthMm || 80}mm`)
const backRoute = computed(() => {
  const from = String(route.query?.from || '').trim()
  if (from === 'finance-cashier-report') return { name: 'finance-cashier-report' }
  if (from === 'cashier-cashier-report') return { name: 'cashier-cashier-report' }
  return { name: 'cashier-report' }
})

function money(n) {
  return Number(n || 0).toLocaleString('id-ID')
}

function itemLine(item) {
  const product = String(item?.item || item?.product_name || '-').trim() || '-'
  const variant = String(item?.variant || item?.variant_name || '').trim()
  return variant ? `${product} - ${variant}` : product
}

async function printNow() {
  const html = printRoot.value?.outerHTML || ''
  if (!html) return window.print()
  const payload = {
    date: dateText.value,
    summary: summary.value,
    items: items.value,
  }
  const res = await triggerPrintSurface({
    title: 'Item Sold Report',
    html: wrapHtmlWithPrintProfile(html, printProfile.value),
    text: renderItemSoldReportText(payload, printProfile.value),
    jobKey: 'cashier_report',
    printProfile: printProfile.value,
  })
  if (!res?.ok) window.print()
}

async function load() {
  banner.value = null
  try {
    const perPage = 200
    let page = 1
    let lastPage = 1
    const merged = []
    let mergedSummary = { item_count: 0, qty_total: 0, grand_total: 0 }

    do {
      const res = await api.get('/reports/item-by-variant', {
        params: {
          date_from: dateText.value || undefined,
          date_to: dateText.value || undefined,
          per_page: perPage,
          page,
        },
      })
      const payload = res?.data?.data || {}
      const pageItems = Array.isArray(payload.data) ? payload.data : []
      if (page === 1) {
        mergedSummary = payload.summary || { item_count: 0, qty_total: 0, grand_total: 0 }
      }
      merged.push(...pageItems)
      const meta = payload.meta || {}
      lastPage = Math.max(1, Number(meta.last_page || 1))
      page += 1
      if (page > 50) break
    } while (page <= lastPage)

    merged.sort((a, b) => itemLine(a).localeCompare(itemLine(b), 'id', { sensitivity: 'base' }))

    summary.value = {
      item_count: Number(mergedSummary.item_count || merged.length),
      qty_total: Number(mergedSummary.qty_total || merged.reduce((sum, row) => sum + Number(row?.qty || 0), 0)),
      grand_total: Number(mergedSummary.grand_total || merged.reduce((sum, row) => sum + Number(row?.total || 0), 0)),
    }
    items.value = merged
  } catch (err) {
    const e = extractApiError(err)
    banner.value = { type: 'error', message: e.message || 'Failed to load report.' }
    summary.value = { item_count: 0, qty_total: 0, grand_total: 0 }
    items.value = []
  }
}

onMounted(async () => {
  await load()
  window.setTimeout(() => {
    if (maybeAutoPrintCurrentPage()) printNow()
  }, 250)
})
</script>

<style scoped>
@media print {
  .no-print {
    display: none !important;
  }
}
</style>
