<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking the academy's buses.
 *
 * Two audiences reach this without an account, each through a link the manager
 * hands out: the supervisors who book, and the officer who receives the buses
 * back and rules on what happened to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            // The fee this bus costs a stage that has been put on prepayment. Per
            // bus rather than per booking, so booking two of them costs both.
            $table->unsignedInteger('fee_amount')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bus_handover_items', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bus_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();

            // pending  — awaiting the prepayment fee; holds no bus
            // confirmed — the booking that actually occupies its buses that day
            // received — the buses came back and were checked
            // cancelled
            $table->string('status')->default('confirmed')->index();

            $table->unsignedInteger('fee_total')->default(0);
            $table->timestamp('fee_paid_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('bus_booking_bus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained()->cascadeOnDelete();
            // Copied at booking time: the bus's fee may be edited later, and what
            // a stage was asked to pay should not change retroactively.
            $table->unsignedInteger('fee_amount')->default(0);
            $table->timestamps();

            $table->unique(['bus_booking_id', 'bus_id']);
            $table->index('bus_id');
        });

        Schema::create('bus_booking_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_handover_item_id')->nullable()->constrained()->nullOnDelete();
            // The wording as it stood when the bus came back: the item list is
            // editable, and a past ruling must stay readable after it changes.
            $table->string('label');
            $table->boolean('is_done')->default(false);
            $table->timestamps();
        });

        Schema::create('stage_bus_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            // ok | prepay | banned
            $table->string('standing');
            $table->text('reason')->nullable();
            $table->foreignId('bus_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['stage_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_bus_standings');
        Schema::dropIfExists('bus_booking_checks');
        Schema::dropIfExists('bus_booking_bus');
        Schema::dropIfExists('bus_bookings');
        Schema::dropIfExists('bus_handover_items');
        Schema::dropIfExists('buses');
    }
};
