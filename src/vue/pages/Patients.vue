<template>
  <div class="wp-wrap">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-semibold">Patients</h2>

    </div>

    <el-row :gutter="20">
      <el-col :span="20"><div class="grid-content ep-bg-purple" />
        <el-button type="primary" @click="openCreate">Add Patient</el-button>
      </el-col>
      <el-col :span="4"><div class="grid-content ep-bg-purple" />
        <div class="mb-3">
          <el-input
              v-model="state.search"
              placeholder="Search by name..."
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
        style="width: 100%"
        size="small"
        empty-text="No patients found"
    >
      <el-table-column prop="id" label="ID" width="70"/>
      <el-table-column prop="first_name" label="First Name"/>
      <el-table-column prop="last_name" label="Last Name"/>
      <el-table-column prop="is_active" label="Is Active?" width="110">
        <template #default="{ row }">
          <el-tag :type="row.is_active == 1 ? 'success' : 'info'">{{ row.is_active == 1  ? 'Active' : 'Inactive'}}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="Actions" width="180" fixed="right">
        <template #default="{ row }">
          <el-button size="small" @click="openEdit(row)">Edit</el-button>
          <el-popconfirm
              title="Delete this patient?"
              confirm-button-text="Yes"
              cancel-button-text="No"
              @confirm="remove(row)"
          >
            <template #reference>
              <el-button size="small" type="danger">Delete</el-button>
            </template>
          </el-popconfirm>
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

    <el-dialog
        :title="state.editing ? 'Edit Patient' : 'Add Patient'"
        v-model="state.showDialog"
        width="520px"
        :close-on-click-modal="false"
    >
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
import {ElMessage} from "element-plus";

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
  is_active: '1'
})

const rules = {
  first_name: [{ required: true, message: 'First name is required', trigger: 'blur' }],
  last_name: [{ required: true, message: 'Last name is required', trigger: 'blur' }],
}

function resetForm() {
  form.first_name = ''
  form.last_name = ''
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
  form.first_name = row.first_name
  form.last_name = row.last_name
  form.is_active = row.is_active || 'active'
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
      fd.append('action', 'mhc_patients_update')
      fd.append('id', state.currentId)
    } else {
      fd.append('action', 'mhc_patients_create')
    }

    fd.append('first_name', form.first_name)
    fd.append('last_name', form.last_name)
    fd.append('is_active', form.is_active)

    const { data } = await axios.post(parameters.ajax_url, fd)
    if (!data.success) throw new Error(data.data?.message || 'Save failed')

    // Update table optimistically
    const item = data.data.item
    if (state.editing) {
      const idx = state.items.findIndex(r => r.id === item.id)
      if (idx >= 0) state.items.splice(idx, 1, item)
    } else {
      // Prepend; or you can refetch the current page
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
.wp-wrap { padding: 0.5rem; }
.el-pagination{
  margin-top: 20px;
}
</style>
