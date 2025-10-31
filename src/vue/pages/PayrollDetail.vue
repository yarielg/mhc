<template>
  <div class="wp-wrap">
    <!-- Breadcrumb -->
    <el-breadcrumb separator="›" class="mb-3">
      <el-breadcrumb-item @click="$router.push('/payrolls')" class="cursor">Payrolls</el-breadcrumb-item>
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
                {{ payroll.status || "—" }}
              </el-tag>
            </h3>
            <div class="text-gray-600">
              Period: <b>{{ formatWeekRange(payroll.start_date, payroll.end_date) }}</b>
              <span v-if="payroll.notes"> • {{ payroll.notes }}</span>
            </div>
          </div>

          <div class="flex gap-2">
            <el-button :loading="loading.seed" @click="reseedPatients" :disabled="!id">Re-seed clients</el-button>
            <el-button v-if="payroll.status !== 'finalized'" type="success" plain :loading="loading.finalize"
              @click="finalizePayroll">Finalize</el-button>
            <el-button v-else type="warning" plain :loading="loading.reopen" @click="reopenPayroll">Reopen</el-button>
          </div>
        </div>
      </template>

      <!-- Content layout -->
      <el-row :gutter="16">
        <!-- Left: Patients -->
        <el-col :xs="24" :md="8">
          <el-card shadow="never" class="h-full">
            <template #header>
              <div class="flex items-center justify-between">
                <div class="font-semibold">Clients</div>

                <el-segmented v-model="patientsFilter" :options="patientFilters" size="small" @change="loadPatients" />
              </div>
            </template>
            <el-input v-model="patientSearch" placeholder="Search client..." clearable size="small"
              @input="loadPatients" style="width: 200px">
              <template #prefix>
                <el-icon>
                  <Search />
                </el-icon>
              </template>
            </el-input>
            <el-select-v2 v-model="filters.worker_id" placeholder="Search worker…"  clearable size="small"
                          @change="onWorkerChange" @clear="onWorkerClear" filterable remote :remote-method="searchWorkers" :options="workersOptions" style="width: 200px" />
            <div class="mt-2">
              <el-table :data="patients" size="small" border highlight-current-row height="440"
                v-loading="loading.patients" @current-change="selectPatient">
                <el-table-column type="index" width="52" label="#" />
                <el-table-column prop="processed" label="Client Name" width="260">
                  <template #default="{ row }">
                    <p>{{ row.first_name }} {{ row.last_name }}</p>
                  </template>
                </el-table-column>
                <el-table-column prop="processed" label="Processed" width="110">
                  <template #default="{ row }">
                    <el-tag :type="is_processed(row.is_processed) ? 'success' : 'info'">
                      {{ is_processed(row.is_processed) ? "Yes" : "No" }}
                    </el-tag>
                  </template>
                </el-table-column>
              </el-table>
            </div>
            <div class="mt-2 text-xs text-gray-600">
              <span v-if="counts">Total: {{ counts.total ?? patients.length }}</span>
              <span v-if="counts && counts.pending !== undefined">
                • Pending: {{ counts.pending }}</span>
              <span v-if="counts && counts.processed !== undefined">
                • Processed: {{ counts.processed }}</span>
            </div>
          </el-card>
        </el-col>

        <!-- Right: Patient processing + Extras + Summary tabs -->
        <el-col :xs="24" :md="16">
          <el-card shadow="never">
            <el-tabs v-model="tabs.active" @tab-change="onTab">
              <el-tab-pane name="process" label="Process client">
                <div v-if="!selectedPatient" class="text-gray-600">
                  Select a client to start processing.
                </div>
                <template v-else>
                  <div class="flex items-center justify-between mb-2">
                    <div class="font-semibold">
                      {{ patientDisplayName(selectedPatient) }}
                      <el-tag class="ml-2" :type="is_processed(selectedPatient.is_processed)
                        ? 'success'
                        : 'info'
                        ">
                        {{
                          is_processed(selectedPatient.is_processed)
                            ? "Processed"
                            : "Pending"
                        }}
                      </el-tag>
                    </div>
                    <el-switch :model-value="is_processed(selectedPatient.is_processed)" active-text="Processed"
                      inactive-text="Pending" @change="toggleProcessed" />
                  </div>

                  <!-- Assigned workers for this patient in this payroll -->
                  <el-card shadow="never" class="mb-3">
                    <template #header>
                      <div class="flex items-center justify-between">
                        <div class="font-semibold">Assigned workers</div>
                        <el-button size="small" @click="modals.addWpr.visible = true"
                          :disabled="payroll.status === 'finalized'">
                          Add worker to this client
                        </el-button>
                      </div>
                    </template>

                    <el-table :data="patientWorkers" size="small" border v-loading="loading.patientWorkers"
                      empty-text="No workers assigned yet">
                      <el-table-column prop="worker_name" label="Worker" min-width="120" show-overflow-tooltip />
                      <el-table-column prop="role_code" label="Role" width="120" />

                      <!-- Rate (editable) -->
                      <el-table-column label="Rate" width="180" prop="effective_rate">
                        <template #default="{ row }">
                          <el-input-number
                              v-model="row.effective_rate"
                              :precision="2"
                              :step="0.25"
                              :min="0"
                              :controls="true"
                              class="w-full"
                              @change="(val) => rateSave(row, segments, val)"                              
                          />
                        </template>
                      </el-table-column>

                      <!-- NEW: inline hours entry -->
                      <el-table-column v-for="seg in segments" :key="'segcol-' + seg.id"
                        :label="formatWeekRange(seg.segment_start, seg.segment_end)" width="180">
                        <template #default="{ row }">
                          <el-input-number :model-value="segVal(row, seg)" @update:model-value="
                            (val) => setSegVal(row, seg, val)
                          " @change="(val) => onSegHoursChange(row, seg, val)" :min="0" :step="0.25" :precision="2"
                            size="small" :disabled="payroll.status === 'finalized'" />
                        </template>
                      </el-table-column>

                      <!-- Optional but useful -->
                      <el-table-column label="Total $" width="130">
                        <template #default="{ row }">
                          {{
                            money(
                              Number(wprHours[getWprId(row)] || 0) *
                              Number(row.effective_rate || 0)
                            )
                          }}
                        </template>
                      </el-table-column>

