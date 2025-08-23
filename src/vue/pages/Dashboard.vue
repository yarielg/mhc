<template>
  <div class="wp-wrap">
    <!-- Title + Quick actions -->
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Dashboard</h2>

      <div class="flex gap-2">

        <el-button size="small" @click="$router.push('/workers')">
          <el-icon class="mr-2"><User /></el-icon>Workers
        </el-button>
        <el-button size="small" @click="$router.push('/patients')">
          <el-icon class="mr-2"><UserFilled /></el-icon>Patients
        </el-button>
        <el-button type="primary" size="small" @click="$router.push('/payrolls')">
          <el-icon class="mr-2"><Wallet /></el-icon>Payrolls
        </el-button>
      </div>
    </div>

    <!-- Top stats -->
    <el-row :gutter="16" class="mb-4">
      <el-col :xs="24" :sm="12" :md="4">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-icon">
            <el-icon><User /></el-icon>
          </div>
          <div class="stat-body">
            <div class="stat-label">Active Workers</div>
            <div class="stat-value">
              <el-skeleton v-if="loading" :rows="1" animated />
              <template v-else>{{ stats?.workers?.active ?? 0 }}</template>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="4">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-icon">
            <el-icon><UserFilled /></el-icon>
          </div>
          <div class="stat-body">
            <div class="stat-label">Active Patients</div>
            <div class="stat-value">
              <el-skeleton v-if="loading" :rows="1" animated />
              <template v-else>{{ stats?.patients?.active ?? 0 }}</template>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="4">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-icon">
            <el-icon><Money /></el-icon>
          </div>
          <div class="stat-body">
            <div class="stat-label">Total Paid (last 60d)</div>
            <div class="stat-value">
              <el-skeleton v-if="loading" :rows="1" animated />
              <template v-else>{{ formatMoney(stats?.payrolls_60d?.total_paid ?? 0) }}</template>
            </div>
            <div class="text-xs text-gray-500">Payrolls: {{ stats?.payrolls_60d?.count ?? 0 }}</div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="4">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-icon">
            <el-icon><Timer /></el-icon>
          </div>
          <div class="stat-body">
            <div class="stat-label">Hours Entered (last 14d)</div>
            <div class="stat-value">
              <el-skeleton v-if="loading" :rows="1" animated />
              <template v-else>{{ quick?.hours_14d ?? 0 }}</template>
            </div>
            <div class="text-xs text-gray-500">Recent activity snapshot</div>
          </div>
        </el-card>
      </el-col>



      <el-col :xs="24" :sm="12" :md="8">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-icon">
            <el-icon><Calendar /></el-icon>
          </div>
          <div class="stat-body">
            <div class="stat-label">Last Refresh</div>
            <div class="stat-value sm">
              <template v-if="!loading && lastRefreshed">{{ lastRefreshed }}</template>
              <el-skeleton v-else :rows="1" animated />
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- Financial + activity quick -->
    <el-row :gutter="16" class="mb-4">


      <el-col :md="24">
        <el-card shadow="never">
          <template #header>
            <div class="card-header">
              <div class="title">
                <el-icon class="mr-2"><Tickets /></el-icon>
                Recent Payrolls
              </div>
              <div class="text-xs text-gray-500">Latest 5</div>
            </div>
          </template>

          <el-table
              :data="recent?.payrolls || []"
              size="small"
              border
              style="width: 100%"
              empty-text="No payrolls yet"
          >
            <el-table-column label="ID" prop="id" width="80" />
            <el-table-column label="Period" min-width="160">
              <template #default="{ row }">
                {{ row.start_date }} → {{ row.end_date }}
              </template>
            </el-table-column>
            <el-table-column label="Status" width="120">
              <template #default="{ row }">
                <el-tag size="small" :type="statusType(row.status)">{{ row.status }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="Items" prop="items" width="90" />
            <el-table-column label="Total" width="120">
              <template #default="{ row }">{{ formatMoney(row.total) }}</template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>

    </el-row>

  </div>
  <!-- === ADD: Charts === -->
  <el-row :gutter="16" class="mb-4">
    <el-col :xs="24" :md="12">
      <el-card shadow="hover" :body-style="{padding:'12px'}" v-loading="chartsLoading">
        <div class="card-title">Last 10 Payroll Totals</div>
        <div ref="elLast10" class="mhc-chart"></div>
      </el-card>
    </el-col>

  </el-row>



  <el-row :gutter="16" class="mb-4">
    <el-col :xs="24" :md="12">
      <el-card shadow="hover" :body-style="{padding:'12px'}" v-loading="chartsLoading">
        <div class="card-title">Top 5 Workers by Hours (Latest Payroll)</div>
        <div ref="elTopWorkers" class="mhc-chart mhc-chart--short"></div>
      </el-card>
    </el-col>
    <el-col :xs="24" :md="12">
      <el-card shadow="hover" :body-style="{padding:'12px'}" v-loading="chartsLoading">
        <div class="card-title">Pending Adjustments (+/–) by Payroll</div>
        <div ref="elPending" class="mhc-chart mhc-chart--short"></div>
      </el-card>
    </el-col>
  </el-row>
  <!-- === /ADD === -->
</template>

<script setup>
import { ref, computed, onMounted,onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import {
  UserFilled, User, Calendar, Plus, Search, Money, Warning, BellFilled,
  Tickets, Collection, Timer, Wallet
} from '@element-plus/icons-vue'

const loading        = ref(false)
const stats          = ref({ workers: { total: 0, active: 0 }, patients: { total: 0, active: 0 }, payrolls_60d: { count: 0, total_paid: 0 } })
const quick          = ref({ hours_14d: 0, neg_count: 0 })
const recent         = ref({ payrolls: [] })
const lastRefreshed  = ref('')
const q              = ref({ search: '' })

onMounted(() => {
  refreshAll()
  initCharts()
  window.addEventListener('resize', onResizeCharts)
})



const chartsLoading = ref(false)
const chartsData = ref(null)

// chart DOM refs
const elLast10 = ref(null)
const elRoles = ref(null)
const elTopWorkers = ref(null)
const elPending = ref(null)

// echarts runtime + instances
let echarts = null
const chartInstances = []
function disposeAllCharts() {
  chartInstances.forEach(i => { try { i.dispose() } catch(e) {} })
  chartInstances.length = 0
}
function mountChart(dom, option) {
  if (!dom || !echarts) return null
  const inst = echarts.init(dom)
  inst.setOption(option)
  chartInstances.push(inst)
  return inst
}

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResizeCharts)
  disposeAllCharts()
})
function fmtMoney(v){ return Number(v||0).toLocaleString(undefined,{style:'currency',currency:'USD',maximumFractionDigits:2}) }

