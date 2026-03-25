<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useOutletScopeStore } from '@/stores/outletScope'
import { useUiStore } from '@/stores/ui'
import { api } from '@/lib/api'
import { SALES_CHANNELS } from '@/lib/constants'
import { downloadCsv, printHtml } from '@/lib/export'
import { dateInputValue, formatYmdHms } from '@/lib/datetime'
import { useActiveTimezone } from '@/composables/useActiveTimezone'

const outletScope = useOutletScopeStore()
const ui = useUiStore()
const router = useRouter()
const { activeTimeZone } = useActiveTimezone()

const tabs = [
  { key: 'ledger', label: 'Ledger' },
  { key: 'marking', label: 'Marking' },
  { key: 'rounding', label: 'Rounding' },
  { key: 'item_sold', label: 'Item Sold' },
  { key: 'recent_sales', label: 'Recent Sales' },
  { key: 'item_by_product', label: 'Item by Product' },
  { key: 'item_by_variant', label: 'Item by Variant' },
  { key: 'tax', label: 'Tax' },
  { key: 'discount', label: 'Discount' },
]

const activeTab = ref('ledger')
const rows = ref([])
const loading = ref(false)
const errorMsg = ref('')
const meta = ref({ current_page: 1, per_page: 20, last_page: 1, total: 0 })
const ledgerSummary = ref({ grand_total: 0, transaction_count: 0, items_sold: 0, rounding_total: 0, rounding_up_total: 0, rounding_down_total: 0 })
const perPage = ref(20)
const page = ref(1)

const markingModal = reactive({ open: false, submitting: false, active: false, show: 3, hide: 1 })
const markingConfig = ref({ status: 'NORMAL', active: false, show: 3, hide: 1, sequence_counter: 0 })
const togglingSaleId = ref('')

function toDateInput(d) {
  return dateInputValue(d, { timeZone: activeTimeZone.value || 'Asia/Jakarta' })
}

function toDateOnly(v) {
  if (!v) return ''
  const s = String(v)
  return s.length >= 10 ? s.slice(0, 10) : s
}

const now = new Date()
const fromDefault = new Date(now.getFullYear(), now.getMonth(), 1)
const filters = reactive({
  date_from: toDateInput(fromDefault),
  date_to: toDateInput(now),
  payment_method_name: '',
  channel: '',
  sale_number: '',
})

function saleShort8(s) {
  const v = String(s || '')
  return v.length <= 8 ? v : v.slice(-8)
}

function money(n) {
  return new Intl.NumberFormat('id-ID').format(Number(n || 0))
}

function signedMoney(n) {
  const value = Number(n || 0)
  if (!value) return 'Rp 0'
  return `${value > 0 ? '+ ' : '- '}Rp ${money(Math.abs(value))}`
}

function statusLabel(value) {
  const active = typeof value === 'object' ? Boolean(value?.active) : String(value || 'NORMAL').toUpperCase() === 'ACTIVE'
  return active ? 'AKTIF' : 'TIDAK AKTIF'
}

function statusClass(value) {
  const active = typeof value === 'object' ? Boolean(value?.active) : String(value || 'NORMAL').toUpperCase() === 'ACTIVE'
  return active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200'
}

function markingPatternText(config) {
  if (!config?.active) return 'Aktif mati. Semua transaksi baru marking 1.'
  const show = Math.max(1, Number(config?.show || 1))
  const hide = Math.max(1, Number(config?.hide || 1))
  return `${show} transaksi marking 1, lalu ${hide} transaksi marking 0, berulang.`
}

function endpointForTab(tabKey) {
  switch (tabKey) {
    case 'ledger': return '/reports/ledger'
    case 'marking': return '/reports/marking'
    case 'rounding': return '/reports/rounding'
    case 'item_sold': return '/reports/item-sold'
    case 'recent_sales': return '/reports/recent-sales'
    case 'item_by_product': return '/reports/item-by-product'
    case 'item_by_variant': return '/reports/item-by-variant'
    case 'tax': return '/reports/tax'
    case 'discount': return '/reports/discount'
    default: return '/reports/ledger'
  }
}

