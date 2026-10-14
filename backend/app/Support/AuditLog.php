<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// One line per security-relevant event (failed logins, denied access, admin
// actions), always with who and from where, so "what happened to this order /
// account" can be answered from the logs. Never pass passwords or tokens.
class AuditLog
{
    public static function record(string $event, ?Request $request = null, array $context = []): void
    {
        $request ??= request();
        Log::warning('audit: '.$event, array_filter([
            'user' => $request->user()?->UserID,
            'role' => $request->user()?->Role,
            'ip' => $request->ip(),
            'path' => $request->method().' '.$request->path(),
        ] + $context, fn ($value) => $value !== null));
    }
}
