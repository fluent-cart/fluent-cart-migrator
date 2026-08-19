<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_order_addresses row (billing or shipping). `order_id` is injected by
 * the writer, which also skips empty addresses.
 */
class AddressData
{
    public $type = 'billing';
    public $name = '';
    public $address1 = '';
    public $address2 = '';
    public $city = '';
    public $state = '';
    public $postcode = '';
    public $country = '';
    public $meta = [];
    public $createdAt = '';
    public $updatedAt = '';

    public static function make(array $data)
    {
        $a = new self();
        foreach ($data as $key => $value) {
            if (property_exists($a, $key)) {
                $a->$key = $value;
            }
        }
        return $a;
    }

    /**
     * True when there's enough to be worth storing.
     */
    public function isMeaningful()
    {
        return (bool) array_filter([$this->address1, $this->city, $this->country, $this->name]);
    }

    public function toArray()
    {
        return [
            'type'       => $this->type,
            'name'       => $this->name,
            'address_1'  => $this->address1,
            'address_2'  => $this->address2,
            'city'       => $this->city,
            'state'      => $this->state,
            'postcode'   => $this->postcode,
            'country'    => $this->country,
            'meta'       => json_encode($this->meta),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
