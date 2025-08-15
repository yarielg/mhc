<template>
  <div class="wp-wrap">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Payroll</h2>
      <div class="flex gap-2">
        <el-button :icon="Refresh" @click="resetAll">Reset</el-button>
        <el-button type="primary" :icon="DocumentChecked" @click="finalizePayroll" :disabled="!canFinalize">Finalize</el-button>
      </div>
    </div>

    <!-- Config / Top controls -->
    <el-card class="mb-4" shadow="hover">
      <el-form :model="form" label-width="160px" class="max-w-5xl">
        <el-row :gutter="12">
          <el-col :xs="24" :md="8">
            <el-form-item label="Pay period">
              <el-date-picker
                  v-model="form.range"
                  type="daterange"
                  range-separator="to"
                  start-placeholder="Start date"
                  end-placeholder="End date"
                  value-format="YYYY-MM-DD"
                  @change="onRangeChange"
              />
            </el-form-item>
          </el-col>

          <el-col :xs="24" :md="6">
            <el-form-item label="Auto‑populate">
              <el-switch v-model="form.autoPopulate" @change="onAutoPopulateToggle" />
            </el-form-item>
          </el-col>

          <el-col :xs="24" :md="10">
            <el-form-item label="Rules">
              <el-tag type="info" round class="mr-2">Max/Patient: {{ rules.maxHoursPerPatient }}h</el-tag>
              <el-tooltip content="Allow temporarily exceeding the per‑patient cap for this payroll only">
                <el-checkbox v-model="form.allowCapOverride">Allow cap override</el-checkbox>
              </el-tooltip>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="12">
          <el-col :xs="24" :md="10">
            <el-form-item label="Quick actions">
              <el-button size="small" @click="autoPopulate()" :disabled="!form.range">Load defaults</el-button>
              <el-button size="small" @click="clearHours" :disabled="!rows.length">Clear hours</el-button>
              <el-button size="small" @click="validateAll" :disabled="!rows.length">Validate</el-button>
              <el-button size="small" @click="openExtras">Extras</el-button>
            </el-form-item>
          </el-col>

          <el-col :xs="24" :md="14" class="text-right">
            <el-form-item label="" label-width="0">
              <el-input v-model="state.quickFilter" placeholder="Filter patients, workers, roles… (⌘/Ctrl+K)" clearable @clear="applyQuickFilter" @input="applyQuickFilter" style="max-width: 360px" />
            </el-form-item>
          </el-col>
        </el-row>
      </el-form>
    </el-card>

    <!-- Editable grid -->
    <el-card shadow="never" body-style="padding:0">
      <el-table
          ref="tbl"
          :data="filteredRows"
          row-key="rowKey"
          border
          style="width: 100%"
          height="62vh"
          @cell-value-change="onCell"
      >
        <el-table-column type="expand">
          <template #default="{ row }">
            <div class="p-3">
              <div class="text-xs mb-2 text-gray-500">Default workers can be overridden for this payroll. Use \"Add worker\" to handle temporary substitutions.</div>
              <el-table :data="row.assignments" border size="small" style="width:100%">
                <el-table-column prop="worker_name" label="Worker" min-width="180"/>
                <el-table-column prop="role_name" label="Role" min-width="120"/>
                <el-table-column label="Rate" min-width="90">
                  <template #default="{ row: ass }">
                    <span>{{ money(ass.resolved_rate) }}</span>
                    <el-tooltip v-if="ass.special_rate" content="Special rate for this patient overrides worker global rate"><el-icon class="ml-1"><WarningFilled/></el-icon></el-tooltip>
                  </template>
                </el-table-column>
                <el-table-column label="Hours" min-width="120">
                  <template #default="{ row: ass }">
                    <el-input-number v-model="ass.hours" :min="0" :max="999" :step="0.5" @change="recalcPatient(row)" />
                  </template>
                </el-table-column>
                <el-table-column label="Amount" min-width="120">
                  <template #default="{ row: ass }">
                    <strong>{{ money(ass.hours * ass.resolved_rate) }}</strong>
                  </template>
                </el-table-column>
                <el-table-column label="Actions" width="160">
                  <template #default="{ row: ass }">
                    <el-button size="small" text type="primary" @click="copyHoursToAll(ass)">Copy hrs → same role</el-button>
                    <el-button size="small" text type="danger" @click="removeAssignment(row, ass)">Remove</el-button>
                  </template>
                </el-table-column>
              </el-table>

              <div class="mt-2 flex items-center gap-2">
                <el-button size="small" @click="addAssignment(row)">Add worker</el-button>
                <el-tag v-if="row.validation.capExceeded && !form.allowCapOverride" type="danger" effect="dark">Cap exceeded ({{ row.totalHours }}h › {{ rules.maxHoursPerPatient }}h)</el-tag>
                <el-tag v-else type="success">Total: {{ row.totalHours }}h · {{ money(row.totalAmount) }}</el-tag>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="patient_name" label="Patient" min-width="220" fixed="left" />
        <el-table-column label="Workers (role · hours · amount)" min-width="520">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-2">
              <template v-for="ass in row.assignments" :key="ass.key">
                <el-tag closable @close="removeAssignment(row, ass)">
                  <span class="font-medium">{{ ass.worker_short }}</span>
                  <span class="mx-1">· {{ ass.role_name }}</span>
                  <span class="mx-1">·</span>
                  <el-input-number v-model="ass.hours" size="small" :min="0" :max="999" :step="0.5" @change="recalcPatient(row)" />
                  <span class="ml-2">{{ money(ass.hours * ass.resolved_rate) }}</span>
                </el-tag>
              </template>
              <el-button size="small" link @click="addAssignment(row)">+ add</el-button>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="Totals" width="170" align="right">
          <template #default="{ row }">
            <div class="text-right">
              <div><strong>{{ row.totalHours }}</strong> h</div>
              <div><strong>{{ money(row.totalAmount) }}</strong></div>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Footer summary -->
    <el-affix :offset="12">
      <el-card class="mt-4" shadow="always">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <el-statistic title="Patients" :value="rows.length" />
            <el-divider direction="vertical" />
            <el-statistic title="Total hours" :value="totals.hours" />
            <el-divider direction="vertical" />
            <el-statistic title="Total amount" :value="money(totals.amount)" />
          </div>
          <div class="flex items-center gap-2">
            <el-switch v-model="state.autoSave" active-text="Autosave (local)" />
            <el-button @click="saveDraft" :icon="Document" :disabled="!rows.length">Save draft</el-button>
            <el-button type="success" @click="finalizePayroll" :icon="DocumentChecked" :disabled="!canFinalize">Finalize</el-button>
          </div>
        </div>
      </el-card>
    </el-affix>

    <!-- Add/Replace worker dialog -->
    <el-dialog v-model="dialogs.addWorker.visible" title="Add worker to patient" width="560px">
      <el-form :model="dialogs.addWorker.form" label-width="110px">
        <el-form-item label="Patient">
          <el-input v-model="dialogs.addWorker.patient_name" disabled />
        </el-form-item>
        <el-form-item label="Worker">
          <el-select v-model="dialogs.addWorker.worker_id" filterable placeholder="Search worker" @change="onPickWorker">
            <el-option v-for="w in workers" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="Role">
          <el-select v-model="dialogs.addWorker.role_id" placeholder="Pick role">
            <el-option v-for="r in roleOptionsFor(dialogs.addWorker.worker_id)" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="Rate">
          <el-input-number v-model="dialogs.addWorker.rate" :min="0" :max="9999" :step="0.5" />
          <el-tooltip class="ml-2" content="Defaults to special patient rate if exists, else worker's global role rate. You can override."><el-icon><InfoFilled/></el-icon></el-tooltip>
        </el-form-item>
        <el-form-item label="Hours">
          <el-input-number v-model="dialogs.addWorker.hours" :min="0" :max="999" :step="0.5" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogs.addWorker.visible=false">Cancel</el-button>
        <el-button type="primary" @click="confirmAddWorker">Add</el-button>
      </template>
    </el-dialog>

    <!-- Extras drawer -->
    <el-drawer v-model="dialogs.extras.visible" title="Extras (pending, assessments, supervision)" size="50%">
      <div class="mb-3 text-sm text-gray-600">Extras apply on top of hours. Use them for adjustments and non-hourly work.</div>

      <el-form :inline="true" :model="dialogs.extras.form" class="mb-3">
        <el-form-item label="Type">
          <el-select v-model="dialogs.extras.form.type" style="width:180px">
            <el-option label="Pending (+/−)" value="pending"/>
            <el-option label="Assessment – Initial" value="assess_initial"/>
            <el-option label="Assessment – Reassessment" value="assess_re"/>
            <el-option label="Supervision (count)" value="supervision"/>
          </el-select>
        </el-form-item>
        <el-form-item v-if="dialogs.extras.form.type!=='supervision'" label="Worker">
          <el-select v-model="dialogs.extras.form.worker_id" filterable style="width:220px">
            <el-option v-for="w in analysts" :key="w.id" :label="w.name" :value="w.id"/>
          </el-select>
        </el-form-item>
        <el-form-item v-if="dialogs.extras.form.type!=='supervision'" label="Patient">
          <el-select v-model="dialogs.extras.form.patient_id" filterable style="width:220px">
            <el-option v-for="p in patients" :key="p.id" :label="p.name" :value="p.id"/>
          </el-select>
        </el-form-item>
        <el-form-item v-if="dialogs.extras.form.type==='pending'" label="Amount">
          <el-input-number v-model="dialogs.extras.form.amount" :step="1" />
        </el-form-item>
        <el-form-item v-if="dialogs.extras.form.type==='assess_initial' || dialogs.extras.form.type==='assess_re'" label="Amount">
          <el-input-number v-model="dialogs.extras.form.amount" :step="1" :min="0" :max="99999" />
          <el-button size="small" link @click="prefillAssessment">Use default</el-button>
        </el-form-item>
        <el-form-item v-if="dialogs.extras.form.type==='supervision'" label="#supervised">
          <el-input-number v-model="dialogs.extras.form.count" :min="1" :max="999" />
          <span class="ml-2">× {{ money(rules.supervisionFee) }} = <b>{{ money((dialogs.extras.form.count||0)*rules.supervisionFee) }}</b></span>
        </el-form-item>
        <el-form-item>
          <el-input v-model="dialogs.extras.form.note" placeholder="Note / code (e.g., 97151)" style="width:240px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="addExtra">Add</el-button>
        </el-form-item>
      </el-form>

      <el-table :data="extras" border height="50vh">
        <el-table-column prop="type_label" label="Type" width="170" />
        <el-table-column prop="worker_name" label="Worker" width="200" />
        <el-table-column prop="patient_name" label="Patient" width="200" />
        <el-table-column prop="note" label="Note/Code" min-width="160" />
        <el-table-column label="Amount" width="140" align="right">
          <template #default="{ row }">{{ money(row.amount) }}</template>
        </el-table-column>
        <el-table-column label="Actions" width="100">
          <template #default="{ $index }">
            <el-button size="small" text type="danger" @click="extras.splice($index,1)">Remove</el-button>
          </template>
        </el-table-column>
      </el-table>

      <template #footer>
        <div class="flex items-center justify-between w-full">
          <div class="text-sm">Total extras: <b>{{ money(totalExtras) }}</b></div>
          <div>
            <el-button @click="dialogs.extras.visible=false">Close</el-button>
          </div>
        </div>
      </template>
    </el-drawer>

    <!-- Finalize dialog -->
    <el-dialog v-model="dialogs.finalize" title="Finalize payroll" width="520px">
      <p class="mb-2">You are finalizing the payroll for <b>{{ form.range?.[0] }}</b> → <b>{{ form.range?.[1] }}</b>.</p>
      <el-alert v-if="validationErrors.length" type="error" title="Fix these before finalizing" :closable="false" class="mb-3">
        <ul class="mt-2 list-disc pl-5">
          <li v-for="(err,i) in validationErrors" :key="i">{{ err }}</li>
        </ul>
      </el-alert>
      <el-descriptions :column="2" border>
        <el-descriptions-item label="Patients">{{ rows.length }}</el-descriptions-item>
        <el-descriptions-item label="Total hours">{{ totals.hours }}</el-descriptions-item>
        <el-descriptions-item label="Extras">{{ money(totalExtras) }}</el-descriptions-item>
        <el-descriptions-item label="Grand total"><b>{{ money(grandTotal) }}</b></el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button @click="dialogs.finalize=false">Cancel</el-button>
        <el-button type="primary" :disabled="validationErrors.length>0" @click="submitFinalize">Finalize</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { reactive, ref, computed, onMounted, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Document, DocumentChecked, Refresh, InfoFilled, WarningFilled } from '@element-plus/icons-vue'

