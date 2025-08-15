<template>
  <div class="wp-wrap">
    <!-- Breadcrumb -->
    <el-breadcrumb separator="›" class="mb-3">
      <el-breadcrumb-item @click="$router.push('/')" class="cursor">Payrolls</el-breadcrumb-item>
      <el-breadcrumb-item>#{{ id }}</el-breadcrumb-item>
    </el-breadcrumb>

    <!-- Header -->
    <el-card shadow="never" class="mb-3" v-loading="loading.header">
      <template #header>
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold">
              Payroll #{{ id }}
              <el-tag :type="payroll.status === 'finalized' ? 'success' : 'info'" effect="light" class="ml-2">
                {{ payroll.status || '—' }}
              </el-tag>
            </h3>
            <div class="text-gray-600">
              Period: <b>{{ fmtDate(payroll.start_date) }}</b> → <b>{{ fmtDate(payroll.end_date) }}</b>
              <span v-if="payroll.notes"> • {{ payroll.notes }}</span>
            </div>
          </div>

          <div class="flex gap-2">
            <el-button :loading="loading.seed" @click="reseedPatients" :disabled="!id">Re-seed patients</el-button>
            <el-button
                v-if="payroll.status !== 'finalized'"
                type="success"
                plain
                :loading="loading.finalize"
                @click="finalizePayroll"
            >Finalize</el-button>
            <el-button
                v-else
                type="warning"
                plain
                :loading="loading.reopen"
                @click="reopenPayroll"
            >Reopen</el-button>
          </div>
        </div>
      </template>

      <!-- Content layout -->
      <el-row :gutter="16">
        <!-- Left: Patients -->
        <el-col :xs="24" :md="9">
          <el-card shadow="never" class="h-full">
            <template #header>
              <div class="flex items-center justify-between">
                <div class="font-semibold">Patients in payroll</div>
                <el-segmented v-model="patientsFilter" :options="patientFilters" size="small" @change="loadPatients" />
              </div>
            </template>

            <el-table
                :data="patients"
                size="small"
                border
                highlight-current-row
                height="440"
                v-loading="loading.patients"
                @current-change="selectPatient"
            >
              <el-table-column type="index" width="52" label="#" />
              <el-table-column prop="processed" label="Patient Name" width="260">
                <template #default="{ row }">
                  <p>{{ row.first_name}} {{ row.last_name }}</p>
                </template>
              </el-table-column>
              <el-table-column prop="processed" label="Processed" width="110">
                <template #default="{ row }">
                  <el-tag :type="is_processed(row.is_processed) ? 'success' : 'info'">
                    {{ is_processed(row.is_processed) ? 'Yes' : 'No' }}
                  </el-tag>
                </template>
              </el-table-column>
            </el-table>

            <div class="mt-2 text-xs text-gray-600">
              <span v-if="counts">Total: {{ counts.total ?? patients.length }}</span>
              <span v-if="counts && counts.pending !== undefined"> • Pending: {{ counts.pending }}</span>
              <span v-if="counts && counts.processed !== undefined"> • Processed: {{ counts.processed }}</span>
            </div>
          </el-card>
        </el-col>

        <!-- Right: Patient processing + Extras + Summary tabs -->
        <el-col :xs="24" :md="15">
          <el-card shadow="never">
            <el-tabs v-model="tabs.active" @tab-change="onTab">
              <el-tab-pane name="process" label="Process patient">
                <div v-if="!selectedPatient" class="text-gray-600">Select a patient to start processing.</div>
                <template v-else>
                  <div class="flex items-center justify-between mb-2">
                    <div class="font-semibold">
                      {{ selectedPatient.patient_name }}
                      <el-tag class="ml-2" :type="is_processed(selectedPatient.is_processed) ? 'success' : 'info'">
                        {{ is_processed(selectedPatient.is_processed) ? 'Processed' : 'Pending' }}
                      </el-tag>
                    </div>
                    <el-switch
                        :model-value="is_processed(selectedPatient.is_processed)"
                        active-text="Processed"
                        inactive-text="Pending"
                        @change="toggleProcessed"
                    />
                  </div>

                  <!-- Assigned workers for this patient in this payroll -->
                  <el-card shadow="never" class="mb-3">
                    <template #header>
                      <div class="flex items-center justify-between">
                        <div class="font-semibold">Assigned workers</div>
                        <el-button size="small" @click="modals.addWpr.visible = true" :disabled="payroll.status === 'finalized'">
                          Add worker to this patient
                        </el-button>
                      </div>
                    </template>

                    <el-table
                        :data="patientWorkers"
                        size="small"
                        border
                        v-loading="loading.patientWorkers"
                        empty-text="No workers assigned yet"
                    >
                      <el-table-column prop="worker_name" label="Worker" min-width="160" show-overflow-tooltip />
                      <el-table-column prop="role_code" label="Role" width="120" />
                      <el-table-column label="Rate" width="110">
                        <template #default="{ row }">
                          {{ money(row.effective_rate) }}
                        </template>
                      </el-table-column>

                      <!-- NEW: inline hours entry -->
                      <el-table-column label="Hours" width="200">
                        <template #default="{ row }">
                          <el-input-number
                              v-model="wprHours[getWprId(row)]"
                              :min="0"
                              :step="0.25"
                              :precision="2"
                              size="small"
                              :disabled="payroll.status === 'finalized'"
                              @input="onHoursInput(row)"
                          @change="onHoursChange(row)"
                          />
                          <span v-if="wprSaving[getWprId(row)]" class="text-xs text-gray-600 ml-2">Saving…</span>
                          <span v-else-if="wprSavedTick[getWprId(row)]" class="text-xs" style="color:#16a34a">Saved</span>
                        </template>
                      </el-table-column>

                      <!-- Optional but useful -->
                      <el-table-column label="Total $" width="130">
                        <template #default="{ row }">
                          {{ money((Number(wprHours[getWprId(row)] || 0)) * Number(row.effective_rate || 0)) }}
                        </template>
                      </el-table-column>
                    </el-table>

                    <div class="mt-2 text-sm text-gray-600" v-if="hoursTotals">
                      Patient totals — Hours: <b>{{ formatHours(hoursTotals.total_hours || 0) }}</b>
                      • Amount: <b>{{ money(hoursTotals.total_amount || 0) }}</b>
                    </div>
                  </el-card>


                </template>
              </el-tab-pane>

              <el-tab-pane name="extras" label="Extras">
                <div class="mb-2 flex gap-2">
                  <!-- Worker filter (optional) -->
                  <el-select-v2
                      v-model="extrasFilter.worker"
                      placeholder="Filter by worker (optional)"
                      style="width: 320px"
                      filterable
                      remote
                      :remote-method="searchWorkers"
                      :options="workersOptions"
                      clearable
                      @change="loadExtras"
                  />
                  <el-button @click="openAddExtra" type="primary" plain :disabled="payroll.status === 'finalized'">Add extra</el-button>
                </div>

                <el-table
                    :data="extras"
                    size="small"
                    border
                    v-loading="loading.extras"
                    empty-text="No extras"
                >
                  <el-table-column prop="worker_name" label="Worker" min-width="160" show-overflow-tooltip />
                  <el-table-column prop="code" label="Rate code" width="120" />
                  <el-table-column prop="label" label="Rate label" min-width="160" show-overflow-tooltip />
                  <el-table-column prop="amount" label="Amount" width="120">
                    <template #default="{ row }">{{ money(row.amount) }}</template>
                  </el-table-column>
                  <el-table-column prop="patient_name" label="Patient" min-width="140" show-overflow-tooltip />
                  <el-table-column prop="notes" label="Notes" min-width="160" show-overflow-tooltip />

                  <el-table-column label="Actions" width="200" fixed="right">
                    <template #default="{ row }">
                      <el-button size="small" plain :disabled="payroll.status === 'finalized'" @click="editExtra(row)">Edit</el-button>
                      <el-popconfirm
                          title="Delete this extra?"
                          confirm-button-text="Delete"
                          cancel-button-text="Cancel"
                          confirm-button-type="danger"
                          @confirm="deleteExtra(row)"
                      >
                        <template #reference>
                          <el-button size="small" type="danger" plain :disabled="payroll.status === 'finalized'">Delete</el-button>
                        </template>
                      </el-popconfirm>
                    </template>
                  </el-table-column>
                </el-table>
              </el-tab-pane>

              <el-tab-pane name="summary" label="Workers summary">
                <div class="mb-2 text-sm text-gray-600">
                  Totals per worker (hours + extras).
                </div>

                <el-table
                    :data="summary.items"
                    size="small"
                    border
                    v-loading="loading.summary"
                    empty-text="No data"
                >
                  <el-table-column prop="worker_name" label="Worker" min-width="180" show-overflow-tooltip />
                  <el-table-column prop="hours_hours" label="Hours" width="110" />
                  <el-table-column prop="hours_amount" label="Hours $" width="120">
                    <template #default="{ row }">{{ money(row.hours_amount) }}</template>
                  </el-table-column>
                  <el-table-column prop="extras_amount" label="Extras $" width="120">
                    <template #default="{ row }">{{ money(row.extras_amount) }}</template>
                  </el-table-column>
                  <el-table-column prop="grand_total" label="Total $" width="130">
                    <template #default="{ row }"><b>{{ money(row.grand_total) }}</b></template>
                  </el-table-column>
                  <el-table-column label="Slip" width="120" fixed="right">
                    <template #default="{ row }">
                      <el-button size="small" @click="openWorkerSlip(row)">View</el-button>
                    </template>
                  </el-table-column>
                </el-table>

                <div class="mt-2 text-sm">
                  <b>Payroll totals:</b>
                  Hours $ {{ money(summary.totals?.hours_amount || 0) }} •
                  Extras $ {{ money(summary.totals?.extras_amount || 0) }} •
                  Grand $ <b>{{ money(summary.totals?.grand_total || 0) }}</b>
                </div>
              </el-tab-pane>
            </el-tabs>
          </el-card>
        </el-col>
      </el-row>
    </el-card>

    <!-- Add WPR (worker → patient) modal -->
    <el-dialog v-model="modals.addWpr.visible" title="Add worker to this patient (temporary)" width="560px" destroy-on-close>
      <el-form :model="modals.addWpr.form" label-width="140px">
        <el-form-item label="Worker">
          <el-select-v2
              v-model="modals.addWpr.form.worker_id"
              placeholder="Search worker…"
              style="width: 100%"
              filterable
              remote
              :remote-method="searchWorkers"
              :options="workersOptions"
          />
        </el-form-item>
        <el-form-item label="Role">
          <el-select
              v-model="modals.addWpr.form.role_id"
              placeholder="Select role…"
              filterable
              style="width: 100%"
              :loading="loading.roles"
              @visible-change="v => { if(v) loadRoles() }"
          >
            <el-option v-for="r in roles" :key="r.id" :label="`${r.code} — ${r.name}`" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="Override rate (optional)">
          <el-input-number v-model="modals.addWpr.form.rate" :min="0" :step="1" :precision="2" style="width: 100%" />
        </el-form-item>
        <div class="text-xs text-gray-600">
          This assignment only lives in this payroll. (We set the temp end date internally to the payroll’s end date.)
        </div>
      </el-form>
      <template #footer>
        <el-button @click="modals.addWpr.visible=false">Cancel</el-button>
        <el-button type="primary" :loading="loading.addWpr" @click="createWpr">Add</el-button>
      </template>
    </el-dialog>

    <!-- Add/Edit Extra modal -->
    <el-dialog v-model="modals.extra.visible" :title="modals.extra.editing ? 'Edit extra' : 'Add extra'" width="620px" destroy-on-close>
      <el-form :model="modals.extra.form" label-width="160px">
        <el-form-item label="Worker">
          <el-select-v2
              v-model="modals.extra.form.worker_id"
              placeholder="Search worker…"
              style="width: 100%"
              filterable
              remote
              :remote-method="searchWorkers"
              :options="workersOptions"
              :disabled="payroll.status === 'finalized'"
          />
        </el-form-item>

        <el-form-item label="Special rate">
          <el-select-v2
              v-model="modals.extra.form.special_rate_id"
              placeholder="Search special rates…"
              style="width: 100%"
              filterable
              remote
              :remote-method="searchRates"
              :options="ratesOptions"
              :disabled="payroll.status === 'finalized'"
          />
        </el-form-item>

        <el-form-item label="Amount">
          <el-input-number v-model="modals.extra.form.amount" :min="0" :step="1" :precision="2" style="width: 100%" :disabled="payroll.status === 'finalized'" />
        </el-form-item>

        <el-form-item label="Patient (optional)">
          <el-select
              v-model="modals.extra.form.patient_id"
              placeholder="(Optional) link to patient"
              clearable
              style="width: 100%"
              :disabled="payroll.status === 'finalized'"
          >
            <el-option v-for="p in patients" :key="p.patient_id" :label="p.patient_name" :value="p.patient_id" />
          </el-select>
        </el-form-item>

        <el-form-item label="Supervised worker (optional)">
          <el-select-v2
              v-model="modals.extra.form.supervised_worker_id"
              placeholder="Search supervised worker…"
              style="width: 100%"
              filterable
              remote
              clearable
              :remote-method="searchWorkers"
              :options="workersOptions"
              :disabled="payroll.status === 'finalized'"
          />
        </el-form-item>

        <el-form-item label="Notes">
          <el-input v-model="modals.extra.form.notes" type="textarea" :rows="2" maxlength="255" show-word-limit :disabled="payroll.status === 'finalized'" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="modals.extra.visible=false">Cancel</el-button>
        <el-button type="primary" :loading="loading.saveExtra" @click="saveExtra" :disabled="payroll.status === 'finalized'">
          {{ modals.extra.editing ? 'Update' : 'Create' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- Worker slip modal -->
    <el-dialog v-model="modals.slip.visible" :title="`Worker slip — ${modals.slip.header.worker_name || ''}`" width="780px" destroy-on-close>
      <div v-loading="loading.slip">


        <el-divider content-position="left">Hours</el-divider>
        <el-table :data="modals.slip.hours" size="small" border empty-text="No hours">
          <el-table-column prop="patient_name" label="Patient" min-width="160" />
          <el-table-column prop="role_code" label="Role" width="100" />
          <el-table-column prop="hours" label="Hours" width="100" />
          <el-table-column prop="used_rate" label="Rate" width="110">
            <template #default="{ row }">{{ money(row.used_rate) }}</template>
          </el-table-column>
          <el-table-column prop="total" label="Total" width="120">
            <template #default="{ row }">{{ money(row.total) }}</template>
          </el-table-column>
        </el-table>

        <el-divider content-position="left" class="mt-3">Extras</el-divider>
        <el-table :data="modals.slip.extras" size="small" border empty-text="No extras">
          <el-table-column prop="code" label="Code" width="100" />
          <el-table-column prop="label" label="Label" min-width="180" />
          <el-table-column prop="amount" label="Amount" width="120">
            <template #default="{ row }">{{ money(row.amount) }}</template>
          </el-table-column>
          <el-table-column prop="notes" label="Notes" min-width="180" show-overflow-tooltip />
        </el-table>
      </div>
      <template #footer>
        <el-button @click="modals.slip.visible=false">Close</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'

/* ======= WP ajax setup ======= */
const props = defineProps({ id: { type: Number, required: true } })
const AJAX_URL = parameters.ajax_url || '/wp-admin/admin-ajax.php'
const NONCE    = parameters.nonce || '' // your controller uses NONCE_ACTION 'mhc_ajax'

const wprHours = reactive({})          // { [wprId]: number }
const hoursEntryId = reactive({})      // { [wprId]: hours_entry.id } (if exists)

// Per-row UX flags
const wprSaving = reactive({})         // { [wprId]: true|false }
const wprSavedTick = reactive({})      // { [wprId]: timestamp }
const debouncers = {}

function getWprId(row) {
  return row.worker_patient_role_id || row.id || row.wpr_id
}

async function ajaxGet(action, params = {}) {
  const url = new URL(AJAX_URL, window.location.origin)
  url.searchParams.set('action', action)
  if (NONCE) url.searchParams.set('nonce', NONCE)
  Object.entries(params).forEach(([k, v]) => v !== undefined && url.searchParams.set(k, v))
  const res = await fetch(url.toString(), { credentials: 'same-origin' })
  const json = await res.json()
  if (!json?.success) throw new Error(json?.data?.message || 'Request failed')
  return json.data
}
async function ajaxPostForm(action, body = {}) {
  const url = new URL(AJAX_URL, window.location.origin)
  url.searchParams.set('action', action)
  if (NONCE) url.searchParams.set('nonce', NONCE)
  const form = new FormData()
  Object.entries(body).forEach(([k, v]) => v !== undefined && form.append(k, v))
  const res = await fetch(url.toString(), { method: 'POST', body: form, credentials: 'same-origin' })
  const json = await res.json()
  if (!json?.success) throw new Error(json?.data?.message || 'Request failed')
  return json.data
}
async function ajaxPostJson(action, payload = {}) {
  const url = new URL(AJAX_URL, window.location.origin)
  url.searchParams.set('action', action)
  if (NONCE) url.searchParams.set('nonce', NONCE)
  const res = await fetch(url.toString(), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  const json = await res.json()
  if (!json?.success) throw new Error(json?.data?.message || 'Request failed')
  return json.data
}

/* ======= State ======= */
const id = props.id
const payroll = reactive({ id, start_date: '', end_date: '', status: '', notes: '' })

const loading = reactive({
  header: false,
  finalize: false,
  reopen: false,
  seed: false,

  patients: false,
  patientWorkers: false,
  hours: false,
  savingId: null,

  extras: false,
  saveExtra: false,

  roles: false,
  addWpr: false,

  summary: false,
  slip: false,
})

/* Patients list and filter */
const patients = ref([])
const counts = ref(null)
const patientsFilter = ref('all')
const patientFilters = computed(() => ([
  { label: `All${badge(counts.value?.total)}`, value: 'all' },
  { label: `Pending${badge(counts.value?.pending)}`, value: '0' },
  { label: `Processed${badge(counts.value?.processed)}`, value: '1' },
]))
function badge(n) { return typeof n === 'number' ? ` (${n})` : '' }

/* Selected patient and its data */
const selectedPatient = ref(null)
const patientWorkers = ref([])
const hours = ref([])
const hoursTotals = ref(null)

/* Tabs */
const tabs = reactive({ active: 'process' })

/* Extras */
const extras = ref([])
const extrasFilter = reactive({ worker: null })

/* Summary */
const summary = reactive({ items: [], totals: null })

/* Modal state */
const modals = reactive({
  addWpr: {
    visible: false,
    form: { worker_id: null, role_id: null, rate: null },
  },
  extra: {
    visible: false,
    editing: false,
    form: { id: null, worker_id: null, special_rate_id: null, amount: null, patient_id: null, supervised_worker_id: null, notes: '' },
  },
  slip: {
    visible: false,
    hours: [],
    extras: [],
    totals: null,
    header: {},
  }
})

/* Lookups for remote selects (workers, rates, roles) */
const workersOptions = ref([])  // [{value:id, label:name}]
const ratesOptions = ref([])
const roles = ref([])

/* ======= Utils ======= */
function fmtDate(d) { return d ? d.toString().slice(0, 10) : '—' }
function money(n) { const v = Number(n || 0); return v.toLocaleString(undefined, { style: 'currency', currency: 'USD' }) }

/* ======= Loaders ======= */
async function loadHeader() {
  loading.header = true
  try {
    const data = await ajaxGet('mhc_payroll_get', { id })
    Object.assign(payroll, data || {})
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load payroll')
  } finally {
    loading.header = false
  }
}

async function loadPatients() {
  selectedPatient.value = null
  patientWorkers.value = []
  hours.value = []
  hoursTotals.value = null

  loading.patients = true
  try {
    const data = await ajaxGet('mhc_payroll_patients', { payroll_id: id, is_processed: patientsFilter.value })
    patients.value = data?.patients || []
    counts.value   = data?.counts || null
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load patients')
  } finally {
    loading.patients = false
  }
}

async function selectPatient(row) {
  selectedPatient.value = row || null
  tabs.active = 'process'

  if (!row) return
  await loadPatientWorkers()
  await loadHours()               // this will fill wprHours + hoursEntryId + hoursTotals
  ensureDefaultHoursForAllWpr()   // set 0 for workers without rows yet
}

function ensureDefaultHoursForAllWpr() {
  // For any assigned worker lacking an hours row, default to 0 for editing
  for (const w of patientWorkers.value || []) {
    const wprId = getWprId(w)
    if (wprId && wprHours[wprId] === undefined) wprHours[wprId] = 0
  }
}

async function loadPatientWorkers() {
  if (!selectedPatient.value) return
  loading.patientWorkers = true
  try {
    const res = await ajaxGet('mhc_payroll_patient_workers', {
      payroll_id: id, patient_id: selectedPatient.value.patient_id
    })
    patientWorkers.value = res?.items || []
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load assigned workers')
  } finally {
    loading.patientWorkers = false
  }
}

function onHoursInput(row) {
  // Debounce saves while typing
  const wprId = getWprId(row)
  if (!wprId) return
  if (debouncers[wprId]) clearTimeout(debouncers[wprId])
  debouncers[wprId] = setTimeout(() => saveHoursForRow(row), 600)
}

function onHoursChange(row) {
  // Safety net for browsers that don't fire 'input' the same
  saveHoursForRow(row)
}

async function saveHoursForRow(row) {
  if (payroll.status === 'finalized' || !selectedPatient.value) return
  const wprId = getWprId(row)
  if (!wprId) return
  const newHours = Number(wprHours[wprId] || 0)

  // Prevent duplicate saves if value hasn't changed from last known server state
  // (Optional: you can keep a separate lastSaved map if you want)
  try {
    wprSaving[wprId] = true

    // If hours becomes 0 and an entry exists, we can DELETE to keep DB clean;
    // otherwise, use upsert for everything (backend is idempotent).
    if (newHours === 0 && hoursEntryId[wprId]) {
      const res = await ajaxPostForm('mhc_payroll_hours_delete', { id: hoursEntryId[wprId] })
      // Refresh local maps from API response
      hydrateFromHoursResponse(res)
      delete hoursEntryId[wprId]
    } else {
      const res = await ajaxPostJson('mhc_payroll_hours_upsert', {
        payroll_id: id,
        worker_patient_role_id: wprId,
        hours: newHours
        // used_rate omitted → backend resolves from WPR+payroll
      })
      hydrateFromHoursResponse(res)
    }

    // little "Saved" tick
    wprSavedTick[wprId] = Date.now()
    setTimeout(() => { delete wprSavedTick[wprId] }, 1500)
  } catch (e) {
    ElMessage.error(e.message || 'Save failed')
  } finally {
    wprSaving[wprId] = false
  }
}

function hydrateFromHoursResponse(res) {
  // res.items is the patient’s hours list; res.totals the patient totals
  const list = res?.items || []
  hoursTotals.value = res?.totals || hoursTotals.value

  // rebuild maps
  const seen = new Set()
  for (const r of list) {
    const wprId = Number(r.worker_patient_role_id)
    wprHours[wprId] = Number(r.hours || 0)
    hoursEntryId[wprId] = Number(r.id || 0) || undefined
    seen.add(wprId)
  }
  // keep zero defaults for other assigned workers so inputs don’t go blank
  for (const w of patientWorkers.value || []) {
    const wprId = getWprId(w)
    if (!seen.has(wprId) && wprHours[wprId] === undefined) wprHours[wprId] = 0
  }
}


function is_processed(processed){
  return processed  == 1;
}

async function loadHours() {
  if (!selectedPatient.value) return
  loading.hours = true
  try {
    const res = await ajaxGet('mhc_payroll_hours_list', {
      payroll_id: id, patient_id: selectedPatient.value.patient_id
    })
    hoursTotals.value = res?.totals || { total_hours: 0, total_amount: 0 }
    // reset maps
    Object.keys(wprHours).forEach(k => delete wprHours[k])
    Object.keys(hoursEntryId).forEach(k => delete hoursEntryId[k])

    // map by WPR
    ;(res?.items || []).forEach(r => {
      const wprId = Number(r.worker_patient_role_id)
      wprHours[wprId] = Number(r.hours || 0)
      hoursEntryId[wprId] = Number(r.id || 0) || undefined
    })
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load hours')
  } finally {
    loading.hours = false
  }
}

function formatHours(v) {
  const n = Number.parseFloat(v);
  return Number.isFinite(n) ? n.toFixed(2) : '0.00';
}


async function reseedPatients() {
  loading.seed = true
  try {
    const res = await ajaxPostForm('mhc_payroll_seed_patients', { payroll_id: id })
    patients.value = res?.patients || []
    counts.value = res?.counts || null
    ElMessage.success(`Added ${res?.added || 0} new patient(s)`)
  } catch (e) {
    ElMessage.error(e.message || 'Re-seed failed')
  } finally {
    loading.seed = false
  }
}

/* ======= Hours actions ======= */
async function saveHours(row) {
  if (!selectedPatient.value) return
  loading.savingId = row.worker_patient_role_id
  try {
    const payload = {
      payroll_id: id,
      worker_patient_role_id: row.worker_patient_role_id,
      hours: Number(row._edit_hours || 0),
      // used_rate: undefined → backend resolves if not provided
    }
    const res = await ajaxPostJson('mhc_payroll_hours_upsert', payload)
    // API returns updated items & totals for this patient
    const list = res?.items || []
    list.forEach(r => { r._edit_hours = r.hours })
    hours.value = list
    hoursTotals.value = res?.totals || hoursTotals.value
    ElMessage.success('Saved')
  } catch (e) {
    ElMessage.error(e.message || 'Save failed')
  } finally {
    loading.savingId = null
  }
}
async function deleteHours(row) {
  if (!row?.id) return
  loading.hours = true
  try {
    const res = await ajaxPostForm('mhc_payroll_hours_delete', { id: row.id })
    const list = res?.items || []
    list.forEach(r => { r._edit_hours = r.hours })
    hours.value = list
    hoursTotals.value = res?.totals || hoursTotals.value
    ElMessage.success('Deleted')
  } catch (e) {
    ElMessage.error(e.message || 'Delete failed')
  } finally {
    loading.hours = false
  }
}

/* ======= Patient processed toggle ======= */
async function toggleProcessed(val) {
  if (!selectedPatient.value) return
  try {
    await ajaxPostForm('mhc_patient_payroll_set_processed', {
      payroll_id: id,
      patient_id: selectedPatient.value.patient_id,
      is_processed: val ? 1 : 0,
    })


    selectedPatient.value.is_processed = val ? 1 : 0
    const selected_id = selectedPatient.value.patient_id;
    // refresh counts quietly
    await loadPatients()


    // reselect same patient row if still present
    const again = patients.value.find(p => p.patient_id === selected_id)

    if (again) selectedPatient.value = again
    await loadPatientWorkers()
  } catch (e) {
    ElMessage.error(e.message || 'Update failed')
  }
}

/* ======= Add worker to patient (temporary WPR) ======= */
async function loadRoles() {
  if (roles.value.length) return
  loading.roles = true
  try {
    // Adjust action name if your roles list differs.
    const res = await ajaxPostForm('mhc_roles_list', { limit: 100, page: 1 }) // expects { items: [...] }
    roles.value = res?.items || []
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load roles')
  } finally {
    loading.roles = false
  }
}
async function createWpr() {
  if (!selectedPatient.value) return
  const f = modals.addWpr.form
  if (!f.worker_id || !f.role_id) {
    ElMessage.warning('Worker and role are required')
    return
  }
  loading.addWpr = true
  try {
    const payload = {
      payroll_id: id,
      patient_id: selectedPatient.value.patient_id,
      worker_id: Number(f.worker_id),
      role_id: Number(f.role_id),
      rate: f.rate !== null ? Number(f.rate) : undefined,
    }
    const res = await ajaxPostJson('mhc_payroll_patient_workers_add', payload)
    patientWorkers.value = res?.items || []
    // After adding, refresh hours list so a new editable row appears
    await loadHours()
    modals.addWpr.visible = false
    modals.addWpr.form = { worker_id: null, role_id: null, rate: null }
    ElMessage.success('Worker added for this payroll')
  } catch (e) {
    ElMessage.error(e.message || 'Could not add worker')
  } finally {
    loading.addWpr = false
  }
}

/* ======= Extras ======= */
async function loadExtras() {
  loading.extras = true
  try {
    const res = await ajaxPostForm('mhc_payroll_extras_list', {
      payroll_id: id, worker_id: extrasFilter.worker || ''
    })
    extras.value = res?.items || []
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load extras')
  } finally {
    loading.extras = false
  }
}
function openAddExtra() {
  modals.extra.editing = false
  modals.extra.form = {
    id: null,
    worker_id: null,
    special_rate_id: null,
    amount: null,
    patient_id: selectedPatient.value?.patient_id || null,
    supervised_worker_id: null,
    notes: ''
  }
  modals.extra.visible = true
}
function editExtra(row) {
  modals.extra.editing = true
  modals.extra.form = {
    id: row.id,
    worker_id: row.worker_id,
    special_rate_id: row.special_rate_id,
    amount: Number(row.amount || 0),
    patient_id: row.patient_id || null,
    supervised_worker_id: row.supervised_worker_id || null,
    notes: row.notes || ''
  }
  // Pre-fill dropdowns caches
  if (row.worker_id && row.first_name) {
    workersOptions.value = [{ value: row.worker_id, label: row.first_name }]
  }
  if (row.special_rate_id && row.label) {
    ratesOptions.value = [{ value: row.special_rate_id, label: `${row.code} — ${row.label}` }]
  }
  modals.extra.visible = true
}
async function saveExtra() {
  const f = modals.extra.form
  if (!f.worker_id || !f.special_rate_id || f.amount === null) {
    ElMessage.warning('Worker, special rate, and amount are required')
    return
  }
  loading.saveExtra = true
  try {
    if (modals.extra.editing) {
      await ajaxPostForm('mhc_payroll_extras_update', { ...f })
    } else {
      await ajaxPostForm('mhc_payroll_extras_create', {
        payroll_id: id,
        worker_id: f.worker_id,
        special_rate_id: f.special_rate_id,
        amount: f.amount,
        patient_id: f.patient_id || '',
        supervised_worker_id: f.supervised_worker_id || '',
        notes: f.notes || ''
      })
    }
    modals.extra.visible = false
    await loadExtras()
    ElMessage.success('Saved')
  } catch (e) {
    ElMessage.error(e.message || 'Save failed')
  } finally {
    loading.saveExtra = false
  }
}
async function deleteExtra(row) {
  try {
    await ajaxPostForm('mhc_payroll_extras_delete', { id: row.id })
    await loadExtras()
    ElMessage.success('Deleted')
  } catch (e) {
    ElMessage.error(e.message || 'Delete failed')
  }
}

/* ======= Special rates & workers lookups ======= */
async function searchRates(q) {
  try {
    const res = await ajaxPostForm('mhc_special_rates_list', { q })
    ratesOptions.value = (res?.items || []).map(i => ({
      value: i.id, label: `${i.code} — ${i.label} ($${Number(i.unit_rate).toFixed(2)})`
    }))
  } catch (_) {}
}
// Adjust this action name to your existing worker search endpoint.
async function searchWorkers(q) {
  try {
    // Example expected response shape: { items: [{ id, name }] }
    const res = await ajaxPostForm('mhc_workers_list', { q, limit: 20 }) // <-- change if needed
    workersOptions.value = (res?.items || []).map(w => ({ value: w.id, label: w.first_name + ' ' + w.last_name }))
  } catch (_) {}
}

/* ======= Summary & slip ======= */
async function loadSummary() {
  loading.summary = true
  try {
    const res = await ajaxPostForm('mhc_payroll_workers', { payroll_id: id })
    summary.items = res?.items || []
    summary.totals = res?.totals || null
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load summary')
  } finally {
    loading.summary = false
  }
}
async function openWorkerSlip(row) {
  modals.slip.visible = true
  loading.slip = true
  try {
    const res = await ajaxPostForm('mhc_payroll_worker_detail', { payroll_id: id, worker_id: row.worker_id })
    modals.slip.header = res?.worker || {}
    modals.slip.hours  = res?.hours || []
    modals.slip.extras = res?.extras || []
    modals.slip.totals = res?.totals || null
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load slip')
  } finally {
    loading.slip = false
  }
}

/* ======= Header actions ======= */
async function finalizePayroll() {
  loading.finalize = true
  try {
    await ElMessageBox.confirm('Finalize this payroll? After finalizing you cannot edit hours/extras.', 'Finalize', { type: 'warning' })
    await ajaxPostForm('mhc_payroll_finalize', { id })
    payroll.status = 'finalized'
    ElMessage.success('Payroll finalized')
  } catch (e) {
    if (e !== 'cancel') ElMessage.error(e.message || 'Finalize failed')
  } finally {
    loading.finalize = false
  }
}
async function reopenPayroll() {
  loading.reopen = true
  try {
    await ElMessageBox.confirm('Reopen this payroll?', 'Reopen', { type: 'warning' })
    await ajaxPostForm('mhc_payroll_reopen', { id })
    payroll.status = 'draft'
    ElMessage.success('Payroll reopened')
  } catch (e) {
    if (e !== 'cancel') ElMessage.error(e.message || 'Reopen failed')
  } finally {
    loading.reopen = false
  }
}

/* ======= Tab hook ======= */
async function onTab(name) {
  if (name === 'extras') await loadExtras()
  if (name === 'summary') await loadSummary()
}

/* ======= Init ======= */
onMounted(async () => {
  await loadHeader()
  await loadPatients()
})
</script>

<style scoped>
.wp-wrap { padding: 12px; }
.mb-3 { margin-bottom: .75rem; }
.mt-2 { margin-top: .5rem; }
.flex { display: flex; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.text-lg { font-size: 1.125rem; }
.font-semibold { font-weight: 600; }
.text-gray-600 { color: #6b7280; }
.text-gray-700 { color: #374151; }
.cursor { cursor: pointer; }
.gap-2 { gap: .5rem; }
.h-full { height: 100%; }
.ml-2 { margin-left: .5rem; }
</style>
