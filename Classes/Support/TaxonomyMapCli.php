<?php

namespace FluentCartMigrator\Classes\Support;

/**
 * The CLI side of taxonomy mapping.
 *
 * `--taxonomy_map` shows which source taxonomy feeds which FluentCart taxonomy
 * (the same mapping the admin UI edits) and can set it with `--set`:
 *
 *   wp fluent_cart_migrator migrate_from_woo --taxonomy_map
 *   wp fluent_cart_migrator migrate_from_woo --taxonomy_map --set=product_cat:product-categories,product_tag:product-tags
 *   wp fluent_cart_migrator migrate_from_woo --taxonomy_map --set=none
 *
 * `--taxonomies` runs the migration step itself (also part of `--all`);
 * migrate() below is its runner, shared by both commands.
 */
class TaxonomyMapCli
{
    public static function run($sourceKey, $assocArgs = [])
    {
        if (!TaxonomyMap::supports($sourceKey)) {
            \WP_CLI::error('Taxonomy mapping is not available for source: ' . $sourceKey);
        }

        if (isset($assocArgs['set'])) {
            self::set($sourceKey, (string) $assocArgs['set']);
        }

        $destinations = TaxonomyMap::destinations();
        $sources      = TaxonomyMap::sources($sourceKey);
        $map          = TaxonomyMap::get($sourceKey);

        \WP_CLI::line('FluentCart taxonomies (destinations):');
        foreach ($destinations as $destination) {
            \WP_CLI::line(sprintf(
                '  %-22s %s (%d terms)',
                $destination['name'],
                $destination['label'],
                $destination['count']
            ));
        }

        \WP_CLI::line('');
        \WP_CLI::line(ucfirst($sourceKey) . ' taxonomies (sources):');
        if (!$sources) {
            \WP_CLI::line('  none found');
        }
        foreach ($sources as $source) {
            \WP_CLI::line(sprintf(
                '  %-22s %s (%d terms)',
                $source['name'],
                $source['label'],
                $source['count']
            ));
        }

        \WP_CLI::line('');
        \WP_CLI::line('Effective mapping' . (TaxonomyMap::isCustomized($sourceKey) ? ':' : ' (defaults — never saved):'));
        if (!$map) {
            \WP_CLI::line('  none — no taxonomies will be migrated');
        }
        foreach ($map as $pair) {
            \WP_CLI::line(sprintf('  %-22s -> %s', $pair['source'], $pair['destination']));
        }
    }

    /**
     * Run the taxonomies step to completion (no time box, as with the other CLI
     * steps). Shared by the EDD and WooCommerce commands.
     *
     * @param \FluentCartMigrator\Classes\Contracts\SourceMigratorInterface $source
     */
    public static function migrate($source)
    {
        $updated = 0;
        $skipped = 0;

        do {
            $result = $source->migrateTaxonomies(null, 200, 0);

            if (is_wp_error($result)) {
                \WP_CLI::warning($result->get_error_message());
                return;
            }

            if (!empty($result['step_skipped'])) {
                \WP_CLI::line('  Taxonomies already applied. Skipping.');
                return;
            }

            if (!empty($result['message'])) {
                \WP_CLI::line('  ' . $result['message']);
                return;
            }

            $updated += (int) ($result['updated'] ?? 0);
            $skipped += (int) ($result['skipped'] ?? 0);
        } while (!empty($result['has_more']));

        \WP_CLI::line('  Products updated: ' . $updated . ' (' . $skipped . ' without mapped terms)');
    }

    /**
     * Parse and store `--set=source:destination,source:destination` (or `none`).
     */
    private static function set($sourceKey, $value)
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'none') {
            TaxonomyMap::save($sourceKey, []);
            \WP_CLI::success('Taxonomy mapping cleared — no taxonomies will be migrated.');
            return;
        }

        $pairs = [];
        foreach (explode(',', $value) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $parts = array_map('trim', explode(':', $chunk, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                \WP_CLI::error('Invalid --set value: "' . $chunk . '". Expected source_taxonomy:fluentcart_taxonomy.');
            }

            $pairs[] = ['source' => $parts[0], 'destination' => $parts[1]];
        }

        $saved = TaxonomyMap::save($sourceKey, $pairs);

        if (count($saved) !== count($pairs)) {
            \WP_CLI::warning('Some pairs were dropped — the FluentCart taxonomy must already be registered.');
        }

        \WP_CLI::success('Taxonomy mapping saved (' . count($saved) . ' pair(s)).');
    }
}
