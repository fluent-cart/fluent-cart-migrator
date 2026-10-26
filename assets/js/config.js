/**
 * Migrator config, passed by AdminMenu::addAppData() through FluentCart's
 * `fluent_cart/admin_app_data` filter (window.fluentCartAdminApp.fct_migrator).
 */
export function config() {
    var app = window.fluentCartAdminApp || {};
    return app.fct_migrator || {};
}
