<?php

namespace FluentCartMigrator\Classes\Load;

/**
 * Maps a source term tree into ANY FluentCart product taxonomy, parents-first
 * so hierarchy is preserved, reusing existing terms by slug.
 *
 * Source-agnostic and taxonomy-agnostic: the caller flattens its taxonomy into
 * the normalized list below and names the destination taxonomy. A flat
 * destination (e.g. a tags taxonomy) simply ignores the parents.
 *
 * Input: array of [
 *   'source_id'        => int,
 *   'name'             => string,
 *   'slug'             => string,
 *   'description'      => string,
 *   'parent_source_id' => int (0 = top level),
 * ]
 */
class TaxonomyWriter
{
    /**
     * @param array  $terms    normalized source terms (see above)
     * @param string $taxonomy destination taxonomy (must be registered)
     *
     * @return array<int,int> sourceTermId => fctTermId
     */
    public static function sync(array $terms, $taxonomy)
    {
        $map = [];

        if (!$taxonomy || !taxonomy_exists($taxonomy) || !$terms) {
            return $map;
        }

        $hierarchical = is_taxonomy_hierarchical($taxonomy);

        $remaining = [];
        foreach ($terms as $term) {
            $remaining[(int) $term['source_id']] = $term;
        }

        $guard = 0;
        while ($remaining && $guard++ < 50) {
            $progressed = false;

            foreach ($remaining as $sourceId => $term) {
                $parentSourceId = $hierarchical ? (int) ($term['parent_source_id'] ?? 0) : 0;
                if ($parentSourceId && !isset($map[$parentSourceId])) {
                    // Wait for the parent — unless it isn't in this set at all,
                    // in which case the final no-progress pass drops it.
                    continue;
                }

                $parentFctId = $parentSourceId ? (int) $map[$parentSourceId] : 0;
                $existing    = get_term_by('slug', $term['slug'], $taxonomy);

                if ($existing) {
                    $map[$sourceId] = (int) $existing->term_id;
                } else {
                    $args = [
                        'slug'        => $term['slug'],
                        'description' => $term['description'] ?? '',
                    ];

                    if ($hierarchical) {
                        $args['parent'] = $parentFctId;
                    }

                    $created = wp_insert_term($term['name'], $taxonomy, $args);

                    if (is_wp_error($created)) {
                        $fallback = get_term_by('slug', $term['slug'], $taxonomy);
                        if ($fallback) {
                            $map[$sourceId] = (int) $fallback->term_id;
                        }
                    } else {
                        $map[$sourceId] = (int) $created['term_id'];
                    }
                }

                unset($remaining[$sourceId]);
                $progressed = true;
            }

            if (!$progressed) {
                break; // orphaned terms whose parent is missing
            }
        }

        return $map;
    }
}
