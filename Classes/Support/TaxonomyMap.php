<?php

namespace FluentCartMigrator\Classes\Support;

use FluentCart\App\CPT\FluentProducts;

/**
 * Discovery + persistence for the taxonomy mapping ("which source taxonomy
 * feeds which FluentCart taxonomy").
 *
 * FluentCart reads product taxonomies dynamically — everything registered on
 * the `fluent-products` post type shows up in its product UI (see
 * FluentCart\Api\Taxonomy::getTaxonomies()) — so the migrator must not hardcode
 * "categories". Instead the admin picks the pairs and this class stores them
 * per source in an option; TaxonomyResolver does the actual work at run time.
 *
 * Both sides are read from the taxonomy registry: the source plugin has to be
 * active for a migration anyway, so its taxonomies are registered.
 */
class TaxonomyMap
{
    const OPTION_PREFIX = '__fluent_cart_migrator_taxonomy_map_';

    /**
     * Per-source wiring the taxonomy step needs:
     *   object_type   the post type the source's products live on
     *   mapping_meta  postmeta on the SOURCE product holding the FluentCart id
     *                 (written by that source's products step)
     */
    private static $sources = [
        'edd'         => [
            'object_type'  => 'download',
            'mapping_meta' => '_fcart_migrated_id',
        ],
        'woocommerce' => [
            'object_type'  => 'product',
            'mapping_meta' => '_fct_migrated_id',
        ],
    ];

    /** State key marking the taxonomy step complete. */
    const STEP = 'taxonomies';

    /**
     * Never offered as a mapping source: WooCommerce/EDD internals, plus WC
     * attribute taxonomies (`pa_*`), which already migrate into FluentCart's
     * attribute library (fct_atts_groups/terms) as product variations.
     */
    private static $excluded = [
        'product_type',
        'product_visibility',
        'product_shipping_class',
        'edd_log_type',
        'edd_payment_mode',
    ];

    /** Source taxonomies never offered, by prefix. */
    private static $excludedPrefixes = ['pa_'];

    /**
     * Pre-selected pairs, applied only when both sides actually exist. Covers
     * the core taxonomies plus the common third-party brand plugins.
     */
    private static $suggestions = [
        'product_cat'        => 'product-categories',
        'download_category'  => 'product-categories',
        'product_tag'        => 'product-tags',
        'download_tag'       => 'product-tags',
        'product_brand'      => 'product-brands',
        'pwb-brand'          => 'product-brands',
        'yith_product_brand' => 'product-brands',
        'berocket_brand'     => 'product-brands',
    ];

    /**
     * The post type this source's products live on, or '' for unknown sources.
     */
    public static function objectType($sourceKey)
    {
        return self::$sources[$sourceKey]['object_type'] ?? '';
    }

    /**
     * Postmeta on a source product holding its migrated FluentCart product id.
     */
    public static function mappingMetaKey($sourceKey)
    {
        return self::$sources[$sourceKey]['mapping_meta'] ?? '';
    }

    /**
     * The source's migration-state option — the same one its other steps use.
     * Mirrors AbstractSourceMigrator::stateOptionKey(); EDD predates that
     * convention and keeps its own key.
     */
    public static function stateOptionKey($sourceKey)
    {
        if (!self::supports($sourceKey)) {
            return '';
        }

        return $sourceKey === 'edd'
            ? '__fluent_cart_edd3_migration_steps'
            : '__fluent_cart_' . $sourceKey . '_migration_steps';
    }

    public static function supports($sourceKey)
    {
        return isset(self::$sources[$sourceKey]);
    }

    /* -----------------------------------------------------------------
     | Discovery
     * ----------------------------------------------------------------- */

    /**
     * FluentCart product taxonomies available as mapping destinations —
     * whatever is registered on the product CPT right now (FluentCart ships
     * categories + brands; plugins/snippets may add more).
     *
     * @return array<int,array{name:string,label:string,hierarchical:bool,count:int}>
     */
    public static function destinations()
    {
        $cpt        = class_exists(FluentProducts::class) ? FluentProducts::CPT_NAME : 'fluent-products';
        $taxonomies = get_object_taxonomies($cpt, 'objects');

        $out = [];
        foreach ($taxonomies as $taxonomy) {
            $out[] = [
                'name'         => $taxonomy->name,
                'label'        => self::label($taxonomy->name, $taxonomy),
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'count'        => self::termCount($taxonomy->name),
            ];
        }

        return $out;
    }

