<template>
  <div class="wp-wrap">
    <el-breadcrumb separator="›" class="mb-3">
      <el-breadcrumb-item @click="$router.push('/')" class="cursor">Home</el-breadcrumb-item>
      <el-breadcrumb-item>Reports</el-breadcrumb-item>
      <el-breadcrumb-item>Payments by Worker</el-breadcrumb-item>
    </el-breadcrumb>

    <el-card shadow="never" class="mb-3">
      <template #header>
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold">Payment Report — By Worker & Date</h3>
        </div>
      </template>

      <el-form :inline="true" class="gap-2 items-end">
        <!-- Worker -->
        <el-form-item label="Worker">
          <el-select
              v-model="filters.workerId"
              filterable
              remote
              reserve-keyword
              placeholder="Search worker"
              :remote-method="onWorkerSearch"
              :loading="loading.workers"
              style="min-width: 260px"
          >
            <el-option v-for="opt in workersOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>

        <!-- Start month/year -->
        <el-form-item label="Start">
          <el-select v-model="filters.startMonth" placeholder="Month" style="width: 120px">
            <el-option v-for="m in months" :key="m.value" :label="m.label" :value="m.value" />
          </el-select>
          <el-select v-model="filters.startYear" placeholder="Year" class="ml-2" style="width: 120px">
            <el-option v-for="y in years" :key="y" :label="y" :value="y" />
          </el-select>
        </el-form-item>

        <!-- End month/year -->
        <el-form-item label="End">
          <el-select v-model="filters.endMonth" placeholder="Month" style="width: 120px">
            <el-option v-for="m in months" :key="m.value" :label="m.label" :value="m.value" />
          </el-select>
          <el-select v-model="filters.endYear" placeholder="Year" class="ml-2" style="width: 120px">
            <el-option v-for="y in years" :key="y" :label="y" :value="y" />
          </el-select>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :disabled="!canRun" :loading="loading.run" @click="runReport">
            Run report
          </el-button>
          <el-button :disabled="!rows.length" @click="exportCsv">Export CSV</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" v-loading="loading.table">
      <template #header>
        <div class="flex items-center justify-between">
          <div>
            <div class="font-semibold">Results</div>
            <div class="text-gray-600 text-sm" v-if="metaText">{{ metaText }}</div>
          </div>
          <div class="text-right">
            <div class="text-sm">Hours total: <strong>{{ currency(totals.hours_total) }}</strong></div>
            <div class="text-sm">Extras total: <strong>{{ currency(totals.extras_total) }}</strong></div>
            <div class="text-base mt-1">Grand total: <strong>{{ currency(totals.grand_total) }}</strong></div>
          </div>
        </div>
      </template>

      <el-table :data="rows" border height="520">
        <el-table-column prop="payroll_end" label="Payroll End" width="120" />
        <el-table-column prop="type" label="Type" width="90" />
        <el-table-column prop="patient" label="Client" />
        <el-table-column prop="role" label="Role" width="100" />
        <el-table-column prop="hours" label="Hours" width="90" />
        <el-table-column prop="rate" label="Rate" width="100" :formatter="moneyFmt" />
        <el-table-column prop="label" label="Extra label" />
        <el-table-column prop="amount" label="Amount" width="120" :formatter="moneyFmt" />
        <el-table-column prop="total" label="Line Total" width="120" :formatter="moneyFmt" />
      </el-table>

      <div v-if="byMonth.length" class="mt-3">
        <div class="font-semibold mb-1">Totals by Month</div>
        <el-table :data="byMonth" size="small" border>
          <el-table-column prop="ym" label="Month" width="120" />
          <el-table-column prop="total" label="Total" :formatter="moneyFmt" />
        </el-table>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, computed } from "vue";
import { ElMessage } from "element-plus";
import { Search } from "@element-plus/icons-vue";

/* ======= WP ajax setup (same as PayrollDetail.vue) ======= */
const AJAX_URL = typeof parameters !== "undefined" && parameters?.ajax_url
    ? parameters.ajax_url
    : (window.ajaxurl || "/wp-admin/admin-ajax.php");
const NONCE = (typeof parameters !== "undefined" && parameters?.nonce) ? parameters.nonce : "";

