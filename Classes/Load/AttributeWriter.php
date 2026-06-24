<?php

namespace FluentCartMigrator\Classes\Load;

/**
 * The shared "Load" for the FluentCart attribute library: owns fct_atts_groups
 * (one row per attribute, e.g. "Color"), fct_atts_terms (one row per value,
 * e.g. "Blue") and fct_atts_relations (links a variation to its term/group).
 *
 * Groups and terms are a global, shared library — they are deduped by slug
 * (group) and group_id+slug (term) and NEVER deleted on a product re-run; the
 * re-run simply resolves back to the same ids. Relations ARE per-variation, so
 * the product writer clears a product's relations before re-inserting variants.
 *
 * Raw table inserts (never Eloquent ::create) keep this aligned with the rest
 * of the Load layer and bypass model boot hooks.
 */
class AttributeWriter
{
    /** @var array<string,int> group slug => fct group id */
    private static $groupCache = [];

    /** @var array<string,int> "groupId\0termSlug" => fct term id */
    private static $termCache = [];

    /**
     * Find (by unique slug) or create an attribute group. Returns the fct id.
     * $settings (e.g. ['type' => 'color'|'image'|'options']) is applied only on
     * creation — an existing group keeps its own settings.
     */
    public static function ensureGroup($title, $slug, $settings = null)
    {
        $slug  = self::slug($slug ?: $title);
        $title = trim((string) $title) !== '' ? $title : $slug;
        if ($slug === '') {
            return 0;
        }

        if (isset(self::$groupCache[$slug])) {
            return self::$groupCache[$slug];
        }

        $db  = fluentCart('db');
        $row = $db->table('fct_atts_groups')->where('slug', $slug)->first();
        if ($row) {
            return self::$groupCache[$slug] = (int) $row->id;
        }

        $now    = current_time('mysql');
        $serial = (int) $db->table('fct_atts_groups')->max('serial') + 1;

        $id = $db->table('fct_atts_groups')->insertGetId([
            'title'       => $title,
            'slug'        => $slug,
            'description' => null,
            'settings'    => ($settings !== null && $settings !== []) ? json_encode($settings) : null,
            'serial'      => $serial,
            'is_system'   => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        return self::$groupCache[$slug] = (int) $id;
    }

    /**
     * Find (by group_id + unique slug) or create a term. Returns the fct id.
     * $settings (e.g. ['color' => '#hex'] or ['image' => '<url>']) is applied
     * only on creation — an existing term keeps its own settings.
     */
    public static function ensureTerm($groupId, $title, $slug, $settings = null)
    {
        $groupId = (int) $groupId;
        $slug    = self::slug($slug ?: $title);
        $title   = trim((string) $title) !== '' ? $title : $slug;
        if (!$groupId || $slug === '') {
            return 0;
        }

        $key = $groupId . "\0" . $slug;
        if (isset(self::$termCache[$key])) {
            return self::$termCache[$key];
        }

        $db  = fluentCart('db');
        $row = $db->table('fct_atts_terms')
            ->where('group_id', $groupId)
            ->where('slug', $slug)
            ->first();
        if ($row) {
            return self::$termCache[$key] = (int) $row->id;
        }

        $now    = current_time('mysql');
        $serial = (int) $db->table('fct_atts_terms')->where('group_id', $groupId)->max('serial') + 1;

        $id = $db->table('fct_atts_terms')->insertGetId([
            'group_id'    => $groupId,
            'serial'      => $serial,
            'title'       => $title,
            'slug'        => $slug,
            'description' => null,
            'settings'    => ($settings !== null && $settings !== []) ? json_encode($settings) : null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        return self::$termCache[$key] = (int) $id;
    }

    /**
     * Insert the (variation -> term/group) relation rows for one variation,
     * skipping any pair already present (the table has a composite unique on
     * object_id+group_id+term_id).
     *
     * @param int   $variationId fct_product_variations.id
     * @param array $pairs       [['term_id' => int, 'group_id' => int], ...]
     */
    public static function writeRelations($variationId, array $pairs)
    {
        $variationId = (int) $variationId;
        if (!$variationId || !$pairs) {
            return;
        }

        $db   = fluentCart('db');
        $now  = current_time('mysql');
        $seen = [];

        $existing = $db->table('fct_atts_relations')->where('object_id', $variationId)->get();
        foreach ($existing as $rel) {
            $seen[$rel->group_id . ':' . $rel->term_id] = true;
        }

        $rows = [];
        foreach ($pairs as $pair) {
            $termId  = (int) ($pair['term_id'] ?? 0);
            $groupId = (int) ($pair['group_id'] ?? 0);
            if (!$termId || !$groupId) {
                continue;
            }
            $key = $groupId . ':' . $termId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[]     = [
                'object_id'  => $variationId,
                'term_id'    => $termId,
                'group_id'   => $groupId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            $db->table('fct_atts_relations')->insert($rows);
        }
    }

    /**
     * Drop relation rows for the given variation ids (idempotent re-run cleanup
     * before a product's variations are deleted and re-created with new ids).
     *
     * @param int[] $variationIds
     */
    public static function deleteRelationsForVariations(array $variationIds)
    {
        $variationIds = array_values(array_filter(array_map('intval', $variationIds)));
        if (!$variationIds) {
            return;
        }
        fluentCart('db')->table('fct_atts_relations')->whereIn('object_id', $variationIds)->delete();
    }

    public static function resetCache()
    {
        self::$groupCache = [];
        self::$termCache  = [];
    }

    private static function slug($value)
    {
        $slug = sanitize_title((string) $value);
        // fct_atts_groups.slug / fct_atts_terms.slug are VARCHAR(192).
        return substr($slug, 0, 192);
    }
}
