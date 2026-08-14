<?php

namespace App\Http\Requests\Profile;

use App\Enums\WorkingStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkingStatusRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'working_status' => ['sometimes', 'nullable', 'string', Rule::in(WorkingStatus::values())],
            'working_status_dates' => ['sometimes', 'nullable', 'string', 'max:255'],
            'disable_notifications_while_away' => ['sometimes', 'boolean'],
            'hide_online_status' => ['sometimes', 'boolean'],
        ];
    }
}
