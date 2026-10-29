import { createApp } from 'vue'
import App from './App.vue'
import router from './router';
import store from './stores'
import VueFeather from 'vue-feather';
import VueSweetalert2 from 'vue-sweetalert2';
import { abilitiesPlugin } from '@casl/vue';
import ability from './services/defineAbility';
import Breadcrumbs from './components/bread_crumbs.vue';
import { createPinia } from 'pinia';
import mitt from 'mitt';
import Lightbox from 'vue-easy-lightbox'
import Toast from "vue-toastification";
import "vue-toastification/dist/index.css";
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap/dist/js/bootstrap.bundle'
import 'sweetalert2/dist/sweetalert2.min.css';
import '@/assets/scss/app.scss'
import DropZone from "dropzone-vue";


const emitter = mitt();
const pinia = createPinia(); // Create Pinia instance
const app = createApp(App);
app.config.globalProperties.emitter = emitter;
const optionsToast = {
  timeout: 30000,
  closeOnClick: true,
};
app.use(abilitiesPlugin, ability, {
    useGlobalProperties: true
  })
app.use(Toast,optionsToast).use(Lightbox).use(DropZone).use(router).use(emitter).use(store).use(pinia).use(VueSweetalert2).component(VueFeather.name, VueFeather).component('Breadcrumbs', Breadcrumbs).mount('#app')

