<?php

namespace App\Http\Controllers\Activity;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public const AREAS = ['device', 'contact', 'conversation', 'template', 'automation', 'settings'];

    /**
     * Administrative audit trail (no message content is ever recorded).
     */
    public function __invoke(Request $request): Response
    {
        /** @var array{area?: string|null, user?: int|string|null} $filters */
        $filters = $request->validate([
            'area' => ['nullable', 'in:'.implode(',', self::AREAS)],
            'user' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($filters['area'] ?? null, fn (Builder $query, string $area) => $query->where('action', 'like', $area.'.%'))
            ->when($filters['user'] ?? null, fn (Builder $query, int|string $user) => $query->where('user_id', $user))
            ->latest('created_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('activity/index', [
            'logs' => $logs,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'areas' => self::AREAS,
            'filters' => ['area' => $filters['area'] ?? null, 'user' => isset($filters['user']) ? (int) $filters['user'] : null],
        ]);
    }
}
