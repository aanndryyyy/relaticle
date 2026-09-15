<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\UserForwardingFullAccessGrant;

/**
 * @extends Factory<UserForwardingFullAccessGrant>
 */
final class UserForwardingFullAccessGrantFactory extends Factory
{
    protected $model = UserForwardingFullAccessGrant::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'granted_user_id' => User::factory(),
        ];
    }
}
