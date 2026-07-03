<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventTypeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'max_bookings_per_day' => $this->max_bookings_per_day === '' ? null : $this->max_bookings_per_day,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['boolean'],
            'buffer_before_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'buffer_after_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'minimum_notice_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'booking_window_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_bookings_per_day' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
