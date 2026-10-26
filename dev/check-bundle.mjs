// Fails the build when assets/build/migrator-app.js imports anything core's
// import map can't satisfy: a package other than @fluent-cart/*, or a Vue name
// that @fluent-cart/vue (fluent-cart/resources/admin/shared/module-vue.js)
// doesn't export. A missing name only shows up as a broken page at runtime.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BUNDLE = path.join(ROOT, 'assets/build/migrator-app.js');

// Mirror of module-vue.js for FLUENTCART_MODULE_API 1. Adding a name here
// means requiring the core release that exports it.
const CORE_VUE_EXPORTS = new Set([
    'computed', 'defineAsyncComponent', 'defineComponent', 'getCurrentInstance', 'nextTick',
    'onMounted', 'onUnmounted', 'ref', 'unref', 'watch',
    'Fragment', 'createBlock', 'createCommentVNode', 'createElementBlock', 'createElementVNode',
    'createTextVNode', 'createVNode', 'normalizeClass', 'normalizeStyle', 'openBlock',
    'renderList', 'resolveComponent', 'resolveDynamicComponent', 'toDisplayString', 'withCtx',
    'withKeys', 'withModifiers'
]);

const code = fs.readFileSync(BUNDLE, 'utf8');
const errors = [];

for (const match of code.matchAll(/(?<![\w$.])import\s*(?:\{([^}]*)\}\s*from\s*)?["']([@\w./-]+)["']/g)) {
    const names = (match[1] || '').split(',').map(s => s.trim().split(/\s+as\s+/)[0]).filter(Boolean);
    const source = match[2];

    if (!source.startsWith('@fluent-cart/')) {
        errors.push(`imports "${source}" (only @fluent-cart/* is allowed)`);
    } else if (source === '@fluent-cart/vue') {
        names.filter(n => !CORE_VUE_EXPORTS.has(n))
            .forEach(n => errors.push(`imports "${n}" from @fluent-cart/vue, which core does not export`));
    }
}

if (errors.length) {
    console.error('Bundle check failed for assets/build/migrator-app.js:\n  - ' + errors.join('\n  - '));
    process.exit(1);
}

console.log('Bundle check passed: only core-exported @fluent-cart/* imports.');
