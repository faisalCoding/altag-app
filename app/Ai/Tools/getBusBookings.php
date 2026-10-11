<?php

namespace App\Ai\Tools;

use App\Models\Bus;
use App\Models\BusBooking;
use App\Models\Stage;
use App\Services\BusBookingService;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The academy's buses (الحافلات) and the stages' bookings of them: which
 * buses there are, who booked which day and where each booking stands —
 * awaiting its fee, confirmed, back and checked, or cancelled — the items
 * that failed the return check, and each stage's standing for booking.
 */
class getBusBookings implements Tool
{
    private const MAX_BOOKINGS = 200;

    private const STATUSES = [
        BusBooking::PENDING => 'بانتظار الرسوم',
        BusBooking::CONFIRMED => 'مؤكد',
        BusBooking::RECEIVED => 'استُلمت الحافلات',
        BusBooking::CANCELLED => 'ملغى',
    ];

    private const STANDINGS = ['ok' => 'مسموح', 'prepay' => 'بدفع مقدّم', 'banned' => 'ممنوع من الحجز'];

    public function description(): Stringable|string
    {
        return 'Get the buses (الحافلات) and their bookings by the stages: each bus, each booking\'s day, stage, buses, fee and status '
            .'(awaiting the fee, confirmed, returned and checked, cancelled), the items that failed the return check, and every stage\'s '
            .'standing for booking. Pass "from" and "to" (Y-m-d) to bound the bookings, and "stage" to narrow them.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(BusBookingService::class);

        $bookings = BusBooking::query()
            ->when($request['from'] ?? null, fn ($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($request['to'] ?? null, fn ($q, $to) => $q->whereDate('date', '<=', $to))
            ->when($request['stage'] ?? null, fn ($q, $stage) => $q->whereHas('stage', fn ($s) => ArabicSearch::where($s, 'name', $stage)))
            ->with('stage:id,name', 'buses:id,name')
            ->orderBy('date')
            ->limit(self::MAX_BOOKINGS)
            ->get();

        return json_encode([
            'buses' => Bus::orderBy('name')->get()->map(fn (Bus $bus) => [
                'name' => $bus->name,
                'type' => $bus->type,
                'fee' => $bus->fee_amount,
                'active' => $bus->is_active,
            ])->all(),
            'bookings' => $bookings->map(fn (BusBooking $booking) => [
                'date' => $booking->date?->format('Y-m-d'),
                'stage' => $booking->stage?->name,
                'buses' => $booking->buses->pluck('name')->all(),
                'status' => self::STATUSES[$booking->status] ?? $booking->status,
                'fee_total' => $booking->fee_total,
                'fee_due_on' => $booking->fee_due_on?->format('Y-m-d'),
                'fee_paid' => $booking->fee_paid_at !== null,
                'failed_return_checks' => $booking->status === BusBooking::RECEIVED
                    ? $service->failedItems($booking)->values()->all()
                    : [],
                'notes' => $booking->notes,
            ])->all(),
            'stage_standings' => Stage::orderBy('position')->get()->mapWithKeys(fn (Stage $stage) => [
                $stage->name => self::STANDINGS[$service->standingFor($stage)] ?? $service->standingFor($stage),
            ])->all(),
            'note' => $bookings->count() === self::MAX_BOOKINGS ? 'Capped at '.self::MAX_BOOKINGS.' bookings; narrow the dates.' : null,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Only bookings on or after this day (Y-m-d).'),
            'to' => $schema->string()->description('Only bookings on or before this day (Y-m-d).'),
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
        ];
    }
}
