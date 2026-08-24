<?php

namespace FluentCartMigrator\Classes\Support;

use FluentCartMigrator\Classes\Load\TaxonomyWriter;

/**
 * Run-time half of the taxonomy mapping: takes the admin's source→FluentCart
 * pairs (TaxonomyMap), copies each source term tree into its destination
 * taxonomy once, and then resolves a source product's terms into FluentCart
 * term ids grouped by destination taxonomy.
 *
 * One instance per migration run (product batches reuse the prepared maps).
 */
class TaxonomyResolver
{
    /** @var string */
    private $sourceKey;

    /** @var array<int,array{source:string,destination:string}> */
    private $pairs;

    /** @var array<string,array<int,int>> "sourceTax|destTax" => [sourceTermId => fctTermId] */
    private $termMaps = [];

    public function __construct($sourceKey, $pairs = null)
    {
        $this->sourceKey = $sourceKey;
        $this->pairs     = is_array($pairs) ? TaxonomyMap::normalize($pairs) : TaxonomyMap::get($sourceKey);
    }

    public function isEmpty()
    {
        return !$this->pairs;
    }

    /**
     * @return array<int,array{source:string,destination:string}>
     */
    public function pairs()
    {
        return $this->pairs;
    }

    /** Distinct source taxonomies in the mapping. */
    public function sourceTaxonomies()
    {
        return array_values(array_unique(wp_list_pluck($this->pairs, 'source')));
    }

    /**
     * Create every mapped term tree up-front. Optional — resolveForObject()
     * prepares lazily — but doing it once before a batch keeps the per-product
     * work to a single lookup.
     */
    public function prepare()
    {
        foreach ($this->pairs as $pair) {
            $this->termMap($pair['source'], $pair['destination']);
        }

        return $this;
    }

    /**
     * Resolve one source product's terms into FluentCart term ids, keyed by
     * destination taxonomy. Two source taxonomies pointing at the same
     * destination are merged.
     *
     * @return array<string,int[]> destinationTaxonomy => fct term ids
     */
    public function resolveForObject($sourceObjectId)
    {
        if (!$this->pairs) {
            return [];
        }

        $sourceTerms = $this->objectTerms((int) $sourceObjectId);
        if (!$sourceTerms) {
            return [];
        }

        $resolved = [];

        foreach ($this->pairs as $pair) {
            $termIds = $sourceTerms[$pair['source']] ?? [];
            if (!$termIds) {
                continue;
            }

            $map = $this->termMap($pair['source'], $pair['destination']);

            foreach ($termIds as $sourceTermId) {
                if (isset($map[$sourceTermId])) {
                    $resolved[$pair['destination']][] = (int) $map[$sourceTermId];
                }
            }
        }

        foreach ($resolved as $taxonomy => $ids) {
            $resolved[$taxonomy] = array_values(array_unique($ids));
        }

        return $resolved;
    }

    /**
     * Assign a source object's mapped terms to the FluentCart product. Used by
     * sources that write the product post themselves (EDD) instead of going
     * through ProductWriter.
     */
    public function assignToProduct($sourceObjectId, $fctProductId)
    {
        foreach ($this->resolveForObject($sourceObjectId) as $taxonomy => $termIds) {
            if ($termIds) {
                wp_set_object_terms((int) $fctProductId, $termIds, $taxonomy, false);
            }
        }
    }

    /**
     * Map a single source term id to its FluentCart counterpart (first
     * destination the source taxonomy is mapped to). Used for term-based
     * settings such as WooCommerce coupon category restrictions.
     *
     * @return int 0 when the taxonomy is unmapped or the term has no match
     */
    public function mapTermId($sourceTaxonomy, $sourceTermId)
    {
        foreach ($this->pairs as $pair) {
            if ($pair['source'] !== $sourceTaxonomy) {
                continue;
            }

            $map = $this->termMap($pair['source'], $pair['destination']);

            return (int) ($map[(int) $sourceTermId] ?? 0);
        }

        return 0;
    }

    /* -----------------------------------------------------------------
     | Term trees
     * ----------------------------------------------------------------- */

    /**
     * sourceTermId => fctTermId for one pair, synced on first use.
     *
     * @return array<int,int>
     */
    private function termMap($sourceTaxonomy, $destinationTaxonomy)
    {
        $key = $sourceTaxonomy . '|' . $destinationTaxonomy;

        if (!isset($this->termMaps[$key])) {
            $this->termMaps[$key] = TaxonomyWriter::sync(
                $this->sourceTerms($sourceTaxonomy),
                $destinationTaxonomy
            );
        }

        return $this->termMaps[$key];
    }

    /**
     * Every term of a source taxonomy, in TaxonomyWriter's normalized shape.
     *
     * @return array<int,array>
     */
    private function sourceTerms($taxonomy)
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }

        $sourceTerms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($sourceTerms)) {
            return [];
        }

        $terms = [];
        foreach ($sourceTerms as $term) {
            $terms[] = [
                'source_id'        => (int) $term->term_id,
                'name'             => $term->name,
                'slug'             => $term->slug,
                'description'      => (string) $term->description,
                'parent_source_id' => (int) $term->parent,
            ];
        }

        return $terms;
    }

    /**
     * All mapped-taxonomy terms attached to one source object:
     * sourceTaxonomy => [sourceTermId, ...].
     *
     * @return array<string,int[]>
     */
    private function objectTerms($objectId)
    {
        $taxonomies = array_values(array_filter($this->sourceTaxonomies(), 'taxonomy_exists'));

        if (!$objectId || !$taxonomies) {
            return [];
        }

        $terms = wp_get_object_terms($objectId, $taxonomies);

        if (is_wp_error($terms)) {
            return [];
        }

        $out = [];
        foreach ($terms as $term) {
            $out[$term->taxonomy][] = (int) $term->term_id;
        }

        return $out;
    }
}
