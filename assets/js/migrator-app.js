// Registers the migrator as a child route of FluentCart's Settings screen.
// Bootstrap only: no exports. Built against core's @fluent-cart/* import map.
import MigratorApp from './MigratorApp.vue';
import { __ } from './i18n.js';
import '../css/migrator-app.css';

var hooks = window.fluent_cart_admin && window.fluent_cart_admin.hooks;

if (hooks) {
    hooks.addFilter('fluent_cart_routes', 'fluent_cart_migrator', function (routes) {
        if (routes.settings && routes.settings.children) {
            routes.settings.children.push({
                name: 'fct_migrator',
                path: 'migrator',
                component: MigratorApp,
                meta: {
                    active_menu: 'settings',
                    title: __('Migrator'),
                    // Same slug as the sidebar item; REST also requires manage_options.
                    permission: ['store/sensitive']
                }
            });
        }
        return routes;
    });
}
