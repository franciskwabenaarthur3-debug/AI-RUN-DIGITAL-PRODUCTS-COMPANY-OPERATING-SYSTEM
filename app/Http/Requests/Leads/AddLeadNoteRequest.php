<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

class AddLeadNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:5000'],
        ];
    }
}