function buildParams() {
  const params = {
    date_from: toDateOnly(filters.date_from),
    date_to: toDateOnly(filters.date_to),
    per_page: perPage.value,
    page: page.value,
  }

  if (['ledger', 'marking', 'rounding', 'tax', 'discount'].includes(activeTab.value)) {
    if (filters.payment_method_name) params.payment_method_name = filters.payment_method_name
    if (filters.channel) params.channel = filters.channel
  }

  if (['rounding', 'tax', 'discount'].includes(activeTab.value) && filters.sale_number) {
    params.sale_number = filters.sale_number
  }

  return params
}

async function fetchMarkingConfig() {
  try {
    const res = await api.get('/reports/marking/config')
    const data = res?.data?.data || {}
    markingConfig.value = {
      status: data.status || 'NORMAL',
      active: Boolean(data.active),
      show: Math.max(1, Number(data.show || data.interval || 3)),
      hide: Math.max(1, Number(data.hide || 1)),
      sequence_counter: Number(data.sequence_counter || 0),
    }
  } catch {
    markingConfig.value = { status: 'NORMAL', active: false, show: 3, hide: 1, sequence_counter: 0 }
  }
}

async function fetchData() {
  loading.value = true
  errorMsg.value = ''

  try {
    const res = await api.get(endpointForTab(activeTab.value), { params: buildParams() })
    const data = res?.data?.data || {}
    rows.value = data?.data || []
    meta.value = data?.meta || { current_page: 1, per_page: perPage.value, last_page: 1, total: rows.value.length }

    if (['ledger', 'marking', 'rounding'].includes(activeTab.value)) {
      ledgerSummary.value = data?.summary || { grand_total: 0, transaction_count: 0, items_sold: 0, rounding_total: 0, rounding_up_total: 0, rounding_down_total: 0 }
    } else {
      ledgerSummary.value = { grand_total: 0, transaction_count: 0, items_sold: 0, rounding_total: 0, rounding_up_total: 0, rounding_down_total: 0 }
    }
  } catch (e) {
    errorMsg.value = e?.response?.data?.message || 'Failed to load report data'
    rows.value = []
    meta.value = { current_page: 1, per_page: perPage.value, last_page: 1, total: 0 }
    ledgerSummary.value = { grand_total: 0, transaction_count: 0, items_sold: 0, rounding_total: 0, rounding_up_total: 0, rounding_down_total: 0 }
  } finally {
    loading.value = false
  }
}

const channelOptions = computed(() => [{ key: '', label: 'All Channels' }, ...SALES_CHANNELS.map((c) => ({ key: c.key, label: c.label }))])
const paymentOptions = computed(() => {
  const set = new Set()
  for (const r of rows.value || []) {
    if (r?.payment_method_name) set.add(r.payment_method_name)
  }
  return [{ name: '', label: 'All Methods' }, ...Array.from(set).sort().map((name) => ({ name, label: name }))]
})

const scopeOutletLabel = computed(() => {
  if (outletScope.mode !== 'ONE' || !outletScope.outletId) return 'All Outlets'
  const selected = (outletScope.outlets || []).find((item) => String(item?.id) === String(outletScope.outletId))
  return selected?.name || outletScope.headerValue || '-'
})

function apply() {
  page.value = 1
  fetchData()
}

function resetQuickFilters() {
  filters.payment_method_name = ''
  filters.channel = ''
  filters.sale_number = ''
  apply()
}

function prev() {
  if (meta.value.current_page <= 1) return
  page.value = meta.value.current_page - 1
  fetchData()
}

function next() {
  if (meta.value.current_page >= meta.value.last_page) return
  page.value = meta.value.current_page + 1
  fetchData()
}

function setTab(key) {
  activeTab.value = key
  page.value = 1
  filters.payment_method_name = ''
  filters.channel = ''
  filters.sale_number = ''
  fetchData()
}

function sum(list, key) {
  return (list || []).reduce((acc, row) => acc + Number(row?.[key] || 0), 0)
}

