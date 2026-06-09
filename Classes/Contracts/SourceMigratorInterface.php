<?php

namespace FluentCartMigrator\Classes\Contracts;

/**
 * Contract every migration source (EDD, WooCommerce, SureCart, ...) implements.
 *
 * The orchestration layer (SourceManager, RestApi, Commands) talks to sources
 * only through this interface, so adding a new source is a matter of dropping in
 * a new implementation and registering it in SourceManager — no changes to the
 * REST/CLI layer required.
 *
 * Return types are intentionally omitted so existing implementations (e.g. the
 * EDD MigratorService) satisfy the contract without touching their signatures.
 */
interface SourceMigratorInterface
{
    /**
     * Machine key for this source, e.g. 'edd' or 'woocommerce'.
     */
    public function key();

    /**
     * Detection payload for the sources list shown in the admin UI.
     * Shape: ['key' => string, 'name' => string, 'detected' => bool, 'version' => ?string, ...].
     */
    public function detect();

    /**
     * @return true|\WP_Error true when the source is ready to migrate.
     */
    public function canMigrate();

    /**
     * Source-side counts (products, orders, customers, ...) for the stats screen.
     */
    public function getStats();

    /**
     * Current migration progress + failed-log count.
     */
    public function getStatus();

    public function migrateProducts();

    public function migrateCoupons();

    public function migrateTaxRates();

    /**
     * The order page the payments step should resume from (1-based).
     */
    public function getPaymentResumePage();

    /**
     * Time-boxed order migration. CLI callers pass a high/zero $maxSeconds.
     */
    public function migratePayments($page = 1, $perPage = 100, $maxSeconds = 25);

    public function migrateMissingCustomers();

    /**
     * Valid substeps accepted by recountStats() for this source.
     */
    public function getRecountSubsteps();

    public function recountStats($substep);

    public function getLogs();

    public function buildAndSaveSummary();

    public function getMigrationSummary();

    /**
     * @return array|\WP_Error reset migration state (dev-mode gated by implementation).
     */
    public function reset();
}
