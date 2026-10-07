<?php

namespace App\Http\Requests\Announcements;

use App\Enums\Level;
use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Rt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Buat/ubah pengumuman (PRD 6.6). Target RT dan RW selalu wilayah akunnya sendiri,
 * diturunkan dari akun dan tidak pernah dibaca dari input. Hanya operator yang
 * memilih target: seluruh desa, satu RW, atau satu RT di desanya.
 */
class AnnouncementRequest extends FormRequest
{
    public const MAX_ATTACHMENTS = 3;

    public const MAX_ATTACHMENT_KB = 5120;

    public function rules(): array
    {
        $user = $this->user();
        $operator = $user->role === Role::Operator;
        $level = $this->input('target_level');

        return [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'target_level' => $operator ? ['required', Rule::enum(Level::class)] : ['exclude'],
            'target_rw_id' => $operator && $level === Level::Rw->value
                ? ['required', 'integer', Rule::exists('rws', 'id')->where('village_id', $user->village_id)]
                : ['exclude'],
            'target_rt_id' => $operator && $level === Level::Rt->value
                ? ['required', 'integer', Rule::exists('rts', 'id')->where('village_id', $user->village_id)]
                : ['exclude'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::MAX_ATTACHMENT_KB],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_rw_id.required' => 'Pilih RW tujuan.',
            'target_rt_id.required' => 'Pilih RT tujuan.',
            'target_rw_id.exists' => 'Pilih RW dari daftar.',
            'target_rt_id.exists' => 'Pilih RT dari daftar.',
            'expires_at.after' => 'Tanggal kedaluwarsa harus di masa depan.',
            'attachments.max' => 'Maksimal '.self::MAX_ATTACHMENTS.' lampiran per pengumuman.',
            'attachments.*.mimes' => 'Lampiran harus berupa foto (JPG/PNG/WebP) atau PDF.',
            'attachments.*.max' => 'Ukuran lampiran maksimal 5 MB per berkas.',
        ];
    }

    public function attributes(): array
    {
        return ['body' => 'isi pengumuman'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $published = $this->publishedAt();
            $expires = $this->expiresAt();

            if ($expires && $published && $expires->lte($published)) {
                $validator->errors()->add('expires_at', 'Tanggal kedaluwarsa harus setelah tanggal terbit.');
            }

            // Saat mengubah, lampiran lama yang tidak dihapus ikut dihitung.
            $announcement = $this->route('announcement');
            $kept = $announcement instanceof Announcement
                ? $announcement->attachments()->whereNotIn('id', (array) $this->input('remove_attachments', []))->count()
                : 0;

            if ($kept + count((array) $this->file('attachments', [])) > self::MAX_ATTACHMENTS) {
                $validator->errors()->add('attachments', 'Maksimal '.self::MAX_ATTACHMENTS.' lampiran per pengumuman.');
            }
        }];
    }

    /**
     * Target pengumuman: [tingkat, id RW, id RT]. Untuk target RT, RW induknya ikut
     * diisi supaya pengurus RW melihat pengumuman RT di wilayahnya.
     *
     * @return array{0: Level, 1: ?int, 2: ?int}
     */
    public function target(): array
    {
        $user = $this->user();

        return match ($user->role) {
            Role::Rt => [Level::Rt, $user->rw_id, $user->rt_id],
            Role::Rw => [Level::Rw, $user->rw_id, null],
            default => match (Level::from($this->validated('target_level'))) {
                Level::Village => [Level::Village, null, null],
                Level::Rw => [Level::Rw, (int) $this->validated('target_rw_id'), null],
                Level::Rt => [Level::Rt, Rt::whereKey($this->validated('target_rt_id'))->value('rw_id'), (int) $this->validated('target_rt_id')],
            },
        };
    }

    /** Waktu terbit dalam UTC; null berarti tidak diisi (buat: sekarang, ubah: tetap). */
    public function publishedAt(): ?Carbon
    {
        $value = $this->input('published_at');

        return filled($value) && strtotime((string) $value) !== false ? Carbon::parse($value)->utc() : null;
    }

    public function expiresAt(): ?Carbon
    {
        $value = $this->input('expires_at');

        return filled($value) && strtotime((string) $value) !== false ? Carbon::parse($value)->utc() : null;
    }
}
