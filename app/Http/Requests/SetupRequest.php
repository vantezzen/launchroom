<?php

namespace App\Http\Requests;

use App\Settings\InstanceSettings;
use Illuminate\Foundation\Http\FormRequest;

class SetupRequest extends FormRequest
{
    public function authorize(InstanceSettings $settings): bool
    {
        return $settings->setup_done === false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',

            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255',
            'publicly_accessible' => 'nullable|boolean',
            'ipv4' => 'required|string|max:255',
            'ipv6' => 'nullable|string|max:255',
        ];
    }
}
