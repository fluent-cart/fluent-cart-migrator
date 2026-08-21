<?php

namespace FluentCartMigrator\Classes\Support;

use FluentCart\App\CPT\FluentProducts;

/**
 * The `taxonomies` migration step: walks the source products, follows the
 * id-mapping postmeta the products step wrote, and assigns each product's
 * mapped terms to its FluentCart counterpart.
 *
 * step() is the step entry point every source shares (skip-when-done, resume
 * page, time/memory box, state marking — the same contract as products and
 * payments); apply() is the raw pass over one or more pages.
 *
 * Idempotent: re-running assigns the same terms. Changing the mapping re-opens
 * the step (see TaxonomyMap::save()), which is how a mapping edited after the
 * migration reaches the catalog.
 */
class TaxonomyApplier
{
    /**
     * Run the step for one source: skips when already complete, resumes from
     * the saved page, and marks the step done when the catalog is exhausted.
     *
     * @param string   $sourceKey  'edd' | 'woocommerce'
     * @param int|null $page       null = resume from saved state
     *
     * @return array|\WP_Error the standard step payload
     */
    public static function step($sourceKey, $page = null, $perPage = 100, $maxSeconds = 20)
    {
        $optionKey = TaxonomyMap::stateOptionKey($sourceKey);

        if (!$optionKey) {
            /* translators: %s: migration source key */
            return new \WP_Error('invalid_source', sprintf(__('Unsupported migration source: %s', 'fluent-cart-migrator'), $sourceKey), ['status' => 400]);
        }

        $state = get_option($optionKey, []);
        $state = is_array($state) ? $state : [];

        if (($state[TaxonomyMap::STEP] ?? '') === 'yes') {
            return [
                'success'         => true,
                'step'            => TaxonomyMap::STEP,
                'processed'       => 0,
                'updated'         => 0,
                'skipped'         => 0,
                'page'            => (int) ($state['last_taxonomy_page'] ?? 0) + 1,
                'has_more'        => false,
                'step_skipped'    => true,
                'message'         => __('Already applied.', 'fluent-cart-migrator'),
                'migration_state' => $state,
            ];
        }

        if ($page === null) {
            // last_taxonomy_page is the last COMPLETED page (0 when nothing has
            // run yet), so always resume at the next one.
            $page = (int) ($state['last_taxonomy_page'] ?? 0) + 1;
        }

        $result = self::apply($sourceKey, $page, $perPage, $maxSeconds);

        if (is_wp_error($result)) {
            return $result;
        }

        $state = get_option($optionKey, []);
        $state = is_array($state) ? $state : [];

        // $result['page'] is the next page to run; the last completed one is
        // the page before it.
        $state['last_taxonomy_page'] = max(0, (int) $result['page'] - 1);

        if (empty($result['has_more'])) {
            $state[TaxonomyMap::STEP] = 'yes';
        }

        update_option($optionKey, $state, false);

        $result['migration_state'] = $state;

        return $result;
    }

    /**
     * One pass over the source catalog, starting at $page.
     *
     * @param string $sourceKey  'edd' | 'woocommerce'
     * @param int    $page       1-based page of source products
     * @param int    $perPage    source products per page
     * @param int    $maxSeconds stop after this long and report has_more (0 = no limit)
     *
     * @return array|\WP_Error {processed, updated, skipped, page, has_more}
     */
    public static function apply($sourceKey, $page = 1, $perPage = 100, $maxSeconds = 20)
    {
        $objectType = TaxonomyMap::objectType($sourceKey);
        $metaKey    = TaxonomyMap::mappingMetaKey($sourceKey);

        if (!$objectType || !$metaKey) {
            /* translators: %s: migration source key */
            return new \WP_Error('invalid_source', sprintf(__('Unsupported migration source: %s', 'fluent-cart-migrator'), $sourceKey), ['status' => 400]);
        }

        $resolver = new TaxonomyResolver($sourceKey);

        if ($resolver->isEmpty()) {
            return [
                'success'   => true,
                'step'      => TaxonomyMap::STEP,
                'processed' => 0,
                'updated'   => 0,
                'skipped'   => 0,
                'page'      => $page,
                'has_more'  => false,
                'message'   => __('No taxonomies are mapped, so there is nothing to apply.', 'fluent-cart-migrator'),
            ];
        }

        $resolver->prepare();

        $page      = max(1, (int) $page);
        $perPage   = max(1, (int) $perPage);
        $startedAt = time();
        $cpt       = class_exists(FluentProducts::class) ? FluentProducts::CPT_NAME : 'fluent-products';

        $processed = 0;
        $updated   = 0;
        $skipped   = 0;
        $hasMore   = true;

        do {
            $sourceIds = self::sourceProductIds($objectType, $page, $perPage);
            $hasMore   = count($sourceIds) === $perPage;

            foreach ($sourceIds as $sourceId) {
                $processed++;

                $fctId = (int) get_post_meta($sourceId, $metaKey, true);
                if (!$fctId || get_post_type($fctId) !== $cpt) {
                    $skipped++;
                    continue;
                }

                $resolved = $resolver->resolveForObject($sourceId);
                if (!$resolved) {
                    $skipped++;
                    continue;
                }

                foreach ($resolved as $taxonomy => $termIds) {
                    wp_set_object_terms($fctId, $termIds, $taxonomy, false);
                }

                $updated++;
            }

            $page++;

            if (!$hasMore) {
                break;
            }

            BatchRuntime::freeMemory();
        } while (($maxSeconds <= 0 || (time() - $startedAt) < $maxSeconds) && !BatchRuntime::memoryNearLimit());

        return [
            'success'   => true,
            'step'      => TaxonomyMap::STEP,
            'processed' => $processed,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'page'      => $page,
            'has_more'  => $hasMore,
        ];
    }

    /**
     * One page of source product ids.
     *
     * @return int[]
     */
    private static function sourceProductIds($objectType, $page, $perPage)
    {
        $ids = get_posts([
            'post_type'        => $objectType,
            'post_status'      => 'any',
            'posts_per_page'   => $perPage,
            'paged'            => $page,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        return array_map('intval', (array) $ids);
    }
}