// --- State ---
const form = reactive({
  range: null, // [start, end]
  autoPopulate: true,
  allowCapOverride: false,
})

const rules = reactive({
  maxHoursPerPatient: 30, // default, can be loaded from backend settings
  assessmentInitial: 457.20,
  assessmentRe: 342.90,
  supervisionFee: 50.00,
})

const patients = ref([]) // [{id, name, is_active}]
const workers = ref([]) // all workers
const analysts = computed(() => workers.value.filter(w => w.is_analyst))

// rows = per-patient working row for this payroll
const rows = ref([])
const extras = ref([]) // list of extra entries

const state = reactive({
  quickFilter: '',
  autoSave: true,
  bootstrapped: false,
})

const dialogs = reactive({
  addWorker: {
    visible: false,
    forPatientId: null,
    patient_name: '',
    form: { worker_id: null, role_id: null, rate: 0, hours: 0 },
  },
  extras: { visible: false, form: { type: 'pending', worker_id: null, patient_id: null, amount: 0, count: 1, note: '' } },
  finalize: false,
})

const tbl = ref(null)

// --- Computed ---
const filteredRows = computed(()=>{
  if(!state.quickFilter) return rows.value
  const q = state.quickFilter.toLowerCase()
  return rows.value.filter(r =>
      r.patient_name.toLowerCase().includes(q) ||
      r.assignments.some(a => (a.worker_name+a.role_name).toLowerCase().includes(q))
  )
})

