<?php

namespace App\Http\Requests;

use App\Models\Hold;
use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hold_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $hold = Hold::where('id', $value)
                        ->where('status', 'pending')
                        ->lockForUpdate()
                        ->first();

                    if (! $hold) {
                        $fail('The selected hold is invalid or has expired.');
                    }
                },
            ],
        ];
    }
}
