<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class BulkDeletionService
{
    /**
     * Perform bulk deletion with relation checks and partial success handling.
     *
     * @param array<int, Model> $models
     * @param array<int, callable(Model): (?string)> $checkers  Each checker returns a reason string to block deletion, or null if OK
     * @return array{deleted_count:int, blocked:array<int, array{label:string, reason:string}>, errors:array<int, string>}
     */
    public function deleteWithChecks(array $models, array $checkers = []): array
    {
        $deletedCount = 0;
        $blocked = [];
        $errors = [];

        foreach ($models as $model) {
            if (!$model instanceof Model) {
                continue;
            }

            // Run all checkers; if any returns a reason, block deletion
            $blockReason = null;
            foreach ($checkers as $checker) {
                $reason = $checker($model);
                if (is_string($reason) && $reason !== '') {
                    $blockReason = $reason;
                    break;
                }
            }

            if ($blockReason !== null) {
                $blocked[] = [
                    'label' => $this->getDisplayLabel($model),
                    'reason' => $blockReason,
                ];
                continue;
            }

            try {
                $model->delete();
                $deletedCount++;
            } catch (\Throwable $e) {
                $errors[] = 'Failed to delete ' . $this->inferModelName($model) . ' "' . $this->getDisplayLabel($model) . '" - ' . $e->getMessage();
            }
        }

        return [
            'deleted_count' => $deletedCount,
            'blocked' => $blocked,
            'errors' => $errors,
        ];
    }

    private function getDisplayLabel(Model $model): string
    {
        foreach (['name', 'model', 'code', 'title'] as $field) {
            if (isset($model->{$field}) && is_string($model->{$field}) && $model->{$field} !== '') {
                return (string) $model->{$field};
            }
        }
        return (string) ($model->id ?? 'ID');
    }

    private function inferModelName(Model $model): string
    {
        $classBase = class_basename($model);
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $classBase));
    }
}


