<?php

namespace App\Models;

use Database\Factories\ScheduleActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One kind of thing the evening programme does — the Quran circle, Maghrib
 * prayer, the science lesson — with the icon and colour it wears on the
 * schedule. A routine activity repeats every day, so the month view leaves it
 * out to make room for what changes. `topics` is the ordered syllabus a new
 * week walks forward through.
 */
#[Fillable(['name', 'icon', 'color', 'second_icon', 'second_color', 'default_time', 'is_routine', 'topics', 'sort_order'])]
class ScheduleActivity extends Model
{
    /** @use HasFactory<ScheduleActivityFactory> */
    use HasFactory;

    /**
     * The colours an activity may wear, by name, with their Arabic labels.
     *
     * @var array<string, array{hex: string, label: string}>
     */
    public const COLORS = [
        'teal' => ['hex' => '#16a6bd', 'label' => 'فيروزي'],
        'blue' => ['hex' => '#1b9dd9', 'label' => 'أزرق'],
        'brown' => ['hex' => '#c9782f', 'label' => 'بني'],
        'yellow' => ['hex' => '#f2b31b', 'label' => 'أصفر'],
        'red' => ['hex' => '#e34b3d', 'label' => 'أحمر'],
        'gold' => ['hex' => '#efab27', 'label' => 'ذهبي'],
        'green' => ['hex' => '#2f9e6d', 'label' => 'أخضر'],
        'orange' => ['hex' => '#f2794d', 'label' => 'برتقالي'],
        'purple' => ['hex' => '#7c5cd6', 'label' => 'بنفسجي'],
        'pink' => ['hex' => '#d9468a', 'label' => 'وردي'],
        'slate' => ['hex' => '#5b6b76', 'label' => 'رمادي'],
    ];

    public static function hexFor(?string $color): string
    {
        return self::COLORS[$color]['hex'] ?? self::COLORS['slate']['hex'];
    }

    protected function casts(): array
    {
        return [
            'is_routine' => 'boolean',
            'topics' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function cells(): HasMany
    {
        return $this->hasMany(ScheduleCell::class);
    }
}
