<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scenario' => ['sometimes', Rule::in(app()->environment('local', 'testing') ? config('payment.mock_scenarios') : ['success'])],
            'card_number' => ['prohibited'], 'cvv' => ['prohibited'], 'card' => ['prohibited'],
        ];
    }
}