const totals = computed(()=>{
  const hours = rows.value.reduce((s,r)=>s + (r.totalHours||0), 0)
  const amount = rows.value.reduce((s,r)=>s + (r.totalAmount||0), 0)
  return { hours, amount }
})

const totalExtras = computed(()=> extras.value.reduce((s,e)=> s + Number(e.amount||0), 0))
const grandTotal = computed(()=> totals.value.amount + totalExtras.value)

const validationErrors = ref([])
const canFinalize = computed(()=> form.range && rows.value.length>0)

// --- Lifecycle ---
onMounted(async ()=>{
  await bootstrap()
  bindHotkeys()
})

watch(rows, ()=>{
  if(state.autoSave) saveLocal()
},{deep:true})

// --- Methods ---
async function bootstrap(){
  try {
    // Fetch initial data from backend (workers, patients, defaults, settings)
    const res = await ajax('mhc_payroll_bootstrap')
    Object.assign(rules, res.rules||{})
    patients.value = res.patients||[]
    workers.value = res.workers||[]
    state.bootstrapped = true

    // Try load local draft
    loadLocal()
  } catch (e){
    console.error(e)
    ElMessage.error('Failed to load data')
  }
}

function onRangeChange(){
  if(form.autoPopulate) autoPopulate()
}

function onAutoPopulateToggle(v){
  if(v && form.range && !rows.value.length) autoPopulate()
}

