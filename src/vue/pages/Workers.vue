<template>
  <div class="wp-wrap">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Workers</h2>
    </div>


    <div class="mb-20">
      <el-row :gutter="20">
        <el-col :span="16">
          <el-button type="primary" @click="openCreate">Add Worker</el-button>
        </el-col>
        <el-col :span="8">
          <div class="mb-3">
            <el-input v-model="state.search" placeholder="Search by name..." clearable @clear="fetchData(1)"
              @keyup.enter.native="fetchData(1)">
              <template #append>
                <el-button @click="fetchData(1)">Search</el-button>
              </template>
            </el-input>
              <!-- Filtro Active/Inactive -->
              <el-select v-model="state.filterActive" placeholder="Filter by status" style="width: 100%; margin-top: 8px;" @change="fetchData(1)">
                <el-option label="All" value="" />
                <el-option label="Active" value="1" />
                <el-option label="Inactive" value="0" />
              </el-select>
          </div>
        </el-col>
      </el-row>
    </div>


    <el-table :data="state.items" v-loading="state.loading" border style="width:100%" size="small"
      empty-text="No workers found">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="first_name" label="First Name" width="150" show-overflow-tooltip />
      <el-table-column prop="last_name" label="Last Name" width="150" show-overflow-tooltip />
      <el-table-column prop="email" label="Email" width="210" show-overflow-tooltip />
      <el-table-column prop="company" label="Company" width="200" show-overflow-tooltip />

      <!-- New: Supervisor -->
      <!--<el-table-column label="Supervisor" min-width="210" show-overflow-tooltip class="d-none">
        <template #default="{ row }">
          <span v-if="row.supervisor_id">
            {{ row.supervisor_full_name || (row.supervisor_first_name + ' ' + row.supervisor_last_name).trim() }}
          </span>
          <span v-else class="text-gray-400">—</span>
        </template>
      </el-table-column>-->

      <!-- Roles with labels + rates -->
      <el-table-column label="Roles" min-width="100">
        <template #default="{ row }">
          <div class="flex flex-wrap gap-1">
            <el-tag v-for="rr in (row.worker_roles || [])" :key="(rr.id ?? rr.role_id) + '-' + (rr.general_rate ?? '')"
              size="small">
              {{ roleLabelById.get(Number(rr.role_id)) || rr.role_id }} — ${{ rr.general_rate ?? 0 }}
            </el-tag>
          </div>
        </template>
      </el-table-column>

      <el-table-column label="Active" width="80">
        <template #default="{ row }">
          <el-tag :type="String(row.is_active) === '1' ? 'success' : 'info'">
            {{ String(row.is_active) === '1' ? 'Active' : 'Inactive' }}
          </el-tag>
        </template>
      </el-table-column>

      <el-table-column label="Actions" width="80" fixed="right">
        <template #default="{ row }">
          <el-button size="small" @click="openEdit(row)">Edit</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div class="mt-4 flex justify-end">
      <el-pagination background layout="prev, pager, next, jumper, ->, total" :total="state.total"
        :page-size="state.per_page" :current-page="state.page" @current-change="fetchData" />
    </div>

    <!-- Dialog: Add/Edit Worker + Roles & Rates -->
    <el-dialog :title="state.editing ? 'Edit Worker' : 'Add Worker'" v-model="state.showDialog" width="860px"
      :close-on-click-modal="false">
      <el-form :model="form" :rules="rules" ref="formRef" label-width="140px">
        <el-form-item label="First Name" prop="first_name">
          <el-input v-model="form.first_name" />
        </el-form-item>

        <el-form-item label="Last Name" prop="last_name">
          <el-input v-model="form.last_name" />
        </el-form-item>

        <el-form-item label="Email" prop="email">
          <el-input v-model="form.email" type="email" />
        </el-form-item>

        <el-form-item label="Company" prop="company">
          <el-input v-model="form.company" />
        </el-form-item>

        <!-- New: Supervisor (remote autocomplete) -->
         <!--Hide this-->
        <el-form-item label="Supervisor" class="d-none">
          <el-select v-model="form.supervisor_id" filterable remote clearable placeholder="Type a name..."
            :remote-method="searchSupervisors" :loading="state.loadingSupers" style="width:100%;">
            <el-option v-for="opt in supervisorOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>

        <el-form-item label="Roles & Rates">
          <div style="width:100%;">
            <el-table :data="form.worker_roles" border size="small" style="width:100%;">
              <el-table-column label="Role" width="300">
                <template #default="{ row }">
                  <el-select v-model="row.role_id" filterable placeholder="Select role" :loading="state.loadingRoles"
                    style="width:100%;">
                    <el-option v-for="opt in rolesOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
                  </el-select>
                </template>
              </el-table-column>

              <el-table-column label="Rate" width="140">
                <template #default="{ row }">
                  <el-input-number v-model="row.general_rate" :min="0" :step="0.5" :precision="2" placeholder="Rate"
                    controls-position="right" style="width:100%;" />
                </template>
              </el-table-column>

              <el-table-column label="" width="100" align="center" fixed="right">
                <template #default="{ row }">
                  <el-button type="danger" text @click="removeRoleRow(row)">Remove</el-button>
                </template>
              </el-table-column>
            </el-table>

            <div style="margin-top:.5rem;">
              <el-button @click="addRoleRow" type="primary" plain>Add role</el-button>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="Is Active?">
          <el-select v-model="form.is_active">
            <el-option label="Active" value="1" />
            <el-option label="Inactive" value="0" />
          </el-select>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="state.showDialog = false">Cancel</el-button>
        <el-button type="primary" :loading="state.saving" @click="submit">Save</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted, computed } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

