<?php

namespace App\Http\Requests\Letters;

use App\Models\ServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pengajuan surat pengantar oleh warga. Field tambahan divalidasi sesuai
 * `form_schema` layanan yang dipilih; field di luar skema dibuang.
 */
class StoreLetterRequest extends FormRequest
{
    public const MAX_ATTACHMENTS = 5;

    public const MAX_ATTACHMENT_KB = 5120;

    private ?ServiceType $service = null;

    protected function prepareForValidation(): void
    {
        if ($this->filled('nik')) {
            $this->merge(['nik' => preg_replace('/\D+/', '', (string) $this->input('nik'))]);
        }
    }

    public function rules(): array
    {
        $service = $this->service();

        return [
            'service_type_id' => ['required', 'integer', Rule::exists('service_types', 'id')
                ->where('village_id', $this->user()->village_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')],
            'purpose' => ['required', 'string', 'max:500'],
            'nik' => $service?->requires_nik ? ['required', 'digits:16'] : ['exclude'],
            'form_data' => ['nullable', 'array'],
            ...$this->formDataRules($service),
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*.file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::MAX_ATTACHMENT_KB],
            'attachments.*.label' => ['nullable', 'string', Rule::in($service?->requirements ?? [])],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function attributes(): array
    {
        $attributes = ['attachments.*.file' => 'lampiran', 'attachments.*.label' => 'jenis lampiran'];

        foreach ($this->service()?->form_schema ?? [] as $field) {
            $attributes["form_data.{$field['key']}"] = mb_strtolower($field['label']);
        }

        return $attributes;
    }

    public function messages(): array
    {
        return [
            'service_type_id.exists' => 'Layanan ini tidak ditemukan atau sedang tidak dibuka.',
            'nik.digits' => 'NIK harus 16 digit angka sesuai KTP.',
            'attachments.*.file.mimes' => 'Lampiran harus berupa foto (JPG/PNG/WebP) atau PDF.',
            'attachments.*.file.max' => 'Ukuran lampiran maksimal 5 MB per berkas.',
        ];
    }

    /** Layanan yang dipilih, hanya dari desa pemohon dan yang masih aktif. */
    public function service(): ?ServiceType
    {
        $id = $this->input('service_type_id');

        if ($this->service === null && is_numeric($id)) {
            $this->service = ServiceType::where('village_id', $this->user()->village_id)
                ->where('is_active', true)
                ->find((int) $id);
        }

        return $this->service;
    }

    /**
     * Isian tambahan sebagai snapshot [{key, label, value}], supaya surat lama tetap
     * terbaca walau label di katalog diubah kemudian.
     */
    public function formData(): array
    {
        $values = $this->validated('form_data') ?? [];

        return collect($this->service()?->form_schema ?? [])
            ->filter(fn (array $field) => filled($values[$field['key']] ?? null))
            ->map(fn (array $field) => ['key' => $field['key'], 'label' => $field['label'], 'value' => (string) $values[$field['key']]])
            ->values()
            ->all();
    }

    private function formDataRules(?ServiceType $service): array
    {
        $rules = [];

        foreach ($service?->form_schema ?? [] as $field) {
            $rules["form_data.{$field['key']}"] = [
                ($field['required'] ?? false) ? 'required' : 'nullable',
                ...match ($field['type']) {
                    'number' => ['numeric', 'between:0,1000000000'],
                    'date' => ['date'],
                    'select' => [Rule::in($field['options'] ?? [])],
                    'textarea' => ['string', 'max:1000'],
                    default => ['string', 'max:255'],
                },
            ];
        }

        return $rules;
    }
}
