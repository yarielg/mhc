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
      <el-col :xs="24" :sm="12" :md="6">
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

      <el-col :xs="24" :sm="12" :md="6">
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

      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-icon">
            <el-icon><Collection /></el-icon>
          </div>
          <div class="stat-body">
            <div class="stat-label">Assignments</div>
            <div class="stat-value">
              <el-skeleton v-if="loading" :rows="1" animated />
              <template v-else>{{ stats?.assignments ?? 0 }}</template>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12" :md="6">
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
      <el-col :xs="24" :sm="12" :md="8">
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

      <el-col :xs="24" :sm="12" :md="8">
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
          <div class="stat-icon warn">
            <el-icon><Warning /></el-icon>
          </div>
          <div class="stat-body">
            <div class="stat-label">Negative Adjustments (14d/latest)</div>
            <div class="stat-value">
              <el-skeleton v-if="loading" :rows="1" animated />
              <template v-else>{{ quick?.neg_count ?? 0 }}</template>
            </div>
            <div class="text-xs text-gray-500">Investigate below in Alerts</div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16" class="mb-4">
      <!-- Alerts -->
      <el-col :xs="24" :md="12">
        <el-card shadow="never">
          <template #header>
            <div class="card-header">
              <div class="title">
                <el-icon class="mr-2"><BellFilled /></el-icon>
                Alerts
              </div>
            </div>
          </template>

          <el-empty v-if="!hasAlerts" description="No alerts right now. Great job!" />

          <template v-else>
            <div class="mb-4" v-if="(alerts?.over30h?.length || 0) > 0">
              <div class="section-title">Over 30 hours (same patient)</div>
              <el-table
                  :data="alerts?.over30h || []"
                  size="small"
                  border
                  style="width: 100%"
                  empty-text="No cases"
              >
                <el-table-column prop="worker" label="Worker" min-width="160" />
                <el-table-column prop="patient" label="Patient" min-width="160" />
                <el-table-column prop="hours" label="Hours" width="90" />
                <el-table-column label="Actions" width="130">
                  <template #default="{ row }">
                    <el-button text size="small" @click="goWorkers">Open Worker</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </div>

            <div v-if="(alerts?.negAdjustments?.length || 0) > 0">
              <div class="section-title">Negative Adjustments</div>
              <el-table
                  :data="alerts?.negAdjustments || []"
                  size="small"
                  border
                  style="width: 100%"
                  empty-text="No negative lines"
              >
                <el-table-column prop="id" label="Item ID" width="90" />
                <el-table-column prop="worker_id" label="Worker" width="100" />
                <el-table-column prop="patient_id" label="Patient" width="100" />
                <el-table-column prop="amount" label="Amount" width="110">
                  <template #default="{ row }">
                    <span class="text-red-600">{{ formatMoney(row.amount) }}</span>
                  </template>
                </el-table-column>
                <el-table-column prop="note" label="Note" />
                <el-table-column label="Actions" width="130">
                  <template #default>
                    <el-button text size="small" @click="goPayrolls">Open Payrolls</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </div>
          </template>
        </el-card>
      </el-col>

      <!-- Recent payrolls -->
      <el-col :xs="24" :md="12">
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

    <!-- Activity placeholder (kept for future charts) -->
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <div class="title">Activity</div>
          <el-tag size="small" effect="plain">coming soon</el-tag>
        </div>
      </template>
      <el-empty description="Charts and trends will live here (weekly hours, top workers, etc.)." />
    </el-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import {
  UserFilled, User, Calendar, Plus, Search, Money, Warning, BellFilled,
  Tickets, Collection, Timer, Wallet
} from '@element-plus/icons-vue'

const loading        = ref(false)
const stats          = ref({ workers: { total: 0, active: 0 }, patients: { total: 0, active: 0 }, payrolls_60d: { count: 0, total_paid: 0 } })
const alerts         = ref({ over30h: [], negAdjustments: [] })
const quick          = ref({ hours_14d: 0, neg_count: 0 })
const recent         = ref({ payrolls: [] })
const lastRefreshed  = ref('')
const q              = ref({ search: '' })

onMounted(() => {
  refreshAll()
})

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
    alerts.value  = payload.alerts  || alerts.value
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

const hasAlerts = computed(() =>
    (alerts.value?.over30h?.length || 0) > 0 ||
    (alerts.value?.negAdjustments?.length || 0) > 0
)

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
  background: #f5f7fa;
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
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-2 { gap: .5rem; }
.mb-4 { margin-bottom: 1rem; }
.text-xs { font-size: 12px; }
.text-gray-500 { color: #6b7280; }
.mr-2 { margin-right: .5rem; }
.text-red-600 { color: #dc2626; }
</style>
