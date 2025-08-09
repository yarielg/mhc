import { createApp } from 'vue'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import App from './App.vue'
import router from './router'
import TopMenu from './components/TopMenu.vue'
import Patients  from "./pages/Patients.vue";

import './styles.css'


const app = createApp(App)
app.use(router)
app.use(ElementPlus)
app.component('mhc-top-menu', TopMenu)
app.component('mhc-patient', Patients)
app.mount('#vwp-plugin')