<!--                      <el-table-column label="Actions" width="80" fixed="right">
                        <template #default="{ row }">
                          <el-popconfirm
                              title="Delete this assigned worker?"
                              confirm-button-text="Delete"
                              cancel-button-text="Cancel"
                              confirm-button-type="danger"
                              @confirm="deleteAssignedWorked(row)"
                          >
                            <template #reference>
                              <el-button
                                  v-if="row.end_date !== null"
                                  size="small"
                                  type="danger"
                                  plain
                              >
                                <el-icon><Delete /></el-icon>
                              </el-button>
                            </template>
                          </el-popconfirm>
                        </template>
                      </el-table-column>-->
                    </el-table>

                    <div class="mt-2 text-sm text-gray-600" v-if="hoursTotals">
                      Client totals — Hours:
                      <b>{{ formatHours(hoursTotals.total_hours || 0) }}</b> •
                      Amount: <b>{{ money(hoursTotals.total_amount || 0) }}</b>
                    </div>
                  </el-card>
                </template>
              </el-tab-pane>

              <el-tab-pane name="extras" label="Additional Payments">
                <div class="mb-20 flex gap-2">
                  <!-- Worker filter (optional) -->
                  <el-select-v2 v-model="extrasFilter.worker" placeholder="Filter by worker (optional)"
                    style="width: 320px" filterable remote :remote-method="searchWorkers" :options="workersOptions"
                    clearable @change="loadExtras" />
                  <el-button @click="openAddExtra" type="primary" plain :disabled="payroll.status === 'finalized'">Add
                    additional
                    payment</el-button>
                </div>

                <el-table :data="extras" size="small" border v-loading="loading.extras"
                  empty-text="No additional payments">
                  <el-table-column prop="worker_name" label="Worker" min-width="160" show-overflow-tooltip />
                  <el-table-column prop="label" label="Rate" min-width="160" show-overflow-tooltip />
                  <el-table-column prop="amount" label="Amount" width="120">
                    <template #default="{ row }">
                      <div>
                        {{ money(row.amount) }}
                        <div v-if="row.code === 'pending_pos_hourly' || row.code === 'pending_neg_hourly'" style="font-size:11px; color:#006699; margin-top:2px;">
                          ({{ Number(row.hours || 0).toFixed(2) }} hrs × ${{ Number(row.hours_rate || 0).toFixed(2) }})
                        </div>                        
                      </div>
                    </template>
                  </el-table-column>
                  <el-table-column label="Applies To" min-width="140" show-overflow-tooltip>
                    <template #default="{ row }">
                      {{
                        row.patient_name || row.supervised_worker_name || "—"
                      }}
                    </template>
                  </el-table-column>
                  <el-table-column prop="notes" label="Notes" min-width="160" show-overflow-tooltip />

                  <el-table-column label="Actions" width="200" fixed="right">
                    <template #default="{ row }">
                      <el-button size="small" plain :disabled="payroll.status === 'finalized'" @click="editExtra(row)">
                        <el-icon>
                          <Edit />
                        </el-icon>
                      </el-button>
                      <el-popconfirm title="Delete this additional payment?" confirm-button-text="Delete"
                        cancel-button-text="Cancel" confirm-button-type="danger" @confirm="deleteExtra(row)">
                        <template #reference>
                          <el-button size="small" type="danger" plain
                            :disabled="payroll.status === 'finalized'"><el-icon>
                              <Delete />
                            </el-icon></el-button>
                        </template>
                      </el-popconfirm>
                    </template>
                  </el-table-column>
                </el-table>
              </el-tab-pane>

              <el-tab-pane name="summary" label="Workers summary">
                <div class="mb-2 text-sm text-gray-600">
                  Totals per worker (regular + additionals).




                  <el-select v-model="summaryOrder" size="small" style="width:140px; float: right; margin-left:8px; margin-right:4px;" @change="loadSummary">
                    <el-option label="Order: Name" value="name" />
                    <el-option label="Order: Company" value="company" />
                  </el-select>
                  <el-input v-model="workerSearch" placeholder="Search worker..." clearable size="small"
                    @input="loadSummary" style="width: 200px; float: right">
                    <template #prefix>
                      <el-icon>
                        <Search />
                      </el-icon>
                    </template>
                  </el-input>
                  <!-- Sync all checks with QuickBooks to add check numbers -->
                  <el-button
                    size="small"
                    type="primary"
                    title="Sync all checks with QuickBooks to get check numbers"
                    style="float: right; margin-right: 8px;"
                    :loading="syncingChecks"
                    @click="confirmSyncChecks"
                    >
                    <el-icon class="">
                      <Refresh />
                    </el-icon>
                  </el-button>
                  <!-- create all checks -->
                  <el-button
                    size="small"
                    type="primary"
                    title="Create checks for all workers without checks"
                    style="float: right; margin-right: 8px;"
                    :loading="creatingAllChecks"
                    @click="confirmCreateAllChecks"
                    >
                    <el-icon class="">
                      <Money />
                    </el-icon>
                  </el-button>
                  <el-button size="small" title="Download Sumary PDF" type="primary" style="float: right; margin-right: 8px;"
                    @click="downloadSummaryPdf">
                    <el-icon>
                      <Download />
                    </el-icon>
                  </el-button>
                  <el-button
                    title="Download all worker slips as PDF"
                   size="small" type="primary" style="float: right; margin-right: 8px;"
                    @click="downloadAllSlips">
                    <el-icon>
                      <Printer />
                    </el-icon>
                  </el-button>                  
                  <el-button
                    type="primary"
                    title="Send all slips by email to workers"
                    size="small"
                    class="send_all_slip"
                    style="margin-right: 8px;"
                    :loading="sendingAll"
                    @click="confirmSendAll"
                    >
                      Send all slips
                  </el-button>
                </div>

                

                <el-table :data="summary.items" size="small" border v-loading="loading.summary" empty-text="No data">
                  <el-table-column prop="worker_name" label="Worker" min-width="160" show-overflow-tooltip />
                  <el-table-column prop="company" label="Company" min-width="120" show-overflow-tooltip />
                  <el-table-column prop="hours_hours" label="Hours" width="80" />
                  <el-table-column prop="hours_amount" label="Regular $" width="120">
                    <template #default="{ row }">{{
                      money(row.hours_amount)
                    }}</template>
                  </el-table-column>
                  <el-table-column prop="extras_amount" label="Additionals $" width="120">
                    <template #default="{ row }">{{
                      money(row.extras_amount)
                    }}</template>
                  </el-table-column>
                  <el-table-column prop="grand_total" label="Total $" width="120">
                    <template #default="{ row }"><b>{{ money(row.grand_total) }}</b></template>
                  </el-table-column>
                  <el-table-column label="Slip" width="200" fixed="right">
                    <template #default="{ row }">
                      <div style="
                          display: flex;
                          flex-direction: row;
                          align-items: center;
                        ">
                        <el-button size="small" @click="openWorkerSlip(row)">
                          <el-icon>
                            <View />
                          </el-icon>
                        </el-button>
                        <el-button size="small" :loading="sendingSlip[row.worker_id]" @click.stop="sendWorkerSlip(row)">
                          <el-icon class="">
                            <Message />
                          </el-icon>
                        </el-button>
                        <el-button size="small" @click.stop="downloadWorkerSlip(row)">
                          <el-icon class="">
                            <Download />
                          </el-icon>
                        </el-button>
                        <!-- if(row.qb_check_id > 0) delete check (with row.qb_check_id and confirmation) else create worker check -->
                        <el-button :class="{ checkBackground: row.qb_check_id > 0, btcheck: Number(row.check_number) === 0 }" size="small" :loading="creatingCheck[row.worker_id]" @click.stop="row.qb_check_id > 0 ? deleteWorkerCheck(row) : createWorkerCheck(row)">
                          <!-- if(row.check_number > 0) check_number else icon -->
                          <span v-if="Number(row.check_number) > 0">{{ row.check_number }}</span>
                          <el-icon v-else class="">
                            <!-- if(row.qb_check_id > 0) add remove check icon -->
                            <Delete v-if="row.qb_check_id > 0" />
                            <Money />
                          </el-icon>
                        </el-button>
                      </div>
                    </template>
                  </el-table-column>
                </el-table>

                <div class="mt-2 text-sm">
                  <b>Payroll totals:</b>
                    <div>
                      Regular {{ money(summary.totals?.hours_amount || 0) }} •
                      Addtionals {{ money(summary.totals?.extras_amount || 0) }} •
                      Grand <b>{{ money(summary.totals?.grand_total || 0) }}</b>
                    </div>
                    <div>
                    <!--Add new totals-->
                    Total workers: <b>{{ summary.totals?.total_workers || 0 }}</b>
                    • With checks: <b>{{ summary.totals?.total_with_checks || 0 }}</b>
                    • Without checks: <b>{{ summary.totals?.total_without_checks || 0 }}</b>
                    • Without check numbers: <b>{{ summary.totals?.total_without_checks_number || 0 }}</b>
                    </div>
                  
                  
                </div>
              </el-tab-pane>
            </el-tabs>
          </el-card>
        </el-col>
      </el-row>
    </el-card>

    <!-- Add WPR (worker → patient) modal -->
    <el-dialog v-model="modals.addWpr.visible" title="Add worker to this client (temporary)" width="560px"
      destroy-on-close>
      <el-form :model="modals.addWpr.form" label-width="140px">
        <el-form-item label="Worker">
          <el-select-v2 v-model="modals.addWpr.form.worker_id" placeholder="Search worker…" style="width: 100%"
            filterable remote :remote-method="searchWorkers" :options="workersOptions" />
        </el-form-item>
        <el-form-item label="Role">
          <el-select v-model="modals.addWpr.form.role_id" placeholder="Select role…" filterable style="width: 100%"
            :loading="loading.roles" @visible-change="
              (v) => {
                if (v) loadRoles();
              }
            ">
            <el-option v-for="r in roles" :key="r.id" :label="`${r.code} — ${r.name}`" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="Override rate (optional)">
          <el-input-number v-model="modals.addWpr.form.rate" :min="0" :step="1" :precision="2" style="width: 100%" />
        </el-form-item>
        <div class="text-xs text-gray-600">
          This assignment only lives in this payroll. (We set the temp end date
          internally to the payroll’s end date.)
        </div>
      </el-form>
      <template #footer>
        <el-button @click="modals.addWpr.visible = false">Cancel</el-button>
        <el-button type="primary" :loading="loading.addWpr" @click="createWpr">Add</el-button>
      </template>
    </el-dialog>

    <!-- Add/Edit Extra modal -->
    <el-dialog v-model="modals.extra.visible"
      :title="modals.extra.editing ? 'Edit additional payment' : 'Add additional payment'" width="620px"
      destroy-on-close>
      <el-form :model="modals.extra.form" label-width="160px">
        <el-form-item label="Worker">
          <el-select v-model="modals.extra.form.worker_id" filterable remote clearable placeholder="Type a name..."
            :loading="loading.workers" :remote-method="searchWorkers" style="width: 100%">
            <el-option v-for="opt in workersOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>

        <el-form-item label="Special rate">
          <el-select-v2 v-model="modals.extra.form.special_rate_id" placeholder="Search special rates…"
            style="width: 100%" filterable remote :remote-method="searchRates" :options="ratesOptions"
            :disabled="payroll.status === 'finalized'" />
        </el-form-item>

        <el-form-item label="Amount">
          <el-input-number v-model="modals.extra.form.amount" :min="0" :step="1" :precision="2" style="width: 100%"
            :disabled="payroll.status === 'finalized' || amountLocked || isPendingHrsAdjustment" />
        </el-form-item>

        <!-- Show patient/client select only if initialassesment or reassesment special rate -->
        <el-form-item v-if="isAssessmentRate" label="Client (required)" required>
          <el-select v-model="modals.extra.form.patient_id" filterable remote clearable
            :multiple="!modals.extra.editing" placeholder="Type a name..." :loading="loading.patients"
            :remote-method="searchPatients" style="width: 100%">
            <el-option v-for="opt in patientsOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>

        <!-- Show supervised worker select only if supervision special rate -->
        <el-form-item v-if="isSupervisionRate" label="Supervised worker (required)" required>
          <el-select-v2 v-model="modals.extra.form.supervised_worker_id" placeholder="Search supervised worker…"
            style="width: 100%" filterable remote clearable :multiple="!modals.extra.editing"
            :remote-method="(q) => searchWorkers(q, 1)" :options="workersOptions"
            :disabled="payroll.status === 'finalized'" />
        </el-form-item>

        <!-- Show hours + rate inputs only if pending_pos_hourly or pending_neg_hourly special rate -->
        <el-form-item v-if="isPendingHrsAdjustment" label="Hours">
          <el-input-number v-model="modals.extra.form.hours" :min="0" :step="0.25" :precision="2" style="width: 100%"
            :disabled="payroll.status === 'finalized'" @change="onExtraHoursOrRateChange" />
        </el-form-item>
        <el-form-item v-if="isPendingHrsAdjustment" label="Hourly rate">
          <el-input-number v-model="modals.extra.form.hours_rate" :min="0" :step="1" :precision="2"
            style="width: 100%" :disabled="payroll.status === 'finalized'" @change="onExtraHoursOrRateChange" />
        </el-form-item>

        <el-form-item label="Notes">
          <el-input v-model="modals.extra.form.notes" type="textarea" :rows="2" maxlength="255" show-word-limit
            :disabled="payroll.status === 'finalized'" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="modals.extra.visible = false">Cancel</el-button>
        <el-button type="primary" :loading="loading.saveExtra" @click="saveExtra"
          :disabled="payroll.status === 'finalized'">
          {{ modals.extra.editing ? "Update" : "Create" }}
        </el-button>
      </template>
    </el-dialog>

    <!-- Worker slip modal -->
    <el-dialog v-model="modals.slip.visible"
      :title="`Worker slip — ${modals.slip.header.worker_name || ''} (${modals.slip.header.company || '-'})`"
      width="780px" destroy-on-close>
      <div v-loading="loading.slip">
        <el-divider content-position="left">Regular Payments</el-divider>
        <el-table :data="modals.slip.hours" size="small" border empty-text="No hours">
          <el-table-column prop="patient_name" label="Client" min-width="160" />
          <el-table-column prop="role_code" label="Role" width="100" />
          <el-table-column label="Week" width="160">
            <template #default="{ row }">
              {{ formatWeekRange(row.segment_start, row.segment_end) }}
            </template>
          </el-table-column>
          <el-table-column prop="hours" label="Hours" width="100" />
          <el-table-column prop="used_rate" label="Rate" width="110">
            <template #default="{ row }">{{ money(row.used_rate) }}</template>
          </el-table-column>
          <el-table-column prop="total" label="Total" width="120">
            <template #default="{ row }">{{ money(row.total) }}</template>
          </el-table-column>
        </el-table>

        <el-divider content-position="left" class="mt-3">Additional Payments</el-divider>
        <el-table :data="modals.slip.extras" size="small" border empty-text="No Additional Payments">
          <el-table-column prop="label" label="Label" min-width="180">
            <template #default="{ row }">
              {{ row.label }} / {{ row.cpt_code }}
            </template>
          </el-table-column>
          <el-table-column label="Applies To" min-width="140" show-overflow-tooltip>
            <template #default="{ row }">
              {{ row.patient_name || row.supervised_worker_name || "—" }}
            </template>
          </el-table-column>
          <el-table-column prop="amount" label="Amount" width="120">
            <template #default="{ row }">
              <div>
                {{ money(row.amount) }}
                <div v-if="row.code === 'pending_pos_hourly' || row.code === 'pending_neg_hourly'" style="font-size:11px; color:#006699; margin-top:2px;">
                  ({{ Number(row.hours || 0).toFixed(2) }} hrs × ${{ Number(row.hours_rate || 0).toFixed(2) }})
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="notes" label="Notes" min-width="180" show-overflow-tooltip />
        </el-table>
      </div>
      <div class="mt-2 text-sm" v-if="modals.slip.totals">
        <b>Totals:</b>
        Hours: <b>{{ formatHours(modals.slip.totals.total_hours) }}</b> •
        Regular: <b>{{ money(modals.slip.totals.hours_amount) }}</b> •
        Additionals: <b>{{ money(modals.slip.totals.extras_amount) }}</b> •
        Grand Total: <b>{{ money(modals.slip.totals.grand_total) }}</b>
      </div>
      <div class="mt-2 text-sm" v-if="modals.slip.header.qb_check_id !== 0 && modals.slip.header.qb_check_id !== null">
        <b>Check number:</b> {{ modals.slip.header.check_number || 'N/A' }} (QuickBooks ID: {{ modals.slip.header.qb_check_id || 'N/A' }})
      </div>
      <template #footer>
        <el-button @click="modals.slip.visible = false">Close</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, computed, watch, nextTick } from "vue";