// === ADD: dynamic loader (keeps your bundle lean) ===
async function loadEchartsIfNeeded() {
  if (window.echarts) { echarts = window.echarts; return }
  await new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src = 'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js'
    s.onload = resolve
    s.onerror = () => reject(new Error('Failed to load chart library'))
    document.head.appendChild(s)
  })
  echarts = window.echarts
}

// === ADD: fetch metrics from WP AJAX (does not touch your existing APIs) ===
async function fetchDashboardCharts() {
  chartsLoading.value = true
  try {
    const params = new URLSearchParams({
      action: 'mhc_dashboard_metrics',
      nonce: parameters.nonce
    })
    const res = await fetch(parameters.ajax_url, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: params.toString()
    })
    const json = await res.json()
    if (!json?.success) throw new Error(json?.data?.message || 'Error loading dashboard metrics')
    chartsData.value = json.data
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load dashboard metrics')
  } finally {
    chartsLoading.value = false
  }
}

// === ADD: render the four charts ===
function renderDashboardCharts() {
  disposeAllCharts()
  if (!chartsData.value || !echarts) return

  // 1) Last 10 payroll totals
  const last10 = [...(chartsData.value.payroll_totals || [])].reverse()
  const lbl = last10.map(r => `#${r.id}\n${r.start_date} → ${r.end_date}`)
  const totals = last10.map(r => Number(r.hours_total||0) + Number(r.extras_total||0))
  mountChart(elLast10.value, {
    tooltip: { trigger: 'axis', valueFormatter: v => fmtMoney(v) },
    grid: { left: 60, right: 20, top: 30, bottom: 60 },
    xAxis: { type: 'category', data: lbl, axisLabel: { interval: 0, rotate: 30 }},
    yAxis: { type: 'value', name: 'Total ($)', axisLabel: { formatter: v => fmtMoney(v) }},
    series: [{ type: 'bar', name: 'Payroll Total', data: totals }]
  })

  // 2) Stacked by role
  const roleRows = chartsData.value.role_totals || []
  const roleCodes = Array.from(new Set(roleRows.map(r => r.role_code))).sort()
  const idOrder = last10.map(r => r.id)
  const roleSeries = roleCodes.map(code => ({
    name: (code||'').toUpperCase(),
    type: 'bar',
    stack: 'roles',
    data: idOrder.map(pid => {
      const row = roleRows.find(x => String(x.payroll_id)===String(pid) && x.role_code===code)
      return row ? Number(row.role_total||0) : 0
    })
  }))
  mountChart(elRoles.value, {
    legend: {},
    tooltip: { trigger: 'axis', valueFormatter: v => fmtMoney(v) },
    grid: { left: 60, right: 20, top: 40, bottom: 60 },
    xAxis: { type: 'category', data: lbl, axisLabel: { interval: 0, rotate: 30 }},
    yAxis: { type: 'value', name: 'Total ($)', axisLabel: { formatter: v => fmtMoney(v) }},
    series: roleSeries
  })

  // 3) Top 5 workers (latest payroll)
  const tops = chartsData.value.top_workers || []
  mountChart(elTopWorkers.value, {
    tooltip: { trigger: 'axis' },
    grid: { left: 140, right: 20, top: 20, bottom: 20 },
    xAxis: { type: 'value', name: 'Hours' },
    yAxis: { type: 'category', data: tops.map(r => r.worker_name || 'Worker') },
    series: [{ type: 'bar', name: 'Hours', data: tops.map(r => Number(r.hours||0)) }]
  })

  // 4) Pending adjustments
  const pend = [...(chartsData.value.pending_adjust || [])].reverse()
  mountChart(elPending.value, {
    legend: {},
    tooltip: { trigger: 'axis', valueFormatter: v => fmtMoney(v) },
    grid: { left: 60, right: 20, top: 40, bottom: 40 },
    xAxis: { type: 'category', data: pend.map(r => `#${r.payroll_id}`) },
    yAxis: { type: 'value', name: 'Adj ($)', axisLabel: { formatter: v => fmtMoney(v) }},
    series: [
      { type: 'bar', name: 'Positive', data: pend.map(r => Number(r.pos_adjust||0)) },
      { type: 'bar', name: 'Negative', data: pend.map(r => Number(r.neg_adjust||0)) }
    ]
  })
}

