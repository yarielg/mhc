<template>
  <div class="mhc-app">
    <el-container class="mhc-layout">

      <!-- HEADER -->
      <el-header class="mhc-header">
        <div class="mhc-left">
          <el-button link class="mhc-hamburger" @click="toggleAside" :icon="Menu" />
          <img style="width: 100px" :src="img_url + 'mentalhelt.png'" alt="Agency of Mental Health Services" />
        </div>

        <div class="mhc-right">
          <!-- 🌗 Theme toggle -->
          <el-tooltip :content="dark ? 'Switch to light' : 'Switch to dark'" placement="bottom">
            <el-button
                circle
                text
                :icon="dark ? Sunny : Moon"
                @click="toggleTheme"
                aria-label="Toggle color theme"
            />
          </el-tooltip>

          <el-dropdown trigger="click">
      <span class="el-dropdown-link">
        {{ userName }}
        <el-icon><ArrowDown /></el-icon>
      </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item @click="go('/settings')">Settings</el-dropdown-item>
                <el-dropdown-item divided @click="logout">Log out</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <el-container>
        <!-- ASIDE (desktop) -->
        <el-aside :width="collapsed ? '64px' : '220px'" class="mhc-aside">
          <el-menu :default-active="route.path" :collapse="collapsed" router>
            <el-menu-item index="/">
              <el-icon><House /></el-icon><span>Dashboard</span>
            </el-menu-item>
            <el-sub-menu index="/payrolls">
              <template #title>
                <el-icon><Wallet /></el-icon><span>Payrolls</span>
              </template>
              <el-menu-item index="/payrolls">All Payrolls</el-menu-item>
            </el-sub-menu>
            <el-menu-item index="/workers">
              <el-icon><User /></el-icon><span>Workers</span>
            </el-menu-item>
            <el-menu-item index="/patients">
              <el-icon><UserFilled /></el-icon><span>Patients</span>
            </el-menu-item>

            <el-sub-menu index="/settings">
              <template #title>
                <el-icon><Setting /></el-icon><span>Settings</span>
              </template>
              <el-menu-item index="/roles">Roles</el-menu-item>
              <el-menu-item index="/special-rates">Special Rates</el-menu-item>
            </el-sub-menu>
          </el-menu>
          <div class="mhc-collapse">
            <el-button text size="small" @click="collapsed = !collapsed">
              <el-icon><Fold v-if="!collapsed" /><Expand v-else /></el-icon>
              <span v-if="!collapsed" style="margin-left:6px">Collapse</span>
            </el-button>
          </div>
        </el-aside>

        <!-- MAIN -->
        <el-main class="mhc-main">
          <router-view />

        </el-main>
      </el-container>

      <!-- MOBILE DRAWER -->
      <el-drawer v-model="drawer" size="75%" direction="ltr" :with-header="false" class="mhc-drawer">
        <el-menu :default-active="route.path" router @select="closeDrawer">
          <el-menu-item index="/"><el-icon><House /></el-icon><span>Dashboard</span></el-menu-item>
          <el-sub-menu index="/payrolls">
            <template #title><el-icon><Wallet /></el-icon><span>Payrolls</span></template>
            <el-menu-item index="/payrolls">All Payrolls</el-menu-item>
          </el-sub-menu>
          <el-menu-item index="/workers"><el-icon><User /></el-icon><span>Workers</span></el-menu-item>
          <el-menu-item index="/patients"><el-icon><UserFilled /></el-icon><span>Patients</span></el-menu-item>

          <el-sub-menu index="/settings">
            <template #title><el-icon><Setting /></el-icon><span>Settings</span></template>
            <el-menu-item index="/roles">Roles</el-menu-item>
            <el-menu-item index="/special-rates">Special Rates</el-menu-item>
          </el-sub-menu>
        </el-menu>
      </el-drawer>
    </el-container>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowDown, House, User, UserFilled, Wallet, Setting, Menu, Fold, Expand,
  Moon, Sunny
} from '@element-plus/icons-vue'

const route = useRoute()
const router = useRouter()
const img_url = (window as any).parameters?.img_url || ''

// Layout state
const collapsed = ref(false)
const drawer = ref(false)

// 🌗 Theme state
const dark = ref(false)
const THEME_KEY = 'mhc-theme'

function applyTheme(isDark: boolean) {
  const el = document.documentElement
  if (isDark) {
    el.classList.add('dark')
    localStorage.setItem(THEME_KEY, 'dark')
  } else {
    el.classList.remove('dark')
    localStorage.setItem(THEME_KEY, 'light')
  }
}

function toggleTheme() {
  dark.value = !dark.value
}

onMounted(() => {
  // Restore saved theme or fall back to system preference
  const saved = localStorage.getItem(THEME_KEY)
  if (saved === 'dark' || saved === 'light') {
    dark.value = saved === 'dark'
  } else {
    dark.value = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
  }
  applyTheme(dark.value)

  // Optional: keep in sync if system theme changes and user hasn’t chosen yet
  if (!saved && window.matchMedia) {
    const mql = window.matchMedia('(prefers-color-scheme: dark)')
    const listener = (e: MediaQueryListEvent) => {
      dark.value = e.matches
    }
    mql.addEventListener?.('change', listener)
  }
})

// Apply whenever user toggles
watch(dark, (v) => applyTheme(v))

const userName = 'Admin'

const toggleAside = () => {
  if (window.matchMedia('(max-width: 1024px)').matches) {
    drawer.value = !drawer.value
  } else {
    collapsed.value = !collapsed.value
  }
}
const closeDrawer = () => (drawer.value = false)
const go = (to: string) => router.push(to)

const logout = () => {
  const fallback = '/'
  const to = (window as any).mhcLoginUrl || fallback
  window.location.href = `/wp-login.php?action=logout&redirect_to=${encodeURIComponent(
      to
  )}&_wpnonce=${(window as any).mhcLogoutNonce || ''}`
}
</script>

<style scoped>
.mhc-layout { min-height: 100vh; }
.mhc-header {
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 10; background: var(--el-bg-color-overlay);
  border-bottom: 1px solid var(--el-border-color-lighter);
  padding: 0 16px;
}
.mhc-left { display: flex; align-items: center; gap: 12px; }
.mhc-title { font-size: 16px; margin: 0 4px 0 0; font-weight: 600; }
.mhc-right { display: flex; align-items: center; gap: 16px; }
.mhc-aside {
  border-right: 1px solid var(--el-border-color-lighter);
  display: flex; flex-direction: column; justify-content: space-between;
}
.mhc-collapse { padding: 8px; text-align: center; }
.mhc-main, .mhc-aside { padding: 16px; background: var(--el-bg-color); }
.mhc-hamburger { margin-left: -6px; }
.el-breadcrumb { margin-left: 8px; }


/* Mobile tweaks */
@media (max-width: 1024px) {
  .mhc-aside { display: none; }
}



/* Simple dark token (optional) */
</style>