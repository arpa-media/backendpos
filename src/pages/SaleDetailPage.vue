<template>
  <div class="min-h-screen bg-gray-100">
    <div class="mx-auto w-full max-w-7xl px-3 py-4 sm:px-4 sm:py-5 lg:px-6">
      <div class="space-y-4">

        <!-- Top bar -->
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
          <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div class="min-w-0">
              <div class="text-xs text-gray-500">
                <button type="button" class="font-bold text-blue-700 hover:underline" @click="goBack">{{ backLabel }}</button>
                <span class="mx-1">/</span>
                <span class="font-mono text-gray-700">{{ id }}</span>
              </div>
              <div class="mt-1 text-base font-extrabold tracking-tight text-gray-900">Receipt</div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <RouterLink :to="{ name: 'receipt-print', params: { id } }" class="h-10 inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 hover:bg-gray-50 active:scale-[0.99]">Receipt</RouterLink>
              <RouterLink :to="{ name: 'kitchen-print', params: { id } }" class="h-10 inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 hover:bg-gray-50 active:scale-[0.99]">Kitchen</RouterLink>
              <RouterLink :to="{ name: 'bar-print', params: { id } }" class="h-10 inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 hover:bg-gray-50 active:scale-[0.99]">BAR</RouterLink>
              <RouterLink :to="{ name: 'table-print', params: { id } }" class="h-10 inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 hover:bg-gray-50 active:scale-[0.99]">SERVER</RouterLink>
              <RouterLink :to="{ name: 'pizza-print', params: { id } }" class="h-10 inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 hover:bg-gray-50 active:scale-[0.99]">PIZZA</RouterLink>

              <button v-if="String(sale.status||'').toUpperCase() !== 'CANCELLED' && (canApproveCancel || canRequestCancel)" type="button" class="h-10 inline-flex items-center justify-center rounded-lg border border-red-200 bg-white px-3 text-xs font-extrabold text-red-700 hover:bg-red-50 active:scale-[0.99]" @click="cancelBill">
                {{ canApproveCancel ? 'Cancel Bill' : 'Request Cancel Bill' }}
              </button>
              <button v-else-if="String(sale.status||'').toUpperCase() === 'CANCELLED' && canApproveCancel" type="button" class="h-10 inline-flex items-center justify-center rounded-lg border border-red-200 bg-white px-3 text-xs font-extrabold text-red-700 hover:bg-red-50 active:scale-[0.99]" @click="confirmDelete">
                Confirm Delete
              </button>

              <button type="button" class="h-10 inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 hover:bg-gray-50 active:scale-[0.99]" @click="load" :disabled="loading">Reload</button>
            </div>
          </div>

          <div
            v-if="banner"
            class="m-4 rounded-lg border px-3 py-2 text-sm font-semibold"
            :class="banner.type === 'error'
              ? 'border-red-200 bg-red-50 text-red-700'
              : 'border-green-200 bg-green-50 text-green-700'"
          >
            {{ banner.message }}
          </div>

          <div v-if="loading" class="p-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600">
              Loading...
            </div>
          </div>
        </div>

        <!-- Receipt card -->
        <div v-if="!loading" class="rounded-xl border border-gray-200 bg-white overflow-hidden">
          <!-- receipt header -->
          <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
              <div class="min-w-0">
                <div class="text-sm font-extrabold text-gray-900">{{ sale.sale_number }}</div>
                <div class="text-xs text-gray-500">{{ fmtDate(sale.created_at) }}</div>
              </div>

              <div class="text-xs text-gray-600">
                Channel:
                <span class="font-extrabold text-gray-900">{{ sale.channel }}</span>
              </div>
            </div>
          </div>

          <div class="p-4 space-y-4 bg-white">
            <!-- summary metrics -->
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
              <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="px-3 py-2 bg-gray-50 border-b border-gray-200 text-[11px] font-extrabold uppercase text-gray-600">
                  Subtotal
                </div>
                <div class="px-3 py-3 text-lg font-extrabold text-gray-900">
                  Rp {{ money(sale.subtotal) }}
                </div>
              </div>

              <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="px-3 py-2 bg-gray-50 border-b border-gray-200 text-[11px] font-extrabold uppercase text-gray-600">
                  Grand Total
                </div>
                <div class="px-3 py-3 text-lg font-extrabold text-gray-900">
                  Rp {{ money(sale.grand_total) }}
                </div>
              </div>

              <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="px-3 py-2 bg-gray-50 border-b border-gray-200 text-[11px] font-extrabold uppercase text-gray-600">
                  Paid / Change
                </div>
                <div class="px-3 py-3 text-sm font-extrabold text-gray-900">
                  Rp {{ money(sale.paid_total) }} <span class="text-gray-400">/</span> Rp {{ money(sale.change_total) }}
                </div>
              </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4">
              <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                  <span class="text-gray-600">Subtotal</span>
                  <span class="font-extrabold text-gray-900">Rp {{ money(sale.subtotal) }}</span>
                </div>
                <div v-if="Number(sale.discount_amount || 0) > 0" class="flex justify-between">
                  <span class="text-gray-600">Discount</span>
                  <span class="font-extrabold text-gray-900">- Rp {{ money(sale.discount_amount) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-600">Tax</span>
                  <span class="font-extrabold text-gray-900">Rp {{ money(sale.tax_total || sale.tax_amount || 0) }}</span>
                </div>
                <div v-if="roundingAmount !== 0" class="flex justify-between">
                  <span class="text-gray-600">Rounding</span>
                  <span class="font-extrabold text-gray-900">{{ roundingAmount > 0 ? '+ ' : '- ' }}Rp {{ money(Math.abs(roundingAmount)) }}</span>
                </div>
              </div>
            </div>

            <!-- items table -->
            <div class="rounded-xl border border-gray-200 overflow-hidden">
              <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm font-extrabold text-gray-900">
                Items
              </div>

              <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                  <thead class="bg-white text-[11px] uppercase text-gray-500 border-b border-gray-200">
                    <tr>
                      <th class="px-4 py-3 font-extrabold">Item</th>
                      <th class="px-4 py-3 font-extrabold">Qty</th>
                      <th class="px-4 py-3 font-extrabold">Unit</th>
                      <th class="px-4 py-3 font-extrabold">Total</th>
                    </tr>
                  </thead>

                  <tbody class="divide-y divide-gray-100">
                    <tr
                      v-for="it in (sale.items || [])"
                      :key="it.id"
                      class="hover:bg-blue-50/50 transition-colors"
                    >
                      <td class="px-4 py-3">
                        <div class="font-extrabold text-gray-900">{{ it.product_name }}</div>
                        <div class="text-xs text-gray-500">{{ it.variant_name }}</div>
                      </td>
                      <td class="px-4 py-3 font-semibold text-gray-800">{{ it.qty }}</td>
                      <td class="px-4 py-3 text-gray-800">Rp {{ money(it.unit_price) }}</td>
                      <td class="px-4 py-3 font-extrabold text-gray-900">Rp {{ money(it.line_total) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- payment -->
            <div class="rounded-xl border border-gray-200 overflow-hidden">
              <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm font-extrabold text-gray-900">
                Payment
              </div>
              <div class="p-4 text-sm">
                <div class="flex items-center justify-between">
                  <span class="text-gray-600">Method</span>
                  <span class="font-extrabold text-gray-900">{{ paymentMethodName }}</span>
                </div>
                <div class="flex items-center justify-between mt-2">
                  <span class="text-gray-600">Amount</span>
                  <span class="font-extrabold text-gray-900">Rp {{ money(firstPayment?.amount || 0) }}</span>
                </div>
                <div v-if="firstPayment?.reference" class="mt-2 text-xs text-gray-500">
                  Ref: <span class="font-mono text-gray-700">{{ firstPayment.reference }}</span>
                </div>
              </div>
            </div>

            <!-- note -->
            <div v-if="sale.note" class="rounded-xl border border-gray-200 overflow-hidden">
              <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm font-extrabold text-gray-900">
                Note
              </div>
              <div class="p-4 text-sm text-gray-700 whitespace-pre-wrap">
                {{ sale.note }}
              </div>
            </div>

          </div>
        </div>

      </div>
    </div>
  </div>
</template>



<script setup>
import { getSaleRoundingTotal } from "../lib/saleRounding";
import { computed, onMounted, ref } from "vue";
import { formatDateTime } from '../lib/datetime'
import { useAuthStore } from "../stores/auth";
import { can } from "../lib/ability";
import { useRoute, useRouter } from "vue-router";
import { api, extractApiError } from "../lib/api";

const route = useRoute();
const router = useRouter();
const backLabel = computed(() => String(route.query?.back_name || '') === 'finance-cashier-report' ? 'Cashier Report' : 'Sales')
const fallbackBackRoute = computed(() => {
  const backName = String(route.query?.back_name || '').trim()
  const date = String(route.query?.date || '').trim()
  if (backName === 'finance-cashier-report') {
    return { name: 'finance-cashier-report', query: date ? { date } : {} }
  }
  return { name: 'sales' }
})
const auth = useAuthStore();
const id = computed(() => String(route.params.id || ""));

const loading = ref(false);
const banner = ref(null);
const sale = ref({ items: [], payments: [] });

const paymentMethodName = ref("-");

const firstPayment = computed(() => (sale.value?.payments || [])[0] || null);
const canApproveCancel = computed(() => can(auth.permissions, "sale.cancel.approve"));
const canRequestCancel = computed(() => can(auth.permissions, "sale.cancel.request"));
const roundingAmount = computed(() => getSaleRoundingTotal(sale.value || {}));

function setBanner(type, message) {
  banner.value = { type, message };
  window.clearTimeout(setBanner._t);
  setBanner._t = window.setTimeout(() => (banner.value = null), 2500);
}

function money(n) {
  return Number(n || 0).toLocaleString("id-ID");
}

function fmtDate(iso) {
  const tz = sale.value?.outlet?.timezone || 'Asia/Jakarta'
  return formatDateTime(iso, { timeZone: tz })
}

async function resolvePaymentMethodName(paymentMethodId) {
  if (!paymentMethodId) {
    paymentMethodName.value = "-";
    return;
  }

  try {
    const res = await api.get(`/payment-methods/${paymentMethodId}`);
    paymentMethodName.value = res?.data?.data?.name || "-";
  } catch {
    // fallback
    paymentMethodName.value = paymentMethodId;
  }
}

async function cancelBill() {
  if (!id.value) return

  if (canApproveCancel.value) {
    if (!confirm(`Cancel bill ${sale.value?.sale_number || id.value}?`)) return
    try {
      await api.post(`/sales/${id.value}/cancel`)
      await load()
    } catch (err) {
      const e = extractApiError(err)
      banner.value = { type: 'error', message: e.message }
    }
    return
  }

  if (!canRequestCancel.value) {
    banner.value = { type: 'error', message: 'User does not have the right permissions.' }
    return
  }

  const reason = window.prompt('Masukkan alasan cancel bill', '')
  if (reason === null) return

  try {
    await api.post(`/sales/${id.value}/cancel-requests`, { reason: String(reason || '').trim() || null })
    banner.value = { type: 'success', message: 'Request cancel terkirim' }
    await load()
  } catch (err) {
    const e = extractApiError(err)
    banner.value = { type: 'error', message: e.message }
  }
}

async function confirmDelete() {
  if (!id.value) return
  if (!confirm(`Hapus transaksi ${sale.value?.sale_number || id.value} dari database?`)) return
  try {
    await api.delete(`/sales/${id.value}`)
    banner.value = { type: 'success', message: 'Sale deleted' }
    // go back to sales list
    router.push('/sales')
  } catch (err) {
    const e = extractApiError(err)
    banner.value = { type: 'error', message: e.message }
  }
}

async function load() {
  loading.value = true;
  banner.value = null;

  try {
    const res = await api.get(`/sales/${id.value}`);
    sale.value = res?.data?.data || {};
    await resolvePaymentMethodName(firstPayment.value?.payment_method_id);
  } catch (err) {
    const e = extractApiError(err);
    setBanner("error", e.message);
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
