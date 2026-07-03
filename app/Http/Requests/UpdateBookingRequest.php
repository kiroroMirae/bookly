<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['completed', 'no_show'])],
            'host_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
