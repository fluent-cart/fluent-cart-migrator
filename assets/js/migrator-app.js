import { createApp } from 'vue';
import MigratorApp from './MigratorApp.vue';
import { __, _n, sprintf } from './i18n.js';
import '../css/migrator-app.css';

var app = createApp(MigratorApp);

// Expose the i18n helpers to every component template.
app.config.globalProperties.__ = __;
app.config.globalProperties._n = _n;
app.config.globalProperties.sprintf = sprintf;

app.mount('#fct-migrator-app');