    /**
     * Taxonomies that can be migrated FROM this source: everything registered
     * on the source's product post type, minus attributes and internals.
     *
     * @return array<int,array{name:string,label:string,count:int}>
     */
    public static function sources($sourceKey)
    {
        $objectType = self::objectType($sourceKey);
        if (!$objectType || !post_type_exists($objectType)) {
            return [];
        }

        $out = [];
        foreach (get_object_taxonomies($objectType, 'objects') as $taxonomy) {
            if (self::isExcluded($taxonomy->name)) {
                continue;
            }

            // Neither public nor manageable in wp-admin: an internal taxonomy
            // (visibility flags and the like), never something to migrate.
            if (!$taxonomy->public && !$taxonomy->show_ui) {
                continue;
            }

            $out[] = [
                'name'  => $taxonomy->name,
                'label' => self::label($taxonomy->name, $taxonomy),
                'count' => self::termCount($taxonomy->name),
            ];
        }

        usort($out, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $out;
    }

    private static function isExcluded($name)
    {
        if (in_array($name, self::$excluded, true)) {
            return true;
        }

        foreach (self::$excludedPrefixes as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human label for a taxonomy — its registered plural label, falling back to
     * the slug humanized.
     */
    private static function label($name, $taxonomy = null)
    {
        if ($taxonomy && !empty($taxonomy->labels->name)) {
            return $taxonomy->labels->name;
        }

        return ucwords(str_replace(['_', '-'], ' ', $name));
    }

    private static function termCount($taxonomy)
    {
        $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        return is_wp_error($count) ? 0 : (int) $count;
    }

    /* -----------------------------------------------------------------
     | Mapping state
     * ----------------------------------------------------------------- */

    public static function optionKey($sourceKey)
    {
        return self::OPTION_PREFIX . $sourceKey;
    }

    /**
     * Suggested pairs for a source: only the ones where both the source
     * taxonomy and the FluentCart taxonomy exist on this site.
     *
     * @return array<int,array{source:string,destination:string}>
     */
    public static function defaults($sourceKey)
    {
        $destinations = wp_list_pluck(self::destinations(), 'name');
        $sources      = wp_list_pluck(self::sources($sourceKey), 'name');

        $pairs = [];
        foreach ($sources as $source) {
            $destination = self::$suggestions[$source] ?? '';
            if ($destination && in_array($destination, $destinations, true)) {
                $pairs[] = ['source' => $source, 'destination' => $destination];
            }
        }

        return $pairs;
    }

    /**
     * The stored mapping, falling back to the suggested defaults when the admin
     * has never saved one (so an unattended CLI run still migrates categories).
     *
     * @return array<int,array{source:string,destination:string}>
     */
    public static function get($sourceKey)
    {
        $saved = get_option(self::optionKey($sourceKey), null);

        if (!is_array($saved)) {
            return self::defaults($sourceKey);
        }

        return self::normalize($saved);
    }

    public static function isCustomized($sourceKey)
    {
        return is_array(get_option(self::optionKey($sourceKey), null));
    }

    /**
     * Persist a mapping. Rows with an empty side are dropped ("not migrated"),
     * unknown destinations are dropped, and duplicate pairs are collapsed.
     *
     * Changing the mapping re-opens the taxonomy step: like every other step it
     * is skipped once complete, so without this a mapping edited after the
     * migration would never reach the catalog.
     *
     * @return array<int,array{source:string,destination:string}> what was stored
     */
    public static function save($sourceKey, $pairs)
    {
        $clean        = self::normalize($pairs);
        $destinations = wp_list_pluck(self::destinations(), 'name');

        $clean = array_values(array_filter($clean, function ($pair) use ($destinations) {
            return in_array($pair['destination'], $destinations, true);
        }));

        $changed = $clean !== self::get($sourceKey);

        update_option(self::optionKey($sourceKey), $clean, false);

        if ($changed) {
            self::reopenStep($sourceKey);
        }

        return $clean;
    }

    /**
     * Clear the taxonomy step's "done" flag (and its resume page) so the next
     * migration run applies the mapping again.
     */
    public static function reopenStep($sourceKey)
    {
        $optionKey = self::stateOptionKey($sourceKey);
        if (!$optionKey) {
            return;
        }

        $state = get_option($optionKey, []);
        if (!is_array($state) || (!isset($state[self::STEP]) && !isset($state['last_taxonomy_page']))) {
            return;
        }

        unset($state[self::STEP], $state['last_taxonomy_page']);
        update_option($optionKey, $state, false);
    }

    public static function clear($sourceKey)
    {
        delete_option(self::optionKey($sourceKey));
    }

    /**
     * Sanitize + dedupe a list of pairs. Accepts both the list-of-rows shape
     * from the UI and a plain [source => destination] map.
     */
    public static function normalize($pairs)
    {
        if (!is_array($pairs)) {
            return [];
        }

        $out  = [];
        $seen = [];

        foreach ($pairs as $key => $pair) {
            if (is_array($pair)) {
                $source      = (string) ($pair['source'] ?? '');
                $destination = (string) ($pair['destination'] ?? '');
            } else {
                // [source => destination]
                $source      = (string) $key;
                $destination = (string) $pair;
            }

            $source      = sanitize_key($source);
            $destination = sanitize_key($destination);

            // Either side empty means "ignore this row".
            if (!$source || !$destination) {
                continue;
            }

            $signature = $source . '|' . $destination;
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;

            $out[] = ['source' => $source, 'destination' => $destination];
        }

        return $out;
    }
}
