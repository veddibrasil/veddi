<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AbacatePayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public Order $order,
        public Customer $customer,
        public ?Company $company,
    ) {}

    public function handle(): void
    {
        try {
            if (app()->environment('production') && $this->company?->abacatepay_token) {
                $billing = (new AbacatePayService($this->company))
                    ->createBilling($this->order, $this->customer);

                Payment::create([
                    'order_id'              => $this->order->id,
                    'abacatepay_billing_id' => $billing['id'],
                    'abacatepay_url'        => $billing['url'],
                    'pix_qr_code'           => $billing['pixQrCode'],
                    'pix_copy_paste'        => $billing['pixCopyPaste'],
                    'amount'                => $this->order->total,
                    'status'                => 'pending',
                ]);
            } else {
                $companyName = $this->company?->name ?? config('app.name');

                Payment::create([
                    'order_id'              => $this->order->id,
                    'abacatepay_billing_id' => 'sim_' . uniqid(),
                    'abacatepay_url'        => '#',
                    'pix_qr_code'           => null,
                    'pix_copy_paste'        => '00020126580014br.gov.bcb.pix0136SIMULACAO-PAGAMENTO-DESENVOLVIMENTO52040000530398654'
                        . number_format($this->order->total, 2, '', '')
                        . '5802BR5924'
                        . mb_substr(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($companyName)), 0, 25)
                        . '6009SAO PAULO62070503***6304ABCD',
                    'amount'                => $this->order->total,
                    'status'                => 'pending',
                ]);
            }

            $this->order->update(['status' => 'awaiting_payment']);
        } catch (\Throwable $e) {
            $this->order->update(['status' => 'cancelled']);
            throw $e;
        }
    }
}