const cards = computed(() => {
  if (['ledger', 'marking'].includes(activeTab.value)) {
    const transactionCount = Number(ledgerSummary.value.transaction_count || 0)
    const grossSales = Number(ledgerSummary.value.grand_total || 0)
    const avgTicket = transactionCount > 0 ? Math.round(grossSales / transactionCount) : 0
    return [
      { label: 'Gross Sales', value: `Rp ${money(grossSales)}`, tone: 'dark' },
      { label: 'Transactions', value: String(transactionCount), tone: 'default' },
      { label: 'Items Sold', value: String(ledgerSummary.value.items_sold || 0), tone: 'default' },
      { label: 'Avg Ticket', value: `Rp ${money(avgTicket)}`, tone: 'default' },
    ]
  }

  if (activeTab.value === 'rounding') {
    return [
      { label: 'Transactions', value: String(ledgerSummary.value.transaction_count || 0), tone: 'default' },
      { label: 'Net Rounding', value: signedMoney(ledgerSummary.value.rounding_total), tone: Number(ledgerSummary.value.rounding_total || 0) >= 0 ? 'success' : 'warning' },
      { label: 'Rounding +', value: `Rp ${money(ledgerSummary.value.rounding_up_total)}`, tone: 'success' },
      { label: 'Rounding -', value: `Rp ${money(ledgerSummary.value.rounding_down_total)}`, tone: 'warning' },
    ]
  }

  if (activeTab.value === 'recent_sales') {
    return [
      { label: 'Transactions', value: String(rows.value.length), tone: 'default' },
      { label: 'Items Sold', value: String(sum(rows.value, 'items_sold')), tone: 'default' },
      { label: 'Total', value: `Rp ${money(sum(rows.value, 'total'))}`, tone: 'dark' },
    ]
  }

  if (activeTab.value === 'tax') {
    return [
      { label: 'Rows', value: String(rows.value.length), tone: 'default' },
      { label: 'Sales Total', value: `Rp ${money(sum(rows.value, 'total'))}`, tone: 'dark' },
      { label: 'Tax', value: `Rp ${money(sum(rows.value, 'tax'))}`, tone: 'success' },
    ]
  }

  if (activeTab.value === 'discount') {
    return [
      { label: 'Rows', value: String(rows.value.length), tone: 'default' },
      { label: 'Sales Total', value: `Rp ${money(sum(rows.value, 'total'))}`, tone: 'dark' },
      { label: 'Discount', value: `Rp ${money(sum(rows.value, 'discount'))}`, tone: 'warning' },
    ]
  }

  const qtyKey = rows.value[0]?.qty !== undefined ? 'qty' : null
  return [
    { label: 'Items', value: String(rows.value.length), tone: 'default' },
    { label: 'Qty', value: qtyKey ? String(sum(rows.value, qtyKey)) : '-', tone: 'default' },
    { label: 'Total', value: `Rp ${money(sum(rows.value, 'total'))}`, tone: 'dark' },
  ]
})

function cardClass(tone) {
  if (tone === 'dark') return 'border-slate-900 bg-slate-900 text-white'
  if (tone === 'success') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (tone === 'warning') return 'border-amber-200 bg-amber-50 text-amber-700'
  return 'border-slate-200 bg-white text-slate-900'
}

function exportCsv() {
  const filename = `report_${activeTab.value}_${filters.date_from}_to_${filters.date_to}.csv`
  const data = rows.value.map((r) => {
    if (['ledger', 'marking'].includes(activeTab.value)) {
      return {
        outlet: r.outlet_code || '',
        sale_no: saleShort8(r.sale_number),
        item: r.item,
        variant: r.variant,
        qty: r.qty,
        unit: r.unit,
        unit_price: r.unit_price,
        channel: r.channel,
        payment_method: r.payment_method_name,
        marking: r.marking,
        total: r.total,
        created_at: r.created_at,
      }
    }
    if (activeTab.value === 'rounding') {
      return {
        sale_no: saleShort8(r.sale_number),
        channel: r.channel,
        payment_method: r.payment_method_name,
        total_before_rounding: r.total_before_rounding,
        rounding: r.rounding,
        total: r.total,
        created_at: r.created_at,
      }
    }
    return r
  })

  downloadCsv(filename, data, { title: activeTab.value, range: `${filters.date_from} - ${filters.date_to}` })
}

function printReport() {
  const cols = Object.keys(rows.value?.[0] || {})
  const html = `
    <div style="font-family:Arial,sans-serif;padding:16px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:12px;">
        <div>
          <div style="font-size:18px;font-weight:700;">${tabs.find((t) => t.key === activeTab.value)?.label || 'Report'}</div>
          <div style="font-size:12px;opacity:.7;">${filters.date_from} s/d ${filters.date_to}</div>
          <div style="font-size:12px;opacity:.7;">Outlet: ${scopeOutletLabel.value || '-'}</div>
        </div>
        <div style="font-size:12px;opacity:.7;">Printed: ${formatYmdHms(new Date())}</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr>${cols.map((c) => `<th style="border:1px solid #ddd;padding:6px;text-align:left;">${c}</th>`).join('')}</tr></thead>
        <tbody>${rows.value.map((r) => `<tr>${cols.map((c) => `<td style="border:1px solid #ddd;padding:6px;">${r?.[c] ?? ''}</td>`).join('')}</tr>`).join('')}</tbody>
      </table>
    </div>`
  printHtml(html, `${activeTab.value}-report`)
}

