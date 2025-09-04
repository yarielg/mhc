<template>
  <div class="wp-wrap">
    <h2 class="text-xl font-semibold mb-4">Settings</h2>
    <el-card>
      <div class="mb-4">
        <el-form label-width="160px">
          <el-form-item label="Week Start Day">
            <el-select
              class="mb4"
              v-model="weekStartDay"
              placeholder="Select day"
              style="width: 200px"
            >
              <el-option
                v-for="d in days"
                :key="d"
                :label="capitalize(d)"
                :value="d"
              />
            </el-select>
              <div>
                <el-button class="ml0" @click="resetDay">Reset</el-button>
              <el-button
                type="primary"
                class="ml2"
                :loading="saving"
                @click="saveDay"
                >Save</el-button
              >
            </div>
          </el-form-item>
        </el-form>
      </div>
      <el-alert
        v-if="message"
        :type="messageType"
        :closable="false"
        show-icon
        >{{ message }}</el-alert
      >
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import axios from "axios";
import { ElMessage } from "element-plus";

const weekStartDay = ref("monday");
const days = [
  "monday",
  "tuesday",
  "wednesday",
  "thursday",
  "friday",
  "saturday",
  "sunday",
];
const saving = ref(false);
const message = ref("");
const messageType = ref("success");

function capitalize(str) {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

async function fetchDay() {
  try {
    const fd = new FormData();
    fd.append("action", "mhc_get_week_start_day");
    fd.append("nonce", parameters.nonce);
    const { data } = await axios.post(parameters.ajax_url, fd);
    if (data.success) {
      weekStartDay.value = data.data.week_start_day || "monday";
    }
  } catch (e) {
    ElMessage.error("Failed to load week start day");
  }
}

async function saveDay() {
  saving.value = true;
  message.value = "";
  try {
    const fd = new FormData();
    fd.append("action", "mhc_set_week_start_day");
    fd.append("nonce", parameters.nonce);
    fd.append("week_start_day", weekStartDay.value);
    const { data } = await axios.post(parameters.ajax_url, fd);
    if (data.success) {
      message.value = "Saved successfully.";
      messageType.value = "success";
    } else {
      message.value = data.data?.message || "Error saving.";
      messageType.value = "error";
    }
  } catch (e) {
    message.value = "Error saving.";
    messageType.value = "error";
  } finally {
    saving.value = false;
  }
}

async function resetDay() {
  saving.value = true;
  message.value = "";
  try {
    const fd = new FormData();
    fd.append("action", "mhc_reset_week_start_day");
    fd.append("nonce", parameters.nonce);
    const { data } = await axios.post(parameters.ajax_url, fd);
    if (data.success) {
      weekStartDay.value = "monday";
      message.value = "Reset to Monday.";
      messageType.value = "success";
    } else {
      message.value = data.data?.message || "Error resetting.";
      messageType.value = "error";
    }
  } catch (e) {
    message.value = "Error resetting.";
    messageType.value = "error";
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  fetchDay();
});
</script>

<style scoped>
.mb4 {
  margin-bottom: 1rem;
}
.ml2 {
  margin-left: 0.5rem;
}
.ml0 {
  margin-left: 0;
}
.wp-wrap {
  padding: 0.5rem;
}
.el-card {
  max-width: 500px;
  margin: 0 auto;
}
</style>
