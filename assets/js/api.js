// The migration source currently selected in the wizard. Every request carries
// it as a `source` query param so the REST API routes to the right migrator
// (EDD, WooCommerce, ...) instead of defaulting to EDD.
var currentSource = '';

export function setApiSource(source) {
    currentSource = source || '';
}

export function getApiSource() {
    return currentSource;
}

/**
 * Absolute REST URL for `path`, carrying the source and — when `withNonce` is
 * set — the REST nonce as `_wpnonce`, so it can be used as a plain link (e.g.
 * a file download) and still authenticate via cookies.
 */
export function apiUrl(path, source, withNonce) {
    var url = window.fctMigrator.restUrl + path;
    var src = source || currentSource;
    var params = [];
    if (src) {
        params.push('source=' + encodeURIComponent(src));
    }
    if (withNonce) {
        params.push('_wpnonce=' + encodeURIComponent(window.fctMigrator.nonce));
    }
    if (params.length) {
        url += (url.indexOf('?') === -1 ? '?' : '&') + params.join('&');
    }
    return url;
}

export function apiRequest(method, path, data, source) {
    var opts = {
        method: method,
        headers: {
            'X-WP-Nonce': window.fctMigrator.nonce,
            'Content-Type': 'application/json'
        }
    };

    if (method !== 'GET' && data) {
        opts.body = JSON.stringify(data);
    }

    return fetch(apiUrl(path, source, false), opts).then(function (res) {
        if (!res.ok) {
            return res.json().catch(function () {
                return {};
            }).then(function (err) {
                throw new Error(err.message || 'HTTP ' + res.status);
            });
        }
        return res.json();
    });
}
