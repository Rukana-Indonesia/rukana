<?php

namespace App\Http\Requests\Admin;

use App\Enums\LetterFlow;
use App\Models\ServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Satu layanan di katalog, diisi operator desa. */
class ServiceTypeRequest extends FormRequest
{
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select'];

    protected function prepareForValidation(): void
    {
        // Operator cukup menulis label; key isian baru dibuat dari label, key lama dipertahankan.
        $schema = collect($this->input('form_schema', []))
            ->map(fn ($field) => is_array($field)
                ? [...$field, 'key' => filled($field['key'] ?? null) ? $field['key'] : Str::slug((string) ($field['label'] ?? ''), '_')]
                : $field)
            ->all();

        $this->merge([
            'form_schema' => $schema,
            'requirements' => array_values(array_filter(array_map('trim', (array) $this->input('requirements', [])))),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:1000'],
            'requirements' => ['present', 'array', 'max:10'],
            'requirements.*' => ['string', 'max:150', 'distinct'],
            'flow' => ['required', Rule::enum(LetterFlow::class)],
            'estimated_days' => ['nullable', 'integer', 'between:1,60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'requires_nik' => ['boolean'],
            'is_active' => ['boolean'],
            'form_schema' => ['present', 'array', 'max:10'],
            'form_schema.*.label' => ['required', 'string', 'max:100'],
            'form_schema.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'form_schema.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'form_schema.*.required' => ['boolean'],
            'form_schema.*.options' => ['exclude_unless:form_schema.*.type,select', 'required', 'array', 'min:2', 'max:20'],
            'form_schema.*.options.*' => ['string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'requirements.*' => 'persyaratan',
            'form_schema.*.label' => 'nama isian',
            'form_schema.*.key' => 'nama isian',
            'form_schema.*.type' => 'jenis isian',
            'form_schema.*.options' => 'pilihan',
        ];
    }

    public function messages(): array
    {
        return [
            'form_schema.*.key.distinct' => 'Nama isian tidak boleh sama.',
            'form_schema.*.key.required' => 'Nama isian harus mengandung huruf atau angka.',
            'form_schema.*.options.required' => 'Isian pilihan butuh minimal dua pilihan.',
            'form_schema.*.options.min' => 'Isian pilihan butuh minimal dua pilihan.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $exists = ServiceType::withTrashed()
                    ->where('village_id', $this->user()->village_id)
                    ->where('name', trim((string) $this->input('name')))
                    ->when($this->route('serviceType'), fn ($q, $service) => $q->whereKeyNot($service->id))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'Layanan dengan nama ini sudah ada.');
                }
            },
        ];
    }

    /**
     * Slug untuk URL katalog publik, dibuat sekali saat layanan dibuat (tidak ikut
     * berubah saat diganti nama supaya tautan yang sudah dibagikan tetap jalan).
     */
    public function uniqueSlug(): string
    {
        $base = Str::slug((string) $this->input('name')) ?: 'layanan';
        $slug = $base;

        for ($i = 2; ServiceType::withTrashed()->where('village_id', $this->user()->village_id)->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** Data siap simpan: isian tambahan tanpa `options` untuk jenis selain pilihan. */
    public function payload(): array
    {
        $data = $this->validated();
        $data['form_schema'] = array_map(fn (array $field) => [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'required' => (bool) ($field['required'] ?? false),
            ...($field['type'] === 'select' ? ['options' => array_values((array) $field['options'])] : []),
        ], $data['form_schema']);

        return $data;
    }
}
