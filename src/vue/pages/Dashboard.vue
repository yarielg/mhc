<template>
  <div class="wp-wrap">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Dashboard</h2>
      <div class="flex gap-2">
        <el-button @click="$router.push('/workers')">Workers</el-button>
        <el-button @click="$router.push('/patients')">Patients</el-button>
      </div>
    </div>

    <!-- Top stats -->
    <el-row :gutter="16" class="mb-4">
      <el-col :xs="24" :sm="12" :md="8">
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

      <el-col :xs="24" :sm="12" :md="8">
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


    <!-- Activity placeholder -->
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <div class="title">Activity</div>
          <el-tag size="small" effect="plain">coming soon</el-tag>
        </div>
      </template>
      <el-empty description="No recent activity yet. Create your first payroll to see updates here." />
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { UserFilled, User, Calendar, Plus } from '@element-plus/icons-vue'

const loading        = ref(false)
const stats          = ref({ workers: { total: 0, active: 0 }, patients: { total: 0, active: 0 } })
const lastRefreshed  = ref('')
const createHint   = ref('')

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
    stats.value = payload.stats || stats.value
    lastRefreshed.value = payload.generated_at
        ? new Date(payload.generated_at.replace(' ', 'T') + 'Z').toLocaleString()
        : new Date().toLocaleString()
  } catch (e) {
    ElMessage.error(e.message || 'Refresh failed')
  } finally {
    loading.value = false
  }
}

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

/* light utilities (if Tailwind not present at build) */
.flex { display: flex; }
.flex-col { flex-direction: column; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-2 { gap: .5rem; }
.gap-3 { gap: .75rem; }
.grid { display: grid; }
.grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.mb-4 { margin-bottom: 1rem; }
.mb-6 { margin-bottom: 1.5rem; }
.mr-2 { margin-right: .5rem; }
.text-xs { font-size: 12px; }
.text-gray-500 { color: #6b7280; }
</style>
