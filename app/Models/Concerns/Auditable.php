<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->recordAudit('created');
        });

        static::updated(function ($model) {
            $model->recordAudit('updated');
        });

        static::deleted(function ($model) {
            $model->recordAudit('deleted');
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->recordAudit('restored');
            });
        }
    }

    public function recordAudit(string $event): void
    {
        if (! ($this->auditableEvents ?? true) && ! in_array($event, $this->auditableEvents ?? [])) {
            return;
        }

        if (($this->auditExclude ?? false) && in_array($event, $this->auditExclude ?? [])) {
            return;
        }

        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'module' => $this->auditModule ?? $this->getModuleKey(),
                'event' => $event,
                'auditable_type' => $this->getMorphClass(),
                'auditable_id' => $this->getKey(),
                'description' => $this->auditDescription ?? null,
                'old_values' => $event === 'updated' ? $this->auditableAttributesDiff()[0] : null,
                'new_values' => $event === 'deleted' ? null : $this->auditableAttributesDiff()[1],
                'ip_address' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed: '.$e->getMessage());
        }
    }

    /**
     * [old, new] attribute snapshots for the audited columns.
     */
    protected function auditableAttributesDiff(): array
    {
        $columns = $this->auditableAttributes ?? $this->getFillable();

        if ($this->exists) {
            $original = $this->getOriginal();
            $current = $this->getAttributes();

            $old = [];
            $new = [];

            foreach ($columns as $column) {
                $key = $column === 'id' ? 'id' : $column;
                if (array_key_exists($key, $original) && array_key_exists($key, $current)) {
                    $old[$key] = $this->toAuditableValue($original[$key]);
                    $new[$key] = $this->toAuditableValue($current[$key]);
                }
            }

            return [$old, $new];
        }

        $new = [];
        foreach ($columns as $column) {
            if ($this->hasAttribute($column)) {
                $new[$column] = $this->toAuditableValue($this->getAttribute($column));
            }
        }

        return [null, $new];
    }

    protected function toAuditableValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    protected function getModuleKey(): string
    {
        return strtolower(class_basename($this));
    }

    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