/** STATE */
const state = reactive({
  items: [],
  total: 0,
  page: 1,
  per_page: 10,
  search: '',
  loading: false,

  // dialog
  showDialog: false,
  saving: false,
  editing: false,
  currentId: null,

  // roles
  loadingRoles: false,

  // supervisors search
  loadingSupers: false,

  // Filtro Active/Inactive
  filterActive: '',
})

/** ROLES OPTIONS for selector */
const rolesOptions = ref([])

/** SUPERVISOR OPTIONS for remote select */
const supervisorOptions = ref([])

/** Quick lookup for labels by id */
const roleLabelById = computed(() => {
  const m = new Map()
  for (const o of rolesOptions.value) m.set(Number(o.value), o.label)
  return m
})

/** FORM */
const formRef = ref(null)
const form = reactive({
  first_name: '',
  last_name: '',
  email: '',
  company: '',               // NEW
  is_active: '1',
  supervisor_id: null,        // NEW
  worker_roles: []            // [{ uid, role_id:Number, general_rate:Number }]
})

/** VALIDATION */
const rules = {
  first_name: [{ required: true, message: 'First name is required', trigger: 'blur' }],
  last_name: [{ required: true, message: 'Last name is required', trigger: 'blur' }],
  email: [{ required: true, type: 'email', message: 'Valid email is required', trigger: 'blur' }],  
}

/** HELPERS (roles table) */
function newRoleRow() {
  return {
    uid: Math.random().toString(36).slice(2),
    role_id: null,
    general_rate: null,
  }
}
function addRoleRow() {
  form.worker_roles.push(newRoleRow())
}
function removeRoleRow(row) {
  const i = form.worker_roles.findIndex(r => r.uid === row.uid)
  if (i >= 0) form.worker_roles.splice(i, 1)
}
function validateRoleRows() {
  for (const r of form.worker_roles) {
    if (r.role_id == null) throw new Error('Each role row needs a Role.')
    if (r.general_rate === null || r.general_rate === '' || isNaN(Number(r.general_rate))) {
      throw new Error('Each role row needs a numeric Rate.')
    }
  }
}

/** LOAD ROLES for selector */
async function fetchRoles() {
  try {
    state.loadingRoles = true
    const fd = new FormData()
    fd.append('action', 'mhc_roles_list')
    fd.append('nonce', parameters.nonce)
    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Failed to load roles')
    const items = Array.isArray(data.data?.items) ? data.data.items : []
    rolesOptions.value = items
      .filter(r => String(r.is_active) === '1')
      .map(r => ({ value: Number(r.id), label: `${r.code || 'ROLE'}` }))
  } catch (e) {
    console.error(e)
    ElMessage.error(e.message || 'Error loading roles')
  } finally {
    state.loadingRoles = false
  }
}

/** SUPERVISOR remote search */
async function searchSupervisors(query) {
  try {
    state.loadingSupers = true
    const fd = new FormData()
    fd.append('action', 'mhc_workers_search')
    fd.append('nonce', parameters.nonce)
    fd.append('q', query || '')
    // Avoid selecting self as supervisor when editing
    if (state.editing && state.currentId) fd.append('exclude_id', state.currentId)
    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Search failed')
    const items = Array.isArray(data.data?.items) ? data.data.items : []
    supervisorOptions.value = items.map(w => ({
      value: Number(w.id),
      label: w.full_name || `${w.first_name || ''} ${w.last_name || ''}`.trim()
    }))
    fd.append('email', form.email)
    fd.append('company', form.company)
  } catch (e) {
    console.error(e)
    ElMessage.error(e.message || 'Error searching supervisors')
  } finally {
    state.loadingSupers = false
  }
}