import { formatWeekRange } from '../util/dateUtils'
import { ElMessage, ElMessageBox } from "element-plus";
import {
  User,
  Message,
  View,
  Delete,
  Edit,
  Download,
  Search,
  Printer,
  Money,
  Check,
  Document,
  Refresh
} from "@element-plus/icons-vue";

const sendingSlip = reactive({});
// Per-row flag used when creating a QuickBooks check for a worker
const creatingCheck = reactive({});

function downloadSummaryPdf() {
  const payrollId = typeof id !== "undefined" ? id : props?.id || null;
  if (!payrollId) return;
  const ajaxUrl = typeof parameters !== "undefined" && parameters?.ajax_url
    ? parameters.ajax_url
    : window.ajaxurl || "/wp-admin/admin-ajax.php";
  const url = new URL(ajaxUrl, window.location.origin);
  url.searchParams.set("action", "mhc_payroll_summary_pdf");
  url.searchParams.set("payroll_id", payrollId);
  if (typeof parameters !== "undefined" && parameters?.nonce) {
    url.searchParams.set("nonce", parameters.nonce);
  }
  window.open(url.toString(), "_blank");
}
function downloadAllSlips() {
  const payrollId = typeof id !== "undefined" ? id : props?.id || null;
  if (!payrollId) return;
  const ajaxUrl = typeof parameters !== "undefined" && parameters?.ajax_url
    ? parameters.ajax_url
    : window.ajaxurl || "/wp-admin/admin-ajax.php";
  const url = new URL(ajaxUrl, window.location.origin);
  url.searchParams.set("action", "mhc_all_slips_pdf");
  url.searchParams.set("payroll_id", payrollId);
  if (typeof parameters !== "undefined" && parameters?.nonce) {
    url.searchParams.set("nonce", parameters.nonce);
  }
  window.open(url.toString(), "_blank");
}



