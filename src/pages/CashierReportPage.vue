<template>
  <div class="h-full overflow-y-auto overscroll-contain bg-gray-100">
    <div class="min-h-full pb-[calc(4rem+env(safe-area-inset-bottom))]">
      <div class="mx-auto w-full max-w-6xl px-3 py-4 sm:px-4 sm:py-5 lg:px-6">
        <div class="space-y-4">
          <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50 px-4 py-4 md:flex-row md:items-end md:justify-between">
              <div>
                <div class="text-base font-extrabold tracking-tight text-gray-900">Cashier Report</div>
                <div class="mt-1 text-xs text-gray-500">
                  {{ isFinanceRoute ? 'Portal Finance · ringkasan cashier per outlet scope aktif.' : 'Ringkasan cashier pada tanggal terpilih.' }}
                </div>
              </div>

              <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1.5 font-semibold text-gray-600">
                  Outlet scope: {{ scopeOutletLabel }}
                </span>
              </div>
            </div>

            <div class="space-y-4 p-4">
              <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <div>
                  <label class="text-xs font-extrabold text-gray-600">Date</label>
                  <input v-model="filters.date" type="date" class="mt-1 h-11 w-full rounded-lg border px-3" />
                </div>

                <div class="flex flex-wrap items-end gap-2 md:col-span-3">
                  <button class="h-11 rounded-lg bg-blue-600 px-4 text-sm font-extrabold text-white" @click="load">
                    Load
                  </button>
                  <button class="h-11 rounded-lg border px-4 text-sm font-extrabold" @click="setToday">
                    Today
                  </button>
                  <RouterLink
                    class="inline-flex h-11 items-center justify-center rounded-lg bg-gray-900 px-4 text-sm font-extrabold text-white hover:bg-gray-800"
                    :to="printRoute"
                  >
                    Print Item Sold
                  </RouterLink>
                </div>
              </div>

              <div
                v-if="banner"
                class="rounded-lg border p-3 text-sm"
                :class="banner.type === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'"
              >
                {{ banner.message }}
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
              <div class="text-xs font-extrabold text-gray-500">Transactions</div>
              <div class="mt-2 text-2xl font-extrabold text-gray-900">{{ summary.transaction_count }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
              <div class="text-xs font-extrabold text-gray-500">Items sold</div>
              <div class="mt-2 text-2xl font-extrabold text-gray-900">{{ summary.items_sold }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
              <div class="text-xs font-extrabold text-gray-500">Grand total</div>
              <div class="mt-2 text-2xl font-extrabold text-gray-900">Rp {{ money(summary.grand_total) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
              <div class="text-xs font-extrabold text-gray-500">Cashier</div>
              <div class="mt-2 text-2xl font-extrabold text-gray-900">{{ items.length }}</div>
            </div>
          </div>

          <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3">
              <div>
                <div class="text-xs font-extrabold text-gray-600">Outlet payment summary</div>
                <div class="text-[11px] text-gray-500">Rekap payment method outlet sesuai tanggal yang sedang di-load.</div>
              </div>
              <div class="text-xs text-gray-500">{{ paymentSummary.length }} metode</div>
            </div>

            <div class="p-4">
              <div v-if="paymentSummary.length === 0" class="text-sm text-gray-500">Belum ada payment method pada tanggal ini.</div>
              <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="pm in paymentSummary" :key="pm.name" class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <div class="truncate text-sm font-extrabold text-gray-900">{{ pm.name }}</div>
                      <div class="mt-1 text-[11px] text-gray-500">{{ pm.transaction_count }} trx</div>
                    </div>
                    <div class="shrink-0 text-right text-sm font-extrabold text-gray-900">Rp {{ money(pm.total) }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3">
              <div>
                <div class="text-xs font-extrabold text-gray-600">Cashier cards</div>
                <div class="text-[11px] text-gray-500">Klik card cashier untuk melihat detail transaksi.</div>
              </div>
              <div class="text-xs text-gray-500">{{ items.length }} cashier</div>
            </div>

            <div class="p-4">
              <div v-if="loading" class="text-sm text-gray-600">Loading...</div>
              <div v-else-if="items.length === 0" class="text-sm text-gray-500">No transactions for selected date.</div>

              <div v-else class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <button
                  v-for="c in items"
                  :key="c.cashier_id"
                  type="button"
                  class="rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-gray-900 hover:shadow-md"
                  @click="openDetail(c)"
                >
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <div class="break-words text-sm font-extrabold text-gray-900">{{ c.cashier_name }}</div>
                      <div class="mt-1 font-mono text-[11px] text-gray-500">{{ c.cashier_id }}</div>
                    </div>
                    <div class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-bold text-gray-700">
                      {{ c.transaction_count }} trx
                    </div>
                  </div>

                  <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                    <div>
                      <div class="font-semibold text-gray-400">Jam transaksi</div>
                      <div class="mt-1 font-bold text-gray-800">{{ timeRangeLabel(c) }}</div>
                    </div>
                    <div>
                      <div class="font-semibold text-gray-400">Grand total</div>
                      <div class="mt-1 font-bold text-gray-900">Rp {{ money(c.grand_total) }}</div>
                    </div>
                  </div>

                  <div class="mt-4 border-t border-dashed border-gray-200 pt-3">
                    <div class="text-[11px] font-extrabold uppercase tracking-wide text-gray-400">Payment method</div>
                    <div v-if="(c.payment_methods || []).length" class="mt-2 space-y-1.5">
                      <div v-for="pm in (c.payment_methods || []).slice(0, 4)" :key="`${c.cashier_id}-${pm.name}`" class="flex items-center justify-between gap-3 text-xs text-gray-700">
                        <span class="truncate pr-2">{{ pm.name }}</span>
                        <span class="shrink-0 font-semibold">Rp {{ money(pm.total) }}</span>
                      </div>
                    </div>
                    <div v-else class="mt-2 text-xs text-gray-400">Belum ada payment method.</div>
                  </div>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <teleport to="body">
    <div v-if="detail.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-3 py-6" @click.self="closeDetail">
      <div class="max-h-[90vh] w-full max-w-5xl overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-4">
          <div>
            <div class="text-lg font-extrabold text-gray-900">{{ detail.title }}</div>
            <div class="mt-1 text-xs text-gray-500">{{ filters.date || todayIso() }} · {{ scopeOutletLabel }}</div>
          </div>
          <button type="button" class="rounded-lg border px-3 py-2 text-sm font-semibold text-gray-700" @click="closeDetail">Close</button>
        </div>

        <div class="max-h-[calc(90vh-72px)] overflow-y-auto p-4">
          <div v-if="detail.loading" class="text-sm text-gray-600">Loading detail...</div>
          <div v-else-if="!detail.item" class="text-sm text-gray-500">Detail not found.</div>
          <div v-else class="space-y-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
              <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <div class="text-xs font-extrabold text-gray-500">Jam transaksi</div>
                <div class="mt-2 text-sm font-extrabold text-gray-900">{{ timeRangeLabel(detail.item.cashier || detail.item) }}</div>
              </div>
              <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <div class="text-xs font-extrabold text-gray-500">Transactions</div>
                <div class="mt-2 text-sm font-extrabold text-gray-900">{{ detailSummary.transaction_count }}</div>
              </div>
              <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <div class="text-xs font-extrabold text-gray-500">Items sold</div>
                <div class="mt-2 text-sm font-extrabold text-gray-900">{{ detailSummary.items_sold }}</div>
              </div>
              <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <div class="text-xs font-extrabold text-gray-500">Grand total</div>
                <div class="mt-2 text-sm font-extrabold text-gray-900">Rp {{ money(detailSummary.grand_total) }}</div>
              </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white">
              <div class="border-b border-gray-200 px-4 py-3 text-xs font-extrabold uppercase tracking-wide text-gray-500">Payment method</div>
              <div class="p-4">
                <div v-if="detailPayments.length" class="grid grid-cols-1 gap-2 md:grid-cols-2">
                  <div v-for="pm in detailPayments" :key="pm.name" class="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-3 py-3 text-sm text-gray-700">
                    <div>
                      <div class="font-bold text-gray-900">{{ pm.name }}</div>
                      <div class="text-[11px] text-gray-500">{{ pm.transaction_count }} trx</div>
                    </div>
                    <div class="font-extrabold">Rp {{ money(pm.total) }}</div>
                  </div>
                </div>
                <div v-else class="text-sm text-gray-400">Belum ada payment method.</div>
              </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
              <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div class="text-xs font-extrabold uppercase tracking-wide text-gray-500">Transaksi cashier</div>
                <div class="text-xs text-gray-500">{{ detailSales.length }} transaksi</div>
              </div>
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-gray-700">
                  <thead class="bg-gray-50 text-xs font-extrabold uppercase tracking-wide text-gray-500">
                    <tr>
                      <th class="px-4 py-3 text-left">Date</th>
                      <th class="px-4 py-3 text-left">Time</th>
                      <th class="px-4 py-3 text-left">Sale #</th>
                      <th class="px-4 py-3 text-left">Channel</th>
                      <th class="px-4 py-3 text-right">Total</th>
                      <th class="px-4 py-3 text-left">Payment Method</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="detailSales.length === 0">
                      <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">No transactions.</td>
                    </tr>
                    <tr v-for="sale in detailSales" :key="sale.id" class="border-t border-gray-100">
                      <td class="px-4 py-3">{{ sale.transaction_date || '-' }}</td>
                      <td class="px-4 py-3">{{ sale.time_only || '-' }}</td>
                      <td class="px-4 py-3 font-medium text-gray-900">
                        <RouterLink class="text-blue-700 hover:underline" :to="saleDetailRoute(sale)">{{ sale.sale_number || '-' }}</RouterLink>
                      </td>
                      <td class="px-4 py-3">{{ channelLabel(sale) }}</td>
                      <td class="px-4 py-3 text-right font-semibold">Rp {{ money(sale.grand_total) }}</td>
                      <td class="px-4 py-3">{{ paymentLabel(sale) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api, extractApiError } from '../lib/api'
import { todayDateInputValue } from '../lib/datetime'
import { useActiveTimezone } from '../composables/useActiveTimezone'
import { useOutletAutoReload } from '../composables/useOutletAutoReload'
import { useOutletScopeStore } from '../stores/outletScope'

const route = useRoute()
const outletScope = useOutletScopeStore()
const { activeTimeZone } = useActiveTimezone()

const loading = ref(false)
const banner = ref(null)
const items = ref([])
const paymentSummary = ref([])
const summary = ref({ transaction_count: 0, items_sold: 0, grand_total: 0 })
const detail = reactive({
  open: false,
  loading: false,
  title: 'Cashier detail',
  item: null,
})

const filters = reactive({
  date: '',
})

const isFinanceRoute = computed(() => String(route.name || '') === 'finance-cashier-report')
const detailSummary = computed(() => detail.item?.summary || detail.item?.cashier || detail.item || { transaction_count: 0, items_sold: 0, grand_total: 0 })
const detailPayments = computed(() => detail.item?.cashier?.payment_methods || detail.item?.payment_methods || [])
const detailSales = computed(() => detail.item?.sales || [])
const scopeOutletLabel = computed(() => {
  if (outletScope.mode !== 'ONE' || !outletScope.outletId) return 'Semua outlet'
  const selected = (outletScope.outlets || []).find((item) => String(item.id) === String(outletScope.outletId))
  return selected?.name || selected?.code || 'Outlet terpilih'
})
const printRoute = computed(() => ({
  name: 'cashier-report-print',
  query: {
    date: filters.date || todayIso(),
    from: String(route.name || 'cashier-report'),
    outlet_scope: outletScope.headerValue || 'ALL',
  },
}))

function todayIso() {
  return todayDateInputValue({ timeZone: activeTimeZone.value || 'Asia/Jakarta' })
}

function setToday() {
  filters.date = todayIso()
  load()
}

function money(n) {
  return Number(n || 0).toLocaleString('id-ID')
}

function timeRangeLabel(item) {
  const start = String(item?.first_transaction_time || '').trim()
  const end = String(item?.last_transaction_time || '').trim()
  if (start && end) return `${start} - ${end}`
  return start || end || '-'
}

function channelLabel(sale) {
  const raw = String(sale?.channel || '').toUpperCase()
  if (raw === 'DELIVERY') {
    const source = String(sale?.online_order_source || '').trim()
    return source || 'Online'
  }
  if (raw === 'DINE_IN') return 'Dine In'
  if (raw === 'TAKEAWAY') return 'Takeaway'
  return raw || '-'
}

function paymentLabel(sale) {
  const first = Array.isArray(sale?.payments) ? sale.payments[0] : null
  return first?.payment_method_name || sale?.payment_method_name || '-'
}

function saleDetailRoute(sale) {
  const routeName = isFinanceRoute.value ? 'sale-detail' : 'cashier-sale-detail'
  return {
    name: routeName,
    params: { id: sale.id },
    query: {
      back_name: String(route.name || ''),
      date: filters.date || todayIso(),
      source: 'cashier-report',
    },
  }
}

async function load() {
  loading.value = true
  banner.value = null
  try {
    const res = await api.get('/reports/cashier-report/cashiers', {
      params: { date: filters.date || undefined },
    })
    const payload = res?.data?.data || {}
    items.value = payload.items || []
    paymentSummary.value = payload.payment_methods || []
    summary.value = payload.summary || { transaction_count: 0, items_sold: 0, grand_total: 0 }
  } catch (err) {
    const e = extractApiError(err)
    banner.value = { type: 'error', message: e.message || 'Failed to load cashier report.' }
    items.value = []
    paymentSummary.value = []
    summary.value = { transaction_count: 0, items_sold: 0, grand_total: 0 }
  } finally {
    loading.value = false
  }
}

async function openDetail(card) {
  detail.open = true
  detail.loading = true
  detail.title = card?.cashier_name || 'Cashier detail'
  detail.item = {
    cashier: card,
    summary: {
      transaction_count: card?.transaction_count || 0,
      items_sold: card?.items_sold || 0,
      grand_total: card?.grand_total || 0,
    },
    sales: [],
  }

  try {
    const res = await api.get(`/reports/cashier-report/${encodeURIComponent(card.cashier_id)}`, {
      params: { date: filters.date || undefined },
    })
    detail.item = res?.data?.data || null
  } catch (err) {
    const e = extractApiError(err)
    banner.value = { type: 'error', message: e.message || 'Failed to load cashier detail.' }
  } finally {
    detail.loading = false
  }
}

function closeDetail() {
  detail.open = false
  detail.loading = false
  detail.title = 'Cashier detail'
  detail.item = null
}

useOutletAutoReload(() => {
  closeDetail()
  load()
})

onMounted(async () => {
  filters.date = String(route.query?.date || '').trim() || todayIso()
  if (!outletScope.loaded && !outletScope.loading) {
    try {
      await outletScope.loadOutlets()
    } catch {}
  }
  load()
})
</script>
