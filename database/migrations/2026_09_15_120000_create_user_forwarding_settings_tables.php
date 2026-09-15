<?php

declare(strict_types=1);

use App\Support\Migrations\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $workspaceId = TenantMigration::foreignKeyColumn();

        Schema::create('user_forwarding_settings', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            TenantMigration::addForeignKey($table);
            $table->string('sharing_tier', 30)->default('metadata_only');
            $table->timestamps();

            $table->unique(['user_id', $workspaceId]);
        });

        Schema::create('user_forwarding_full_access_grants', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            TenantMigration::addForeignKey($table);
            $table->foreignUlid('granted_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', $workspaceId, 'granted_user_id'], 'user_forwarding_grants_unique');
        });

        Schema::create('user_forwarding_blocklists', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            TenantMigration::addForeignKey($table);
            $table->string('type', 20);
            $table->string('value');
            $table->timestamps();

            $table->index(['user_id', $workspaceId, 'type', 'value']);
        });

        if (! $this->inboundMessageIdIndexExists()) {
            DB::statement(
                'CREATE UNIQUE INDEX emails_inbound_message_id_unique ON emails (workspace_id, user_id, rfc_message_id) WHERE connected_account_id IS NULL AND rfc_message_id IS NOT NULL'
            );
        }
    }

    private function inboundMessageIdIndexExists(): bool
    {
        $result = DB::selectOne("SELECT 1 FROM pg_indexes WHERE indexname = 'emails_inbound_message_id_unique'");

        return $result !== null;
    }
};
