<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // bpchar to varchar is a function cast, so this rewrites the table and
        // both its indexes under ACCESS EXCLUSIVE. Bounded so an in-flight media
        // write cannot queue every read of the table behind a pending lock.
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('media', function (Blueprint $table): void {
            $table->string('model_id', 36)->change();
        });
    }
};
