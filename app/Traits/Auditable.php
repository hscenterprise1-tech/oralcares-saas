<?php

namespace App\Traits;

use App\ActivityType;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable()
    {
        static::created(function (Model $model) {
            $model->logActivity(ActivityType::RecordCreated, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $model->logActivity(ActivityType::RecordUpdated, $model->getDirty());
        });

        static::deleted(function (Model $model) {
            $model->logActivity(ActivityType::RecordDeleted, $model->getAttributes());
        });
    }

    protected function logActivity(ActivityType $action, array $properties)
    {
        if (auth()->check()) {
            Activity::create([
                'subject_type' => get_class($this),
                'subject_id' => $this->id,
                'user_id' => auth()->id(),
                'type' => $action,
                'description' => class_basename($this)." was {$action->value}",
                'properties' => $properties,
            ]);
        }
    }
}
