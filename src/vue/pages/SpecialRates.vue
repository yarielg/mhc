<template>
  <div class="wp-wrap">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Special Rates</h2>
    </div>

    <el-row :gutter="20">
      <el-col :span="16">
        <el-button type="primary" @click="openCreate">Add Special Rate</el-button>
      </el-col>
      <el-col :span="8">
        <div class="mb-3">
          <el-input
                v-model="state.search"
                placeholder="Search by code, label, or CPT code..."
              clearable
              @clear="fetchData(1)"
              @keyup.enter.native="fetchData(1)"
          >
            <template #append>
              <el-button @click="fetchData(1)">Search</el-button>
            </template>
          </el-input>
        </div>
      </el-col>
    </el-row>

    <el-table
        :data="state.items"
        v-loading="state.loading"
        border
        style="width:100%"
        size="small"
        empty-text="No special rates found"
    >
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="code" label="Code" width="120" />
      <el-table-column prop="label" label="Label" width="180" />
      <el-table-column prop="cpt_code" label="CPT Code" width="120" />
      <el-table-column prop="unit_rate" label="Unit Rate" width="120" />
      <el-table-column prop="is_active" label="Active" width="110">
        <template #default="{ row }">
          <el-tag :type="String(row.is_active) === '1' ? 'success' : 'info'">
            {{ String(row.is_active) === '1' ? 'Active' : 'Inactive' }}
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
      <el-pagination
          background
          layout="prev, pager, next, jumper, ->, total"
          :total="state.total"
          :page-size="state.per_page"
          :current-page="state.page"
          @current-change="fetchData"
      />
    </div>

    <!-- Dialog: Add/Edit Special Rate -->
    <el-dialog
        :title="state.editing ? 'Edit Special Rate' : 'Add Special Rate'"
        v-model="state.showDialog"
        width="700px"
        :close-on-click-modal="false"
    >
      <el-form :model="form" :rules="rules" ref="formRef" label-width="140px">
        <el-form-item label="Code" prop="code">
          <el-input v-model="form.code" />
        </el-form-item>
        <el-form-item label="Label" prop="label">
          <el-input v-model="form.label" />
        </el-form-item>
        <el-form-item label="CPT Code" prop="cpt_code">
          <el-input v-model="form.cpt_code" />
        </el-form-item>
        <el-form-item label="Unit Rate" prop="unit_rate">
          <el-input-number v-model="form.unit_rate" :min="0" :step="0.5" :precision="2" style="width:100%;" />
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
  code: '',
  label: '',
  cpt_code: '',
  unit_rate: null,
  is_active: '1',
})

const rules = {
  code: [{ required: true, message: 'Code is required', trigger: 'blur' }],
  label: [{ required: true, message: 'Label is required', trigger: 'blur' }],
  unit_rate: [{ required: true, message: 'Unit Rate is required', trigger: 'blur' }],
}

function resetForm() {
  form.code = ''
  form.label = ''
  form.cpt_code = ''
  form.unit_rate = null
  form.is_active = '1'
  state.currentId = null
  state.editing = false
}

function openCreate() {
  resetForm()
  state.showDialog = true
}

function openEdit(row) {
  state.editing = true
  state.currentId = row.id
  form.code = row.code || ''
  form.label = row.label || ''
  form.cpt_code = row.cpt_code || ''
  form.unit_rate = row.unit_rate != null ? Number(row.unit_rate) : null
  form.is_active = String(row.is_active ?? '1')
  state.showDialog = true
}

async function fetchData(page = state.page) {
  try {
    state.loading = true
    state.page = page
    const fd = new FormData()
  fd.append('action', 'mhc_special_rates_list')
    fd.append('nonce', parameters.nonce)
    fd.append('page', state.page)
    fd.append('per_page', state.per_page)
    if (state.search) fd.append('search', state.search)

    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Failed to load')
    state.items = data.data.items || []
    state.total = Number(data.data.total || 0)
  } catch (e) {
    console.error(e)
    ElMessage.error(e.message || 'Error loading special rates')
  } finally {
    state.loading = false
  }
}


async function submit() {
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  try {
    state.saving = true
    const fd = new FormData()
    fd.append('nonce', parameters.nonce)

    if (state.editing) {
      fd.append('action', 'mhc_special_rates_update')
      fd.append('id', state.currentId)
    } else {
      fd.append('action', 'mhc_special_rates_create')
    }

    fd.append('code', form.code)
    fd.append('label', form.label)
    fd.append('cpt_code', form.cpt_code)
    fd.append('unit_rate', form.unit_rate)
    fd.append('is_active', form.is_active)

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
  fd.append('action', 'mhc_special_rates_delete')
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
.wp-wrap { padding: 0.5rem; }
.el-pagination { margin-top: 20px; }
</style>
