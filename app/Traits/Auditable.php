<?php

namespace App\Traits;

use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            app(ActivityLogService::class)->created($model);
        });

        static::updating(function (Model $model) {
            app(ActivityLogService::class)->updating($model);
        });

        static::updated(function (Model $model) {
            app(ActivityLogService::class)->updated($model);
        });

        static::deleting(function (Model $model) {
            app(ActivityLogService::class)->deleted($model);
        });

        static::restored(function (Model $model) {
            app(ActivityLogService::class)->restored($model);
        });
    }
};
