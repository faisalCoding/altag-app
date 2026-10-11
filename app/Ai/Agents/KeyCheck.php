<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The smallest request a provider will answer, sent from the AI settings page
 * so the manager knows a key works before the assistant needs it. No tools
 * and no data from the academy go with it. The token budget leaves room for
 * models that think before answering; the reply itself is one word.
 */
#[MaxTokens(512)]
class KeyCheck implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Reply with the single word: OK';
    }
}
