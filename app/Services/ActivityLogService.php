<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CompanyIntegration;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class ActivityLogService
{
    public const REDACTED = '[redacted]';

    /**
     * @var array<int, array{old: array<string, mixed>, new: array<string, mixed>}>
     */
    private static array $pendingUpdates = [];

    /**
     * @var list<string>
     */
    private const SECRET_KEYS = [
        'api_key',
        'client_secret',
        'access_token',
        'refresh_token',
        'password',
        'token',
        'secret',
        'auth_token',
        'authorization',
        'key_hash',
        'encrypted_key',
        'remember_token',
    ];

    /**
     * @var list<string>
     */
    private const IDENTITY_KEYS = [
        'id',
        'company_id',
        'product_id',
        'integration_type',
        'rentman_equipment_id',
        'flex_resource_id',
        'software_code',
        'quantity',
    ];

    /**
     * @var array<string, class-string<Model>>
     */
    private const RESTORABLE = [
        ActivityLog::ENTITY_COMPANY_INVENTORY => Equipment::class,
        ActivityLog::ENTITY_COMPANY_INTEGRATION => CompanyIntegration::class,
    ];

    public function shouldRecord(): bool
    {
        if (app()->environment('testing') && !Schema::hasTable('activity_logs')) {
            return false;
        }

        return true;
    }

    public function created(Model $model): void
    {
        if (!$this->shouldRecord()) {
            return;
        }

        $this->write(
            ActivityLog::ACTION_CREATED,
            $model,
            null,
            $this->snapshot($model, $model->getAttributes())
        );
    }

    public function updating(Model $model): void
    {
        $objectId = spl_object_id($model);
        unset(self::$pendingUpdates[$objectId]);

        if (!$this->shouldRecord()) {
            return;
        }

        $changes = $this->changedValues($model);
        if ($changes === null) {
            return;
        }

        self::$pendingUpdates[$objectId] = $changes;
    }

    public function updated(Model $model): void
    {
        $objectId = spl_object_id($model);
        $pending = self::$pendingUpdates[$objectId] ?? null;
        unset(self::$pendingUpdates[$objectId]);

        if ($pending === null || !$this->shouldRecord()) {
            return;
        }

        $this->write(
            ActivityLog::ACTION_UPDATED,
            $model,
            $pending['old'],
            $pending['new']
        );
    }

    public function deleted(Model $model): void
    {
        if (!$this->shouldRecord()) {
            return;
        }

        $this->write(
            ActivityLog::ACTION_DELETED,
            $model,
            $this->snapshot($model, $model->getAttributes()),
            null
        );
    }

    public function restored(Model $model): void
    {
        if (!$this->shouldRecord()) {
            return;
        }

        $this->write(
            ActivityLog::ACTION_RESTORED,
            $model,
            null,
            $this->snapshot($model, $model->getAttributes())
        );
    }

    public function restorableRecord(ActivityLog $log): ?Model
    {
        if ($log->action !== ActivityLog::ACTION_DELETED) {
            return null;
        }

        $class = self::RESTORABLE[$log->entity_type] ?? null;
        if ($class === null) {
            return null;
        }

        $record = $class::withTrashed()->find($log->entity_id);
        if (!$record || !method_exists($record, 'trashed') || !$record->trashed()) {
            return null;
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public function forDisplay(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return $this->sanitizeArray($values);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function write(string $action, Model $model, ?array $oldValues, ?array $newValues): void
    {
        $request = request();
        $userAgent = $request?->userAgent();
        $companyId = $model->getAttributes()['company_id'] ?? $model->getRawOriginal('company_id');

        ActivityLog::create([
            'company_id' => $companyId !== null && $companyId !== '' ? (int) $companyId : null,
            'user_id' => $this->actorId(),
            'action' => $action,
            'entity_type' => $model->getTable(),
            'entity_id' => (int) $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 2000) : null,
        ]);
    }

    /**
     * @return array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    private function changedValues(Model $model): ?array
    {
        $old = [];
        $new = [];

        foreach ($model->getDirty() as $key => $value) {
            if ($this->isIgnored($model, (string) $key)) {
                continue;
            }

            $old[$key] = $this->normalize((string) $key, $model->getRawOriginal($key));
            $new[$key] = $this->normalize((string) $key, $value);
        }

        if ($old === []) {
            return null;
        }

        $original = $model->getRawOriginal();
        $current = $model->getAttributes();

        return [
            'old' => array_merge($this->identity($model, is_array($original) ? $original : []), $old),
            'new' => array_merge($this->identity($model, $current), $new),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(Model $model, array $attributes): array
    {
        $snapshot = [];

        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            if ($this->isIgnored($model, $key)) {
                continue;
            }

            $snapshot[$key] = $this->normalize($key, $value);
        }

        return array_merge($snapshot, $this->supplements($model, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function identity(Model $model, array $attributes): array
    {
        $identity = [];

        foreach (self::IDENTITY_KEYS as $key) {
            if (!array_key_exists($key, $attributes) || $this->isIgnored($model, $key)) {
                continue;
            }

            $identity[$key] = $this->normalize($key, $attributes[$key]);
        }

        if (isset($attributes['id'])) {
            $identity['id'] = $this->normalize('id', $attributes['id']);
        }

        return array_merge($identity, $this->supplements($model, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function supplements(Model $model, array $attributes): array
    {
        if (!method_exists($model, 'auditSupplements')) {
            return [];
        }

        try {
            $extra = $model->auditSupplements($attributes);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($extra)) {
            return [];
        }

        $clean = [];
        foreach ($extra as $key => $value) {
            $clean[(string) $key] = $this->normalize((string) $key, $value);
        }

        return $clean;
    }

    private function isIgnored(Model $model, string $key): bool
    {
        $ignored = [
            'created_at',
            'updated_at',
            'deleted_at',
            'active_rentman_key',
            'active_flex_key',
            'active_integration_key',
        ];

        if (method_exists($model, 'auditIgnoredAttributes')) {
            $ignored = array_merge($ignored, $model->auditIgnoredAttributes());
        }

        return in_array($key, $ignored, true);
    }

    private function normalize(string $key, mixed $value): mixed
    {
        if ($this->isSecretKey($key)) {
            return $this->redacted($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $this->sanitizeArray($decoded);
                }
            }

            if (mb_strlen($value) > 2000) {
                return mb_substr($value, 0, 2000).'…';
            }

            return $value;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->normalize($key, (string) $value);
        }

        return null;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function sanitizeArray(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $clean[$key] = $this->redacted($value);
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeArray($value);
                continue;
            }

            $clean[$key] = is_string($key)
                ? $this->normalize($key, $value)
                : $value;
        }

        return $clean;
    }

    private function isSecretKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (str_ends_with($normalized, '_at')) {
            return false;
        }

        if (in_array($normalized, self::SECRET_KEYS, true)) {
            return true;
        }

        foreach (['password', 'api_key', 'secret', 'access_token', 'refresh_token', 'token'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function redacted(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::REDACTED;
    }

    private function actorId(): ?int
    {
        $guards = array_unique(array_filter([
            (string) config('auth.defaults.guard'),
            'web',
            'api',
        ]));

        foreach (array_keys(config('auth.guards', [])) as $guard) {
            $guards[] = (string) $guard;
        }

        foreach (array_unique($guards) as $guard) {
            if ($guard === '') {
                continue;
            }

            try {
                $id = Auth::guard($guard)->id();
            } catch (\Throwable $e) {
                continue;
            }

            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }
};
