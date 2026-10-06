<?php

namespace App\Concerns;

use App\Services\TurnBooking;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;

/**
 * The student's buttons for their turn in the circle's tasmeeh queue, shared
 * by both student dashboards. The circle and the window come from the student
 * signed in, never from the browser.
 */
trait BooksTasmeehTurns
{
    public function reserveTurn(): void
    {
        [$result] = TurnBooking::reserve(Auth::guard('student')->user());

        match ($result) {
            'closed' => Flux::toast('عذراً، وقت الحجز غير متاح حالياً.', variant: 'danger'),
            'busy' => Flux::toast('الحجز مزدحم الآن، حاول مرة أخرى بعد لحظات.', variant: 'danger'),
            'booked' => Flux::toast('تم حجز دورك بنجاح!', variant: 'success'),
            'held' => null,
        };
    }

    public function cancelTurn(): void
    {
        if (TurnBooking::cancel(Auth::guard('student')->user())) {
            Flux::toast('تم إلغاء حجزك بنجاح.', variant: 'success');

            return;
        }

        Flux::toast('انتهى وقت الحجز، فلا يمكن إلغاء الدور الآن.', variant: 'danger');
    }
}
