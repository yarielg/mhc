<template>
  <div class="wp-wrap">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Patients</h2>
    </div>

    <div class="mb-20">
      <el-row :gutter="20">
        <el-col :span="20">
          <el-button type="primary" @click="openCreate">Add Patient</el-button>
        </el-col>
        <el-col :span="4">
          <div class="mb-3">
            <el-input v-model="state.search" placeholder="Search by name..." clearable @clear="fetchData(1)"
              @keyup.enter.native="fetchData(1)">
              <template #append>
                <el-button @click="fetchData(1)">Search</el-button>
              </template>
            </el-input>
          </div>
        </el-col>
      </el-row>
    </div>

    <el-table :data="state.items" v-loading="state.loading" border style="width: 100%" size="small"
      empty-text="No patients found">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="first_name" label="First Name" />
      <el-table-column prop="last_name" label="Last Name" />
      <el-table-column prop="is_active" label="Is Active?" width="110">
        <template #default="{ row }">
          <el-tag :type="row.is_active == 1 ? 'success' : 'info'">
            {{ row.is_active == 1 ? 'Active' : 'Inactive' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="Actions" width="180" fixed="right">
        <template #default="{ row }">
          <el-button size="small" @click="openEdit(row)">Edit</el-button>          
        </template>
      </el-table-column>
    </el-table>

    <div class="mt-4 flex justify-end">
      <el-pagination background layout="prev, pager, next, jumper, ->, total" :total="state.total"
        :page-size="state.per_page" :current-page="state.page" @current-change="fetchData" />
    </div>

    <el-dialog :title="state.editing ? 'Edit Patient' : 'Add Patient'" v-model="state.showDialog" width="820px"
      :close-on-click-modal="false">
      <el-form :model="form" :rules="rules" ref="formRef" label-width="130px">
        <el-form-item label="First Name" prop="first_name">
          <el-input v-model="form.first_name" />
        </el-form-item>

        <el-form-item label="Last Name" prop="last_name">
          <el-input v-model="form.last_name" />
        </el-form-item>

        <el-form-item label="Is Active?">
          <el-select v-model="form.is_active">
            <el-option label="Active" value="1" />
            <el-option label="Inactive" value="0" />
          </el-select>
        </el-form-item>

        <!-- Assignments -->
        <el-divider>Assignments (Worker → Role → Rate)</el-divider>
        <el-alert type="info" show-icon :closable="false" class="mb-3"
          description="Add one or more worker-role assignments for this patient. Role options are filtered by the selected worker. Rate defaults to the worker's role rate but can be adjusted." />
        <div class="assignments">
          <el-table :data="form.assignments" border style="width: 100%" size="small" empty-text="No assignments yet">
            <el-table-column label="Worker">
              <template #default="{ row }">
                <el-select v-model="row.worker_id" filterable remote reserve-keyword placeholder="Search worker…"
                  :remote-method="q => remoteSearchWorkers(q, row)" :loading="row._workerLoading === true"
                  style="width: 100%" @change="() => onWorkerChange(row)">
                  <el-option v-for="w in row._workerOptions || []" :key="w.id"
                    :label="`${w.first_name} ${w.last_name}${w.is_active == 1 ? '' : ' (inactive)'}`" :value="w.id" />
                </el-select>
              </template>
            </el-table-column>

            <el-table-column label="Role" width="260">
              <template #default="{ row }">
                <el-select v-model="row.role_id" placeholder="Select role" :disabled="!row.worker_id"
                  style="width: 100%" @change="() => onRoleChange(row)">
                  <el-option v-for="r in row._rolesForWorker || []" :key="r.role_id" :label="r.role_name"
                    :value="r.role_id" />
                </el-select>
              </template>
            </el-table-column>

            <el-table-column label="Rate" width="160">
              <template #default="{ row }">
                <el-input-number v-model="row.rate" :precision="2" :step="1" :min="0" style="width: 140px" />
              </template>
            </el-table-column>

            <el-table-column label="" width="80" fixed="right">
              <template #default="{ $index }">
                <el-button type="danger" link @click="removeAssignment($index)">
                  Remove
                </el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="mt-2">
            <el-button type="primary" link @click="addAssignment">
              + Add assignment
            </el-button>
          </div>
        </div>
      </el-form>

      <template #footer>
        <el-button @click="state.showDialog = false">Cancel</el-button>
        <el-button type="primary" :loading="state.saving" @click="submit">Save</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const state = reactive({
  items: [],
  total: 0,
  page: 1,
  per_page: 10,
  search: '',
  loading: false,
  showDialog: false,
  saving: false,
  editing: false,
  currentId: null,
})

const formRef = ref(null)
const form = reactive({
  first_name: '',
  last_name: '',
  is_active: '1',
  // NEW
  assignments: [], // [{ worker_id, role_id, rate, _workerOptions, _rolesForWorker, _lastRoleDefault }]
})

const rules = {
  first_name: [{ required: true, message: 'First name is required', trigger: 'blur' }],
  last_name: [{ required: true, message: 'Last name is required', trigger: 'blur' }],
}

function resetForm() {
  form.first_name = ''
  form.last_name = ''
  form.is_active = '1'
  form.assignments = []
  state.currentId = null
  state.editing = false
}

function openCreate() {
  resetForm()
  state.showDialog = true
}

async function openEdit(row) {
  state.editing = true
  state.currentId = row.id
  form.first_name = row.first_name
  form.last_name = row.last_name
  form.is_active = String(row.is_active ?? '1')
  form.assignments = (row.assignments || []).map(a => ({
    worker_id: a.worker_id,
    role_id: a.role_id,
    rate: a.rate,
    _workerOptions: [],
    _workerLoading: false,
    _rolesForWorker: [],
    _lastRoleDefault: null,
  }))
  // Para cada assignment, cargar worker y roles
  for (const assignment of form.assignments) {

    await remoteSearchWorkers('', assignment) // carga worker options
    if (assignment.worker_id) {
      await onWorkerChange(assignment) // carga roles para ese worker
    }
    // Seleccionar el rol del assignment si existe en la lista
    const foundRole = assignment._rolesForWorker.find(r => r.role_id === assignment.role_id)
    if (foundRole) {
      assignment.role_id = foundRole.role_id
      if (foundRole.general_rate != null) {
        assignment._lastRoleDefault = Number(foundRole.general_rate)
      }
    }
  }
  state.showDialog = true
}

async function fetchData(page = state.page) {
  try {
    state.loading = true
    state.page = page
    const fd = new FormData()
    fd.append('action', 'mhc_patients_list')
    fd.append('nonce', parameters.nonce)
    fd.append('page', state.page)
    fd.append('per_page', state.per_page)
    if (state.search) fd.append('search', state.search)

    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Failed to load')
    state.items = data.data.items
    state.total = data.data.total
  } catch (e) {
    console.error(e)
    ElMessage.error(e.message || 'Error loading patients')
  } finally {
    state.loading = false
  }
}

function addAssignment() {
  form.assignments.push({
    worker_id: null,
    role_id: null,
    rate: null,
    _workerOptions: [],
    _workerLoading: false,
    _rolesForWorker: [],
    _lastRoleDefault: null, // remember last default so we don't overwrite user's manual edit
  })
}

function removeAssignment(idx) {
  form.assignments.splice(idx, 1)
}

async function remoteSearchWorkers(query, row) {
  row._workerLoading = true
  try {
    const fd = new FormData()
    fd.append('action', 'mhc_workers_search_for_role')
    fd.append('nonce', parameters.nonce)
    fd.append('term', query || '')
    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Search failed')
    row._workerOptions = data.data.items || []
  } catch (e) {
    console.error(e)
  } finally {
    row._workerLoading = false
  }
}

async function onWorkerChange(row) {
  // Solo limpiar si es cambio manual, no en inicialización de edición
  // row.role_id = null
  // row.rate = null
  row._lastRoleDefault = null
  row._rolesForWorker = []

  if (!row.worker_id) return

  try {
    const fd = new FormData()
    fd.append('action', 'mhc_worker_roles_by_worker')
    fd.append('nonce', parameters.nonce)
    fd.append('worker_id', row.worker_id)
    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Roles load failed')
    row._rolesForWorker = data.data.roles || []

    // Si el rol del assignment existe en la lista, seleccionarlo
    const foundRole = row._rolesForWorker.find(r => r.role_id === row.role_id)
    if (foundRole) {
      row.role_id = foundRole.role_id
      if (foundRole.general_rate != null) {
        row._lastRoleDefault = Number(foundRole.general_rate)
      }
      // El rate ya viene del assignment, no lo sobreescribas
    } else if (row._rolesForWorker.length === 1) {
      // Si solo hay un rol, seleccionarlo y poner el rate por defecto
      const r = row._rolesForWorker[0]
      row.role_id = r.role_id
      if (r.general_rate != null) {
        const def = Number(r.general_rate)
        row.rate = def
        row._lastRoleDefault = def
      }
    }
  } catch (e) {
    console.error(e)
    ElMessage.error(e.message || 'Error loading roles')
  }
}

function onRoleChange(row) {
  const r = (row._rolesForWorker || []).find(x => x.role_id === row.role_id)
  if (!r) return

  const def = r.general_rate != null ? Number(r.general_rate) : null

  // Only overwrite the rate if the current rate equals the previous default or is empty.
  // This way, if the user already typed a custom number, we don't nuke it.
  if (row.rate == null || row.rate === row._lastRoleDefault) {
    row.rate = def
    row._lastRoleDefault = def
  }
}

async function submit() {
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  // Validación: debe haber al menos un worker con rol RBT y uno con rol BCBA
  const assignments = (form.assignments || []).filter(a => a.worker_id && a.role_id)
  const hasRBT = assignments.some(a => {
    const role = a._rolesForWorker?.find(r => r.role_id === a.role_id)
    return role && (role.role_code === 'RBT' || role.role_name === 'RBT')
  })
  const hasBCBA = assignments.some(a => {
    const role = a._rolesForWorker?.find(r => r.role_id === a.role_id)
    return role && (role.role_code === 'BCBA' || role.role_name === 'BCBA')
  })
  if (!hasRBT || !hasBCBA) {
    ElMessage.error('You must assign at least one worker with role RBT and one with role BCBA.')
    return
  }

  try {
    state.saving = true
    const fd = new FormData()
    fd.append('nonce', parameters.nonce)

    if (state.editing) {
      fd.append('action', 'mhc_patients_update')
      fd.append('id', state.currentId)
    } else {
      fd.append('action', 'mhc_patients_create')
    }

    fd.append('first_name', form.first_name)
    fd.append('last_name', form.last_name)
    fd.append('is_active', form.is_active)

    // Prepare assignments payload (new patients only for now)
    const cleanAssignments = assignments.map(a => ({
      worker_id: Number(a.worker_id),
      role_id: Number(a.role_id),
      rate: a.rate != null ? Number(a.rate) : null,
    }))
    fd.append('assignments', JSON.stringify(cleanAssignments))

    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Save failed')

    const item = data.data.item
    if (state.editing) {
      const idx = state.items.findIndex(r => r.id === item.id)
      if (idx >= 0) state.items.splice(idx, 1, item)
    } else {
      state.items.unshift(item)
      state.total += 1
    }

    state.showDialog = false
    ElMessage.success('Saved')
  } catch (e) {
    console.error(e)
    ElMessage.error(e.message || 'Error saving')
  } finally {
    state.saving = false
  }
}

async function remove(row) {
  try {
    const fd = new FormData()
    fd.append('action', 'mhc_patients_delete')
    fd.append('nonce', parameters.nonce)
    fd.append('id', row.id)

    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Delete failed')

    state.items = state.items.filter(r => r.id !== row.id)
    state.total -= 1
    ElMessage.success('Deleted')
  } catch (e) {
    console.error(e)
    ElMessage.error(e.message || 'Error deleting')
  }
}

onMounted(() => {
  fetchData(1)
})
</script>

<style scoped>
.wp-wrap {
  padding: 0.5rem;
}

.el-pagination {
  margin-top: 20px;
}

.assignments :deep(.el-table__empty-block) {
  min-height: 48px;
}

h2{ color: var(--el-text-color-primary); }
</style>
