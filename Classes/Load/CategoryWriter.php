<?php

namespace FluentCartMigrator\Classes\Load;

/**
 * Maps a source category tree into FluentCart's 'product-categories' taxonomy,
 * parents-first so hierarchy is preserved, reusing existing terms by slug.
 * Source-agnostic: the source flattens its taxonomy into the normalized list.
 *
 * Input: array of [
 *   'source_id'        => int,
 *   'name'             => string,
 *   'slug'             => string,
 *   'description'      => string,
 *   'parent_source_id' => int (0 = top level),
 * ]
 *
 * @return array<int,int> sourceTermId => fctTermId
 */
class CategoryWriter
{
    const TAXONOMY = 'product-categories';

    public static function sync(array $categories)
    {
        $map = [];

        if (!taxonomy_exists(self::TAXONOMY) || !$categories) {
            return $map;
        }

        $remaining = [];
        foreach ($categories as $cat) {
            $remaining[(int) $cat['source_id']] = $cat;
        }

        $guard = 0;
        while ($remaining && $guard++ < 50) {
            $progressed = false;

            foreach ($remaining as $sourceId => $cat) {
                $parentSourceId = (int) ($cat['parent_source_id'] ?? 0);
                if ($parentSourceId && !isset($map[$parentSourceId])) {
                    continue; // wait for parent
                }

                $parentFctId = $parentSourceId ? (int) $map[$parentSourceId] : 0;
                $existing    = get_term_by('slug', $cat['slug'], self::TAXONOMY);

                if ($existing) {
                    $map[$sourceId] = (int) $existing->term_id;
                } else {
                    $created = wp_insert_term($cat['name'], self::TAXONOMY, [
                        'slug'        => $cat['slug'],
                        'description' => $cat['description'] ?? '',
                        'parent'      => $parentFctId,
                    ]);

                    if (is_wp_error($created)) {
                        $fallback = get_term_by('slug', $cat['slug'], self::TAXONOMY);
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
