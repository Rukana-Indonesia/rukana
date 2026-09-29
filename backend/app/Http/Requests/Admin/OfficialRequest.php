<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\Rt;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Data akun pengurus RT/RW atau perangkat desa yang dibuat/diubah operator. */
class OfficialRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge(['phone' => Phone::normalize($this->string('phone'))]);
        }
    }

    public function rules(): array
    {
        $villageId = $this->user()->village_id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^628\d{7,11}$/', Rule::unique('users', 'phone')->ignore($this->route('user'))],
            'role' => ['required', Rule::enum(Role::class)->except([Role::Warga])],
            'rw_id' => ['exclude_unless:role,rw', 'required', 'integer', Rule::exists('rws', 'id')->where('village_id', $villageId)],
            'rt_id' => ['exclude_unless:role,rt', 'required', 'integer', Rule::exists('rts', 'id')->where('village_id', $villageId)],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor HP tidak valid. Contoh: 081234567890.',
            'phone.unique' => 'Nomor HP ini sudah dipakai akun lain.',
            'rw_id.required' => 'Pilih RW untuk pengurus RW.',
            'rt_id.required' => 'Pilih RT untuk pengurus RT.',
        ];
    }

    /** Kolom wilayah sesuai peran: RT ikut RW induknya, perangkat desa tanpa RW/RT. */
    public function region(): array
    {
        $role = Role::from($this->validated('role'));

        if ($role === Role::Rt) {
            $rt = Rt::findOrFail($this->validated('rt_id'));

            return ['rw_id' => $rt->rw_id, 'rt_id' => $rt->id];
        }

        return ['rw_id' => $role === Role::Rw ? $this->validated('rw_id') : null, 'rt_id' => null];
    }
}
