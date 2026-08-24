import { __, sprintf } from './i18n';

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
        // Read the raw body first so a blank or non-JSON reply (PHP fatal,
        // exit() from another plugin, host/WAF page, timeout) produces a
        // readable error instead of "JSON.parse: unexpected end of data".
        return res.text().then(function (text) {
            var body = parseJsonBody(text);

            if (!res.ok) {
                throw new Error(describeErrorBody(body, text, res.status));
            }

            if (body === undefined) {
                throw new Error(describeInvalidBody(text, res.status));
            }

            return body;
        });
    });
}

function parseJsonBody(text) {
    if (typeof text !== 'string' || text.trim() === '') {
        return undefined;
    }
    try {
        return JSON.parse(text);
    } catch (_) {
        return undefined;
    }
}

function stripTags(html) {
    var div = document.createElement('div');
    div.innerHTML = html;
    return (div.textContent || div.innerText || '').replace(/\s+/g, ' ').trim();
}

function describeErrorBody(body, text, status) {
    if (body && typeof body === 'object') {
        // WP's fatal handler puts the real PHP error under data.error.
        var detail = body.data && body.data.error && body.data.error.message;
        if (detail) {
            var where = '';
            if (body.data.error.file) {
                where = ' (' + body.data.error.file + (body.data.error.line ? ':' + body.data.error.line : '') + ')';
            }
            return __('Server error:') + ' ' + detail + where;
        }
        if (body.message) {
            return stripTags(String(body.message)) || 'HTTP ' + status;
        }
    }
    return describeInvalidBody(text, status);
}

function describeInvalidBody(text, status) {
    var snippet = stripTags(String(text || '')).slice(0, 300);
    if (!snippet) {
        return sprintf(
            __('The server returned an empty response (HTTP %s). This usually means PHP stopped before replying — a fatal error, memory limit, timeout, or another plugin calling exit(). Check the PHP error log (enable WP_DEBUG_LOG), try deactivating other plugins on the staging site, or run the migration via WP-CLI.'),
            status
        );
    }
    return sprintf(__('Unexpected server response (HTTP %s): %s'), status, snippet);
}
