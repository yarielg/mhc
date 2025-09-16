<template>
  <div class="wp-wrap">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Payrolls</h2>

      <div class="flex gap-2">
        <el-input
            v-model="q.search"
            placeholder="Search notes…"
            size="small"
            clearable
            @clear="fetchList()"
            @keyup.enter.native="fetchList()"
            style="width: 220px"
        />
        <el-select
            v-model="q.status"
            placeholder="All statuses"
            clearable
            size="small"
            style="width: 160px"
            @change="fetchList()"
        >
          <el-option label="Draft" value="draft" />
          <el-option label="Finalized" value="finalized" />
        </el-select>

        <el-button type="primary" size="small" @click="openNewModal">
          New payroll
        </el-button>
      </div>
    </div>

    <el-card shadow="never">
      <el-table
          :data="items"
          v-loading="loading.list"
          border
          size="small"
          empty-text="No payrolls yet"
          style="width: 100%"
      >
        <el-table-column prop="id" label="ID" width="80" sortable />

        <el-table-column label="Period" min-width="220">
          <template #default="{ row }">
            <div class="flex flex-col">
              <span class="font-medium">
                {{ formatWeekRange(row.start_date, row.end_date) }}
              </span>
              <small class="text-gray-500 ml-1">Created: {{ fmtDateTime(row.created_at) }}</small>
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="notes" label="Notes" min-width="260" show-overflow-tooltip />

        <el-table-column label="Status" width="130">
          <template #default="{ row }">
            <el-tag :type="row.status === 'finalized' ? 'success' : 'info'" effect="light">
              {{ row.status }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="Actions" width="360" fixed="right">
          <template #default="{ row }">
            <!-- Placeholder for navigation if you wire a details route later -->
            <el-button size="small" @click="goTo(row)">Open</el-button>

            <el-button
                v-if="row.status !== 'finalized'"
                type="success"
                plain
                size="small"
                :loading="loading.actionId === row.id && loading.actionType === 'finalize'"
                @click="finalize(row)"
            >
              Finalize
            </el-button>

            <el-button
                v-else
                type="warning"
                plain
                size="small"
                :loading="loading.actionId === row.id && loading.actionType === 'reopen'"
                @click="reopen(row)"
            >
              Reopen
            </el-button>

            <!-- Delete payroll removed -->
          </template>
        </el-table-column>
      </el-table>

      <div class="flex items-center justify-between mt-3">
        <div class="text-xs text-gray-500">
          Showing {{ items.length }} item(s)
        </div>
        <el-pagination
            background
            layout="prev, pager, next"
            :current-page="q.page"
            :page-size="q.limit"
            :total="total"
            @current-change="onPage"
        />
      </div>
    </el-card>

    <!-- New payroll modal -->
    <el-dialog
        v-model="modals.new.visible"
        title="New payroll"
        width="520px"
        destroy-on-close
    >
      <el-form
          ref="newFormRef"
          :model="modals.new.form"
          :rules="newRules"
          label-width="110px"
      >
        <el-form-item label="Start date" prop="start_date">
          <el-date-picker
              v-model="modals.new.form.start_date"
              type="date"
              placeholder="YYYY-MM-DD"
              value-format="YYYY-MM-DD"
              format="YYYY-MM-DD"
              style="width: 100%"
          />
        </el-form-item>

        <el-form-item label="End date" prop="end_date">
          <el-date-picker
              v-model="modals.new.form.end_date"
              type="date"
              placeholder="YYYY-MM-DD"
              value-format="YYYY-MM-DD"
              format="YYYY-MM-DD"
              style="width: 100%"
          />
        </el-form-item>

        <el-form-item label="Notes" prop="notes">
          <el-input
              v-model="modals.new.form.notes"
              maxlength="255"
              show-word-limit
              type="textarea"
              :rows="3"
              placeholder="Optional note…"
          />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="modals.new.visible = false">Cancel</el-button>
        <el-button
            type="primary"
            :loading="loading.create"
            @click="submitNew"
        >
          Create
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { formatWeekRange } from '../util/dateUtils'

import { useRouter } from 'vue-router' // ✅ import

const router = useRouter() // ✅ create instance

/** ===== Ajax helpers (expects window.mhc.ajaxUrl & window.mhc.noncePayroll) ===== */
const AJAX_URL = parameters.ajax_url;
const NONCE    = parameters.nonce;

async function ajaxGet(action, params = {}) {
  const url = new URL(AJAX_URL, window.location.origin)
  url.searchParams.set('action', action)
  if (NONCE) url.searchParams.set('_wpnonce', NONCE)
  Object.entries(params).forEach(([k, v]) => v !== undefined && url.searchParams.set(k, v))

  const res = await fetch(url.toString(), { credentials: 'same-origin' })
  const json = await res.json()
  if (!json?.success) throw new Error(json?.data?.message || 'Request failed')
  return json.data
}

async function ajaxPost(action, body = null, asJson = true) {
  const url = new URL(AJAX_URL, window.location.origin)
  url.searchParams.set('action', action)
  if (NONCE) url.searchParams.set('_wpnonce', NONCE)

  const opts = {
    method: 'POST',
    credentials: 'same-origin',
    headers: {},
    body: null
  }
  if (asJson) {
    opts.headers['Content-Type'] = 'application/json'
    opts.body = JSON.stringify(body || {})
  } else {
    const form = new FormData()
    Object.entries(body || {}).forEach(([k, v]) => form.append(k, v))
    opts.body = form
  }

  const res = await fetch(url.toString(), opts)
  const json = await res.json()
  if (!json?.success) throw new Error(json?.data?.message || 'Request failed')
  return json.data
}

/** ===== State ===== */
const loading = reactive({
  list: false,
  create: false,
  actionId: null,
  actionType: null,
})

const q = reactive({
  page: 1,
  limit: 10,
  status: '',
  search: '',
  orderby: 'id',
  order: 'DESC',
})

const total = ref(0)
const items = ref([])

/** ===== New payroll modal ===== */
const modals = reactive({
  new: {
    visible: false,
    form: {
      start_date: '',
      end_date: '',
      notes: '',
    },
  },
})
const newFormRef = ref(null)

const newRules = {
  start_date: [{ required: true, message: 'Start date is required', trigger: 'change' }],
  end_date: [
    { required: true, message: 'End date is required', trigger: 'change' },
    {
      validator: (_rule, value, cb) => {
        const s = modals.new.form.start_date
        if (s && value && s > value) cb(new Error('End date must be after start date'))
        else cb()
      },
      trigger: 'change',
    },
  ],
  notes: [{ min: 0, max: 255, message: 'Max 255 characters', trigger: 'blur' }],
}

/** ===== Methods ===== */
function fmtDate(d) {
  if (!d) return '—'
  // Expecting YYYY-MM-DD or full datetime
  return d.length >= 10 ? d.substring(0, 10) : d
}

function fmtDateTime(dt) {
  if (!dt) return '—'
  // MySQL DATETIME → YYYY-MM-DD HH:mm
  return dt.replace(/:\d{2}$/, '')
}

function openNewModal() {
  modals.new.form.start_date = ''
  modals.new.form.end_date = ''
  modals.new.form.notes = ''
  modals.new.visible = true
}

async function submitNew() {
  try {
    await newFormRef.value.validate()
  } catch {
    return
  }
  loading.create = true
  try {
    const payload = {
      start_date: modals.new.form.start_date,
      end_date: modals.new.form.end_date,
      notes: modals.new.form.notes?.trim() || '',
    }
    // Overlap check before creating
    let overlap = false
    try {
      const overlapRes = await ajaxPost('mhc_payroll_check_overlap', {
        start_date: payload.start_date,
        end_date: payload.end_date,
      })
      overlap = overlapRes.overlap
    } catch (e) {
      // If endpoint fails, ignore and continue
      overlap = false
    }
    if (overlap) {
      try {
        await ElMessageBox.confirm(
          'Warning: The selected period overlaps with an existing payroll. Do you want to continue and create this payroll?',
          'Overlap Warning',
          {
            confirmButtonText: 'Accept',
            cancelButtonText: 'Cancel',
            type: 'warning',
          }
        )
      } catch (e) {
        // User cancelled
        loading.create = false
        return
      }
    }
    const data = await ajaxPost('mhc_payroll_create', payload)
    const now = new Date()
    const createdRow = {
      id: data.id,
      start_date: payload.start_date,
      end_date: payload.end_date,
      status: 'draft',
      notes: payload.notes,
      created_at: now.toISOString().slice(0, 19).replace('T', ' '),
      updated_at: null,
    }
    items.value.unshift(createdRow)
    total.value += 1
    modals.new.visible = false
    ElMessage.success(`Payroll #${data.id} created`)
  } catch (e) {
    ElMessage.error(e.message || 'Failed to create payroll')
  } finally {
    loading.create = false
  }
}

async function fetchList() {
  loading.list = true
  try {
    const params = {
      limit: q.limit,
      offset: (q.page - 1) * q.limit,
      orderby: q.orderby,
      order: q.order,
    }
    if (q.status) params.status = q.status
    if (q.search) params.search = q.search

    const data = await ajaxGet('mhc_payroll_list', params)
    // If backend doesn’t return total, we’ll infer (best effort).
    items.value = Array.isArray(data.items) ? data.items : []
    // Sort client-side as a guard (id DESC)
    items.value.sort((a, b) => Number(b.id) - Number(a.id))
    total.value = Math.max(total.value, items.value.length) // adjust if you add a count API later
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load payrolls')
  } finally {
    loading.list = false
  }
}

function onPage(p) {
  q.page = p
  fetchList()
}

function goTo(row) {
  // Wire up when you add the detail route (e.g. `/payrolls/:id`)
  router.push({ path: `/payrolls/${row.id}` })
  ElMessage.info(`Open payroll #${row.id} (stub)`)
}

async function finalize(row) {
  loading.actionId = row.id
  loading.actionType = 'finalize'
  try {
    await ElMessageBox.confirm(
        `Finalize payroll #${row.id}? You won’t be able to edit hours after finalizing.`,
        'Finalize payroll',
        { type: 'warning' }
    )
    await ajaxPost('mhc_payroll_finalize', { id: row.id }, false) // form-data or $_REQUEST OK
    row.status = 'finalized'
    ElMessage.success(`Payroll #${row.id} finalized`)
  } catch (e) {
    if (e !== 'cancel') ElMessage.error(e.message || 'Failed to finalize')
  } finally {
    loading.actionId = null
    loading.actionType = null
  }
}

async function reopen(row) {
  loading.actionId = row.id
  loading.actionType = 'reopen'
  try {
    await ElMessageBox.confirm(
        `Reopen payroll #${row.id}?`,
        'Reopen payroll',
        { type: 'warning' }
    )
    await ajaxPost('mhc_payroll_reopen', { id: row.id }, false)
    row.status = 'draft'
    ElMessage.success(`Payroll #${row.id} reopened`)
  } catch (e) {
    if (e !== 'cancel') ElMessage.error(e.message || 'Failed to reopen')
  } finally {
    loading.actionId = null
    loading.actionType = null
  }
}

// Delete payroll removed 

onMounted(fetchList)
</script>

<style scoped>
.wp-wrap { padding: 12px; }
.flex { display: flex; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.mb-4 { margin-bottom: 1rem; }
.mt-3 { margin-top: .75rem; }
.ml-1 { margin-left: .25rem; }
.gap-2 { gap: .5rem; }
.text-gray-500 { color: #6b7280; }
.text-xs { font-size: 12px; }
.font-medium { font-weight: 600; }
h2{ color: var(--el-text-color-primary); }
</style>