function buildRowFromPatient(p){
  // default assignments come from backend (per patient default workers+roles+special rates)
  const assigns = (p.defaults||[]).map(a=>({
    key: `${p.id}-${a.worker_id}-${a.role_id}`,
    worker_id: a.worker_id,
    worker_name: a.worker_name,
    worker_short: shortName(a.worker_name),
    role_id: a.role_id,
    role_name: a.role_name,
    special_rate: a.special_rate||null,
    resolved_rate: Number(a.special_rate||a.global_rate||0),
    hours: 0,
  }))
  return {
    rowKey: `patient-${p.id}`,
    patient_id: p.id,
    patient_name: p.name,
    assignments: assigns,
    validation: { capExceeded: false },
    totalHours: 0,
    totalAmount: 0,
  }
}

async function autoPopulate(){
  if(!form.range){ ElMessage.warning('Pick a pay period first'); return }
  // get active patients + defaults from backend for the selected period
  try{
    const res = await ajax('mhc_payroll_defaults', { start: form.range[0], end: form.range[1] })
    rows.value = res.patients.map(p=> buildRowFromPatient(p))
    extras.value = []
    validateAll()
  }catch(e){ ElMessage.error('Could not auto‑populate') }
}

function clearHours(){
  rows.value.forEach(r=> r.assignments.forEach(a=> a.hours=0))
  recalcAll()
}

function recalcPatient(r){
  r.totalHours = r.assignments.reduce((s,a)=> s + Number(a.hours||0), 0)
  r.totalAmount = r.assignments.reduce((s,a)=> s + Number(a.hours||0) * Number(a.resolved_rate||0), 0)
  r.validation.capExceeded = r.totalHours > rules.maxHoursPerPatient
}