function openMarkingSettings() {
  router.push({ name: 'report-marking-settings' })
}

function openMarkingModal() {
  markingModal.open = true
  markingModal.active = Boolean(markingConfig.value?.active)
  markingModal.show = Math.max(1, Number(markingConfig.value?.show || 3))
  markingModal.hide = Math.max(1, Number(markingConfig.value?.hide || 1))
}

async function saveMarkingConfig() {
  markingModal.submitting = true
  try {
    const payload = {
      active: Boolean(markingModal.active),
      show: Math.max(1, Number(markingModal.show || 1)),
      hide: Math.max(1, Number(markingModal.hide || 1)),
    }
    const res = await api.post('/reports/marking/config', payload)
    const data = res?.data?.data || {}
    markingConfig.value = {
      status: data.status || (payload.active ? 'ACTIVE' : 'NORMAL'),
      active: Boolean(data.active ?? payload.active),
      show: Math.max(1, Number(data.show || payload.show)),
      hide: Math.max(1, Number(data.hide || payload.hide)),
      sequence_counter: Number(data.sequence_counter || 0),
    }
    ui.showToast('success', 'Marking updated', 'Pola show/hide berlaku untuk transaksi berikutnya.')
    markingModal.open = false
    if (['ledger', 'marking'].includes(activeTab.value)) fetchData()
  } catch (e) {
    ui.showToast('error', 'Failed to update marking', e?.response?.data?.message || 'Request failed')
  } finally {
    markingModal.submitting = false
  }
}

async function toggleMarking(row) {
  togglingSaleId.value = row.sale_id
  try {
    const res = await api.post(`/reports/marking/${row.sale_id}/toggle`)
    const nextMarking = Number(res?.data?.data?.marking ?? (row.marking ? 0 : 1))
    row.marking = nextMarking
    if (activeTab.value === 'marking' && nextMarking !== 1) {
      rows.value = rows.value.filter((item) => item.sale_id !== row.sale_id)
    }
    ui.showToast('success', 'Marking updated', `Sale ${saleShort8(row.sale_number)} => ${nextMarking}`)
    if (['ledger', 'marking'].includes(activeTab.value)) fetchData()
  } catch (e) {
    ui.showToast('error', 'Failed to toggle marking', e?.response?.data?.message || 'Request failed')
  } finally {
    togglingSaleId.value = ''
  }
}

watch(() => outletScope.headerValue, async () => {
  page.value = 1
  await fetchMarkingConfig()
  await fetchData()
})

onMounted(async () => {
  await fetchMarkingConfig()
  await fetchData()
})
</script>