/* ======= WP ajax setup ======= */
const props = defineProps({ id: { type: Number, required: true } });
const AJAX_URL = parameters.ajax_url || "/wp-admin/admin-ajax.php";
const NONCE = parameters.nonce || ""; // your controller uses NONCE_ACTION 'mhc_ajax'

const wprHours = reactive({}); // { [wprId]: number }
const hoursEntryId = reactive({}); // { [wprId]: hours_entry.id } (if exists)

// Per-row UX flags
const wprSaving = reactive({}); // { [wprId]: true|false }
const wprSavedTick = reactive({}); // { [wprId]: timestamp }
const debouncers = {};

const patientSearch = ref("");
const workerSearch = ref("");
const summaryOrder = ref('name'); // 'name' or 'company'

const sendingAll = ref(false);
// Flag used when syncing check numbers from QuickBooks
const syncingChecks = ref(false);
// Flag used when creating all checks for a payroll
const creatingAllChecks = ref(false);

function getWprId(row) {
  return row.worker_patient_role_id || row.id || row.wpr_id;
}

/**
 * Friendly label for a patient including insurer info when present.
 * Example: "Hassie Lesch — Medicaid (0001234)"
 */
function patientDisplayName(p) {
  if (!p) return '';
  const name = p.patient_name || (p.first_name ? p.first_name + (p.last_name ? ' ' + p.last_name : '') : '');
  if (!p.insurer_name) return name;
  const num = p.insurer_number ? ' (' + p.insurer_number + ')' : '';
  return name + ' — ' + p.insurer_name + num;
}

function confirmSendAll() {
  ElMessageBox.confirm(
      'This will email a payment slip to every worker in this payroll who has hours or extra payments. Continue?',
      'Send all slips',
      { type: 'warning', confirmButtonText: 'Send', cancelButtonText: 'Cancel' }
  ).then(() => sendAllSlips())
      .catch(() => {});
}

// Confirm + trigger syncing of check numbers from QuickBooks
function confirmSyncChecks() {
  ElMessageBox.confirm(
    'This will contact QuickBooks and update check numbers for this payroll (if any). Continue?',
    'Sync checks with QuickBooks',
    { type: 'warning', confirmButtonText: 'Sync', cancelButtonText: 'Cancel' }
  )
    .then(() => syncChecks())
    .catch(() => {});
}

// Confirm + trigger creation of checks for all workers in this payroll
function confirmCreateAllChecks() {
  ElMessageBox.confirm(
    'This will create QuickBooks checks for every eligible worker in this payroll. This action cannot be easily undone. Continue?',
    'Create all checks',
    { type: 'warning', confirmButtonText: 'Create', cancelButtonText: 'Cancel' }
  )
    .then(() => createAllChecks())
    .catch(() => {});
}

async function createAllChecks() {
  const payrollId = typeof id !== 'undefined' ? id : props?.id || null;
  if (!payrollId) {
    ElMessage.error('Missing payroll id');
    return;
  }

  creatingAllChecks.value = true;
  try {
    // The backend handler expects payroll_id on GET for this action, so use ajaxGet
    const data = await ajaxGet('mhc_qb_create_all_checks', { payroll_id: payrollId });

    ElMessage.success(data?.message || 'Checks created');
    // Refresh summary to show created checks
    await loadSummary();
  } catch (err) {
    ElMessage.error(err?.message || 'Failed to create checks');
  } finally {
    creatingAllChecks.value = false;
  }
}

async function syncChecks() {
  const payrollId = typeof id !== 'undefined' ? id : props?.id || null;
  if (!payrollId) {
    ElMessage.error('Missing payroll id');
    return;
  }

  syncingChecks.value = true;
  try {
    const data = await ajaxPostForm('mhc_qb_sync_check_numbers', {
      payroll_id: String(payrollId),
    });

    ElMessage.success(data?.message || 'Checks synced successfully');
    // Refresh UI to show updated check numbers
    await loadSummary();
  } catch (err) {
    ElMessage.error(err?.message || 'Failed to sync checks');
  } finally {
    syncingChecks.value = false;
  }
}

async function sendAllSlips() {
  sendingAll.value = true;
  try {
    const form = new FormData();
    form.append('action', 'mhc_payroll_send_all_worker_slips');
    form.append('payroll_id', String(id.value || props.id)); // use your existing id reference
    form.append('nonce', NONCE); // adapt to your nonce variable

    const res = await fetch(AJAX_URL, { method: 'POST', body: form, credentials: 'same-origin' });
    const json = await res.json();

    if (!json?.success) {
      throw new Error(json?.data?.message || 'Failed sending slips.');
    }

    const { sent = 0, skipped = 0, message = '' } = json.data || {};
    ElMessage.success(message || `Sent: ${sent} • Skipped: ${skipped}`);
  } catch (err) {
    ElMessage.error(err.message || 'Error sending slips.');
  } finally {
    sendingAll.value = false;
  }
}

/* ====== Create QuickBooks check for a single worker ====== */
async function createWorkerCheck(row) {
  const workerId = row?.worker_id || row?.id || 0;
  const wprId = row?.worker_patient_role_id || row?.wpr_id || 0;
  const total = Number(row?.grand_total ?? row?.total ?? 0);

  if (!workerId || total <= 0) {
    ElMessage.error('Missing worker or amount to create check.');
    return;
  }

  // payroll id may be in reactive `id` or props
  const payrollId = id || props.id;
  const period_start = payroll.start_date || '';
  const period_end = payroll.end_date || '';
  const payroll_print_date = payroll.payroll_print_date || '';

  try {
    creatingCheck[workerId] = true;

    const data = await ajaxPostForm('mhc_qb_create_check', {
      worker_id: String(workerId),
      wpr_id: String(wprId),
      payroll_id: String(payrollId),
      total: String(total),
      period_start,
      period_end,
      payroll_print_date,
    });

    ElMessage.success(data?.message || 'Check created successfully');
    // Optionally reload summary / status after creation
    await loadSummary();
  } catch (err) {
    ElMessage.error(err?.message || err?.toString() || 'Failed creating check');
  } finally {
    delete creatingCheck[workerId];
  }
}

/* ====== Delete QuickBooks check for a single worker (with confirmation) ====== */
async function deleteWorkerCheck(row) {
  const workerId = row?.worker_id || row?.id || 0;
  const qbCheckId = row?.qb_check_id || row?.check_id || null;
  const payrollId = id || props.id;

  if (!qbCheckId) {
    ElMessage.error('No check id found for this worker.');
    return;
  }

  try {
    await ElMessageBox.confirm(
      `Are you sure you want to delete QuickBooks check ${qbCheckId} for ${row.worker_name || ''}?`,
      'Delete check',
      { type: 'warning', confirmButtonText: 'Delete', cancelButtonText: 'Cancel' }
    );

    creatingCheck[workerId] = true;

    const data = await ajaxPostForm('mhc_qb_delete_check', {
      qb_check_id: String(qbCheckId),
      payroll_id: String(payrollId),
      worker_id: String(workerId),
    });

    ElMessage.success(data?.message || 'Check deleted locally');
    // Refresh UI
    await loadSummary();
  } catch (err) {
    // If user cancelled, err === 'cancel' or an Error
    if (err === 'cancel' || (err && err.message && err.message.indexOf('cancel') !== -1)) {
      // user cancelled
    } else {
      ElMessage.error(err?.message || 'Failed to delete check');
    }
  } finally {
    delete creatingCheck[workerId];
  }
}

const segHours = reactive({});

const keySeg = (wprId, segId) => `${wprId}_${segId}`;

function segVal(row, seg) {
  const wprId = getWprId(row);
  return Number(segHours[wprId]?.[seg.id] ?? 0);
}

function setSegVal(row, seg, val) {
  const wprId = getWprId(row);
  if (!segHours[wprId]) segHours[wprId] = {};
  segHours[wprId][seg.id] = Number(val) || 0;
}

