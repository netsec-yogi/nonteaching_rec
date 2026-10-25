import '../scss/app.scss';
import 'bootstrap/js/dist/collapse';
import 'bootstrap/js/dist/dropdown';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { useAuth } from './stores/auth';
import { installSessionHandling } from './api/session';
import { installTableCards } from './utils/tableCards';
import { installScrollRegions } from './utils/scrollRegions';
import './utils/theme';

const app = createApp(App);
app.use(createPinia());
installSessionHandling(router);
installTableCards();
installScrollRegions();

useAuth()
    .load()
    .finally(() => {
        app.use(router);
        app.mount('#app');
    });
