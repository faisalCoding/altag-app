<?php

namespace App\Support;

/**
 * The login links the assistant chose to show the manager in the current
 * request, held as accounts rather than links. The assistant's tool picks the
 * people; the chat renders their links on the page once the reply is done,
 * so a link never passes through the AI provider.
 */
class MagicLinkTray
{
    /** @var array<int, array{role: string, ids: array<int, int>}> */
    private array $batches = [];

    /**
     * @param  array<int, int>  $ids
     */
    public function add(string $role, array $ids): void
    {
        if ($ids !== []) {
            $this->batches[] = ['role' => $role, 'ids' => array_values(array_map('intval', $ids))];
        }
    }

    /**
     * Hand over what was gathered, and start empty again.
     *
     * @return array<int, array{role: string, ids: array<int, int>}>
     */
    public function take(): array
    {
        $batches = $this->batches;
        $this->batches = [];

        return $batches;
    }
}
