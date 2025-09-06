<template>
  <div class="wp-wrap">
    <!-- Breadcrumb -->
    <el-breadcrumb separator="›" class="mb-3">
      <el-breadcrumb-item @click="$router.push('/')" class="cursor">Dashboard</el-breadcrumb-item>
      <el-breadcrumb-item>Reports</el-breadcrumb-item>
      <el-breadcrumb-item>By Date Range (All Workers)</el-breadcrumb-item>
    </el-breadcrumb>

    <!-- Filters -->
    <el-card shadow="never" class="mb-3">
      <template #header>
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold">Payment Report — By Date Range (All Workers)</h3>
          <div class="flex items-center gap-2">
            <el-button type="primary" :loading="loading.run" :disabled="!canRun" @click="runReport">Run report</el-button>
            <el-button :disabled="!rows.length" @click="exportCsv">Export CSV</el-button>
          </div>
        </div>
      </template>

      <el-form :inline="true" class="gap-2 items-end">
        <!-- Start month/year -->
        <el-form-item label="Start">
          <el-select v-model="filters.startMonth" placeholder="Month" style="width: 160px">
            <el-option v-for="m in months" :key="m.value" :label="m.label" :value="m.value" />
          </el-select>
          <el-select v-model="filters.startYear" placeholder="Year" class="ml-2" style="width: 140px">
            <el-option v-for="y in years" :key="y" :label="y" :value="y" />
          </el-select>
        </el-form-item>

        <!-- End month/year -->
        <el-form-item label="End">
          <el-select v-model="filters.endMonth" placeholder="Month" style="width: 160px">
            <el-option v-for="m in months" :key="m.value" :label="m.label" :value="m.value" />
          </el-select>
          <el-select v-model="filters.endYear" placeholder="Year" class="ml-2" style="width: 140px">
            <el-option v-for="y in years" :key="y" :label="y" :value="y" />
          </el-select>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Results -->
    <el-card shadow="never" v-loading="loading.table">
      <template #header>
        <div class="flex items-center justify-between">
          <div class="text-sm text-gray-600">
            Period:
            <b>{{ monthLabel(filters.startMonth) }} {{ filters.startYear }}</b>
            →
            <b>{{ monthLabel(filters.endMonth) }} {{ filters.endYear }}</b>
            • Workers: <b>{{ rows.length }}</b>
          </div>
          <div class="text-right">
            <div class="text-sm">Total Hours: <strong>{{ totals.hours_qty.toFixed(2) }}</strong></div>
            <div class="text-sm">Total Extras: <strong>{{ currency(totals.extras_total) }}</strong></div>
            <div class="text-base mt-1">Grand Total: <strong>{{ currency(totals.grand_total) }}</strong></div>
          </div>
        </div>
      </template>

      <el-table
          :data="rows"
          border
          height="560"
          :default-sort="{ prop: 'grand_total', order: 'descending' }"
      >
        <el-table-column prop="worker_name" label="Worker" min-width="220" sortable />
        <el-table-column prop="hours_qty" label="Hours" width="120" align="right" sortable>
          <template #default="scope">{{ Number(scope.row.hours_qty || 0).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="extras_total" label="Extra Payment" width="160" align="right" sortable>
          <template #default="scope">{{ currency(scope.row.extras_total) }}</template>
        </el-table-column>
        <el-table-column prop="grand_total" label="Grand Total" width="180" align="right" sortable>
          <template #default="scope"><b>{{ currency(scope.row.grand_total) }}</b></template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, computed } from "vue";
import { ElMessage } from "element-plus";

/* ======= WP ajax setup (exactly like PayrollDetail.vue) ======= */
const AJAX_URL = (typeof parameters !== "undefined" && parameters?.ajax_url)
    ? parameters.ajax_url
    : (window.ajaxurl || "/wp-admin/admin-ajax.php");
const NONCE = (typeof parameters !== "undefined" && parameters?.nonce) ? parameters.nonce : "";

/* ======= Minimal helpers (same style/pattern) ======= */
async function ajaxPostForm(action, body = {}) {
  const url = new URL(AJAX_URL, window.location.origin);
  url.searchParams.set("action", action);
  if (NONCE) url.searchParams.set("nonce", NONCE);
  const form = new FormData();
  Object.entries(body).forEach(([k, v]) => v !== undefined && form.append(k, v));
  const res = await fetch(url.toString(), { method: "POST", body: form, credentials: "same-origin" });
  const json = await res.json();
  if (!json?.success) throw new Error(json?.data?.message || "Request failed");
  return json.data;
}

