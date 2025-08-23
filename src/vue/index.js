import { createApp } from 'vue'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import App from './App.vue'
import router from './router'
import TopMenu from './components/TopMenu.vue'

import 'element-plus/theme-chalk/dark/css-vars.css'

import './styles.css'



document.documentElement.classList.add('dark') // force dark mode


const app = createApp(App)
app.use(router)
app.use(ElementPlus)
app.component('mhc-top-menu', TopMenu)
app.mount('#vwp-plugin')