/** MAP row -> form (for edit) */
function mapRowToForm(row) {
  form.first_name = row.first_name || ''
  form.last_name = row.last_name || ''
  form.email = row.email || ''
  form.company = row.company || ''
  form.is_active = String(row.is_active ?? '1')
  form.supervisor_id = row.supervisor_id != null ? Number(row.supervisor_id) : null

  // ensure current supervisor shows as selected label in the remote select
  if (form.supervisor_id) {
    const label =
      row.supervisor_full_name ||
      `${row.supervisor_first_name || ''} ${row.supervisor_last_name || ''}`.trim()
    if (label && !supervisorOptions.value.find(o => o.value === form.supervisor_id)) {
      supervisorOptions.value.push({ value: form.supervisor_id, label })
    }
  }

  const incoming = Array.isArray(row.worker_roles)
    ? row.worker_roles
    : Array.isArray(row.roles_assignments)
      ? row.roles_assignments
      : []

  form.worker_roles = incoming.map((r) => ({
    uid: Math.random().toString(36).slice(2),
    role_id: r.role_id != null ? Number(r.role_id) : (r.id != null ? Number(r.id) : null),
    general_rate: r.general_rate != null ? Number(r.general_rate) : null,
  }))
}

/** DIALOG open/create/edit */
function resetForm() {
  form.first_name = ''
  form.last_name = ''
  form.email = ''
  form.company = ''
  form.is_active = '1'
  form.supervisor_id = null
  form.worker_roles = []
  state.currentId = null
  state.editing = false
  supervisorOptions.value = []
}
function openCreate() {
  resetForm()
  addRoleRow()
  state.showDialog = true
}
async function openEdit(row) {
  state.editing = true
  state.currentId = row.id
  if (!rolesOptions.value.length) {
    await fetchRoles() // ensure options exist so labels render
  }
  mapRowToForm(row)
  if (form.worker_roles.length === 0) addRoleRow()
  state.showDialog = true
}

/** LIST */
async function fetchData(page = state.page) {
  try {
    state.loading = true
    state.page = page
    const fd = new FormData()
    fd.append('action', 'mhc_workers_list')
    fd.append('nonce', parameters.nonce)
    fd.append('page', state.page)
    fd.append('per_page', state.per_page)
    if (state.search) fd.append('search', state.search)

    // Filtro Active/Inactive
    if (state.filterActive !== '') fd.append('is_active', state.filterActive)

    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Failed to load')
    state.items = data.data.items || []
    state.total = Number(data.data.total || 0)
  } catch (e) {
    console.error(e)
    ElMessage.error(e.message || 'Error loading workers')
  } finally {
    state.loading = false
  }
}

/** SAVE */
async function submit() {
  try {
    await formRef.value.validate()
    validateRoleRows()
  } catch (e) {
    if (e?.message) ElMessage.error(e.message)
    return
  }

  try {
    state.saving = true
    const fd = new FormData()
    fd.append('nonce', parameters.nonce)

    if (state.editing) {
      fd.append('action', 'mhc_workers_update')
      fd.append('id', state.currentId)
    } else {
      fd.append('action', 'mhc_workers_create')
    }

    fd.append('first_name', form.first_name)
    fd.append('last_name', form.last_name)
    fd.append('is_active', form.is_active)
    fd.append('email', form.email)
    fd.append('company', form.company)


    if (form.supervisor_id != null && form.supervisor_id !== '') {
      fd.append('supervisor_id', String(form.supervisor_id))
    } else {
      // explicit empty to clear
      fd.append('supervisor_id', '')
    }

    const payloadRoles = form.worker_roles.map(r => ({
      role_id: r.role_id != null ? Number(r.role_id) : null,
      general_rate: r.general_rate != null ? Number(r.general_rate) : null,
    }))
    fd.append('worker_roles', JSON.stringify(payloadRoles))

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
    const msg =
      e?.response?.data?.data?.message ||
      e?.message ||
      'Error saving'
    if (
      msg.includes('Asigne otro trabajador a los pacientes antes de inactivarlo')
    ) {
      ElMessage.warning(msg)
    } else {
      console.error(e)
      ElMessage.error(msg)
    }
  } finally {
    state.saving = false
  }
}

/** DELETE */
async function remove(row) {
  try {
    const fd = new FormData()
    fd.append('action', 'mhc_workers_delete')
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
  fetchRoles()
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

.flex {
  display: flex;
}

.flex-wrap {
  flex-wrap: wrap;
}

.gap-1 {
  gap: 0.25rem;
}

.text-gray-400 {
  color: #a0aec0;
}
.d-none{
  display: none;
}

h2 {
  color: var(--el-text-color-primary);
}
</style>