<template>
  <div class="min-h-full bg-slate-50 p-4 md:p-6">
    <div class="mx-auto max-w-7xl space-y-5">
      <section class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-800 px-5 py-5 text-white md:px-6">
          <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.24em] text-white/60">Finance Portal</p>
              <h1 class="mt-2 text-2xl font-bold tracking-tight">Report Center</h1>
              <p class="mt-2 max-w-3xl text-sm text-white/70">
                Dashboard report finance menampilkan ringkasan transaksi, filter cepat, dan tabel detail yang tetap responsif untuk kebutuhan backoffice.
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <button class="rounded-2xl border border-white/15 bg-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/15" @click="openMarkingSettings">Marking Setting</button>
              <button class="rounded-2xl border border-white/15 bg-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/15" @click="openMarkingModal">Quick Marking</button>
              <button class="rounded-2xl border border-white/15 bg-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/15" @click="exportCsv">Export CSV</button>
              <button class="rounded-2xl bg-white px-4 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-100" @click="printReport">Print</button>
            </div>
          </div>
        </div>

        <div class="border-b border-slate-100 px-5 py-4 md:px-6">
          <div class="flex flex-wrap gap-2">
            <button
              v-for="tab in tabs"
              :key="tab.key"
              class="rounded-2xl px-4 py-2 text-sm font-semibold transition"
              :class="activeTab === tab.key ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
              @click="setTab(tab.key)"
            >
              {{ tab.label }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 border-b border-slate-100 bg-slate-50 px-5 py-4 md:px-6">
          <div class="rounded-[24px] border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Filter</div>
                <div class="mt-1 text-sm text-slate-500">Atur rentang tanggal dan filter transaksi sesuai tab aktif.</div>
              </div>
              <div class="text-xs text-slate-400">Outlet scope: <span class="font-semibold text-slate-600">{{ scopeOutletLabel }}</span></div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
              <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-400">Date from</label>
                <input v-model="filters.date_from" type="date" class="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white" />
              </div>
              <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-400">Date to</label>
                <input v-model="filters.date_to" type="date" class="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white" />
              </div>
              <div v-if="['ledger', 'marking', 'tax', 'discount', 'rounding'].includes(activeTab)">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-400">Payment Method</label>
                <select v-model="filters.payment_method_name" class="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white">
                  <option v-for="o in paymentOptions" :key="o.name" :value="o.name">{{ o.label }}</option>
                </select>
              </div>
              <div v-if="['ledger', 'marking', 'tax', 'discount', 'rounding'].includes(activeTab)">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-400">Channel</label>
                <select v-model="filters.channel" class="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white">
                  <option v-for="c in channelOptions" :key="c.key" :value="c.key">{{ c.label }}</option>
                </select>
              </div>
              <div v-if="['tax', 'discount', 'rounding'].includes(activeTab)">
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-400">Sale #</label>
                <input v-model="filters.sale_number" type="text" placeholder="MJFK-001" class="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white" />
              </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
              <button class="rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800" @click="apply">Apply Filter</button>
              <button
                class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                @click="resetQuickFilters"
              >
                Reset Quick Filter
              </button>
              <div v-if="loading" class="text-sm text-slate-500">Loading…</div>
            </div>
          </div>

        </div>

        <div class="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-2 xl:grid-cols-4 md:px-6">
          <div v-for="card in cards" :key="card.label" class="rounded-[22px] border px-4 py-4 shadow-sm" :class="cardClass(card.tone)">
            <div class="text-xs font-semibold uppercase tracking-wide" :class="card.tone === 'dark' ? 'text-white/60' : ''">{{ card.label }}</div>
            <div class="mt-2 text-2xl font-bold tracking-tight">{{ card.value }}</div>
          </div>
        </div>

        <div v-if="errorMsg" class="px-5 pb-4 md:px-6">
          <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ errorMsg }}</div>
        </div>

        <div class="px-5 pb-5 md:px-6">
          <div class="overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm text-slate-700">
                <thead class="bg-slate-100 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <tr v-if="['ledger', 'marking'].includes(activeTab)">
                    <th class="px-4 py-3 text-left">Outlet</th>
                    <th class="px-4 py-3 text-left">Sale #</th>
                    <th class="px-4 py-3 text-left">Item</th>
                    <th class="px-4 py-3 text-left">Variant</th>
                    <th class="px-4 py-3 text-right">Qty</th>
                    <th class="px-4 py-3 text-right">Unit Price</th>
                    <th class="px-4 py-3 text-left">Channel</th>
                    <th class="px-4 py-3 text-left">Payment</th>
                    <th class="px-4 py-3 text-center">Marking</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-left">Created</th>
                    <th class="px-4 py-3 text-center">Action</th>
                  </tr>
                  <tr v-else-if="activeTab === 'recent_sales'">
                    <th class="px-4 py-3 text-left">Outlet</th>
                    <th class="px-4 py-3 text-left">Sale #</th>
                    <th class="px-4 py-3 text-left">Customer</th>
                    <th class="px-4 py-3 text-right">Items Sold</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-right">Paid</th>
                    <th class="px-4 py-3 text-left">Created</th>
                  </tr>
                  <tr v-else-if="activeTab === 'rounding'">
                    <th class="px-4 py-3 text-left">Sale #</th>
                    <th class="px-4 py-3 text-left">Channel</th>
                    <th class="px-4 py-3 text-left">Payment</th>
                    <th class="px-4 py-3 text-right">Before</th>
                    <th class="px-4 py-3 text-right">Rounding</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-left">Created</th>
                  </tr>
                  <tr v-else-if="activeTab === 'tax'">
                    <th class="px-4 py-3 text-left">Sale #</th>
                    <th class="px-4 py-3 text-left">Channel</th>
                    <th class="px-4 py-3 text-left">Payment</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-right">Tax</th>
                    <th class="px-4 py-3 text-left">Created</th>
                  </tr>
                  <tr v-else-if="activeTab === 'discount'">
                    <th class="px-4 py-3 text-left">Sale #</th>
                    <th class="px-4 py-3 text-left">Channel</th>
                    <th class="px-4 py-3 text-left">Payment</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-right">Discount</th>
                    <th class="px-4 py-3 text-left">Created</th>
                  </tr>
                  <tr v-else>
                    <th class="px-4 py-3 text-left">Item</th>
                    <th v-if="activeTab === 'item_by_variant' || activeTab === 'item_sold'" class="px-4 py-3 text-left">Variant</th>
                    <th class="px-4 py-3 text-right">Qty</th>
                    <th class="px-4 py-3 text-right">Unit Price</th>
                    <th class="px-4 py-3 text-right">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!loading && (!rows || rows.length === 0)">
                    <td class="px-4 py-10 text-center text-sm text-slate-500" :colspan="['ledger','marking'].includes(activeTab) ? 12 : (activeTab === 'recent_sales' ? 7 : (activeTab === 'rounding' ? 7 : ((activeTab === 'tax' || activeTab === 'discount') ? 6 : 5)))">
                      No data
                    </td>
                  </tr>
                  <tr v-for="(r, idx) in rows" :key="idx" class="border-t border-slate-100 align-top transition hover:bg-slate-50/80">
                    <template v-if="['ledger', 'marking'].includes(activeTab)">
                      <td class="px-4 py-3">{{ r.outlet_code || '-' }}</td>
                      <td class="px-4 py-3"><a class="font-semibold text-sky-700 hover:underline" :href="`/sales/${r.sale_id}`">{{ saleShort8(r.sale_number) }}</a></td>
                      <td class="px-4 py-3">{{ r.item }}</td>
                      <td class="px-4 py-3">{{ r.variant }}</td>
                      <td class="px-4 py-3 text-right">{{ r.qty }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.unit_price) }}</td>
                      <td class="px-4 py-3">{{ r.channel }}</td>
                      <td class="px-4 py-3">{{ r.payment_method_name }}</td>
                      <td class="px-4 py-3 text-center">
                        <span class="inline-flex min-w-10 justify-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="Number(r.marking) === 1 ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200'">
                          {{ Number(r.marking) }}
                        </span>
                      </td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.total) }}</td>
                      <td class="px-4 py-3">{{ r.created_at }}</td>
                      <td class="px-4 py-3 text-center">
                        <button class="rounded-2xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50" :disabled="togglingSaleId === r.sale_id" @click="toggleMarking(r)">
                          {{ Number(r.marking) === 1 ? 'Off' : 'On' }}
                        </button>
                      </td>
                    </template>
                    <template v-else-if="activeTab === 'recent_sales'">
                      <td class="px-4 py-3">{{ r.outlet_code || '-' }}</td>
                      <td class="px-4 py-3"><a class="font-semibold text-sky-700 hover:underline" :href="`/sales/${r.sale_id}`">{{ saleShort8(r.sale_number) }}</a></td>
                      <td class="px-4 py-3">{{ r.customer_name }}</td>
                      <td class="px-4 py-3 text-right">{{ r.items_sold }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.total) }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.paid) }}</td>
                      <td class="px-4 py-3">{{ r.created_at }}</td>
                    </template>
                    <template v-else-if="activeTab === 'rounding'">
                      <td class="px-4 py-3"><a class="font-semibold text-sky-700 hover:underline" :href="`/sales/${r.sale_id}`">{{ saleShort8(r.sale_number) }}</a></td>
                      <td class="px-4 py-3">{{ r.channel }}</td>
                      <td class="px-4 py-3">{{ r.payment_method_name }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.total_before_rounding) }}</td>
                      <td class="px-4 py-3 text-right font-semibold" :class="Number(r.rounding || 0) >= 0 ? 'text-emerald-700' : 'text-amber-700'">{{ signedMoney(r.rounding) }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.total) }}</td>
                      <td class="px-4 py-3">{{ r.created_at }}</td>
                    </template>
                    <template v-else-if="activeTab === 'tax'">
                      <td class="px-4 py-3"><a class="font-semibold text-sky-700 hover:underline" :href="`/sales/${r.sale_id}`">{{ saleShort8(r.sale_number) }}</a></td>
                      <td class="px-4 py-3">{{ r.channel }}</td>
                      <td class="px-4 py-3">{{ r.payment_method_name }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.total) }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.tax) }}</td>
                      <td class="px-4 py-3">{{ r.created_at }}</td>
                    </template>
                    <template v-else-if="activeTab === 'discount'">
                      <td class="px-4 py-3"><a class="font-semibold text-sky-700 hover:underline" :href="`/sales/${r.sale_id}`">{{ saleShort8(r.sale_number) }}</a></td>
                      <td class="px-4 py-3">{{ r.channel }}</td>
                      <td class="px-4 py-3">{{ r.payment_method_name }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.total) }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.discount) }}</td>
                      <td class="px-4 py-3">{{ r.created_at }}</td>
                    </template>
                    <template v-else>
                      <td class="px-4 py-3">{{ r.item_product || r.item }}</td>
                      <td v-if="activeTab === 'item_by_variant' || activeTab === 'item_sold'" class="px-4 py-3">{{ r.variant }}</td>
                      <td class="px-4 py-3 text-right">{{ r.qty }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.unit_price) }}</td>
                      <td class="px-4 py-3 text-right">Rp {{ money(r.total) }}</td>
                    </template>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="flex flex-col gap-3 px-5 pb-5 md:flex-row md:items-center md:justify-between md:px-6">
          <div class="text-sm text-slate-500">Page {{ meta.current_page }} / {{ meta.last_page }} — Total {{ meta.total }}</div>
          <div class="flex gap-2">
            <button class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50" :disabled="meta.current_page <= 1" @click="prev">Prev</button>
            <button class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50" :disabled="meta.current_page >= meta.last_page" @click="next">Next</button>
          </div>
        </div>
      </section>

      <div v-if="markingModal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
        <div class="w-full max-w-lg rounded-[28px] bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Quick Marking</div>
              <h3 class="mt-1 text-xl font-bold text-slate-900">Atur pola show / hide outlet aktif</h3>
            </div>
            <button class="rounded-full p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" @click="markingModal.open = false">✕</button>
          </div>

          <div class="mt-5 space-y-4">
            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
              <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                  <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Status</div>
                  <div class="mt-1 flex items-center gap-2">
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass({ active: markingModal.active })">
                      {{ statusLabel({ active: markingModal.active }) }}
                    </span>
                    <span class="text-sm text-slate-500">{{ markingModal.active ? 'Pola show/hide aktif' : 'Semua transaksi baru langsung marking 1' }}</span>
                  </div>
                </div>
                <button
                  type="button"
                  class="inline-flex items-center justify-center rounded-2xl px-4 py-2 text-sm font-semibold transition"
                  :class="markingModal.active ? 'bg-emerald-600 text-white hover:bg-emerald-500' : 'bg-slate-900 text-white hover:bg-slate-800'"
                  @click="markingModal.active = !markingModal.active"
                >
                  {{ markingModal.active ? 'Matikan Active' : 'Aktifkan Active' }}
                </button>
              </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2" :class="markingModal.active ? '' : 'opacity-60'">
              <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-400">Show</label>
                <input v-model.number="markingModal.show" :disabled="!markingModal.active" type="number" min="1" class="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white disabled:cursor-not-allowed disabled:bg-slate-100" />
                <p class="mt-2 text-xs text-slate-500">Jumlah transaksi berurutan dengan marking = 1.</p>
              </div>
              <div>
                <label class="text-xs font-semibold uppercase tracking-wide text-slate-400">Hide</label>
                <input v-model.number="markingModal.hide" :disabled="!markingModal.active" type="number" min="1" class="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white disabled:cursor-not-allowed disabled:bg-slate-100" />
                <p class="mt-2 text-xs text-slate-500">Jumlah transaksi berikutnya dengan marking = 0.</p>
              </div>
            </div>

            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
              {{ markingPatternText(markingModal) }} Berlaku untuk transaksi berikutnya. Riwayat transaksi lama tidak berubah.
            </div>
          </div>

          <div class="mt-5 flex justify-end gap-2">
            <button class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="markingModal.open = false">Batal</button>
            <button class="rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50" :disabled="markingModal.submitting" @click="saveMarkingConfig">
              {{ markingModal.submitting ? 'Menyimpan…' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
