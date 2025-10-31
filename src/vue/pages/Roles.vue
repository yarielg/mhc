<template>
  <div class="wp-wrap">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Roles</h2>
    </div>

    <div class="mb-20">
      <el-row :gutter="20">
        <el-col :span="16">
          <el-button type="primary" @click="openCreate">Add Role</el-button>
        </el-col>
        <el-col :span="8">
          <div class="mb-3">
            <el-input v-model="state.search" placeholder="Search by name/code..." clearable @clear="fetchData(1)"
              @keyup.enter="fetchData(1)">
              <template #append>
                <el-button @click="fetchData(1)">Search</el-button>
              </template>
            </el-input>
          </div>
        </el-col>
      </el-row>
    </div>

    <el-table :data="state.items" v-loading="state.loading" border style="width:100%" size="small"
      empty-text="No roles found">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="code" label="Code" width="160" />
      <el-table-column prop="name" label="Name" width="220" />
      <el-table-column prop="description" label="Description" min-width="220" />
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
      <el-pagination background layout="prev, pager, next, jumper, ->, total" :total="state.total"
        :page-size="state.per_page" :current-page="state.page" @current-change="fetchData" />
    </div>

    <!-- Dialog: Add/Edit Role -->
    <el-dialog :title="state.editing ? 'Edit Role' : 'Add Role'" v-model="state.showDialog" width="600px"
      :close-on-click-modal="false">
      <el-form :model="form" :rules="rules" ref="formRef" label-width="120px">
        <el-form-item label="Code" prop="code">
          <el-input v-model="form.code" />
        </el-form-item>
        <el-form-item label="Name" prop="name">
          <el-input v-model="form.name" />
        </el-form-item>
        <el-form-item label="Description">
          <el-input v-model="form.description" type="textarea" />
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
  name: '',
  description: '',
  is_active: '1',
})

const rules = {
  code: [{ required: true, message: 'Code is required', trigger: 'blur' }],
  name: [{ required: true, message: 'Name is required', trigger: 'blur' }],
}

function resetForm() {
  form.code = ''
  form.name = ''
  form.description = ''
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
  form.name = row.name || ''
  form.description = row.description || ''
  form.is_active = String(row.is_active ?? '1')
  state.showDialog = true
}

async function fetchData(page = state.page) {
  try {
    state.loading = true
    state.page = page
    const fd = new FormData()
    fd.append('action', 'mhc_roles_list')
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
    ElMessage.error(e.message || 'Error loading roles')
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
      fd.append('action', 'mhc_roles_update')
      fd.append('id', state.currentId)
    } else {
      fd.append('action', 'mhc_roles_create')
    }

    fd.append('code', form.code)
    fd.append('name', form.name)
    fd.append('description', form.description)
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
    fd.append('action', 'mhc_roles_delete')
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

h2 {
  color: var(--el-text-color-primary);
}

</style>
