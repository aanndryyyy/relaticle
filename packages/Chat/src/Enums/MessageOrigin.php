<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Context;

enum MessageOrigin: string implements HasColor, HasLabel
{
    case Typed = 'typed';

    case Greeting = 'greeting';

    case Resume = 'resume';

    public const string CONTEXT_KEY = 'chat.message_origin';

    public static function current(): self
    {
        $value = Context::getHidden(self::CONTEXT_KEY);

        return is_string($value) ? self::from($value) : self::Typed;
    }

    public function isTyped(): bool
    {
        return $this === self::Typed;
    }

    public function opener(): ?string
    {
        return match ($this) {
            self::Typed => null,
            self::Greeting => 'The user opened their setup conversation.',
            self::Resume => 'The user decided the proposals above.',
        };
    }

    public function directive(): ?string
    {
        return match ($this) {
            self::Typed => null,
            self::Greeting => 'The user finished signing up a moment ago and just opened this conversation. Nobody has typed anything: you speak first, and they are watching this message appear. Greet them by first name. Say in one line what their workspace is ready for, naming the first and last stage from the stages line when there is one. Then ask them to bring their own data in: paste a list of contacts in any columns and any order, attach a CSV, or describe a few people they are working with right now. Three short paragraphs at most, no lists, no headings, and call no tools in this turn.',
            self::Resume => 'The proposals from your last turn have just been decided. Their outcome is in <resolved_actions>. Confirm what happened in one short sentence, naming each record as a link. If a step of the request is still outstanding and you can act on it now, do it in this turn. If nothing is left, say so and stop.',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Typed => __('Typed'),
            self::Greeting => __('Greeting'),
            self::Resume => __('Resume'),
        };
    }

    public function getColor(): string
    {
        return $this->isTyped() ? 'gray' : 'warning';
    }
}