async function onSegHoursChange(row, seg, newVal) {
  if (payroll.status === "finalized") return;
  const wprId = getWprId(row);
  const key = keySeg(wprId, seg.id);
  const hours = Number(newVal ?? segHours[wprId]?.[seg.id] ?? 0);

  try {
    segSaving[key] = true;
    delete segSavedTick[key];

    const existingId = segEntryId[key]; // if you track existing entry IDs
    if (hours === 0 && existingId) {
      const res = await ajaxPostForm("mhc_payroll_hours_delete", {
        id: existingId,
      });
      hydrateFromHoursResponse(res);
      delete segEntryId[key];
    } else {
      const res = await ajaxPostForm("mhc_payroll_hours_upsert", {
        payroll_id: id,
        worker_patient_role_id: wprId,
        segment_id: seg.id,
        used_rate: seg.used_rate,
        hours,
      });
      hydrateFromHoursResponse(res);
    }

    segSavedTick[key] = true;
  } finally {
    delete segSaving[key];
    setTimeout(() => {
      delete segSavedTick[key];
    }, 1200);
  }
}

async function rateSave(row, segments, newVal) {
  if (payroll.status === "finalized") return;
  const wprId = getWprId(row);

  for (const seg of segments) {
    seg.used_rate = newVal;
    seg.segment_id = seg.id;
    seg.hours = segVal(row, seg);
  }

  try {
    const data = await ajaxGet("mhc_payroll_hours_bulk_upsert", {
      payroll_id: id,
      used_rate: newVal,
      worker_patient_role_id: wprId,
      segments: JSON.stringify(segments),
    });    
    hoursTotals.value = data?.totals || { total_hours: 0, total_amount: 0 };

  } catch (e) {
    ElMessage.error(e.message || "Failed to load patients");
  } finally {
    loading.patients = false;
  }
}

