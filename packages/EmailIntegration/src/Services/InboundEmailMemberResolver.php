<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

final class InboundEmailMemberResolver
{
    public function resolve(Workspace $workspace, string $envelopeFrom): ?User
    {
        $email = Str::lower(trim($envelopeFrom));

        if ($email === '') {
            return null;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        return $user->belongsToWorkspace($workspace) ? $user : null;
    }
}
