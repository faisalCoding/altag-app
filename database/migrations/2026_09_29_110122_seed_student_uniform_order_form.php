<?php

use App\Models\Form;
use App\Models\Manager;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the uniform order survey under the first manager: the student's name,
 * their size and how many sets they want. The first set is free and every one
 * after it costs fifty riyals, so each count is offered with its price already
 * beside it — the respondent sees what they owe before sending, and the results
 * screen tallies the orders by that same choice. Left as a draft so the manager
 * chooses who is asked before anyone is notified.
 */
return new class extends Migration
{
    private const SLUG = 'uniform';

    public function up(): void
    {
        $owner = Manager::query()->orderBy('id')->first();

        if (! $owner || Form::where('slug', self::SLUG)->exists()) {
            return;
        }

        Form::create([
            'title' => 'طلب طقم الطالب',
            'description' => 'الطقم الأول مجاني لكل طالب، وكل طقم إضافي بـ ٥٠ ريالاً.',
            'success_text' => 'تم استلام طلب الطقم، شكراً لك.',
            'color' => '#7a2727',
            'slug' => self::SLUG,
            'fields' => [
                [
                    'id' => 'field_student_name',
                    'type' => 'text',
                    'required' => true,
                    'label' => 'اسم الطالب',
                    'options' => [],
                    'is_student_name' => true,
                    'is_student_username' => false,
                ],
                [
                    'id' => 'field_uniform_size',
                    'type' => 'select',
                    'required' => true,
                    'label' => 'مقاس الطقم',
                    'options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
                    'allow_other' => true,
                    'is_student_name' => false,
                    'is_student_username' => false,
                ],
                [
                    'id' => 'field_uniform_count',
                    'type' => 'select',
                    'required' => true,
                    'label' => 'عدد الأطقم',
                    'options' => [
                        'طقم واحد — مجاناً',
                        'طقمان — ٥٠ ريالاً',
                        '٣ أطقم — ١٠٠ ريال',
                        '٤ أطقم — ١٥٠ ريالاً',
                        '٥ أطقم — ٢٠٠ ريال',
                    ],
                    'is_student_name' => false,
                    'is_student_username' => false,
                ],
            ],
            'audience' => [],
            'status' => 'draft',
            'is_blocking' => false,
            'created_by_id' => $owner->id,
            'created_by_type' => 'manager',
        ]);
    }

    /**
     * Only an unanswered survey is taken back; orders already placed stay.
     */
    public function down(): void
    {
        Form::where('slug', self::SLUG)->doesntHave('responses')->delete();
    }
};
