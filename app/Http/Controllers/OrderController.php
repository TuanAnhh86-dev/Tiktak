<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\OrderReservationService;
use App\Services\PaymentRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(Request $request, Event $event, OrderReservationService $reservations): JsonResponse
    {
        $data = $request->validate([
            'tickets' => ['required', 'array', 'min:1'],
            'tickets.*' => ['required', 'integer', 'min:1', 'max:10'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        ksort($data['tickets'], SORT_NUMERIC);
        $reservations->expirePendingOrders();
        try {
            $order = DB::transaction(function () use ($data, $event, $request, $reservations): Order {
            $tickets = TicketType::query()->where('event_id', $event->id)
                ->whereIn('id', array_keys($data['tickets']))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = Order::where('user_id', $request->user()->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) return $existing;

            if ($tickets->count() !== count($data['tickets'])) {
                throw ValidationException::withMessages(['tickets' => 'Một hạng vé không còn tồn tại. Vui lòng tải lại trang.']);
            }

            $order = Order::create([
                'code' => 'TX-'.strtoupper(Str::random(8)),
                'user_id' => $request->user()->id,
                'total' => 0,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(OrderReservationService::HOLD_MINUTES),
                'idempotency_key' => $data['idempotency_key'],
            ]);
            $total = 0;

            foreach ($data['tickets'] as $id => $quantity) {
                $ticket = $tickets->get((int) $id);
                if (! $ticket) {
                    throw ValidationException::withMessages(['tickets' => 'Một hạng vé không còn tồn tại. Vui lòng tải lại trang.']);
                }
                $available = max(0, $ticket->quantity - $ticket->sold - $reservations->reservedQuantity($ticket->id));
                if ($quantity > $available) {
                    throw new HttpResponseException(response()->json([
                        'message' => $available > 0 ? "Hạng vé {$ticket->name} chỉ còn {$available} vé." : "Hạng vé {$ticket->name} đã hết vé.",
                        'stock' => ['id' => $ticket->id, 'available' => $available],
                    ], 409));
                }
                $subtotal = $ticket->price * $quantity;
                $total += $subtotal;
                $order->items()->create([
                    'ticket_type_id' => $ticket->id,
                    'ticket_name' => $ticket->name,
                    'event_title' => $event->title,
                    'unit_price' => $ticket->price,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                ]);
            }

            $order->update(['total' => $total]);
            return $order;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $order = Order::where('user_id', $request->user()->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if (! $order) throw $exception;
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'message' => 'Đơn này đã bị hủy. Vui lòng tạo đơn mới nếu vẫn muốn mua vé.',
                'code' => $order->code,
                'final' => true,
            ], 409);
        }

        return response()->json([
            'message' => $order->status === 'confirmed'
                ? 'Đơn vé đã được thanh toán.'
                : 'Đơn đã tạo. Vui lòng tiếp tục đến trang thanh toán.',
            'code' => $order->code,
            'payment_url' => route('payment.show', $order),
        ]);
    }

    public function payment(Request $request, Order $order, OrderReservationService $reservations, \App\Services\VnpayService $vnpay): \Illuminate\View\View
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id || $request->user()->isAdmin(), 403);
        $reservations->expirePendingOrders();
        $order->refresh()->load(['items', 'paymentTransactions' => fn ($query) => $query->latest()]);

        return view('payment', [
            'order' => $order,
            'vnpayConfigured' => $vnpay->isConfigured(),
        ]);
    }

    public function transactions(Request $request): \Illuminate\View\View
    {
        app(OrderReservationService::class)->expirePendingOrders();
        $orders = $request->user()->orders()->with('items.ticketType.event')->latest()->paginate(15);

        return view('transactions.index', compact('orders'));
    }

    public function myTickets(Request $request, PaymentRecoveryService $paymentRecovery): \Illuminate\View\View
    {
        $paymentRecovery->recoverRecordedSuccesses((int) $request->user()->id);

        $orders = $request->user()->orders()
            ->where('status', 'confirmed')
            ->with('items.ticketType.event', 'items.qrInfo')
            ->latest()
            ->paginate(15);

        return view('my-tickets', compact('orders'));
    }

    public function cancel(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id || $request->user()->isAdmin(), 403);
        abort_unless($order->status === 'pending', 422, 'Only unpaid orders can be cancelled.');

        DB::transaction(function () use ($order): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->status === 'pending', 422, 'Only unpaid orders can be cancelled.');
            $locked->update(['status' => 'cancelled']);
        });

        return redirect()->route('dashboard')->with('status', 'Unpaid order cancelled.');
    }
}
