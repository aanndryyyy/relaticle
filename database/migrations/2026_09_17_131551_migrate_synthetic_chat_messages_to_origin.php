<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Literal copies of MessageOrigin::opener(): a migration must not depend on app code.
    private const string GREETING_OPENER = 'The user opened their setup conversation.';

    private const string RESUME_OPENER = 'The user decided the proposals above.';

    private const string MESSAGES = 'agent_conversation_messages';

    public function up(): void
    {
        DB::table(self::MESSAGES)
            ->where('role', 'user')
            ->where('content', 'like', '[approval]%')
            ->update(['origin' => 'resume']);

        $this->legacyContinuations()
            ->whereExists(function (Builder $conversation): void {
                $conversation->from('agent_conversations')
                    ->whereColumn('agent_conversations.id', self::MESSAGES.'.conversation_id')
                    ->where('agent_conversations.purpose', 'setup');
            })
            ->whereNotExists(function (Builder $earlier): void {
                $earlier->from(self::MESSAGES.' as earlier')
                    ->whereColumn('earlier.conversation_id', self::MESSAGES.'.conversation_id')
                    ->whereColumn('earlier.id', '<', self::MESSAGES.'.id');
            })
            ->update(['origin' => 'greeting']);

        $this->legacyContinuations()
            ->where('origin', 'typed')
            ->update(['origin' => 'resume']);

        DB::table(self::MESSAGES)->where('origin', 'greeting')->update(['content' => self::GREETING_OPENER]);
        DB::table(self::MESSAGES)->where('origin', 'resume')->update(['content' => self::RESUME_OPENER]);

        $this->legacyContinuations()->update(['meta' => DB::raw("meta - 'kind'")]);
    }

    private function legacyContinuations(): Builder
    {
        return DB::table(self::MESSAGES)
            ->where('role', 'user')
            ->whereRaw("meta->>'kind' = 'continuation'");
    }
};
