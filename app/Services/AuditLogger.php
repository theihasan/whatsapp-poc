<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records administrative actions. Callers must not pass message bodies, QR codes, tokens
 * or other secrets in $properties.
 */
class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * @param  array<string, scalar|null>  $properties
     */
    public function record(string $action, ?Model $subject = null, array $properties = []): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => $this->request->user()?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => $this->request->ip(),
        ]);
    }
}