// === ADD: lifecycle (hook into your existing onMounted if you already have one) ===
async function initCharts() {
  await loadEchartsIfNeeded()
  await fetchDashboardCharts()
  renderDashboardCharts()
}
function onResizeCharts(){ chartInstances.forEach(i => i && i.resize()) }

async function refreshAll() {
  loading.value = true
  try {
    const form = new URLSearchParams()
    form.append('action', 'mhc_dashboard_get')
    form.append('nonce', parameters.nonce)

    const res = await fetch(parameters.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form.toString()
    })
    const json = await res.json()
    if (!json?.success) throw new Error(json?.data?.message || 'Failed')

    const payload = json.data || {}
    stats.value   = payload.stats   || stats.value
    quick.value   = payload.quick   || quick.value
    recent.value  = payload.recent  || recent.value

    lastRefreshed.value = payload.generated_at
        ? new Date(payload.generated_at.replace(' ', 'T') + 'Z').toLocaleString()
        : new Date().toLocaleString()
  } catch (e) {
    ElMessage.error(e.message || 'Refresh failed')
  } finally {
    loading.value = false
  }
}

function statusType(s) {
  if (!s) return ''
  const ss = s.toLowerCase()
  if (ss.includes('draft')) return 'info'
  if (ss.includes('final')) return 'success'
  if (ss.includes('pending') || ss.includes('review')) return 'warning'
  return ''
}

function formatMoney(v) {
  const n = Number(v || 0)
  return n.toLocaleString(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 2 })
}

function handleGlobalSearch() {
  if (!q.value.search) return
  // Simple heuristic: numbers -> patients, text -> workers (tweak as you like)
  const term = q.value.search.trim()
  if (/^\d+$/.test(term)) {
    // if you implement query param handling in Patients.vue
    // $router.push({ path: '/patients', query: { q: term } })
    $router.push('/patients')
  } else {
    // $router.push({ path: '/workers', query: { q: term } })
    $router.push('/workers')
  }
}

function openNewPayroll() {
  // If you have a route or modal trigger, hook it here.
  // Example route:
  // $router.push('/payrolls?new=1')
  ElMessage.info('Hook the "New Payroll" modal/route here.')
}

function goWorkers() { $router.push('/workers') }
function goPayrolls() { $router.push('/payrolls') }
</script>

<style scoped>
.wp-wrap { padding: 8px; }

/* Stat cards */
.stat-card {
  display: flex; gap: 12px; align-items: center; min-height: 96px;
}
.stat-icon {
  display: grid; place-items: center;
  width: 56px; height: 56px; border-radius: 16px;
}
.stat-icon.warn { background: #fff7f5; }
.stat-body { display: grid; gap: 4px; }
.stat-label { font-size: 12px; color: #7c8a9a; text-transform: uppercase; letter-spacing: .04em; }
.stat-value { font-size: 28px; font-weight: 700; line-height: 1; }
.stat-value.sm { font-size: 16px; font-weight: 600; color: #3a3a3a; }

/* Card header */
.card-header {
  display: flex; align-items: center; justify-content: space-between;
}
.card-header .title {
  display: inline-flex; align-items: center; font-weight: 600;
}

/* Section titles */
.section-title {
  font-weight: 600; margin-bottom: 8px;
}

/* light utilities (if Tailwind not present at build) */
.flex { display: flex; }
h2{ color: var(--el-text-color-primary); }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-2 { gap: .5rem; }
.mb-4 { margin-bottom: 1rem; }
.text-xs { font-size: 12px; }
.text-gray-500 { color: #6b7280; }
.mr-2 { margin-right: .5rem; }
.text-red-600 { color: #dc2626; }
.card-title{font-weight:600;font-size:14px;margin-bottom:8px;}
.mhc-chart{width:100%;height:340px;}
.mhc-chart--short{height:300px;}
.mb-4{margin-bottom:16px;}
</style>
