<?php

namespace App\Http\Requests\Auth;

use App\Enums\ResidencyStatus;
use App\Models\Rt;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge(['phone' => Phone::normalize($this->string('phone'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^628\d{7,11}$/', Rule::unique('users', 'phone')],
            'password' => ['required', 'confirmed', Password::min(8)],
            'rw_id' => ['required', 'integer', 'exists:rws,id'],
            'rt_id' => ['required', 'integer', 'exists:rts,id'],
            'residency_status' => ['required', Rule::enum(ResidencyStatus::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty()
                    && Rt::whereKey($this->integer('rt_id'))->value('rw_id') !== $this->integer('rw_id')) {
                    $validator->errors()->add('rt_id', 'RT tidak termasuk RW yang dipilih.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor HP tidak valid. Contoh: 081234567890.',
            'phone.unique' => 'Nomor HP ini sudah terdaftar.',
        ];
    }
}
