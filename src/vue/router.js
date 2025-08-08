import { createRouter, createWebHistory } from 'vue-router'
/*
import Dashboard from '../pages/Dashboard.vue'
import Workers from '../pages/Workers.vue'
import Patients from '../pages/Patients.vue'
*/

const routes = [
   /* { path: '/', component: Dashboard, meta: { title: 'Dashboard' } },
    { path: '/workers', component: Workers, meta: { title: 'Workers' } },
    { path: '/patients', component: Patients, meta: { title: 'Patients' } },*/
]

export default createRouter({
    history: createWebHistory(),
    routes
})