function recalcAll(){ rows.value.forEach(recalcPatient) }

function validateAll(){
  recalcAll()
  const errs = []
  rows.value.forEach(r=>{
    if(r.validation.capExceeded && !form.allowCapOverride){
      errs.push(`${r.patient_name}: ${r.totalHours}h exceeds cap of ${rules.maxHoursPerPatient}h`)
    }
  })
  validationErrors.value = errs
  if(!errs.length) ElMessage.success('Validation passed')
}

function addAssignment(row){
  dialogs.addWorker.forPatientId = row.patient_id
  dialogs.addWorker.patient_name = row.patient_name
  dialogs.addWorker.form = { worker_id:null, role_id:null, rate:0, hours:0 }
  dialogs.addWorker.visible = true
}

function onPickWorker(){
  const w = workers.value.find(x=> x.id === dialogs.addWorker.form.worker_id)
  // Preselect first available role
  const roles = roleOptionsFor(w?.id)
  if(roles?.length){ dialogs.addWorker.form.role_id = roles[0].id }
}

function roleOptionsFor(workerId){
  const w = workers.value.find(x=> x.id===workerId)
  return w?.roles || [] // [{id, name, rate}]
}

function confirmAddWorker(){
  const pid = dialogs.addWorker.forPatientId
  const row = rows.value.find(r=> r.patient_id===pid)
  const w = workers.value.find(x=> x.id===dialogs.addWorker.form.worker_id)
  const role = (w?.roles||[]).find(r=> r.id===dialogs.addWorker.form.role_id)
  if(!row || !w || !role){ ElMessage.warning('Missing worker/role'); return }

  // Resolve special patient rate if any
  const p = patients.value.find(p=> p.id===pid)
  const special = p?.special_rates?.find(sr=> sr.worker_id===w.id && sr.role_id===role.id)
  const resolved = Number(dialogs.addWorker.form.rate || special?.rate || role.rate || 0)

  const ass = {
    key: `${pid}-${w.id}-${role.id}-${Date.now()}`,
    worker_id: w.id,
    worker_name: w.name,
    worker_short: shortName(w.name),
    role_id: role.id,
    role_name: role.name,
    special_rate: special?.rate||null,
    resolved_rate: resolved,
    hours: Number(dialogs.addWorker.form.hours||0),
  }
  row.assignments.push(ass)
  dialogs.addWorker.visible = false
  recalcPatient(row)
}

function removeAssignment(row, ass){
  const i = row.assignments.findIndex(a=> a===ass)
  if(i>-1){ row.assignments.splice(i,1); recalcPatient(row) }
}

function copyHoursToAll(ass){
  // copy hours to all assignments with same role_name across table (fast entry shortcut)
  rows.value.forEach(r=> r.assignments.forEach(a=>{ if(a.role_id===ass.role_id) a.hours = ass.hours }))
  recalcAll()
}

function openExtras(){ dialogs.extras.visible = true }

function prefillAssessment(){
  if(dialogs.extras.form.type==='assess_initial') dialogs.extras.form.amount = rules.assessmentInitial
  if(dialogs.extras.form.type==='assess_re') dialogs.extras.form.amount = rules.assessmentRe
}

function addExtra(){
  const f = dialogs.extras.form
  const entry = { type: f.type, amount: 0, note: f.note||'' }
  if(f.type==='pending'){
    if(!f.worker_id) return ElMessage.warning('Pick worker')
    entry.worker_id = f.worker_id
    entry.worker_name = nameOfWorker(f.worker_id)
    entry.patient_id = f.patient_id||null
    entry.patient_name = f.patient_id ? nameOfPatient(f.patient_id) : ''
    entry.amount = Number(f.amount||0)
    entry.type_label = 'Pending (+/−)'
  } else if(f.type==='assess_initial' || f.type==='assess_re'){
    if(!f.worker_id || !f.patient_id) return ElMessage.warning('Pick worker & patient')
    entry.worker_id = f.worker_id
    entry.worker_name = nameOfWorker(f.worker_id)
    entry.patient_id = f.patient_id
    entry.patient_name = nameOfPatient(f.patient_id)
    entry.amount = Number(f.amount||0)
    entry.type_label = f.type==='assess_initial' ? 'Assessment – Initial' : 'Assessment – Reassessment'
  } else if(f.type==='supervision'){
    entry.worker_id = null
    entry.worker_name = 'Supervision'
    entry.patient_id = null
    entry.patient_name = ''
    const count = Number(f.count||0)
    entry.amount = count * Number(rules.supervisionFee)
    entry.type_label = 'Supervision'
  }
  extras.value.push(entry)
  dialogs.extras.form.note = ''
}

