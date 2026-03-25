<template>
  <!-- Dashboard ONLY scroll -->
  <div :class="isCashier ? 'h-full overflow-y-auto overscroll-contain bg-gray-100' : 'h-full overflow-hidden bg-gray-100'">
    <!-- padding bawah supaya tidak ketutup bottom nav -->
    <div class="min-h-full pb-[calc(4rem+env(safe-area-inset-bottom))]">
      <div class="mx-auto w-full max-w-7xl px-3 py-4 sm:px-4 sm:py-5 lg:px-6">
        <div class="space-y-4">
          <!-- Top bar (Moka-like) -->
          <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div
              class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between bg-gray-50 border-b border-gray-200"
            >
              <div class="min-w-0">
                <div class="text-base font-extrabold tracking-tight text-gray-900">Dashboard</div>
                <div class="text-xs text-gray-500">Ringkasan penjualan untuk outlet aktif.</div>
              </div>

              <div class="flex items-center gap-2">
                <RouterLink
                  v-if="isCashier"
                  to="/c/pos"
                  class="h-11 inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-extrabold text-white
                         hover:bg-blue-700 active:scale-[0.99] transition-colors"
                >
                  Open POS
                </RouterLink>
              </div>
            </div>

            <!-- Filters (dense) -->
            <div class="p-4">
              <div class="grid grid-cols-1 gap-3 md:grid-cols-6 md:items-end">
                <div>
                  <label class="mb-1 block text-xs font-bold text-gray-600">Date from</label>
                  <input
                    v-model="filters.date_from"
                    type="date"
                    class="block w-full h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-900
                           focus:outline-none focus:ring-2 focus:ring-blue-200"
                  />
                </div>

                <div>
                  <label class="mb-1 block text-xs font-bold text-gray-600">Date to</label>
                  <input
                    v-model="filters.date_to"
                    type="date"
                    class="block w-full h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-900
                           focus:outline-none focus:ring-2 focus:ring-blue-200"
                  />
                </div>

                <div>
                  <label class="mb-1 block text-xs font-bold text-gray-600">Status</label>
                  <select
                    v-model="filters.status"
                    class="block w-full h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-900
                           focus:outline-none focus:ring-2 focus:ring-blue-200"
                  >
                    <option value="PAID">PAID</option>
                    <option value="VOID">VOID</option>
                  </select>
                </div>

                <div>
                  <label class="mb-1 block text-xs font-bold text-gray-600">Recent limit</label>
                  <select
                    v-model.number="filters.recent_limit"
                    class="block w-full h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-900
                           focus:outline-none focus:ring-2 focus:ring-blue-200"
                  >
                    <option :value="5">5</option>
                    <option :value="10">10</option>
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                  </select>
                </div>

                <div class="md:col-span-2 flex gap-2">
                  <button
                    type="button"
                    class="w-full h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm font-extrabold text-gray-700
                           hover:bg-gray-50 active:scale-[0.99] disabled:opacity-60 transition-colors"
                    @click="reset"
                    :disabled="loading"
                  >
                    Reset
                  </button>
                  <button
                    type="button"
                    class="w-full h-11 rounded-lg bg-blue-600 px-4 text-sm font-extrabold text-white
                           hover:bg-blue-700 active:scale-[0.99] disabled:opacity-60 transition-colors"
                    @click="load"
                    :disabled="loading"
                  >
                    {{ loading ? "Loading..." : "Apply" }}
                  </button>
                </div>
              </div>

              <div
                v-if="banner"
                class="mt-3 rounded-lg border px-3 py-2 text-sm font-semibold"
                :class="banner.type === 'error'
                  ? 'border-red-200 bg-red-50 text-red-700'
                  : 'border-green-200 bg-green-50 text-green-700'"
              >
                {{ banner.message }}
              </div>
            </div>
          </div>

          <!-- Metrics (more POS/dense) -->
          <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <MetricCard label="Gross Sales" :badge="rangeLabel" hint="Omzet (grand_total) pada range">
              Rp {{ money(summary.metrics?.gross_sales) }}
            </MetricCard>

            <MetricCard label="Transactions" hint="Jumlah transaksi">
              {{ summary.metrics?.trx_count ?? 0 }}
            </MetricCard>

            <MetricCard label="Items Sold" hint="Total qty dari semua item">
              {{ summary.metrics?.items_sold ?? 0 }}
            </MetricCard>

            <MetricCard label="Avg Ticket" hint="Rata-rata omzet per transaksi">
              Rp {{ money(summary.metrics?.avg_ticket) }}
            </MetricCard>
          </div>

          <!-- Breakdown -->
          <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
              <BreakdownTable
                title="By Channel"
                subtitle="Omzet & transaksi per channel"
                col1="Channel"
                :rows="byChannelRows"
              />
            </div>
