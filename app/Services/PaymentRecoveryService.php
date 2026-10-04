<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;

class PaymentRecoveryService
{
    public function __construct(private TicketQrService $ticketQrs)
    {
    }

    public function recoverRecordedSuccesses(int $userId): void
    {
        $orderIds = PaymentTransaction::query()
            ->whereNotNull('order_id')
            ->where('signature_valid', true)
            ->where('response_code', '00')
            ->where('transaction_status', '00')
            ->whereNotNull('transaction_no')
            ->whereHas('order', fn ($query) => $query->where('user_id', $userId)->whereIn('status', ['pending', 'cancelled']))
            ->distinct()
            ->pluck('order_id');

        foreach ($orderIds as $orderId) {
            DB::transaction(function () use ($orderId, $userId): void {
                $order = Order::query()->where('user_id', $userId)->lockForUpdate()->find($orderId);
                if (! $order || ! in_array($order->status, ['pending', 'cancelled'], true)) {
                    return;
                }

                $payment = PaymentTransaction::query()
                    ->where('order_id', $order->id)
                    ->where('signature_valid', true)
                    ->where('response_code', '00')
                    ->where('transaction_status', '00')
                    ->whereNotNull('transaction_no')
                    ->latest('id')
                    ->first();

                if (! $payment) {
                    return;
                }

                $items = $order->items()->whereNotNull('ticket_type_id')->orderBy('ticket_type_id')->get();
                if ($items->isEmpty() || $items->count() !== $order->items()->count()) {
                    return;
                }

                foreach ($items as $item) {
                    $ticket = TicketType::query()->whereKey($item->ticket_type_id)->lockForUpdate()->first();
                    if (! $ticket || $item->quantity > $ticket->quantity - $ticket->sold) {
                        return;
                    }
                }

                foreach ($items as $item) {
                    TicketType::query()->whereKey($item->ticket_type_id)->increment('sold', $item->quantity);
                }

                $order->update([
                    'status' => 'confirmed',
                    'payment_transaction_id' => $payment->transaction_no,
                ]);
                $order->setRelation('items', $items);
                $this->ticketQrs->issueForOrder($order);
            }, attempts: 3);
        }
    }
}
