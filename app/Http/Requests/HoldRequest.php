<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class HoldRequest extends FormRequest
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
            'product_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $product = Product::where('id', $value)->lockForUpdate()->first();

                    if (! $product) {
                        $fail('The selected product does not exist.');
                        return;
                    }

                    if ($product->total_stock < $this->input('qty')) {
                        $fail('Insufficient stock available.');
                    }
                },
            ],
            'qty' => 'required|integer|min:1',
        ];
    }
}
