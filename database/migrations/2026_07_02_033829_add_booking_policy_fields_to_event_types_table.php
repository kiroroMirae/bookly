<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0)->after('duration_minutes');
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0)->after('buffer_before_minutes');
            $table->unsignedSmallInteger('minimum_notice_minutes')->default(0)->after('buffer_after_minutes');
            $table->unsignedSmallInteger('booking_window_days')->default(60)->after('minimum_notice_minutes');
            $table->unsignedSmallInteger('max_bookings_per_day')->nullable()->after('booking_window_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn([
                'buffer_before_minutes',
                'buffer_after_minutes',
                'minimum_notice_minutes',
                'booking_window_days',
                'max_bookings_per_day',
            ]);
        });
    }
};
