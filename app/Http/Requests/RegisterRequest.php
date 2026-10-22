<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z .\'-]+$/'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'mobile' => ['required', 'regex:/^(\+91[- ]?)?[6-9]\d{9}$/'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()->symbols()],
            'accept_privacy' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Use letters only, as in your certificates.',
            'mobile.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'email.unique' => 'An account with this email already exists. Sign in or reset your password.',
            'accept_privacy.accepted' => 'Please read and accept the privacy notice.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->email))]);
    }
}
