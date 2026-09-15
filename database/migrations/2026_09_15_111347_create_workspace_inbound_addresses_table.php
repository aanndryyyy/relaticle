<?php

declare(strict_types=1);

use App\Support\Migrations\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $workspaceId = TenantMigration::foreignKeyColumn();

        Schema::create('workspace_inbound_addresses', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            TenantMigration::addForeignKey($table);
            $table->string('local_part')->unique();
            $table->string('email')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_received_at')->nullable();
            $table->timestamps();

            $table->index([$workspaceId, 'is_active']);
        });
    }
};
