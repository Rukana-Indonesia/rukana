<?php

namespace App\Jobs;

use App\Models\Complaint;
use App\Support\Ai\SmartComplaint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Smart Complaint lewat queue (PRD 14), supaya warga tidak menunggu AI saat mengirim
 * laporan. Kegagalan AI dicatat di label ("Gagal") dan bisa diulang pengurus, jadi
 * job tidak perlu diulang otomatis.
 */
class ClassifyComplaint implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly Complaint $complaint)
    {
        $this->afterCommit();
    }

    public function handle(SmartComplaint $smartComplaint): void
    {
        $smartComplaint->classify($this->complaint);
    }
}
