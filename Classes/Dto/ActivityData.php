<?php

namespace FluentCartMigrator\Classes\Dto;

/**
 * One fct_activity row (order/subscription note). Source-specific (EDD); the
 * shared writer bulk-inserts these when present. `module_id` defaults to the
 * order id but the source may target a subscription.
 */
class ActivityData
{
    public $status = 'info';
    public $logType = 'activity';
    public $moduleType = 'FluentCart\App\Models\Order';
    public $moduleId = 0;
    public $moduleName = 'Order';
    public $userId = null;
    public $title = '';
    public $content = '';
    public $readStatus = 'read';
    public $createdBy = 'Migrator';
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

    public function toArray()
    {
        return [
            'status'      => $this->status,
            'log_type'    => $this->logType,
            'module_type' => $this->moduleType,
            'module_id'   => $this->moduleId,
            'module_name' => $this->moduleName,
            'user_id'     => $this->userId,
            'title'       => $this->title,
            'content'     => $this->content,
            'read_status' => $this->readStatus,
            'created_by'  => $this->createdBy,
            'created_at'  => $this->createdAt,
            'updated_at'  => $this->updatedAt,
        ];
    }
}
