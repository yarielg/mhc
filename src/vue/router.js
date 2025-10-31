// router.js
import { createRouter, createWebHashHistory } from 'vue-router'
import Workers from './pages/Workers.vue'
import Patients from './pages/Patients.vue'
import Dashboard from './pages/Dashboard.vue'
import Roles from './pages/Roles.vue'
import SpecialRates from './pages/SpecialRates.vue'
import Payrolls from "./pages/Payrolls.vue";
import PayrollDetail from "./pages/PayrollDetail.vue";
import Settings from './pages/Settings.vue'
import Reports from './pages/Reports.vue'
import ReportByDate from './pages/ReportByDate.vue'

const routes = [
    { path: '/', component: Dashboard, meta: { title: 'Dashboard' } },
    { path: '/workers', component: Workers, meta: { title: 'Workers' } },
    { path: '/patients', component: Patients, meta: { title: 'Patients' } },
    { path: '/payrolls', component: Payrolls, meta: { title: 'Payrolls' } },
    { path: '/roles', component: Roles, meta: { title: 'Roles' } },
    {path: '/insurers', component: () => import('./pages/Insurers.vue'), meta: { title: 'Insurers' } },
    { path: '/special-rates', component: SpecialRates, meta: { title: 'Special Rates' } },
    { path: '/settings', component: Settings, meta: { title: 'Settings' } },
    { path: '/reports', component: Reports, meta: { title: 'Reports' } },
    { path: '/reports/all', component: ReportByDate, meta: { title: 'Report By Date' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
    {
        path: '/payrolls/:id(\\d+)',
        name: 'PayrollDetail',
        component: PayrollDetail,
        props: route => ({ id: Number(route.params.id) }) // pass id as a prop
    },
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