async function ajaxGet(action, params = {}) {
  const url = new URL(AJAX_URL, window.location.origin);
  url.searchParams.set("action", action);
  if (NONCE) url.searchParams.set("nonce", NONCE);
  Object.entries(params).forEach(
    ([k, v]) => v !== undefined && url.searchParams.set(k, v)
  );
  const res = await fetch(url.toString(), { credentials: "same-origin" });
  const json = await res.json();
  if (!json?.success) throw new Error(json?.data?.message || "Request failed");
  return json.data;
}
async function ajaxPostForm(action, body = {}) {
  const url = new URL(AJAX_URL, window.location.origin);
  url.searchParams.set("action", action);
  if (NONCE) url.searchParams.set("nonce", NONCE);
  const form = new FormData();
  Object.entries(body).forEach(
    ([k, v]) => v !== undefined && form.append(k, v)
  );
  const res = await fetch(url.toString(), {
    method: "POST",
    body: form,
    credentials: "same-origin",
  });
  const json = await res.json();
  if (!json?.success) throw new Error(json?.data?.message || "Request failed");
  return json.data;
}
async function ajaxPostJson(action, payload = {}) {
  const url = new URL(AJAX_URL, window.location.origin);
  url.searchParams.set("action", action);
  if (NONCE) url.searchParams.set("nonce", NONCE);
  const res = await fetch(url.toString(), {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  const json = await res.json();
  if (!json?.success) throw new Error(json?.data?.message || "Request failed");
  return json.data;
}

/* ======= State ======= */
const id = props.id;
const payroll = reactive({
  id,
  start_date: "",
  end_date: "",
  payroll_print_date: "",
  status: "",
  notes: "",
});

const loading = reactive({
  header: false,
  finalize: false,
  reopen: false,
  seed: false,
  workers: false,

  patients: false,
  patientWorkers: false,
  segments: false,
  hours: false,
  savingId: null,

  extras: false,
  saveExtra: false,

  roles: false,
  addWpr: false,

  summary: false,
  slip: false,
});

/* Patients list and filter */
const patients = ref([]);
const counts = ref(null);
const patientsFilter = ref("all");
const patientFilters = computed(() => [
  { label: `All${badge(counts.value?.total)}`, value: "all" },
  { label: `Pending${badge(counts.value?.pending)}`, value: "0" },
  { label: `Processed${badge(counts.value?.processed)}`, value: "1" },
]);

const filters = reactive({
  // keep your existing filters (e.g., search text)...
  worker_id: null, // <— NEW
});

function badge(n) {
  return typeof n === "number" ? ` (${n})` : "";
}

/* Selected patient and its data */
const selectedPatient = ref(null);
const patientWorkers = ref([]);
const segments = ref([]);
const hours = ref([]);
const hoursTotals = ref(null);
const segSaving = reactive({}); // { [`${wprId}_${segmentId}`]: true|false }
const segSavedTick = reactive({}); // { [`${wprId}_${segmentId}`]: true|false }
const segEntryId = reactive({}); // { [`${wprId}_${segmentId}`]: entryId }

/* Tabs */
const tabs = reactive({ active: "process" });

/* Extras */
const extras = ref([]);
const extrasFilter = reactive({ worker: null });

/* Summary */
const summary = reactive({ items: [], totals: null });

/* Modal state */
const modals = reactive({
  addWpr: {
    visible: false,
    form: { worker_id: null, role_id: null, rate: null },
  },
  extra: {
    visible: false,
    loading: false,
    editing: false,
    form: {
      id: null,
      worker_id: null,
      special_rate_id: null,
      amount: null,
      patient_id: null,
      supervised_worker_id: null,
      hours: null,
      hours_rate: null,
      notes: "",
    },
  },
  slip: {
    visible: false,
    hours: [],
    extras: [],
    totals: null,
    header: {},
  },
});

const onWorkerChange = () => {
  // If your table pulls from server, just trigger your existing loader
  loadPatients();
};

const onWorkerClear = () => {
  filters.worker_id = null;
  loadPatients();
};


/* Lookups for remote selects (workers, rates, roles) */
const workersOptions = ref([]); // [{value:id, label:name}]
const patientsOptions = ref([]); // [{value:id, label:name}]
const ratesOptions = ref([]);
const roles = ref([]);
/* ======= Special rate → Amount auto/lock ======= */
const selectedRate = computed(() => {
  const rid = Number(modals.extra.form?.special_rate_id || 0);
  return (
    (ratesOptions.value || []).find((r) => Number(r.value) === rid) || null
  );
});
const amountLocked = computed(() => {
  const r = selectedRate.value;
  return !!(r && Number(r.unit_rate || 0) !== 0);
});
const isAssessmentRate = computed(() => {
  const r = selectedRate.value;
  return r && (r.code === "initial_assessment" || r.code === "reassessment");
});

//add condition for pending adjustment hours + or -
const isPendingHrsAdjustment = computed(() => {
  const r = selectedRate.value;
  return r && (r.code === "pending_pos_hourly" || r.code === "pending_neg_hourly");
});

const isSupervisionRate = computed(() => {
  const r = selectedRate.value;
  return r && r.code === "supervision";
});
watch(
  () => modals.extra.form?.special_rate_id,
  (newVal, oldVal) => {
    const r = selectedRate.value;
    if (r && Number(r.unit_rate || 0) !== 0) {
      modals.extra.form.amount = Math.abs(Number(r.unit_rate) || 0); // force positive
    }
    // Only clear selects if switching rate, not on edit open
    if (!modals.extra.editing) {
      if (isSupervisionRate.value) {
        modals.extra.form.patient_id = null;
      } else if (isAssessmentRate.value) {
        modals.extra.form.supervised_worker_id = null;
      } else {
        modals.extra.form.patient_id = null;
        modals.extra.form.supervised_worker_id = null;
      }
    }
  }
);

/* ======= Utils ======= */
function fmtDate(d) {
  return d ? d.toString().slice(0, 10) : "—";
}
function money(n) {
  const v = Number(n || 0);
  return v.toLocaleString(undefined, { style: "currency", currency: "USD" });
}

function onExtraHoursOrRateChange() {
  // Only auto-calculate for pending_pos_hourly or pending_neg_hourly
  const r = selectedRate.value;
  if (!r) return;
  // Accept both possible typos in code (pending_pos_hourly, pending_neg_hourly, etc)
  const code = r.code || '';
  if (code === 'pending_pos_hourly' || code === 'pending_neg_hourly' || code === 'pending_pos_hours' || code === 'pending_neg_hourlys') {
    const f = modals.extra.form;
    const hrs = Number(f.hours) || 0;
    const rate = Number(f.hours_rate) || 0;
    f.amount = +(hrs * rate).toFixed(2);
  }
}

/* ======= Loaders ======= */
async function loadHeader() {
  loading.header = true;
  try {
    const data = await ajaxGet("mhc_payroll_get", { id });
    Object.assign(payroll, data || {});
  } catch (e) {
    ElMessage.error(e.message || "Failed to load payroll");
  } finally {
    loading.header = false;
  }
}

async function loadPatients() {
  selectedPatient.value = null;
  patientWorkers.value = [];
  hours.value = [];
  hoursTotals.value = null;

  loading.patients = true;
  try {
    const data = await ajaxGet("mhc_payroll_patients", {
      payroll_id: id,
      is_processed: patientsFilter.value,
      search: patientSearch.value,
      worker_id: filters.worker_id,
    });
    patients.value = data?.patients || [];
    counts.value = data?.counts || null;
  } catch (e) {
    ElMessage.error(e.message || "Failed to load patients");
  } finally {
    loading.patients = false;
  }
}

async function loadSegments() {
  segments.value = [];

  loading.segments = true;
  try {
    const data = await ajaxGet("mhc_segment_list", { payroll_id: id });
    segments.value = data;
  } catch (e) {
    ElMessage.error(e.message || "Failed to load segments");
  } finally {
    loading.segments = false;
  }
}

async function selectPatient(row) {
  selectedPatient.value = row || null;
  tabs.active = "process";

  if (!row) return;
  await loadPatientWorkers();
  await loadHours(); // this will fill wprHours + hoursEntryId + hoursTotals
  ensureDefaultHoursForAllWpr(); // set 0 for workers without rows yet
}

function ensureDefaultHoursForAllWpr() {
  // For any assigned worker lacking an hours row, default to 0 for editing
  for (const w of patientWorkers.value || []) {
    const wprId = getWprId(w);
    if (wprId && wprHours[wprId] === undefined) wprHours[wprId] = 0;
  }
}

function prefillSegHoursFromWorkers(items = []) {
  items.forEach((row) => {
    const wprId = getWprId(row);
    if (!wprId) return;

    // ensure bucket
    if (!segHours[wprId]) segHours[wprId] = {};

    // Accept any of these shapes:
    //   row.segments = [{ segment_id, hours, entry_id? }, ...]
    //   row.segment_hours = [{ segment_id, hours, entry_id? }, ...]
    //   row.hours_by_segment = [{ segment_id, hours, entry_id? }, ...]
    const list =
      row.segments || row.segment_hours || row.hours_by_segment || [];

    list.forEach((s) => {
      const segId = Number(s.segment_id ?? s.id ?? s.segmentId ?? 0);
      if (!segId) return;

      const hrs = Number(s.hours ?? s.value ?? s.qty ?? 0);
      segHours[wprId][segId] = hrs;

      const hid = Number(s.entry_id ?? s.hours_entry_id ?? s.entryId ?? 0);
      if (hid) segEntryId[keySeg(wprId, segId)] = hid;
    });
  });

  // Fill any missing segment cells with 0 so inputs are controlled
  ensureSegBucketsForRows(items);
}

function ensureSegBucketsForRows(rows = []) {
  rows.forEach((row) => {
    const wprId = getWprId(row);
    if (!wprId) return;
    if (!segHours[wprId]) segHours[wprId] = {};
    segments.value.forEach((seg) => {
      if (typeof segHours[wprId][seg.id] !== "number")
        segHours[wprId][seg.id] = 0;
    });
  });
}

async function loadPatientWorkers() {
  if (!selectedPatient.value) return;
  loading.patientWorkers = true;
  try {
    const res = await ajaxGet("mhc_payroll_patient_workers", {
      payroll_id: id,
      patient_id: selectedPatient.value.patient_id,
    });
    patientWorkers.value = res?.items || [];
    // ⬅️ NEW: prefill inputs with the hours your API returns
    prefillSegHoursFromWorkers(patientWorkers.value);
  } catch (e) {
    ElMessage.error(e.message || "Failed to load assigned workers");
  } finally {
    loading.patientWorkers = false;
  }
}

function onHoursInput(row) {
  // Debounce saves while typing
  const wprId = getWprId(row);
  if (!wprId) return;
  if (debouncers[wprId]) clearTimeout(debouncers[wprId]);
  debouncers[wprId] = setTimeout(() => saveHoursForRow(row), 600);
}

function onHoursChange(row) {
  // Safety net for browsers that don't fire 'input' the same
  saveHoursForRow(row);
}

async function saveHoursForRow(row) {
  if (payroll.status === "finalized" || !selectedPatient.value) return;
  const wprId = getWprId(row);
  if (!wprId) return;
  const newHours = Number(wprHours[wprId] || 0);

  // Prevent duplicate saves if value hasn't changed from last known server state
  // (Optional: you can keep a separate lastSaved map if you want)
  try {
    wprSaving[wprId] = true;

    // If hours becomes 0 and an entry exists, we can DELETE to keep DB clean;
    // otherwise, use upsert for everything (backend is idempotent).
    if (newHours === 0 && hoursEntryId[wprId]) {
      const res = await ajaxPostForm("mhc_payroll_hours_delete", {
        id: hoursEntryId[wprId],
      });
      // Refresh local maps from API response
      hydrateFromHoursResponse(res);
      delete hoursEntryId[wprId];
    } else {
      const res = await ajaxPostJson("mhc_payroll_hours_upsert", {
        payroll_id: id,
        worker_patient_role_id: wprId,
        hours: newHours,
        // used_rate omitted → backend resolves from WPR+payroll
      });
      hydrateFromHoursResponse(res);
    }

    // little "Saved" tick
    wprSavedTick[wprId] = Date.now();
    setTimeout(() => {
      delete wprSavedTick[wprId];
    }, 1500);
  } catch (e) {
    ElMessage.error(e.message || "Save failed");
  } finally {
    wprSaving[wprId] = false;
  }
}

function hydrateFromHoursResponse(res) {
  // res.items is the patient’s hours list; res.totals the patient totals
  const list = res?.items || [];
  hoursTotals.value = res?.totals || hoursTotals.value;

  // rebuild maps
  const seen = new Set();
  //set put wprHours[wprId] = 0 for all seen
  Object.keys(wprHours).forEach((k) => (wprHours[k] = 0));
  for (const r of list) {
    const wprId = Number(r.worker_patient_role_id);
    wprHours[wprId] += Number(r.hours || 0);
    hoursEntryId[wprId] = Number(r.id || 0) || undefined;
    seen.add(wprId);
  }
  // keep zero defaults for other assigned workers so inputs don’t go blank
  for (const w of patientWorkers.value || []) {
    const wprId = getWprId(w);
    if (!seen.has(wprId) && wprHours[wprId] === undefined) wprHours[wprId] = 0;
  }
}

function is_processed(processed) {
  return processed == 1;
}

async function loadHours() {
  if (!selectedPatient.value) return;
  loading.hours = true;
  try {
    const res = await ajaxGet("mhc_payroll_hours_list", {
      payroll_id: id,
      patient_id: selectedPatient.value.patient_id,
    });
    hoursTotals.value = res?.totals || { total_hours: 0, total_amount: 0 };
    // reset maps
    Object.keys(wprHours).forEach((k) => delete wprHours[k]);
    Object.keys(hoursEntryId).forEach((k) => delete hoursEntryId[k]);

    // map by WPR
    (res?.items || []).forEach((r) => {
      const wprId = Number(r.worker_patient_role_id);
      wprHours[wprId] = (Number(wprHours[wprId]) || 0) + (Number(r.hours) || 0);
      hoursEntryId[wprId] = Number(r.id || 0) || undefined;
    });
  } catch (e) {
    ElMessage.error(e.message || "Failed to load hours");
  } finally {
    loading.hours = false;
  }
}

function formatHours(v) {
  const n = Number.parseFloat(v);
  return Number.isFinite(n) ? n.toFixed(2) : "0.00";
}

async function reseedPatients() {
  loading.seed = true;
  try {
    const res = await ajaxPostForm("mhc_payroll_seed_patients", {
      payroll_id: id,
    });
    patients.value = res?.patients || [];
    counts.value = res?.counts || null;
    ElMessage.success(`Added ${res?.added || 0} new client(s)`);
  } catch (e) {
    ElMessage.error(e.message || "Re-seed failed");
  } finally {
    loading.seed = false;
  }
}

/* ======= Hours actions ======= */
async function saveHours(row) {
  if (!selectedPatient.value) return;
  loading.savingId = row.worker_patient_role_id;
  try {
    const payload = {
      payroll_id: id,
      worker_patient_role_id: row.worker_patient_role_id,
      hours: Number(row._edit_hours || 0),
      // used_rate: undefined → backend resolves if not provided
    };
    const res = await ajaxPostJson("mhc_payroll_hours_upsert", payload);
    // API returns updated items & totals for this patient
    const list = res?.items || [];
    list.forEach((r) => {
      r._edit_hours = r.hours;
    });
    hours.value = list;
    hoursTotals.value = res?.totals || hoursTotals.value;
    ElMessage.success("Saved");
  } catch (e) {
    ElMessage.error(e.message || "Save failed");
  } finally {
    loading.savingId = null;
  }
}
async function deleteHours(row) {
  if (!row?.id) return;
  loading.hours = true;
  try {
    const res = await ajaxPostForm("mhc_payroll_hours_delete", { id: row.id });
    const list = res?.items || [];
    list.forEach((r) => {
      r._edit_hours = r.hours;
    });
    hours.value = list;
    hoursTotals.value = res?.totals || hoursTotals.value;
    ElMessage.success("Deleted");
  } catch (e) {
    ElMessage.error(e.message || "Delete failed");
  } finally {
    loading.hours = false;
  }
}

/* ======= Patient processed toggle ======= */
async function toggleProcessed(val) {
  if (!selectedPatient.value) return;
  try {
    await ajaxPostForm("mhc_patient_payroll_set_processed", {
      payroll_id: id,
      patient_id: selectedPatient.value.patient_id,
      is_processed: val ? 1 : 0,
    });

    selectedPatient.value.is_processed = val ? 1 : 0;
    const selected_id = selectedPatient.value.patient_id;
    // refresh counts quietly
    await loadPatients();

    // reselect same patient row if still present
    const again = patients.value.find((p) => p.patient_id === selected_id);

    if (again) selectedPatient.value = again;
    await loadPatientWorkers();
  } catch (e) {
    ElMessage.error(e.message || "Update failed");
  }
}

/* ======= Add worker to patient (temporary WPR) ======= */
async function loadRoles() {
  if (roles.value.length) return;
  loading.roles = true;
  try {
    // Adjust action name if your roles list differs.
    const res = await ajaxPostForm("mhc_roles_list", { limit: 100, page: 1 }); // expects { items: [...] }
    roles.value = res?.items || [];
  } catch (e) {
    ElMessage.error(e.message || "Failed to load roles");
  } finally {
    loading.roles = false;
  }
}
async function createWpr() {
  if (!selectedPatient.value) return;
  const f = modals.addWpr.form;
  if (!f.worker_id || !f.role_id) {
    ElMessage.warning("Worker and role are required");
    return;
  }
  loading.addWpr = true;
  try {
    const payload = {
      payroll_id: id,
      patient_id: selectedPatient.value.patient_id,
      worker_id: Number(f.worker_id),
      role_id: Number(f.role_id),
      rate: f.rate !== null ? Number(f.rate) : undefined,
    };
    const res = await ajaxPostJson("mhc_payroll_patient_workers_add", payload);
    patientWorkers.value = res?.items || [];
    // After adding, refresh hours list so a new editable row appears
    await loadHours();
    modals.addWpr.visible = false;
    modals.addWpr.form = { worker_id: null, role_id: null, rate: null };
    ElMessage.success("Worker added for this payroll");
  } catch (e) {
    ElMessage.error(e.message || "Could not add worker");
  } finally {
    loading.addWpr = false;
  }
}

/* ======= Extras ======= */
async function loadExtras() {
  loading.extras = true;
  try {
    const res = await ajaxPostForm("mhc_payroll_extras_list", {
      payroll_id: id,
      worker_id: extrasFilter.worker || "",
    });
    extras.value = res?.items || [];
  } catch (e) {
    ElMessage.error(e.message || "Failed to load additional payments");
  } finally {
    loading.extras = false;
  }
}
function openAddExtra() {
  modals.extra.editing = false;
  modals.extra.form = {
    id: null,
    worker_id: null,
    worker_name: null,
    special_rate_id: null,
    amount: null,
    patient_id: selectedPatient.value?.patient_id || null,
    patient_name: selectedPatient.value?.patient_name || null,
    supervised_worker_id: null,
    notes: "",
  };
  patientsOptions.value = []; // Limpiar opciones de pacientes al agregar
  modals.extra.visible = true;
}
function editExtra(row) {
  modals.extra.editing = true;
  modals.extra.form = {
    id: row.id,
    worker_id: row.worker_id,
    worker_name: row.worker_name,
    special_rate_id: row.special_rate_id,
    amount: Math.abs(Number(row.amount || 0)),
    patient_id: row.patient_id || null,
    patient_name: row.patient_name || null,
    supervised_worker_id: row.supervised_worker_id || null,
    hours: row.hours || "",
    hours_rate: row.hours_rate || "",
    notes: row.notes || "",
  };
  // Pre-fill dropdowns caches
  if (row.worker_id) {
    let label = row.worker_name;
    workersOptions.value = [{ value: row.worker_id, label }];
  }
  if (row.patient_id) {
    let label = row.patient_name;
    patientsOptions.value = [{ value: row.patient_id, label }];
  }
  if (row.supervised_worker_id) {
    let label = row.supervised_worker_name;
    if (!label && row.supervised_first_name)
      label =
        row.supervised_first_name +
        (row.supervised_last_name ? " " + row.supervised_last_name : "");
    if (!label && row.supervised_last_name) label = row.supervised_last_name;
    if (!label) label = String(row.supervised_worker_id);
    if (
      !workersOptions.value.some((w) => w.value === row.supervised_worker_id)
    ) {
      workersOptions.value.push({ value: row.supervised_worker_id, label });
    }
  }
  if (row.special_rate_id && row.label) {
    ratesOptions.value = [
      {
        value: row.special_rate_id,
        label: `${row.code ? row.code : ""} — ${row.label}`,
        code: row.code || null,
        unit_rate: row.unit_rate || 0,
      },
    ];
  }
  // --- Force computed logic to run for selects visibility ---
  nextTick(() => {
    // Force watcher and computed to update
    modals.extra.form.special_rate_id = row.special_rate_id;
  });
  modals.extra.visible = true;
}
async function saveExtra() {
  const f = modals.extra.form;
  if (!f.worker_id || !f.special_rate_id || f.amount === null) {
    ElMessage.warning("Worker, special rate, and amount are required");
    return;
  }
  // Required validation for patient o supervised worker en add/edit
  if (
    isAssessmentRate.value &&
    (!f.patient_id ||
      (Array.isArray(f.patient_id) && f.patient_id.length === 0))
  ) {
    ElMessage.warning("Client is required for this rate");
    return;
  }
  if (
    isSupervisionRate.value &&
    (!f.supervised_worker_id ||
      (Array.isArray(f.supervised_worker_id) &&
        f.supervised_worker_id.length === 0))
  ) {
    ElMessage.warning("Supervised worker is required for this rate");
    return;
  }
  loading.saveExtra = true;
  try {
    if (modals.extra.editing) {
      await ajaxPostForm("mhc_payroll_extras_update", { ...f });
    } else {
      // Si es multiselect, guardar uno por uno
      let patientIds =
        isAssessmentRate.value && Array.isArray(f.patient_id)
          ? f.patient_id
          : [f.patient_id];
      let supervisedIds =
        isSupervisionRate.value && Array.isArray(f.supervised_worker_id)
          ? f.supervised_worker_id
          : [f.supervised_worker_id];
      // Si no aplica, usar array con un solo elemento
      if (!isAssessmentRate.value) patientIds = [null];
      if (!isSupervisionRate.value) supervisedIds = [null];

      // Si ambos son multiselect, hacer combinaciones
      for (const pid of patientIds) {
        for (const sid of supervisedIds) {
          await ajaxPostForm("mhc_payroll_extras_create", {
            payroll_id: id,
            worker_id: f.worker_id,
            special_rate_id: f.special_rate_id,
            amount: f.amount,
            patient_id: pid || "",
            supervised_worker_id: sid || "",
            hours: f.hours || "",
            hours_rate: f.hours_rate || "",
            notes: f.notes || "",
          });
        }
      }
    }
    modals.extra.visible = false;
    await loadExtras();
    ElMessage.success("Saved");
  } catch (e) {
    ElMessage.error(e.message || "Save failed");
  } finally {
    loading.saveExtra = false;
  }
}
async function deleteExtra(row) {
  try {
    await ajaxPostForm("mhc_payroll_extras_delete", { id: row.id });
    await loadExtras();
    ElMessage.success("Deleted");
  } catch (e) {
    ElMessage.error(e.message || "Delete failed");
  }
}

async function deleteAssignedWorked(row) {
  try {
    await ajaxPostForm("mhc_patients_delete_assignment", { id: row.id });
    await loadPatientWorkers()
    ElMessage.success("Deleted");
  } catch (e) {
    ElMessage.error(e.message || "Delete failed");
  }
}

/* ======= Special rates & workers lookups ======= */
async function searchRates(q) {
  try {
    const res = await ajaxPostForm("mhc_special_rates_list", { q });
    ratesOptions.value = (res?.items || []).map((i) => ({
      value: Number(i.id),
      label: `${i.label} ($${Number(i.unit_rate || 0).toFixed(2)})`,
      unit_rate: Number(Math.abs(i.unit_rate) || 0),
      code: i.code || null,
    }));
  } catch (_) { }
}
// Adjust this action name to your existing worker search endpoint.
async function searchWorkers(q, roleId = null) {
  loading.workers = true;
  try {
    // Example expected response shape: { items: [{ id, name }] } 
    const params = {
      search: q,
      limit: 20,
    };
    if (roleId) params.role_id = roleId;
    const res = await ajaxPostForm("mhc_workers_list", params);
    workersOptions.value = (res?.items || []).map((w) => ({
      value: w.id,
      label: w.first_name + " " + w.last_name,
    }));
  } catch (_) { }
  loading.workers = false;
}

async function searchPatients(q) {
  try {
    loading.patients = true;
    // Example expected response shape: { items: [{ id, name }] }
    const res = await ajaxPostForm("mhc_patients_list", {
      search: q,
      limit: 20,
    }); // <-- change if needed
    patientsOptions.value = (res?.items || []).map((p) => ({
      value: p.id,
      label: p.first_name + " " + p.last_name,
    }));
  } catch (_) { }
  loading.patients = false;
}

/* ======= Summary & slip ======= */
async function loadSummary() {
  loading.summary = true;
  try {
    const res = await ajaxPostForm("mhc_payroll_workers", {
      payroll_id: id,
      search: workerSearch.value,
      orderby: summaryOrder.value,
    });
    summary.items = res?.items || [];
    summary.totals = res?.totals || null;
  } catch (e) {
    ElMessage.error(e.message || "Failed to load summary");
  } finally {
    loading.summary = false;
  }
}
async function openWorkerSlip(row) {
  modals.slip.visible = true;
  loading.slip = true;
  try {
    const res = await ajaxPostForm("mhc_payroll_worker_detail", {
      payroll_id: id,
      worker_id: row.worker_id,
    });
    modals.slip.header = res?.worker || {};
    modals.slip.hours = res?.hours || [];
    modals.slip.extras = res?.extras || [];
    modals.slip.totals = res?.totals || null;
  } catch (e) {
    ElMessage.error(e.message || "Failed to load slip");
  } finally {
    loading.slip = false;
  }
}

/* ======= Header actions ======= */
async function finalizePayroll() {
  loading.finalize = true;
  try {
    await ElMessageBox.confirm(
      "Finalize this payroll? After finalizing you cannot edit Regular/Additional Payments.",
      "Finalize",
      { type: "warning" }
    );
    await ajaxPostForm("mhc_payroll_finalize", { id });
    payroll.status = "finalized";
    ElMessage.success("Payroll finalized");
  } catch (e) {
    if (e !== "cancel") ElMessage.error(e.message || "Finalize failed");
  } finally {
    loading.finalize = false;
  }
}
async function reopenPayroll() {
  loading.reopen = true;
  try {
    await ElMessageBox.confirm("Reopen this payroll?", "Reopen", {
      type: "warning",
    });
    await ajaxPostForm("mhc_payroll_reopen", { id });
    payroll.status = "draft";
    ElMessage.success("Payroll reopened");
  } catch (e) {
    if (e !== "cancel") ElMessage.error(e.message || "Reopen failed");
  } finally {
    loading.reopen = false;
  }
}

/* ======= Tab hook ======= */
async function onTab(name) {
  if (name === "extras") await loadExtras();
  if (name === "summary") await loadSummary();
}

/* ======= Init ======= */
onMounted(async () => {
  await loadHeader();
  await loadPatients();
  await loadSegments();
});

async function sendWorkerSlip(row) {
  const payrollId = typeof id !== "undefined" ? id : props?.id || null;
  if (!payrollId || !row?.worker_id) return;

  const wid = row.worker_id;
  sendingSlip[wid] = true;

  try {
    const ajaxUrl =
      typeof parameters !== "undefined" && parameters?.ajax_url
        ? parameters.ajax_url
        : window.ajaxurl || "/wp-admin/admin-ajax.php";

    const form = new FormData();
    form.append("action", "mhc_payroll_send_worker_slip"); // maps to controller's ajax_send_worker_slip
    form.append("payroll_id", payrollId);
    form.append("worker_id", wid);
    if (typeof parameters !== "undefined" && parameters?.nonce) {
      form.append("nonce", parameters.nonce);
    }

    const res = await fetch(ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form,
    });
    const json = await res.json();

    if (!json?.success) throw new Error(json?.data?.message || "Send failed");
    ElMessage.success("Slip emailed successfully");
  } catch (e) {
    ElMessage.error(e?.message || "Failed to send slip");
  } finally {
    sendingSlip[wid] = false;
  }
}

function downloadWorkerSlip(row) {
  const payrollId = typeof id !== "undefined" ? id : props?.id || null;
  if (!payrollId || !row?.worker_id) return;

  const ajaxUrl =
    typeof parameters !== "undefined" && parameters?.ajax_url
      ? parameters.ajax_url
      : window.ajaxurl || "/wp-admin/admin-ajax.php";

  const url = new URL(ajaxUrl, window.location.origin);
  url.searchParams.set("action", "mhc_worker_slip_pdf");
  url.searchParams.set("payroll_id", payrollId);
  url.searchParams.set("worker_id", row.worker_id);
  if (typeof parameters !== "undefined" && parameters?.nonce) {
    url.searchParams.set("nonce", parameters.nonce);
  }

  window.open(url.toString(), "_blank");
}
</script>

<style scoped>
.wp-wrap {
  padding: 12px;
}

.mb-3 {
  margin-bottom: 0.75rem;
}

.mt-2 {
  margin-top: 0.5rem;
}

.flex {
  display: flex;
}

.items-center {
  align-items: center;
}

.justify-between {
  justify-content: space-between;
}

.text-lg {
  font-size: 1.125rem;
}

.font-semibold {
  font-weight: 600;
}

.text-gray-600 {
  color: #6b7280;
}

.text-gray-700 {
  color: #374151;
}

.cursor {
  cursor: pointer;
}

.gap-2 {
  gap: 0.5rem;
}

.h-full {
  height: 100%;
}
.checkBackground {
  /* Match Element Plus plain success button appearance */
  color: var(--el-color-success, #67c23a) !important;
  background-color: transparent !important;
  border-color: var(--el-color-success, #67c23a) !important;
}
.checkBackground:hover {
  /* On hover show danger (like el-button--danger is-plain) */
  color: var(--el-color-danger, #f56c6c) !important;
  background-color: transparent !important;
  border-color: var(--el-color-danger, #f56c6c) !important;
}
.btcheck {
  padding: 0%;
}
.btcheck i {
  width: 2rem;
  height: 2rem;
}
.ml-2 {
  margin-left: 0.5rem;
}

.send_all_slip{
  float: right;
}
</style>
