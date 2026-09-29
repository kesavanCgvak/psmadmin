<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Mass soft-deletes must clear active unique keys, otherwise a deleted
 * rental-software id still blocks the next import.
 */
class ReleaseActiveUniqueKeysScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
    }

    public function extend(Builder $builder): void
    {
        $builder->onDelete(function (Builder $builder) {
            $model = $builder->getModel();
            $columns = [
                $model->getDeletedAtColumn() => $model->freshTimestampString(),
            ];

            if (method_exists($model, 'releasedUniqueKeyColumns')) {
                foreach ($model->releasedUniqueKeyColumns() as $column) {
                    $columns[$column] = null;
                }
            }

            return $builder->update($columns);
        });
    }
}
