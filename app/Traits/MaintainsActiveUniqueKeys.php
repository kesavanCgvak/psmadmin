<?php

namespace App\Traits;

use App\Models\Scopes\ReleaseActiveUniqueKeysScope;
use Illuminate\Support\Facades\Schema;

trait MaintainsActiveUniqueKeys
{
    public static function bootMaintainsActiveUniqueKeys(): void
    {
        static::saving(function ($model) {
            $model->syncActiveUniqueKeys();
        });

        static::addGlobalScope(new ReleaseActiveUniqueKeysScope);
    }

    public function syncActiveUniqueKeys(): void
    {
        if (!$this->activeUniqueKeysAvailable()) {
            return;
        }

        $release = $this->exists && method_exists($this, 'trashed') && $this->trashed();

        foreach ($this->activeUniqueKeyMap() as $column => $value) {
            $this->setAttribute($column, $release ? null : $value);
        }
    }

    /**
     * @return list<string>
     */
    public function releasedUniqueKeyColumns(): array
    {
        if (!$this->activeUniqueKeysAvailable()) {
            return [];
        }

        return array_keys($this->activeUniqueKeyMap());
    }

    /**
     * @return array<string, ?string>
     */
    abstract public function activeUniqueKeyMap(): array;

    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());
        $time = $this->freshTimestamp();
        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->usesTimestamps() && !is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;
            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        foreach ($this->releasedUniqueKeyColumns() as $column) {
            $columns[$column] = null;
            $this->setAttribute($column, null);
        }

        $query->update($columns);
        $this->syncOriginalAttributes(array_keys($columns));
        $this->fireModelEvent('trashed', false);
    }

    protected function composeActiveKey(mixed $companyId, mixed $externalId): ?string
    {
        $externalId = trim((string) $externalId);
        if ($companyId === null || $companyId === '' || $externalId === '') {
            return null;
        }

        return mb_substr((string) $companyId.':'.$externalId, 0, 191);
    }

    private function activeUniqueKeysAvailable(): bool
    {
        $columns = array_keys($this->activeUniqueKeyMap());
        $column = $columns[0] ?? null;
        if ($column === null) {
            return false;
        }

        $table = $this->getTable();

        if (app()->environment('testing')) {
            return Schema::hasColumn($table, $column);
        }

        static $ready = [];
        if (!array_key_exists($table, $ready)) {
            $ready[$table] = Schema::hasColumn($table, $column);
        }

        return $ready[$table];
    }
}
