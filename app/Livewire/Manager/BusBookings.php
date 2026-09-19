<?php

namespace App\Livewire\Manager;

use App\Models\Bus;
use App\Models\BusBooking;
use App\Models\BusBookingSettings;
use App\Models\BusHandoverItem;
use App\Models\Setting;
use App\Services\BusBookingService;
use Flux\Flux;
use Livewire\Component;

/**
 * Everything about bus booking that belongs to the manager: the fleet, when it
 * may be booked, what a stage promises on taking one, and the two links that
 * let people who have no account do any of this.
 */
class BusBookings extends Component
{
    /** @var array<int, int|string> */
    public array $weekdays = [];

    public string $penaltyText = '';

    public int $lockDays = 1;

    public string $officerPhone = '';

    public bool $sameWeekOnly = false;

    public int $feeDeadlineWeekday = 4;

    // ── bus being added or edited ───────────────────────────────────────────
    public ?int $editingBusId = null;

    public string $busName = '';

    public string $busType = '';

    public int $busFee = 0;

    // ── handover item being added or edited ─────────────────────────────────
    public ?int $editingItemId = null;

    public string $itemLabel = '';

    public function mount(): void
    {
        $this->weekdays = BusBookingSettings::weekdays();
        $this->penaltyText = BusBookingSettings::penaltyText();
        $this->lockDays = BusBookingSettings::lockDays();
        $this->officerPhone = BusBookingSettings::officerPhone();
        $this->sameWeekOnly = BusBookingSettings::sameWeekOnly();
        $this->feeDeadlineWeekday = BusBookingSettings::feeDeadlineWeekday();
    }

    public function saveSettings(): void
    {
        $this->validate([
            'weekdays' => 'array',
            'weekdays.*' => 'integer|between:1,7',
            'penaltyText' => 'nullable|string|max:5000',
            'lockDays' => 'integer|min:0|max:30',
            'officerPhone' => 'nullable|string|max:20',
            'sameWeekOnly' => 'boolean',
            'feeDeadlineWeekday' => 'integer|between:1,7',
        ], [
            'lockDays.min' => 'المهلة لا تكون سالبة.',
        ]);

        BusBookingSettings::setWeekdays($this->weekdays);
        Setting::setVal(BusBookingSettings::PENALTY_TEXT, $this->penaltyText);
        Setting::setVal(BusBookingSettings::LOCK_DAYS, $this->lockDays);
        Setting::setVal(BusBookingSettings::OFFICER_PHONE, $this->digitsOnly($this->officerPhone));
        Setting::setVal(BusBookingSettings::SAME_WEEK_ONLY, $this->sameWeekOnly ? 1 : 0);
        Setting::setVal(BusBookingSettings::FEE_DEADLINE_WEEKDAY, $this->feeDeadlineWeekday);

        Flux::toast(__('حُفظت الإعدادات'), variant: 'success');
    }

    // ── الباصات ─────────────────────────────────────────────────────────────

    public function editBus(int $id): void
    {
        $bus = Bus::findOrFail($id);

        $this->editingBusId = $bus->id;
        $this->busName = $bus->name;
        $this->busType = $bus->type ?? '';
        $this->busFee = $bus->fee_amount;
    }

    public function saveBus(): void
    {
        $this->validate([
            'busName' => 'required|string|max:255',
            'busType' => 'nullable|string|max:255',
            'busFee' => 'integer|min:0|max:100000',
        ], [
            'busName.required' => 'اسم الباص مطلوب.',
        ]);

        $attributes = [
            'name' => trim($this->busName),
            'type' => trim($this->busType) ?: null,
            'fee_amount' => $this->busFee,
        ];

        $this->editingBusId
            ? Bus::whereKey($this->editingBusId)->update($attributes)
            : Bus::create($attributes);

        $this->cancelBus();

        Flux::toast(__('حُفظ الباص'), variant: 'success');
    }

    public function cancelBus(): void
    {
        $this->reset(['editingBusId', 'busName', 'busType', 'busFee']);
    }

    public function toggleBus(int $id): void
    {
        $bus = Bus::findOrFail($id);
        $bus->update(['is_active' => ! $bus->is_active]);
    }

