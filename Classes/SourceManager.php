<?php

namespace FluentCartMigrator\Classes;

use FluentCartMigrator\Classes\Contracts\SourceMigratorInterface;
use FluentCartMigrator\Classes\WooCommerce\WooSourceMigrator;

/**
 * Registry that maps a source key to its migrator implementation.
 *
 * RestApi and Commands resolve the active source through here, so the rest of
 * the plugin never hardcodes "edd". To add WooCommerce/SureCart, register the
 * implementation in $map (and drop it from $comingSoon).
 */
class SourceManager
{
    /**
     * Implemented sources: key => migrator class implementing SourceMigratorInterface.
     */
    protected $map = [
        'edd'         => MigratorService::class,
        'woocommerce' => WooSourceMigrator::class,
    ];

    /**
     * Announced-but-not-yet-implemented sources, surfaced in the UI as "coming soon".
     */
    protected $comingSoon = [
        'surecart' => 'SureCart',
    ];

    public function has($source)
    {
        return isset($this->map[$source]);
    }

    /**
     * @return SourceMigratorInterface|null
     */
    public function resolve($source)
    {
        if (!$this->has($source)) {
            return null;
        }

        $class = $this->map[$source];

        return new $class();
    }

    /**
     * Keys of implemented sources.
     */
    public function keys()
    {
        return array_keys($this->map);
    }

    /**
     * Detection payloads for every source (implemented + coming soon), for the
     * admin sources list.
     */
    public function sources()
    {
        $sources = [];

        foreach ($this->map as $class) {
            /** @var SourceMigratorInterface $migrator */
            $migrator  = new $class();
            $sources[] = $migrator->detect();
        }

        foreach ($this->comingSoon as $key => $name) {
            $sources[] = [
                'key'         => $key,
                'name'        => $name,
                'detected'    => false,
                'coming_soon' => true,
            ];
        }

        return $sources;
    }
}
