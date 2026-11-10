<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ManageUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'role' => ['required', Rule::in(['Admin', 'Manager', 'Staff'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => [$this->isMethod('POST') ? 'required' : 'nullable', 'string', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'string', 'size:64'],
            'permissions' => ['prohibited'],
            'roles' => ['prohibited'],
        ];
    }
}
