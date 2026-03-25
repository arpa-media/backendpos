<template>
  <div class="h-full overflow-y-auto overscroll-contain bg-gray-100">
    <div class="min-h-full pb-[calc(4rem+env(safe-area-inset-bottom))]">
      <div class="mx-auto w-full max-w-3xl px-3 py-4 sm:px-4 sm:py-5">
        <div class="space-y-4">
          <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3">
              <div class="min-w-0">
                <div class="text-base font-extrabold tracking-tight text-gray-900">Sale Detail</div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ sale?.sale_number || sale?.invoice_no || '-' }} • {{ channelText }} • {{ dateTimeText }}
                </div>
              </div>

              <button
                type="button"
                class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 transition-colors hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 active:scale-[0.99]"
                @click="goBack"
              >
                Back
              </button>
            </div>

            <div
              v-if="banner"
              class="m-4 rounded-lg border px-3 py-2 text-sm font-semibold"
              :class="banner.type === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'"
            >
              {{ banner.message }}
            </div>

            <div class="p-4">
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-3">
                  <div class="text-[11px] font-extrabold uppercase text-gray-500">Outlet</div>
                  <div class="mt-1 text-sm font-extrabold text-gray-900">{{ sale?.outlet?.name || '-' }}</div>
                  <div class="mt-1 text-xs text-gray-600">Cashier: <span class="font-bold">{{ cashierText }}</span></div>
                  <div v-if="sale?.bill_name" class="mt-1 text-xs text-gray-600">Customer: <span class="font-bold">{{ sale.bill_name }}</span></div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-3">
                  <div class="text-[11px] font-extrabold uppercase text-gray-500">Payment</div>
                  <div class="mt-1 text-sm font-extrabold text-gray-900">{{ paymentMethodName }}</div>
                  <div class="mt-1 text-xs text-gray-600">Paid: <span class="font-bold">Rp {{ money(sale?.paid_total) }}</span></div>
                  <div class="mt-1 text-xs text-gray-600">Change: <span class="font-bold">Rp {{ money(sale?.change_total) }}</span></div>
                </div>
              </div>

              <div v-if="visiblePrintJobs.length > 0" class="mt-4 grid grid-cols-2 gap-2">
                <RouterLink
                  v-for="job in visiblePrintJobs"
                  :key="job.key"
                  :to="job.to"
                  class="flex h-12 min-h-[44px] items-center justify-center rounded-xl font-extrabold text-white transition-colors active:scale-[0.99]"
                  :class="job.className"
                >
                  {{ job.label }}
                </RouterLink>
              </div>

              <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <button
                  type="button"
                  class="flex h-11 min-h-[44px] items-center justify-center rounded-xl bg-red-600 font-extrabold text-white transition-colors hover:bg-red-700 active:scale-[0.99] disabled:opacity-60"
                  :disabled="cancelReq.loading"
                  @click="openCancelModal"
                >
                  Request Cancel
                </button>
                <button
                  type="button"
                  class="flex h-11 min-h-[44px] items-center justify-center rounded-xl bg-amber-600 font-extrabold text-white transition-colors hover:bg-amber-700 active:scale-[0.99] disabled:opacity-60"
                  :disabled="voidReq.loading || !(sale?.items || []).length"
                  @click="openVoidModal"
                >
                  Request Void Item
                </button>
              </div>
            </div>
          </div>

          <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
              <div class="text-sm font-extrabold text-gray-900">Cancel / Void Request</div>
              <div class="mt-0.5 text-xs text-gray-500">Ajukan dari POS lalu print receipt, kitchen, server, atau pizza meski status masih Requested.</div>
            </div>

            <div class="p-4">
              <div v-if="requestsLoading" class="text-sm text-gray-600">Loading requests...</div>
              <div v-else-if="requestRows.length === 0" class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-sm text-gray-500">
                Belum ada request cancel / void untuk transaksi ini.
              </div>
              <div v-else class="space-y-3">
                <div v-for="req in requestRows" :key="req.id" class="rounded-xl border border-gray-200 bg-white p-3">
                  <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <div class="text-sm font-extrabold text-gray-900">{{ requestTypeLabel(req.request_type) }}</div>
                        <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-bold" :class="requestStatusClass(req.status)">
                          {{ requestStatusLabel(req.status) }}
                        </span>
                      </div>
                      <div class="mt-1 text-xs text-gray-500">
                        {{ formatRequestDate(req.created_at) }}
                        <span v-if="req.requested_by_name">• {{ req.requested_by_name }}</span>
                      </div>
                    </div>
                  </div>

                  <div v-if="req.reason" class="mt-2 text-sm text-gray-700">
                    <span class="font-semibold">Reason:</span> {{ req.reason }}
                  </div>

                  <div v-if="req.request_type === 'VOID' && (req.void_items_snapshot || []).length" class="mt-2 text-xs text-gray-600">
                    <span class="font-semibold text-gray-700">Void items:</span>
                    {{ summarizeVoidItems(req.void_items_snapshot) }}
                  </div>

                  <div v-if="req.status !== 'REJECTED'" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <RouterLink
                      v-for="job in requestPrintJobs(req)"
                      :key="`${req.id}-${job.key}`"
                      :to="job.to"
                      class="flex h-10 items-center justify-center rounded-xl text-xs font-extrabold text-white transition-colors active:scale-[0.99]"
                      :class="job.className"
                    >
                      {{ job.label }}
                    </RouterLink>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
              <div class="text-sm font-extrabold text-gray-900">Items</div>
              <div class="mt-0.5 text-xs text-gray-500">{{ (sale?.items || []).length }} item(s)</div>
            </div>

            <div class="p-4">
              <div v-if="loading" class="text-sm text-gray-600">Loading...</div>
              <div v-else-if="(sale?.items || []).length === 0" class="text-sm text-gray-500">No items.</div>

              <div v-else class="overflow-hidden rounded-lg border border-gray-200 divide-y divide-gray-100">
                <div v-for="(it, idx) in (sale.items || [])" :key="it.id || idx" class="bg-white px-3 py-3">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <div class="truncate text-sm font-extrabold text-gray-900">{{ it.product_name }}</div>
                      <div class="truncate text-xs text-gray-500">
                        {{ it.variant_name }}
                        <span
                          v-if="it.note"
                          class="ml-2 inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-bold text-amber-700"
                          :title="it.note"
                        >
                          {{ it.note }}
                        </span>
                      </div>
                      <div class="mt-1 text-[11px] text-gray-600">{{ it.qty }} x Rp {{ money(it.unit_price) }}</div>
                    </div>

                    <div class="shrink-0 text-right">
                      <div class="text-xs font-extrabold text-gray-900">Rp {{ money(it.line_total) }}</div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="mt-4 rounded-xl border border-gray-200 bg-white p-3">
                <div class="space-y-1 text-sm">
                  <div class="flex justify-between">
                    <span class="text-gray-600">Subtotal</span>
                    <span class="font-extrabold text-gray-900">Rp {{ money(sale?.subtotal) }}</span>
                  </div>

                  <div v-if="Number(sale?.discount_amount || 0) > 0" class="flex justify-between">
                    <span class="text-gray-600">Discount</span>
                    <span class="font-extrabold text-gray-900">- Rp {{ money(sale?.discount_amount) }}</span>
                  </div>

                  <div class="flex justify-between">
                    <span class="text-gray-600">Tax</span>
                    <span class="font-extrabold text-gray-900">Rp {{ money(sale?.tax_total || sale?.tax_amount || 0) }}</span>
                  </div>

                  <div v-if="roundingAmount !== 0" class="flex justify-between">
                    <span class="text-gray-600">Rounding</span>
                    <span class="font-extrabold text-gray-900">{{ roundingAmount > 0 ? '+ ' : '- ' }}Rp {{ money(Math.abs(roundingAmount)) }}</span>
                  </div>

                  <div class="my-2 h-px bg-gray-200"></div>

                  <div class="flex justify-between text-base">
                    <span class="font-extrabold text-gray-900">Total</span>
                    <span class="font-extrabold text-gray-900">Rp {{ money(sale?.grand_total) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div v-if="cancelReq.open" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4">
    <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-sm">
      <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
        <h3 class="text-sm font-semibold text-gray-900">Request Cancel Bill</h3>
        <button class="rounded-lg p-2 text-gray-600 hover:bg-gray-100" aria-label="Close" @click="closeCancelModal">✕</button>
      </div>
      <div class="px-5 py-4">
        <div class="text-xs text-gray-500">Invoice</div>
        <div class="text-sm font-extrabold text-gray-900">{{ sale?.sale_number || sale?.invoice_no || '-' }}</div>

        <div class="mt-4">
          <label class="mb-1 block text-xs font-extrabold text-gray-700">Alasan cancel</label>
          <textarea
            v-model.trim="cancelReq.reason"
            rows="4"
            class="w-full rounded-xl border border-gray-300 bg-gray-50 p-3 text-sm text-gray-900 focus:border-gray-900 focus:ring-gray-900"
            placeholder="Contoh: salah input item / customer cancel / dll"
          ></textarea>
          <p v-if="cancelReq.error" class="mt-2 text-sm text-red-600">{{ cancelReq.error }}</p>
        </div>
      </div>
      <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-4">
        <button class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-extrabold text-gray-700 hover:bg-gray-50" type="button" @click="closeCancelModal">Batal</button>
        <button class="h-10 rounded-xl bg-red-600 px-4 text-sm font-extrabold text-white hover:bg-red-700 disabled:opacity-60" type="button" :disabled="cancelReq.loading" @click="submitCancelRequest">
          {{ cancelReq.loading ? 'Mengirim...' : 'Kirim Request' }}
        </button>
      </div>
    </div>
  </div>

  <div v-if="voidReq.open" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4">
    <div class="w-full max-w-2xl rounded-2xl border border-gray-200 bg-white shadow-sm">
      <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
        <h3 class="text-sm font-semibold text-gray-900">Request Void Item</h3>
        <button class="rounded-lg p-2 text-gray-600 hover:bg-gray-100" aria-label="Close" @click="closeVoidModal">✕</button>
      </div>
      <div class="space-y-4 px-5 py-4">
        <div>
          <div class="text-xs text-gray-500">Invoice</div>
          <div class="text-sm font-extrabold text-gray-900">{{ sale?.sale_number || sale?.invoice_no || '-' }}</div>
        </div>

        <div>
          <div class="mb-2 text-xs font-extrabold uppercase text-gray-500">Pilih item yang akan di-void</div>
          <div class="max-h-72 space-y-2 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50 p-2">
            <label
              v-for="(it, idx) in (sale?.items || [])"
              :key="it.id || idx"
              class="flex cursor-pointer items-start gap-3 rounded-xl border border-transparent bg-white px-3 py-3 hover:border-amber-200"
            >
              <input type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500" :checked="isVoidItemSelected(it.id)" @change="toggleVoidItem(it.id)" />
              <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-extrabold text-gray-900">{{ it.product_name }}</div>
                <div class="truncate text-xs text-gray-500">{{ it.variant_name || '-' }}</div>
                <div class="mt-1 text-[11px] text-gray-600">{{ it.qty }} x Rp {{ money(it.unit_price) }}</div>
                <div v-if="it.note" class="mt-1 text-[11px] text-amber-700">Note: {{ it.note }}</div>
              </div>
              <div class="shrink-0 text-right text-xs font-extrabold text-gray-900">Rp {{ money(it.line_total) }}</div>
            </label>
          </div>
          <div class="mt-2 text-xs text-gray-500">Dipilih: <span class="font-bold text-gray-700">{{ voidSelectedCount }}</span> item</div>
        </div>

        <div>
          <label class="mb-1 block text-xs font-extrabold text-gray-700">Alasan void</label>
          <textarea
            v-model.trim="voidReq.reason"
            rows="3"
            class="w-full rounded-xl border border-gray-300 bg-gray-50 p-3 text-sm text-gray-900 focus:border-gray-900 focus:ring-gray-900"
            placeholder="Contoh: salah input item / item dibatalkan customer / dll"
          ></textarea>
          <p v-if="voidReq.error" class="mt-2 text-sm text-red-600">{{ voidReq.error }}</p>
        </div>
      </div>
      <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-4">
        <button class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-extrabold text-gray-700 hover:bg-gray-50" type="button" @click="closeVoidModal">Batal</button>
        <button class="h-10 rounded-xl bg-amber-600 px-4 text-sm font-extrabold text-white hover:bg-amber-700 disabled:opacity-60" type="button" :disabled="voidReq.loading || voidSelectedCount === 0" @click="submitVoidRequest">
          {{ voidReq.loading ? 'Mengirim...' : 'Kirim Request Void' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { api, extractApiError } from '../lib/api'
import { formatDateTime } from '../lib/datetime'
import { usePrinterSettingsStore } from '@/stores/printerSettings'
import { getSaleRoundingTotal } from '../lib/saleRounding'

const route = useRoute()
const router = useRouter()
const id = computed(() => String(route.params.id || ''))

const loading = ref(false)
const banner = ref(null)
const sale = ref({ items: [], payments: [] })
const paymentMethodName = ref('-')
const requestsLoading = ref(false)
const requestRows = ref([])

const cancelReq = ref({
  open: false,
  loading: false,
  reason: '',
  error: '',
})

const voidReq = ref({
  open: false,
  loading: false,
  reason: '',
  error: '',
  itemIds: [],
})

const printer = usePrinterSettingsStore()

const roundingAmount = computed(() => getSaleRoundingTotal(sale.value || {}))
const firstPayment = computed(() => (sale.value?.payments || [])[0] || null)
const channelText = computed(() => {
  const ch = String(sale.value?.channel || '').toUpperCase()
  return ch ? ch.replaceAll('_', ' ') : '-'
})
const cashierText = computed(() => sale.value?.cashier?.name || sale.value?.cashier_name || 'Nama Kasir')
const dateTimeText = computed(() => {
  const tz = sale.value?.outlet?.timezone || 'Asia/Jakarta'
  const raw = sale.value?.created_at || sale.value?.paid_at || sale.value?.timestamp || null
  return formatDateTime(raw, { timeZone: tz })
})
const voidSelectedCount = computed(() => voidReq.value.itemIds.length)
const fallbackBackRoute = computed(() => {
  const backName = String(route.query?.back_name || '').trim()
  const date = String(route.query?.date || '').trim()
  if (backName === 'cashier-cashier-report') {
    return { name: 'cashier-cashier-report', query: date ? { date } : {} }
  }
  return { name: 'cashier-sales' }
})

const visiblePrintJobs = computed(() => buildPrintJobs({ requestId: '' }))

function buildPrintJobs({ requestId }) {
  const defs = [
    { key: 'receipt', label: 'Print Receipt', routeName: 'receipt-print', className: 'bg-gray-900 hover:bg-gray-800' },
    { key: 'kitchen', label: 'Print Kitchen', routeName: 'kitchen-print', className: 'bg-blue-600 hover:bg-blue-700' },
    { key: 'table', label: 'Print Server', routeName: 'table-print', className: 'bg-indigo-600 hover:bg-indigo-700' },
    { key: 'pizza', label: 'Print Pizza', routeName: 'pizza-print', className: 'bg-amber-600 hover:bg-amber-700' },
    { key: 'bar', label: 'Print Bar', routeName: 'bar-print', className: 'bg-emerald-600 hover:bg-emerald-700' },
  ]
  return defs
    .filter((job) => !!printer.enabled_jobs?.[job.key])
    .filter((job) => router.hasRoute(job.routeName))
    .map((job) => ({
      ...job,
      to: {
        name: job.routeName,
        params: { id: id.value },
        query: {
          from: 'cashier-sale',
          ...(requestId ? { request_id: requestId } : {}),
        },
      },
    }))
}

function requestPrintJobs(req) {
  return buildPrintJobs({ requestId: req?.id }).filter((job) => job.key !== 'bar')
}

function setBanner(type, message) {
  banner.value = { type, message }
  window.clearTimeout(setBanner._t)
  setBanner._t = window.setTimeout(() => (banner.value = null), 2500)
}

function goBack() {
  if (window.history.length > 1) {
    router.back()
    return
  }
  router.push(fallbackBackRoute.value)
}

function money(n) {
  return Number(n || 0).toLocaleString('id-ID')
}

function formatRequestDate(value) {
  return formatDateTime(value, { timeZone: sale.value?.outlet?.timezone || 'Asia/Jakarta' })
}

function requestTypeLabel(value) {
  return String(value || '').toUpperCase() === 'VOID' ? 'VOID BILL' : 'CANCEL BILL'
}

function requestStatusLabel(value) {
  const status = String(value || '').toUpperCase()
  if (status === 'APPROVED') return 'Approved'
  if (status === 'REJECTED') return 'Rejected'
  return 'Requested'
}

function requestStatusClass(value) {
  const status = String(value || '').toUpperCase()
  if (status === 'APPROVED') return 'border-green-200 bg-green-50 text-green-700'
  if (status === 'REJECTED') return 'border-gray-200 bg-gray-50 text-gray-600'
  return 'border-amber-200 bg-amber-50 text-amber-700'
}

function summarizeVoidItems(items = []) {
  return items.map((item) => `${item.product_name || '-'} x${item.qty || 1}`).join(', ')
}

function openCancelModal() {
  cancelReq.value = { open: true, loading: false, reason: '', error: '' }
}

function closeCancelModal() {
  cancelReq.value = { ...cancelReq.value, open: false, loading: false, error: '' }
}

function openVoidModal() {
  voidReq.value = { open: true, loading: false, reason: '', error: '', itemIds: [] }
}

function closeVoidModal() {
  voidReq.value = { ...voidReq.value, open: false, loading: false, error: '', itemIds: [] }
}

function isVoidItemSelected(itemId) {
  return voidReq.value.itemIds.includes(String(itemId || ''))
}

function toggleVoidItem(itemId) {
  const normalizedId = String(itemId || '')
  if (!normalizedId) return
  if (isVoidItemSelected(normalizedId)) {
    voidReq.value.itemIds = voidReq.value.itemIds.filter((idValue) => idValue !== normalizedId)
    return
  }
  voidReq.value.itemIds = [...voidReq.value.itemIds, normalizedId]
}

async function resolvePaymentMethodName(paymentMethodId) {
  if (!paymentMethodId) {
    paymentMethodName.value = '-'
    return
  }
  try {
    const res = await api.get(`/payment-methods/${paymentMethodId}`)
    paymentMethodName.value = res?.data?.data?.name || '-'
  } catch {
    paymentMethodName.value = paymentMethodId
  }
}

async function loadRequests() {
  requestsLoading.value = true
  try {
    const res = await api.get(`/sales/${id.value}/cancel-requests`)
    requestRows.value = res?.data?.data?.items || res?.data?.items || []
  } catch (err) {
    const e = extractApiError(err)
    setBanner('error', e.message)
  } finally {
    requestsLoading.value = false
  }
}

async function load() {
  loading.value = true
  banner.value = null
  try {
    const res = await api.get(`/sales/${id.value}`)
    sale.value = res?.data?.data || { items: [], payments: [] }
    await resolvePaymentMethodName(firstPayment.value?.payment_method_id)
    await loadRequests()
  } catch (err) {
    const e = extractApiError(err)
    setBanner('error', e.message)
  } finally {
    loading.value = false
  }
}

async function submitCancelRequest() {
  if (!id.value) return
  cancelReq.value.loading = true
  cancelReq.value.error = ''
  try {
    await api.post(`/sales/${id.value}/cancel-requests`, { reason: cancelReq.value.reason || null })
    closeCancelModal()
    setBanner('success', 'Request cancel terkirim')
    await loadRequests()
  } catch (err) {
    const e = extractApiError(err)
    cancelReq.value.error = e.message
  } finally {
    cancelReq.value.loading = false
  }
}

async function submitVoidRequest() {
  if (!id.value) return
  voidReq.value.loading = true
  voidReq.value.error = ''
  try {
    await api.post(`/sales/${id.value}/void-requests`, {
      reason: voidReq.value.reason || null,
      item_ids: voidReq.value.itemIds,
    })
    closeVoidModal()
    setBanner('success', 'Request void terkirim')
    await loadRequests()
  } catch (err) {
    const e = extractApiError(err)
    voidReq.value.error = e.message
  } finally {
    voidReq.value.loading = false
  }
}

onMounted(load)
</script>