/* ======= Filters ======= */
const now = new Date();
const years = Array.from({ length: 8 }, (_, i) => now.getFullYear() - 2 + i);
const months = [
  { value: 1, label: "January" }, { value: 2, label: "February" }, { value: 3, label: "March" },
  { value: 4, label: "April" }, { value: 5, label: "May" }, { value: 6, label: "June" },
  { value: 7, label: "July" }, { value: 8, label: "August" }, { value: 9, label: "September" },
  { value: 10, label: "October" }, { value: 11, label: "November" }, { value: 12, label: "December" },
];

const filters = reactive({
  startMonth: now.getMonth() + 1,
  startYear:  now.getFullYear(),
  endMonth:   now.getMonth() + 1,
  endYear:    now.getFullYear(),
});

const canRun = computed(() =>
    !!filters.startMonth && !!filters.startYear && !!filters.endMonth && !!filters.endYear
);

function monthLabel(m) {
  return months.find(x => x.value === m)?.label || "";
}

function toMonthStart(y, m) {
  const d = new Date(y, m - 1, 1);
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${mm}-${dd}`;
}
function toMonthEnd(y, m) {
  const d = new Date(y, m, 0);
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${mm}-${dd}`;
}

/* ======= Data ======= */
const rows = ref([]);
const totals = reactive({ hours_qty: 0, extras_total: 0, grand_total: 0 });
const loading = reactive({ run: false, table: false });

/* ======= Run report ======= */
async function runReport() {
  if (!canRun.value) return;

  loading.run = true;
  loading.table = true;
  rows.value = [];
  totals.hours_qty = totals.extras_total = totals.grand_total = 0;

  try {
    // Compute bounds from month/year picks
    let start_date = toMonthStart(filters.startYear, filters.startMonth);
    let end_date   = toMonthEnd(filters.endYear, filters.endMonth);

    // Safety: if reversed, swap
    if (new Date(start_date) > new Date(end_date)) {
      const s2 = toMonthStart(filters.endYear, filters.endMonth);
      const e2 = toMonthEnd(filters.startYear, filters.startMonth);
      start_date = s2; end_date = e2;
    }

    // Use the same endpoint (it already supports ranges via BETWEEN on payrolls.end_date)
    const data = await ajaxPostForm("mhc_reports_workers_month_totals", {
      start_date,
      end_date,
    });

    rows.value = data?.items || [];
    totals.hours_qty    = Number(data?.totals?.hours_qty || 0);
    totals.extras_total = Number(data?.totals?.extras_total || 0);
    totals.grand_total  = Number(data?.totals?.grand_total || 0);
  } catch (e) {
    ElMessage.error(e?.message || "Failed to load report");
  } finally {
    loading.run = false;
    loading.table = false;
  }
}

/* ======= Formatting & CSV ======= */
function currency(v) {
  return `$${Number(v || 0).toFixed(2)}`;
}
function exportCsv() {
  const header = ["Worker","Hours","Extra Payment","Grand Total"];
  const lines = rows.value.map(r => [
    r.worker_name ?? `Worker #${r.worker_id}`,
    Number(r.hours_qty || 0).toFixed(2),
    Number(r.extras_total || 0).toFixed(2),
    Number(r.grand_total || 0).toFixed(2),
  ]);

  const csv = [header, ...lines]
      .map(row => row.map(v => `"${String(v).replace(/"/g,'""')}"`).join(","))
      .join("\n");

  const m = (n) => String(n).padStart(2, "0");
  const fname = `report_by_date_${filters.startYear}-${m(filters.startMonth)}_to_${filters.endYear}-${m(filters.endMonth)}.csv`;

  const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = fname;
  a.click();
  URL.revokeObjectURL(url);
}
</script>

<style scoped>
.wp-wrap { max-width: 1200px; }
.mb-3 { margin-bottom: 1rem; }
.cursor { cursor: pointer; }
.flex { display: flex; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-2 { gap: 0.5rem; }
.text-gray-600 { color: #4b5563; }
.text-lg { font-size: 1.125rem; }
.font-semibold { font-weight: 600; }
.ml-2 { margin-left: 0.5rem; }
.mt-1 { margin-top: 0.25rem; }
</style>
