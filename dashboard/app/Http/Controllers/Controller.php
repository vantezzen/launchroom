<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Audit\AuditActor;
use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The signed-in user as an audit actor, with IP and user daemon.
     */
    protected function auditActor(Request $request, User $user): AuditActor
    {
        return AuditActor::user($user, $request->ip(), $request->userAgent());
    }
}