function finalizePayroll(){
  validateAll()
  dialogs.finalize = true
}

async function submitFinalize(){
  try{
    const payload = serialize()
    // server will validate again and create payroll records
    const res = await ajax('mhc_payroll_finalize', payload)
    dialogs.finalize = false
    clearLocal()
    ElMessageBox.alert('Payroll created successfully', 'Success', { type: 'success' })
  }catch(e){
    ElMessage.error(e.message||'Failed to finalize')
  }
}

function serialize(){
  return {
    start: form.range?.[0],
    end: form.range?.[1],
    allowCapOverride: form.allowCapOverride,
    items: rows.value.map(r=>({
      patient_id: r.patient_id,
      assignments: r.assignments.map(a=>({ worker_id: a.worker_id, role_id: a.role_id, hours: Number(a.hours||0), rate: Number(a.resolved_rate||0) }))
    })),
    extras: extras.value,
  }
}

// --- Persistence (local draft) ---
const LS_KEY = 'mhc_payroll_new_draft'
function saveLocal(){ localStorage.setItem(LS_KEY, JSON.stringify({ form, rows: rows.value, extras: extras.value })) }
function loadLocal(){
  const raw = localStorage.getItem(LS_KEY)
  if(!raw) return
  try{
    const data = JSON.parse(raw)
    form.range = data.form?.range||null
    form.autoPopulate = !!data.form?.autoPopulate
    form.allowCapOverride = !!data.form?.allowCapOverride
    rows.value = (data.rows||[])
    extras.value = (data.extras||[])
    recalcAll()
  }catch{}
}
function clearLocal(){ localStorage.removeItem(LS_KEY) }

function saveDraft(){ saveLocal(); ElMessage.success('Draft saved (local)') }
function resetAll(){ rows.value=[]; extras.value=[]; form.allowCapOverride=false; state.quickFilter=''; clearLocal() }

function applyQuickFilter(){ /* computed handles */ }

function onCell(){ /* reserved */ }

// --- Utils ---
function shortName(n){
  if(!n) return ''
  const parts = n.split(' ')
  return parts.length>1 ? parts[0] + ' ' + parts[1][0] + '.' : n
}
function nameOfWorker(id){ return workers.value.find(w=>w.id===id)?.name || '' }
function nameOfPatient(id){ return patients.value.find(p=>p.id===id)?.name || '' }
function money(v){ return new Intl.NumberFormat(undefined,{ style:'currency', currency:'USD' }).format(Number(v||0)) }

function bindHotkeys(){
  window.addEventListener('keydown', (e)=>{
    if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k'){ e.preventDefault(); const el=document.querySelector('input[placeholder^="Filter patients"]'); el&&el.focus() }
  })
}

// --- Minimal ajax helper for WP ---
async function ajax(action, data={}){
  const body = new URLSearchParams({ action, nonce: window.mhcNonce||'', ...objToForm(data) })
  const res = await fetch(window.ajaxurl||'/wp-admin/admin-ajax.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
  const json = await res.json()
  if(!json?.success) throw new Error(json?.data?.message || 'Request failed')
  return json.data
}

function objToForm(o){
  const flat = {}
  for(const k in o){
    const v = o[k]
    flat[k] = typeof v === 'object' ? JSON.stringify(v) : v
  }
  return flat
}
</script>

<style scoped>
/* Basic spacing helpers in case Tailwind is not present */
.flex { display: flex; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-2 { gap: .5rem; }
.gap-3 { gap: .75rem; }
.flex-wrap { flex-wrap: wrap; }
.mb-2 { margin-bottom: .5rem; }
.mb-3 { margin-bottom: .75rem; }
.mb-4 { margin-bottom: 1rem; }
.mt-2 { margin-top: .5rem; }
.mt-4 { margin-top: 1rem; }
.mr-2 { margin-right: .5rem; }
.max-w-5xl { max-width: 80rem; }
.text-right { text-align: right; }
.text-gray-500 { color: #6b7280; }
.text-gray-600 { color: #4b5563; }
.font-medium { font-weight: 600; }
</style>
