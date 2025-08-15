// router.js
import { createRouter, createWebHashHistory } from 'vue-router'
import Workers from './pages/Workers.vue'
import Patients from './pages/Patients.vue'
import Dashboard from './pages/Dashboard.vue'
import Roles from './pages/Roles.vue'
import SpecialRates from './pages/SpecialRates.vue'

const routes = [
    { path: '/', component: Dashboard, meta: { title: 'Dashboard' } },
    { path: '/workers', component: Workers, meta: { title: 'Workers' } },
    { path: '/patients', component: Patients, meta: { title: 'Patients' } },
    { path: '/roles', component: Roles, meta: { title: 'Roles' } },
    { path: '/special-rates', component: SpecialRates, meta: { title: 'Special Rates' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
    history: createWebHashHistory(), // keep hash mode for WP admin
    routes,
})

// Save last route on each navigation
router.afterEach((to) => {
    try { localStorage.setItem('lastRoute', to.fullPath) } catch {}
})

// Restore only on the first navigation
let restoredOnce = false
router.beforeEach((to, from, next) => {
    if (!restoredOnce) {
        restoredOnce = true
        const last = (() => { try { return localStorage.getItem('lastRoute') } catch { return null } })()
        // On initial load, if we hit '/', go to the last route instead
        const isInitial = !from.matched.length // first load
        if (isInitial && to.fullPath === '/' && last && last !== '/') {
            return next(last)
        }
    }
    next()
})

export default router
