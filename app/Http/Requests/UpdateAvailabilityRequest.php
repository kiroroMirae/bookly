<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvailabilityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'windows' => ['present', 'array'],
            'windows.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'windows.*.start_time' => ['required', 'date_format:H:i'],
            'windows.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $windows = $this->input('windows', []);

            foreach ($windows as $index => $window) {
                if (! isset($window['start_time'], $window['end_time'])) {
                    continue;
                }

                if ($window['end_time'] <= $window['start_time']) {
                    $validator->errors()->add(
                        "windows.{$index}.end_time",
                        'End time must be after start time.'
                    );
                }
            }

            $byDay = [];
            foreach ($windows as $index => $window) {
                if (! isset($window['day_of_week'], $window['start_time'], $window['end_time'])) {
                    continue;
                }

                $day = $window['day_of_week'];

                foreach ($byDay[$day] ?? [] as [$prevStart, $prevEnd]) {
                    $overlapStart = max($prevStart, $window['start_time']);
                    $overlapEnd = min($prevEnd, $window['end_time']);

                    if ($overlapStart < $overlapEnd) {
                        $validator->errors()->add(
                            "windows.{$index}.start_time",
                            'Windows on the same day must not overlap.'
                        );
                        break;
                    }
                }

                $byDay[$day][] = [$window['start_time'], $window['end_time']];
            }
        });
    }
}
