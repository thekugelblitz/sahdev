<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class CreateInvoice implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 110;
    public function __construct(public string $orderId) {}
    public function handle(\App\Services\BillingService $billing): void { $billing->createInvoice($this->orderId); }
}
