<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Multi-selection for a list of accounts, plus a copyable block pairing every
 * selected person with their magic login link.
 *
 * Written once for students and now shared by the four directories, because the
 * only things that actually differ between them are the model, the route their
 * token opens, and the word for who they are.
 *
 * Consuming components supply two queries: every account the component may act
 * on, and that same set narrowed by whatever is on screen.
 */
trait CopiesMagicLinks
{
    /** @var array<int, string> */
    public array $selectedIds = [];

    public bool $selectAll = false;

    /**
     * Every account this component may act on, before any filtering.
     *
     * @return Builder<Model>
     */
    abstract protected function selectableQuery();

    /**
     * The accounts currently listed, with the active filters applied.
     *
     * @return Builder<Model>
     */
    abstract protected function filteredQuery();

    /** The named route a token of this kind opens. */
    abstract protected function magicLinkRoute(): string;

    /** Who these accounts are, for the heading and the warning. */
    abstract protected function magicLinkAudience(): string;

    /**
     * The selection, re-resolved through the component's own scope, so an id
     * edited in a browser can never widen what the component touches.
     *
     * @return Builder<Model>
     */
    protected function selectedQuery()
    {
        return $this->selectableQuery()->whereIn('id', $this->selectedIds);
    }

    public function resetSelection(): void
    {
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Selection deliberately survives searching and filtering, so a list can be
     * gathered over several searches. Only the header checkbox is cleared,
     * because it describes the rows on screen rather than the selection.
     */
    public function resetSelectAllToggle(): void
    {
        $this->selectAll = false;
    }

    /**
     * Ticking the header checkbox adds the filtered rows to the selection
     * rather than replacing it; unticking removes only those same rows, so
     * picks made under other filters survive.
     */
    public function updatedSelectAll(bool $value): void
    {
        $filtered = $this->filteredIds();

        $this->selectedIds = $value
            ? array_values(array_unique(array_merge($this->selectedIds, $filtered)))
            : array_values(array_diff($this->selectedIds, $filtered));
    }

    /**
     * How many of the selected the active filters currently hide, so the page
     * can say that an action reaches further than the visible rows.
     */
    public function selectedOutsideFiltersCount(): int
    {
        if ($this->selectedIds === []) {
            return 0;
        }

        $visible = $this->filteredQuery()->whereIn('id', $this->selectedIds)->count();

        return count($this->selectedIds) - $visible;
    }

    /**
     * @return array<int, string>
     */
    private function filteredIds(): array
    {
        return $this->filteredQuery()->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * A shareable block pairing every selected person with their magic link,
     * issuing a token to any account that never had one.
     */
    public function buildSelectedMagicLinksText(): string
    {
        if ($this->selectedIds === []) {
            return '';
        }

        $people = $this->selectedQuery()->orderBy('name')->get();

        if ($people->isEmpty()) {
            return '';
        }

        $audience = $this->magicLinkAudience();

        $lines = $people->map(function (Model $person) {
            if (blank($person->access_token)) {
                $person->update(['access_token' => Str::random(32)]);
            }

            return "⦿ {$person->name}:\n".route($this->magicLinkRoute(), ['token' => $person->access_token]);
        });

        return "🔗 روابط الدخول السحرية — {$audience}\n"
            ."كل رابط أدناه خاص بشخص واحد، ويفتح حسابه مباشرة دون كلمة مرور.\n\n"
            .$lines->implode("\n\n")
            ."\n\n⚠️ تنبيه: أرسِل لكل واحد رابطه الخاص به فقط، ولا تشارك هذه الروابط مع غيره.";
    }

    /**
     * Mint fresh tokens for the selection, so a list that leaked can be killed
     * in one act instead of one account at a time.
     *
     * @return int how many links were replaced
     */
    public function regenerateSelectedMagicLinks(): int
    {
        $people = $this->selectedQuery()->get();

        foreach ($people as $person) {
            $person->update(['access_token' => Str::random(32)]);
        }

        return $people->count();
    }
}
