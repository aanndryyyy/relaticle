<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->dropForeign(['connected_account_id']);
        });

        Schema::table('emails', function (Blueprint $table): void {
            $table->foreignUlid('connected_account_id')->nullable()->change();
            $table->foreign('connected_account_id')
                ->references('id')
                ->on('connected_accounts')
                ->nullOnDelete();
        });
    }
};
