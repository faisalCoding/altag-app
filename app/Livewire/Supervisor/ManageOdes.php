<?php

namespace App\Livewire\Supervisor;

use App\Models\Ode;
use App\Models\OdeVerse;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ManageOdes extends Component
{
    public string $search = '';

    // Ode Form Fields
    #[Locked]
    public ?int $editingOdeId = null;

    public string $name = '';

    public string $description = '';

    // Verses Management Fields
    #[Locked]
    public ?int $selectedOdeId = null;

    // New Verse Fields
    public ?int $newVerseNumber = null;

    public string $newSadr = '';

    public string $newAjuz = '';

    // Editing Verse Fields
    #[Locked]
    public ?int $editingVerseId = null;

    public ?int $editingVerseNumber = null;

    public string $editingSadr = '';

    public string $editingAjuz = '';

    // Bulk Import Field
    public string $bulkText = '';

    public bool $showBulkImport = false;

    public string $versesText = '';

    public function selectOde(?int $id): void
    {
        $this->selectedOdeId = $id;
        $this->resetVerseForm();
        if ($id) {
            $this->autoFillNextVerseNumber();
        }
    }

    public function createOde(): void
    {
        $this->reset(['editingOdeId', 'name', 'description', 'versesText']);
        Flux::modal('ode-modal')->show();
    }

    public function editOde(int $id): void
    {
        $ode = Ode::findOrFail($id);
        $this->editingOdeId = $ode->id;
        $this->name = $ode->name;
        $this->description = $ode->description ?? '';
        Flux::modal('ode-modal')->show();
    }

    public function saveOde(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'versesText' => 'nullable|string',
        ]);

        if ($this->editingOdeId) {
            $ode = Ode::findOrFail($this->editingOdeId);
            $ode->update([
                'name' => $this->name,
                'description' => $this->description,
            ]);
            Flux::toast('تم تعديل المنظومة بنجاح', variant: 'success');
        } else {
            $ode = Ode::create([
                'name' => $this->name,
                'description' => $this->description,
            ]);

            if (! empty($this->versesText)) {
                $lines = explode("\n", $this->versesText);
                $nextNum = 0;
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    // Split by tabs, double spaces, or hash symbol as separators
                    $parts = preg_split('/\t|\s{2,}|\s*#\s*/', $line);

                    if (count($parts) >= 2) {
                        $nextNum++;
                        $sadr = trim($parts[0]);
                        $ajuz = trim($parts[1]);

                        OdeVerse::create([
                            'ode_id' => $ode->id,
                            'verse_number' => $nextNum,
                            'sadr' => $sadr,
                            'ajuz' => $ajuz,
                        ]);
                    }
                }
            }

            Flux::toast('تم إنشاء المنظومة بنجاح', variant: 'success');
            $this->selectedOdeId = $ode->id; // Auto select for managing verses
            $this->autoFillNextVerseNumber();
        }

        Flux::modal('ode-modal')->close();
        $this->reset(['editingOdeId', 'name', 'description', 'versesText']);
    }

    public function deleteOde(int $id): void
    {
        $ode = Ode::findOrFail($id);
        $ode->delete();

        if ($this->selectedOdeId === $id) {
            $this->selectedOdeId = null;
            $this->resetVerseForm();
        }

        Flux::toast('تم حذف المنظومة بنجاح', variant: 'success');
    }

    // --- Verse Management Functions ---

    public function autoFillNextVerseNumber(): void
    {
        if (! $this->selectedOdeId) {
            return;
        }

        $max = OdeVerse::where('ode_id', $this->selectedOdeId)->max('verse_number');
        $this->newVerseNumber = $max ? $max + 1 : 1;
    }

    public function saveVerse(): void
    {
        $this->validate([
            'newVerseNumber' => 'required|integer|min:1',
            'newSadr' => 'required|string|max:500',
            'newAjuz' => 'required|string|max:500',
        ]);

        OdeVerse::create([
            'ode_id' => $this->selectedOdeId,
            'verse_number' => $this->newVerseNumber,
            'sadr' => $this->newSadr,
            'ajuz' => $this->newAjuz,
        ]);

        Flux::toast('تم إضافة البيت بنجاح', variant: 'success');
        $this->reset(['newSadr', 'newAjuz']);
        $this->autoFillNextVerseNumber();
    }

    public function startEditVerse(int $id): void
    {
        $verse = OdeVerse::findOrFail($id);
        $this->editingVerseId = $verse->id;
        $this->editingVerseNumber = $verse->verse_number;
        $this->editingSadr = $verse->sadr;
        $this->editingAjuz = $verse->ajuz;
    }

    public function saveEditingVerse(): void
    {
        $this->validate([
            'editingVerseNumber' => 'required|integer|min:1',
            'editingSadr' => 'required|string|max:500',
            'editingAjuz' => 'required|string|max:500',
        ]);

        $verse = OdeVerse::findOrFail($this->editingVerseId);

        $verse->update([
            'verse_number' => $this->editingVerseNumber,
            'sadr' => $this->editingSadr,
            'ajuz' => $this->editingAjuz,
        ]);

        Flux::toast('تم تعديل البيت بنجاح', variant: 'success');
        $this->reset(['editingVerseId', 'editingVerseNumber', 'editingSadr', 'editingAjuz']);
    }

    public function cancelEditVerse(): void
    {
        $this->reset(['editingVerseId', 'editingVerseNumber', 'editingSadr', 'editingAjuz']);
    }

    public function deleteVerse(int $id): void
    {
        $verse = OdeVerse::findOrFail($id);
        $verse->delete();
        Flux::toast('تم حذف البيت بنجاح', variant: 'success');
        $this->autoFillNextVerseNumber();
    }

    public function resetVerseForm(): void
    {
        $this->reset([
            'newVerseNumber', 'newSadr', 'newAjuz',
            'editingVerseId', 'editingVerseNumber', 'editingSadr', 'editingAjuz',
            'bulkText', 'showBulkImport',
        ]);
    }

    // --- Bulk Import of Verses ---

    public function openBulkImport(): void
    {
        $this->bulkText = '';
        $this->showBulkImport = true;
    }

    public function closeBulkImport(): void
    {
        $this->showBulkImport = false;
        $this->bulkText = '';
    }

    public function importBulkVerses(): void
    {
        $this->validate([
            'bulkText' => 'required|string',
        ]);

        $lines = explode("\n", $this->bulkText);
        $importedCount = 0;
        $nextNum = OdeVerse::where('ode_id', $this->selectedOdeId)->max('verse_number') ?: 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Split by tabs, double spaces, or hash symbol as separators
            $parts = preg_split('/\t|\s{2,}|\s*#\s*/', $line);

            if (count($parts) >= 2) {
                $nextNum++;
                $sadr = trim($parts[0]);
                $ajuz = trim($parts[1]);

                OdeVerse::create([
                    'ode_id' => $this->selectedOdeId,
                    'verse_number' => $nextNum,
                    'sadr' => $sadr,
                    'ajuz' => $ajuz,
                ]);
                $importedCount++;
            }
        }

        if ($importedCount > 0) {
            Flux::toast("تم استيراد {$importedCount} بيتاً بنجاح", variant: 'success');
            $this->showBulkImport = false;
            $this->bulkText = '';
            $this->autoFillNextVerseNumber();
        } else {
            $this->addError('bulkText', 'لم نتمكن من تحديد الصدر والعجز. يرجى التأكد من استخدام فاصل (Tab أو مسافتين أو الرمز #) بين الشطرين.');
        }
    }

    public function render()
    {
        $query = Ode::query();
        if ($this->search) {
            $query->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('description', 'like', '%'.$this->search.'%');
        }
        $odesList = $query->latest()->get();

        $selectedOde = $this->selectedOdeId ? Ode::with('verses')->find($this->selectedOdeId) : null;

        return view('livewire.supervisor.manage-odes', [
            'odesList' => $odesList,
            'selectedOde' => $selectedOde,
        ])->layout('layouts.role-shell');
    }
}
