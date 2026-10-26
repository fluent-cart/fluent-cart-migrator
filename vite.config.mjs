// Builds the migrator screen as an ES module against FluentCart's
// @fluent-cart/* import map. Core's Vue comes from the map and is never
// bundled here: a second Vue can't see core's app (Element Plus, router).
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// Resolved by core's import map; everything else is bundled.
// The stylesheet loads on every FluentCart admin page, so each rule is scoped
// under the screen's root (.fct-migrator) and can't restyle core's screens.
// Dialogs are appended to <body> like core's, so they carry their own root
// (.fct-migrator-dialog). `:root` (the theme tokens) lands on both roots, and
// core's dark class on an ancestor stays in front:
// `.fluent_theme_dark X` becomes `.fluent_theme_dark .fct-migrator X`.
const SCOPE = '.fct-migrator';
const ROOTS = /^\.fct-migrator(-dialog)?(?![\w-])/;
const DARK = '.fluent_theme_dark';
const scopeToMigrator = {
    postcssPlugin: 'scope-to-migrator',
    Rule(rule) {
        if (rule.parent && rule.parent.type === 'atrule' && /keyframes$/.test(rule.parent.name)) {
            return;
        }
        var selectors = [];
        rule.selectors.forEach(function (selector) {
            var dark = '';
            if (selector.indexOf(DARK + ' ') === 0) {
                dark = DARK + ' ';
                selector = selector.slice(dark.length);
            }
            if (selector === ':root') {
                selectors.push(dark + SCOPE, dark + SCOPE + '-dialog');
            } else {
                selectors.push(dark + (ROOTS.test(selector) ? selector : SCOPE + ' ' + selector));
            }
        });
        rule.selectors = selectors;
    }
};

const CORE_MODULES = {
    'vue': '@fluent-cart/vue',
    '@fluent-cart/ui': '@fluent-cart/ui'
};

export default defineConfig({
    plugins: [vue({
        template: {
            compilerOptions: {
                // Static stringification emits createStaticVNode, which core's
                // @fluent-cart/vue does not export.
                hoistStatic: false
            }
        }
    })],
    css: {
        postcss: {
            plugins: [scopeToMigrator]
        }
    },
    // Keep identifier names (so `__`, `_n`, `sprintf` survive minification and
    // `wp i18n make-pot` can extract translatable strings from the built bundle).
    esbuild: {
        minifyIdentifiers: false
    },
    build: {
        outDir: 'assets/build',
        emptyOutDir: true,
        cssCodeSplit: false,
        manifest: false,
        rollupOptions: {
            input: 'assets/js/migrator-app.js',
            external: Object.keys(CORE_MODULES),
            output: {
                format: 'es',
                paths: CORE_MODULES,
                // One file, no chunks: the JS translations JSON is keyed to
                // this file's path, so strings in a lazy chunk would never load.
                inlineDynamicImports: true,
                entryFileNames: 'migrator-app.js',
                assetFileNames: 'migrator-app.[ext]'
            }
        }
    },
    // Never alias `vue` here: it must stay a bare external.
    resolve: {}
});
