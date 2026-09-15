<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;

/**
 * @extends Factory<UserForwardingBlocklist>
 */
final class UserForwardingBlocklistFactory extends Factory
{
    protected $model = UserForwardingBlocklist::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'type' => EmailBlocklistType::EMAIL,
            'value' => fake()->unique()->safeEmail(),
        ];
    }
}
