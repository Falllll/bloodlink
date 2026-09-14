<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Auditing;

use App\Models\AuditLog;
use App\Models\User;
use App\Shared\Logging\SensitiveKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

final class AuditObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'created', [], $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $after = $model->getChanges();
        $before = array_intersect_key($model->getOriginal(), $after);

        $this->record($model, 'updated', $before, $after);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $model->getAttributes(), []);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function record(Model $model, string $action, array $before, array $after): void
    {
        $except = method_exists($model, 'auditExcept') ? $model->auditExcept() : [];

        $before = $this->filter($before, $except);
        $after = $this->filter($after, $except);

        if ($before === [] && $after === []) {
            return;
        }

        /** @var User|null $actor */
        $actor = Auth::user();

        AuditLog::create([
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'actor_id' => $actor?->id,
            'actor_facility_id' => $actor?->facility_id,
            'changes' => ['before' => $before, 'after' => $after],
            'trace_id' => Context::get('trace_id'),
            'ip' => request()->ip(),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $except
     * @return array<string, mixed>
     */
    private function filter(array $attributes, array $except): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, $except, true)) {
                continue;
            }

            $filtered[$key] = SensitiveKeys::isSensitive($key) ? SensitiveKeys::REDACTED : $value;
        }

        return $filtered;
    }
}