    /**
     * A bus with bookings behind it is retired rather than removed: deleting it
     * would take those bookings with it and leave past rulings referring to a
     * bus nobody can name.
     */
    public function deleteBus(int $id): void
    {
        $bus = Bus::withCount('bookings')->findOrFail($id);

        if ($bus->bookings_count > 0) {
            $bus->update(['is_active' => false]);

            Flux::toast(__('للباص حجوزات سابقة، فعُطِّل بدل حذفه حفاظاً على السجل.'), variant: 'warning');

            return;
        }

        $bus->delete();

        Flux::toast(__('حُذف الباص'), variant: 'success');
    }

    // ── بنود التسليم ────────────────────────────────────────────────────────

    public function editItem(int $id): void
    {
        $item = BusHandoverItem::findOrFail($id);

        $this->editingItemId = $item->id;
        $this->itemLabel = $item->label;
    }

    public function saveItem(): void
    {
        $this->validate([
            'itemLabel' => 'required|string|max:255',
        ], [
            'itemLabel.required' => 'نص البند مطلوب.',
        ]);

        if ($this->editingItemId) {
            BusHandoverItem::whereKey($this->editingItemId)->update(['label' => trim($this->itemLabel)]);
        } else {
            BusHandoverItem::create([
                'label' => trim($this->itemLabel),
                'position' => (int) BusHandoverItem::max('position') + 1,
            ]);
        }

        $this->cancelItem();

        Flux::toast(__('حُفظ البند'), variant: 'success');
    }

    public function cancelItem(): void
    {
        $this->reset(['editingItemId', 'itemLabel']);
    }

    public function toggleItem(int $id): void
    {
        $item = BusHandoverItem::findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);
    }

    /**
     * Retired rather than deleted, for the same reason a bus is: rulings already
     * made cite this item, and they must keep reading as they were made.
     */
    public function retireItem(int $id): void
    {
        BusHandoverItem::whereKey($id)->update(['is_active' => false]);

        Flux::toast(__('عُطِّل البند. السجلات السابقة تحتفظ بنصّه كما كان.'), variant: 'success');
    }

    public function moveItem(int $id, int $direction): void
    {
        $items = BusHandoverItem::orderBy('position')->orderBy('id')->get()->values();

        // Normalised first: positions may have collided or never been set, and a
        // swap only means anything once each item owns a distinct number.
        foreach ($items as $position => $item) {
            if ($item->position !== $position) {
                $item->update(['position' => $position]);
            }
        }

        $index = $items->search(fn (BusHandoverItem $item) => $item->id === $id);
        $target = $index === false ? null : ($items[$index + $direction] ?? null);

        if ($target === null) {
            return;
        }

        $items[$index]->update(['position' => $index + $direction]);
        $target->update(['position' => $index]);
    }

    // ── الروابط ─────────────────────────────────────────────────────────────

    public function regenerateLink(string $which): void
    {
        $key = $which === 'officer'
            ? BusBookingSettings::OFFICER_TOKEN
            : BusBookingSettings::SUPERVISOR_TOKEN;

        BusBookingSettings::regenerate($key);

        Flux::toast(__('جُدِّد الرابط. الرابط القديم لم يعد يعمل.'), variant: 'success');
    }

    private function digitsOnly(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public function render(BusBookingService $bookings)
    {
        return view('livewire.manager.bus-bookings', [
            // Nothing should ever be in here. It is shown anyway, because a clash
            // nobody is told about is settled on the morning of the trip, in the
            // car park, by whoever shouts loudest.
            'conflicts' => $bookings->allConflicts(),
            'buses' => Bus::withCount('bookings')->orderBy('name')->get(),
            'items' => BusHandoverItem::orderBy('position')->orderBy('id')->get(),
            'supervisorToken' => BusBookingSettings::supervisorToken(),
            'officerToken' => BusBookingSettings::officerToken(),
            'upcoming' => BusBooking::with(['stage:id,name', 'buses:id,name'])
                ->whereDate('date', '>=', now('Asia/Riyadh')->format('Y-m-d'))
                ->where('status', '!=', BusBooking::CANCELLED)
                ->orderBy('date')
                ->limit(15)
                ->get(),
        ]);
    }
}