<div v-if="summary.by_channel_meta && summary.by_channel_meta.last_page > 1" class="px-4 pb-4 pt-2 flex items-center justify-between">
  <div class="text-xs font-semibold text-gray-500">
    Page {{ summary.by_channel_meta.current_page }} / {{ summary.by_channel_meta.last_page }}
  </div>
  <div class="flex gap-2">
    <button
      class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
      :disabled="summary.by_channel_meta.current_page <= 1"
      @click="prevByChannel"
    >
      Prev
    </button>
    <button
      class="h-9 rounded-lg bg-gray-900 px-3 text-xs font-extrabold text-white hover:bg-black disabled:opacity-50"
      :disabled="summary.by_channel_meta.current_page >= summary.by_channel_meta.last_page"
      @click="nextByChannel"
    >
      Next
    </button>
  </div>
</div>


            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
              <BreakdownTable
                title="By Payment Method"
                subtitle="Omzet & transaksi per metode (snapshot)"
                col1="Method"
                :rows="byPaymentRows"
              />
            </div>
<div v-if="summary.by_payment_method_meta && summary.by_payment_method_meta.last_page > 1" class="px-4 pb-4 pt-2 flex items-center justify-between">
  <div class="text-xs font-semibold text-gray-500">
    Page {{ summary.by_payment_method_meta.current_page }} / {{ summary.by_payment_method_meta.last_page }}
  </div>
  <div class="flex gap-2">
    <button
      class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-xs font-extrabold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
      :disabled="summary.by_payment_method_meta.current_page <= 1"
      @click="prevByPayment"
    >
      Prev
    </button>
    <button
      class="h-9 rounded-lg bg-gray-900 px-3 text-xs font-extrabold text-white hover:bg-black disabled:opacity-50"
      :disabled="summary.by_payment_method_meta.current_page >= summary.by_payment_method_meta.last_page"
      @click="nextByPayment"
    >
      Next
    </button>
  </div>
