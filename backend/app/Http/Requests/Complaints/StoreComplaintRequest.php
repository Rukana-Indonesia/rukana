<?php

namespace App\Http\Requests\Complaints;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Laporan baru dari warga (PRD 6.4). Lokasi boleh berupa titik dari GPS browser,
 * patokan yang diketik, atau keduanya, asal salah satu ada.
 */
class StoreComplaintRequest extends FormRequest
{
    public const MAX_PHOTOS = 3;

    public const MAX_PHOTO_KB = 5120;

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
            'category_id' => ['required', 'integer', Rule::exists('complaint_categories', 'id')
                ->whereNotNull('parent_id')
                ->where('is_active', true)],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'location_text' => ['nullable', 'required_without:latitude', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:+5 minutes', 'after:-1 year'],
            'photos' => ['nullable', 'array', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_PHOTO_KB],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'Pilih kategori laporan dari daftar.',
            'location_text.required_without' => 'Tulis patokan lokasi, atau tekan "Gunakan lokasi saya".',
            'occurred_at.before_or_equal' => 'Waktu kejadian tidak boleh di masa depan.',
            'occurred_at.after' => 'Waktu kejadian paling lama setahun yang lalu.',
            'photos.max' => 'Maksimal '.self::MAX_PHOTOS.' foto per laporan.',
            'photos.*.mimes' => 'Foto harus berupa JPG, PNG, atau WebP.',
            'photos.*.max' => 'Ukuran foto maksimal 5 MB.',
        ];
    }

    /** Waktu kejadian dalam UTC (frontend mengirim ISO 8601 dengan zona waktu). */
    public function occurredAt(): ?Carbon
    {
        $value = $this->validated('occurred_at');

        return $value ? Carbon::parse($value)->utc() : null;
    }
}
