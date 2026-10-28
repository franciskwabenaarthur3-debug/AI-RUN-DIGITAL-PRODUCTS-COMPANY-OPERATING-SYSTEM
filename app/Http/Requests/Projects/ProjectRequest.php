<?php

namespace App\Http\Requests\Projects;

use App\Rules\LinkWithoutCredentials;
use Illuminate\Foundation\Http\FormRequest;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    public function rules(): array
    {
        $url = ['nullable', 'url:http,https', 'max:255', new LinkWithoutCredentials];

        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'repository_url' => $url,
            'staging_url' => $url,
            'production_url' => $url,
        ];
    }
}
