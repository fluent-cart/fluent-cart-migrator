/**
 * Thin wrapper around WordPress' wp.i18n runtime.
 *
 * The admin bundle is enqueued with `wp-i18n` as a dependency and translations
 * are registered via wp_set_script_translations(), so wp.i18n is always there
 * in WordPress. The fallbacks only keep the app functional if it is ever
 * mounted outside wp-admin (tests, storybook, ...).
 *
 * Keep the exported names (__, _n, sprintf) as-is: the Vite config disables
 * identifier mangling so `wp i18n make-pot` can extract these calls from the
 * built assets/build/migrator-app.js.
 */
var TEXT_DOMAIN = 'fluent-cart-migrator';

function wpI18n() {
    return (window.wp && window.wp.i18n) || null;
}

function fallbackSprintf(format) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(format).replace(/%(\d+\$)?([sd])/g, function (match, pos) {
        var idx = pos ? parseInt(pos, 10) - 1 : i++;
        return idx < args.length ? args[idx] : match;
    });
}

export function __(text) {
    var i18n = wpI18n();
    return i18n ? i18n.__(text, TEXT_DOMAIN) : text;
}

export function _n(single, plural, number) {
    var i18n = wpI18n();
    if (i18n) {
        return i18n._n(single, plural, number, TEXT_DOMAIN);
    }
    return number === 1 ? single : plural;
}

export function sprintf() {
    var i18n = wpI18n();
    if (i18n && i18n.sprintf) {
        return i18n.sprintf.apply(null, arguments);
    }
    return fallbackSprintf.apply(null, arguments);
}
