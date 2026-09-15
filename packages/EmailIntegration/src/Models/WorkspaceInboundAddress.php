<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use Carbon\CarbonInterface;
use Database\Factories\WorkspaceInboundAddressFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $local_part
 * @property string $email
 * @property bool $is_active
 * @property CarbonInterface|null $last_received_at
 */
final class WorkspaceInboundAddress extends Model
{
    /** @use HasFactory<WorkspaceInboundAddressFactory> */
    use HasFactory, HasUlids, HasWorkspace;

    protected static function newFactory(): WorkspaceInboundAddressFactory
    {
        return WorkspaceInboundAddressFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'local_part',
        'email',
        'is_active',
        'last_received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_received_at' => 'datetime',
        ];
    }

    public function fullAddress(): string
    {
        return $this->email;
    }
}
