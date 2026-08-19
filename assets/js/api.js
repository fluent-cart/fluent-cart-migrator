// The migration source currently selected in the wizard. Every request carries
// it as a `source` query param so the REST API routes to the right migrator
// (EDD, WooCommerce, ...) instead of defaulting to EDD.
var currentSource = '';

export function setApiSource(source) {
    currentSource = source || '';
}

export function apiRequest(method, path, data) {
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

    var url = window.fctMigrator.restUrl + path;
    if (currentSource) {
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'source=' + encodeURIComponent(currentSource);
    }

    return fetch(url, opts).then(function (res) {
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
