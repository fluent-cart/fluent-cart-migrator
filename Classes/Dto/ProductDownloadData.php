<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One downloadable file for a variation. The writer derives driver/extension/
 * file_name and the download_identifier from `file`; the source only supplies
 * the file location and a display name.
 */
class ProductDownloadData
{
    public $file = '';
    public $name = '';

    public static function make(array $data)
    {
        $d = new self();
        foreach ($data as $key => $value) {
            if (property_exists($d, $key)) {
                $d->$key = $value;
            }
        }
        return $d;
    }
}