</div>

          </div>

          <!-- Top items + recent sales -->
          <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Top Items (2 tabs) -->
            <div class="lg:col-span-1 rounded-xl border border-gray-200 bg-white overflow-hidden">
              <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="text-sm font-extrabold text-gray-900">Top Items</div>
                    <div class="mt-0.5 text-xs text-gray-500">
                      {{ topTab === "variant" ? "Top 5 variant by qty" : "Top 5 product by qty" }}
                    </div>
                  </div>

                  <!-- Tabs -->
                  <div class="shrink-0 inline-flex rounded-lg border border-gray-200 bg-white p-1">
                    <button
                      type="button"
                      class="h-8 px-3 rounded-md text-xs font-extrabold transition-colors"
                      :class="topTab === 'variant'
                        ? 'bg-gray-900 text-white'
                        : 'text-gray-700 hover:bg-blue-50 hover:text-blue-700'"
                      @click="topTab = 'variant'"
                    >
                      Variant
                    </button>
                    <button
                      type="button"
                      class="h-8 px-3 rounded-md text-xs font-extrabold transition-colors"
                      :class="topTab === 'product'
                        ? 'bg-gray-900 text-white'
                        : 'text-gray-700 hover:bg-blue-50 hover:text-blue-700'"
                      @click="topTab = 'product'"
                    >
                      Product
                    </button>
                  </div>
                </div>
              </div>

              <div class="p-3">
                <div v-if="topItemsShown.length === 0" class="text-sm text-gray-500 px-2 py-3">
                  No data.
                </div>

                <div v-else class="divide-y divide-gray-100 rounded-lg border border-gray-200 overflow-hidden">
                  <div
                    v-for="t in topItemsShown"
                    :key="t.key"
                    class="px-3 py-3 bg-white"
                  >
                    <div class="flex items-start justify-between gap-3">
                      <div class="min-w-0">
                        <div class="text-sm font-extrabold text-gray-900 truncate">
                          {{ t.product_name }}
                        </div>
                        <div v-if="topTab === 'variant'" class="text-xs text-gray-500 truncate">
                          {{ t.variant_name }}
                        </div>
                      </div>

                      <div class="text-right shrink-0">
                        <div class="text-xs font-extrabold text-gray-900">
                          Rp {{ money(t.revenue) }}
                        </div>
                        <div
                          class="mt-1 inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5
                                 text-[11px] font-bold text-gray-700"
                        >
                          Qty {{ t.qty_sold }}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Recent Sales -->
            <div class="lg:col-span-2 rounded-xl border border-gray-200 bg-white overflow-hidden">
              <RecentSalesTable
                title="Recent Sales"
                subtitle="Klik receipt untuk detail, atau print"
                :rows="summary.recent_sales || []"
                :to="{ name: 'sales' }"
              />
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRoute } from "vue-router";
import { api, extractApiError, toQueryParams } from "../lib/api";
import { todayDateInputValue } from "../lib/datetime";
import { useActiveTimezone } from "../composables/useActiveTimezone";
import { useOutletAutoReload } from "../composables/useOutletAutoReload";

import MetricCard from "../components/dashboard/MetricCard.vue";
import BreakdownTable from "../components/dashboard/BreakdownTable.vue";
import RecentSalesTable from "../components/dashboard/RecentSalesTable.vue";

const loading = ref(false);
const banner = ref(null);

const route = useRoute();
const isCashier = computed(() => route.meta?.area === 'cashier' || String(route.path || '').startsWith('/c'))
const { activeTimeZone } = useActiveTimezone()

// Dashboard breakdown pagination (server-side)
const breakdownPerPage = ref(5);
const byChannelPage = ref(1);
const byPaymentPage = ref(1);
const topItemsPage = ref(1);

const todayStr = () => todayDateInputValue({ timeZone: activeTimeZone.value || 'Asia/Jakarta' });

const filters = reactive({
  date_from: todayStr(),
  date_to: todayStr(),
  status: "PAID",
  recent_limit: 10
});

const summary = reactive({
  range: {},
  metrics: {},
  by_channel: [],
  by_payment_method: [],
  top_items: [],
  recent_sales: [],
  by_channel_meta: null,
  by_payment_method_meta: null,
  top_items_meta: null
});

const topItems = computed(() => summary.top_items || []);

const rangeLabel = computed(() => {
  const rf = summary.range?.date_from || filters.date_from;
  const rt = summary.range?.date_to || filters.date_to;
  return rf === rt ? rf : `${rf} → ${rt}`;
});

const byChannelRows = computed(() =>
  (summary.by_channel || []).map((r) => ({
    label: r.channel,
    trx_count: r.trx_count,
    gross_sales: r.gross_sales
  }))
);

const byPaymentRows = computed(() =>
  (summary.by_payment_method || []).map((r) => ({
    label: r.payment_method_name || "-",
    subLabel: r.payment_method_type || "",
    trx_count: r.trx_count,
    gross_sales: r.gross_sales
  }))
);

function setBanner(type, message) {
  banner.value = { type, message };
  window.clearTimeout(setBanner._t);
  setBanner._t = window.setTimeout(() => (banner.value = null), 2500);
}

function money(n) {
  return Number(n || 0).toLocaleString("id-ID");
}

/** Top Items Tabs (variant vs product) */
const topTab = ref("variant"); // 'variant' | 'product'

watch(
  () => summary.top_items,
  () => {
    if (topTab.value !== "variant" && topTab.value !== "product") topTab.value = "variant";
  }
);

