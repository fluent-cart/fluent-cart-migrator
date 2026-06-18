<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_licenses row plus its activations. Source-specific (EDD) but the
 * write itself is standard, so the shared writer persists it when present.
 * `order_id`, `customer_id` and `subscription_id` are injected by the writer.
 */
class LicenseData
{
    public $status = 'active';
    public $limit = 0;
    public $activationCount = 0;
    public $licenseKey = '';
    public $productId = 0;
    public $variationId = null;
    public $expirationDate = null;
    public $config = [];
    public $createdAt = '';
    public $updatedAt = '';

    /** @var array[] each: ['site_id','status','is_local','product_id','variation_id','activation_method','activation_hash','created_at','updated_at'] */
    public $activations = [];

    public static function make(array $data)
    {
        $l = new self();
        foreach ($data as $key => $value) {
            if (property_exists($l, $key)) {
                $l->$key = $value;
            }
        }
        return $l;
    }

    public function toArray()
    {
        return [
            'status'           => $this->status,
            'limit'            => $this->limit,
            'activation_count' => $this->activationCount,
            'license_key'      => $this->licenseKey,
            'product_id'       => $this->productId,
            'variation_id'     => $this->variationId,
            'expiration_date'  => $this->expirationDate,
            'config'           => json_encode($this->config),
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }
}
