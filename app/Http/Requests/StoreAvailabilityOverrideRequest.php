<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityOverrideRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i', 'after:start_time'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $existing = $this->user()
                ->availabilityOverrides()
                ->whereDate('date', $this->input('date'));

            $isFullDayBlock = $this->input('start_time') === null;

            if ($isFullDayBlock && $existing->exists()) {
                $validator->errors()->add('date', 'This date already has overrides. Remove them first.');

                return;
            }

            if (! $isFullDayBlock) {
                if ($existing->clone()->whereNull('start_time')->exists()) {
                    $validator->errors()->add('date', 'This date is already blocked. Remove the block first.');

                    return;
                }

                $start = $this->input('start_time').':00';
                $end = $this->input('end_time').':00';

                $overlaps = $existing->clone()
                    ->where('start_time', '<', $end)
                    ->where('end_time', '>', $start)
                    ->exists();

                if ($overlaps) {
                    $validator->errors()->add('start_time', 'These hours overlap an existing override for this date.');
                }
            }
        });
    }
}