/* ======= Helpers (identical style to PayrollDetail.vue) ======= */
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
  workerId: null,
  startMonth: now.getMonth() + 1,
  startYear: now.getFullYear(),
  endMonth: now.getMonth() + 1,
  endYear: now.getFullYear(),
});

const loading = reactive({ workers: false, run: false, table: false });
const workersOptions = ref([]);

const canRun = computed(() =>
    !!filters.workerId && !!filters.startMonth && !!filters.startYear && !!filters.endMonth && !!filters.endYear
);

/* ======= Worker search (same endpoint & pattern used in PayrollDetail.vue) ======= */
async function onWorkerSearch(q) {
  loading.workers = true;
  try {
    const res = await ajaxPostForm("mhc_workers_list", { search: q || "", limit: 20 });
    workersOptions.value = (res?.items || []).map(w => ({
      value: w.id,
      label: `${w.first_name} ${w.last_name}`,
    }));
  } catch (e) {
    // silent
  } finally {
    loading.workers = false;
  }
}

/* ======= Run report ======= */
const rows = ref([]);
const totals = reactive({ hours_total: 0, extras_total: 0, grand_total: 0 });
const byMonth = ref([]);

const metaText = computed(() => {
  if (!rows.value.length) return "";
  const s = `${months.find(m => m.value === filters.startMonth)?.label} ${filters.startYear}`;
  const e = `${months.find(m => m.value === filters.endMonth)?.label} ${filters.endYear}`;
  return `Worker: ${workersOptions.value.find(o => o.value === filters.workerId)?.label || filters.workerId} • Period: ${s} → ${e}`;
});

function toYYYYMMDD(y, m, last = false) {
  const d = new Date(y, m - 1, 1);
  if (last) d.setMonth(d.getMonth() + 1, 0); // last day of month
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${mm}-${dd}`;
}

async function runReport() {
  if (!canRun.value) return;
  loading.run = true;
  loading.table = true;
  rows.value = [];
  byMonth.value = [];
  totals.hours_total = totals.extras_total = totals.grand_total = 0;

  try {
    const start_date = toYYYYMMDD(filters.startYear, filters.startMonth, false);
    const end_date = toYYYYMMDD(filters.endYear, filters.endMonth, true);

    const data = await ajaxPostForm("mhc_reports_worker_payments", {
      worker_id: filters.workerId,
      start_date,
      end_date,
    });

    rows.value = data?.items || [];
    byMonth.value = data?.by_month || [];
    Object.assign(totals, data?.totals || {});
  } catch (e) {
    ElMessage.error(e?.message || "Failed to load report");
  } finally {
    loading.run = false;
    loading.table = false;
  }
}

/* ======= Formatting/Export ======= */
function currency(v) {
  if (v === null || v === undefined) return "";
  return `$${Number(v).toFixed(2)}`;
}
function moneyFmt(_row, _col, cell) { return currency(cell); }

function exportCsv() {
  const header = [
    "Payroll End", "Type", "Client", "Role", "Hours", "Rate", "Extra label", "Amount", "Line Total",
  ];
  const lines = rows.value.map(r => [
    r.payroll_end, r.type, r.patient ?? "", r.role ?? "", r.hours ?? "", r.rate ?? "", r.label ?? "", r.amount ?? "", r.total ?? "",
  ]);
  const csv = [header, ...lines].map(arr => arr.map(v => `"${String(v ?? "").replace(/"/g, '""')}"`).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = "worker_payment_report.csv";
  a.click();
  URL.revokeObjectURL(url);
}
</script>

<style scoped>
.wp-wrap { padding: 12px; }
.mb-3 { margin-bottom: 0.75rem; }
.cursor { cursor: pointer; }
.text-gray-600 { color: #4b5563; }
.text-lg { font-size: 1.125rem; }
.font-semibold { font-weight: 600; }
.ml-2 { margin-left: 0.5rem; }
.mt-1 { margin-top: 0.25rem; }
.mt-3 { margin-top: 0.75rem; }
.flex { display: flex; }
.items-end { align-items: flex-end; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-2 { gap: 0.5rem; }
.text-right { text-align: right; }
</style>
