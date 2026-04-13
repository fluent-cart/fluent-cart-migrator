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

    return fetch(window.fctMigrator.restUrl + path, opts).then(function (res) {
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
