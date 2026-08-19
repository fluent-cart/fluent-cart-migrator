<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * A normalized customer. The writer dedupes by email and injects the uuid.
 */
class CustomerData
{
    public $userId = null;
    public $email = '';
    public $firstName = '';
    public $lastName = '';
    public $status = 'active';
    public $country = '';
    public $city = '';
    public $state = '';
    public $postcode = '';
    public $createdAt = '';
    public $updatedAt = '';

    public static function make(array $data)
    {
        $c = new self();
        foreach ($data as $key => $value) {
            if (property_exists($c, $key)) {
                $c->$key = $value;
            }
        }
        return $c;
    }

    /**
     * The fct_customers row (without uuid, which the writer generates).
     */
    public function toArray()
    {
        return [
            'user_id'    => $this->userId ?: null,
            'email'      => $this->email,
            'first_name' => $this->firstName,
            'last_name'  => $this->lastName,
            'status'     => $this->status,
            'country'    => $this->country,
            'city'       => $this->city,
            'state'      => $this->state,
            'postcode'   => $this->postcode,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
