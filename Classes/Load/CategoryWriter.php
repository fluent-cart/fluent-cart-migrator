<?php

namespace FluentCartMigrator\Classes\Load;

/**
 * Backwards-compatible shim for the product-category case of TaxonomyWriter,
 * kept because callers reference CategoryWriter::TAXONOMY as the name of
 * FluentCart's built-in category taxonomy.
 *
 * New code should use TaxonomyWriter (any destination taxonomy) driven by the
 * admin's taxonomy mapping — see Support\TaxonomyMap / Support\TaxonomyResolver.
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
        return TaxonomyWriter::sync($categories, self::TAXONOMY);
    }
}
