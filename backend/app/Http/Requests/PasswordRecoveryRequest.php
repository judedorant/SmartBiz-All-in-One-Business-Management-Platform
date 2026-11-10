<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PasswordRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $operation = $this->route()->getActionMethod();

        return [
            'email' => [$operation === 'change' ? 'prohibited' : 'required', 'email', 'max:255'],
            'token' => [$operation === 'reset' ? 'required' : 'prohibited', 'string', 'max:255'],
            'current_password' => [$operation === 'change' ? 'required' : 'prohibited', 'string', 'max:128'],
            'password' => [$operation === 'forgot' ? 'prohibited' : 'required', 'string', 'max:72', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ];
    }
}