const topItemsByVariant = computed(() => {
  const arr = summary.top_items || [];
  return arr.map((t) => ({
    key: `v:${t.variant_id || t.variant_name || t.product_name}`,
    product_name: t.product_name || "-",
    variant_name: t.variant_name || "",
    qty_sold: Number(t.qty_sold || 0),
    revenue: Number(t.revenue || 0)
  }));
});

const topItemsByProduct = computed(() => {
  const arr = summary.top_items || [];
  const map = new Map();

  for (const t of arr) {
    const productName = t.product_name || "-";
    const prev = map.get(productName) || { product_name: productName, qty_sold: 0, revenue: 0 };

    prev.qty_sold += Number(t.qty_sold || 0);
    prev.revenue += Number(t.revenue || 0);

    map.set(productName, prev);
  }

  return Array.from(map.values())
    .sort((a, b) => (b.qty_sold - a.qty_sold) || (b.revenue - a.revenue))
    
    .map((p) => ({
      key: `p:${p.product_name}`,
      product_name: p.product_name,
      variant_name: "",
      qty_sold: p.qty_sold,
      revenue: p.revenue
    }));
});

const topItemsShown = computed(() => {
  return topTab.value === "product" ? topItemsByProduct.value : topItemsByVariant.value;
});

function prevByChannel() {
  if ((summary.by_channel_meta?.current_page || 1) <= 1) return;
  byChannelPage.value = Math.max(1, byChannelPage.value - 1);
  load();
}
function nextByChannel() {
  if ((summary.by_channel_meta?.current_page || 1) >= (summary.by_channel_meta?.last_page || 1)) return;
  byChannelPage.value = byChannelPage.value + 1;
  load();
}
function prevByPayment() {
  if ((summary.by_payment_method_meta?.current_page || 1) <= 1) return;
  byPaymentPage.value = Math.max(1, byPaymentPage.value - 1);
  load();
}
function nextByPayment() {
  if ((summary.by_payment_method_meta?.current_page || 1) >= (summary.by_payment_method_meta?.last_page || 1)) return;
  byPaymentPage.value = byPaymentPage.value + 1;
  load();
}
function prevTopItems() {
  if ((summary.top_items_meta?.current_page || 1) <= 1) return;
  topItemsPage.value = Math.max(1, topItemsPage.value - 1);
  load();
}
function nextTopItems() {
  if ((summary.top_items_meta?.current_page || 1) >= (summary.top_items_meta?.last_page || 1)) return;
  topItemsPage.value = topItemsPage.value + 1;
  load();
}

function reset() {
  filters.date_from = todayStr();
  filters.date_to = todayStr();
  filters.status = "PAID";
  filters.recent_limit = 10;
  byChannelPage.value = 1;
  byPaymentPage.value = 1;
  topItemsPage.value = 1;
  load();
}

async function load() {
  loading.value = true;
  banner.value = null;

  try {
    const params = toQueryParams({
      date_from: filters.date_from || null,
      date_to: filters.date_to || null,
      status: filters.status || null,
      recent_limit: filters.recent_limit || 10,
      breakdown_per_page: breakdownPerPage.value || 5,
      by_channel_page: byChannelPage.value || 1,
      by_payment_page: byPaymentPage.value || 1,
      top_items_page: topItemsPage.value || 1,
    });

    const res = await api.get(`/dashboard/summary?${params.toString()}`);
    const data = res?.data?.data || {};

    summary.range = data.range || {};
    summary.metrics = data.metrics || {};
    summary.by_channel = data.by_channel || [];
    summary.by_payment_method = data.by_payment_method || [];
    summary.top_items = data.top_items || [];
    summary.recent_sales = data.recent_sales || [];
    summary.by_channel_meta = data.by_channel_meta || null;
    summary.by_payment_method_meta = data.by_payment_method_meta || null;
    summary.top_items_meta = data.top_items_meta || null;
  } catch (err) {
    const e = extractApiError(err);
    setBanner("error", e.message);
  } finally {
    loading.value = false;
  }
}

onMounted(load);

// Auto refresh when Admin switches outlet scope via Topbar selector
useOutletAutoReload(() => {
  // keep current filters, just reload summary under new outlet scope
  load();
});
</script>
