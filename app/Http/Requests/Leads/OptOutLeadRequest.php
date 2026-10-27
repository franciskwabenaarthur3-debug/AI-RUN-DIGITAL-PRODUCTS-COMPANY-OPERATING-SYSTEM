<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

class OptOutLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('optOut', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'opt_out_reason' => ['required', 'string', 'max:500'],
            'confirm' => ['accepted'],
        ];
    }
}
