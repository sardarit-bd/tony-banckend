<?php

namespace App\Http\Controllers\PaymentGateway;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = env('STRIPE_WEBHOOK_SECRET');

        if (!$webhookSecret) {
            Log::error('Stripe webhook secret not configured');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        try {
            // Verify webhook signature
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );

            Log::info('Stripe webhook received', [
                'type' => $event->type,
                'id' => $event->id,
            ]);

            // Route to appropriate handler
            switch ($event->type) {
                case 'checkout.session.completed':
                    return $this->handleCheckoutCompleted($event->data->object);

                case 'checkout.session.expired':
                    return $this->handleCheckoutExpired($event->data->object);

                default:
                    Log::info('Unhandled event type', ['type' => $event->type]);
                    return response()->json(['received' => true]);
            }

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Invalid webhook signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);

        } catch (\Exception $e) {
            Log::error('Webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Webhook failed'], 500);
        }
    }

    /**
     * Create order after successful payment
     */
    protected function handleCheckoutCompleted($session)
    {
        $checkoutSessionId = $session->metadata->checkout_session_id ?? null;

        if (!$checkoutSessionId) {
            Log::error('Missing checkout_session_id in metadata');
            return response()->json(['error' => 'Missing session ID'], 400);
        }

        $checkoutData = Cache::get($checkoutSessionId);

        if (!$checkoutData) {
            Log::error('Checkout data expired', ['session_id' => $checkoutSessionId]);
            return response()->json(['error' => 'Session expired'], 400);
        }

        // Prevent duplicate orders
        if (Order::where('stripe_session_id', $session->id)->exists()) {
            Log::warning('Duplicate webhook - order already exists');
            return response()->json(['received' => true]);
        }

        DB::beginTransaction();

        try {
            $order = Order::create([
                'user_id'           => $checkoutData['user_id'],
                'name'              => $checkoutData['name'],
                'email'             => $checkoutData['email'],
                'phone'             => $checkoutData['phone'],
                'address'           => $checkoutData['address'],
                'city'              => $checkoutData['city'] ?? null,
                'zipcode'           => $checkoutData['zipcode'] ?? null,
                'total'             => $checkoutData['total'],
                'status'            => 'processing',
                'is_paid'           => true,
                'stripe_session_id' => $session->id,
            ]);

            foreach ($checkoutData['cart_items'] as $item) {
                $orderItem = $order->orderItems()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'price'      => $item['price'],
                ]);

                if (!empty($item['FinalProduct'])) {
                    $orderItem->update([
                        'customization_images' => json_encode($item['FinalProduct']),
                    ]);
                }

                if (!empty($item['FinalPDF']['data'])) {
                    $pdfData = base64_decode($item['FinalPDF']['data']);
                    $fileName = 'custom_pdf_' . time() . '_' . $item['product_id'] . '.pdf';
                    $filePath = 'customized_files/' . $fileName;
                    Storage::disk('public')->put($filePath, $pdfData);

                    $order->update([
                        'is_customized' => true,
                        'customized_file' => $filePath,
                    ]);
                }
            }

            $order->orderHasPaids()->create([
                'amount'         => $checkoutData['total'],
                'method'         => 'stripe',
                'status'         => 'completed',
                'transaction_id' => $session->payment_intent,
            ]);

            DB::commit();
            Cache::forget($checkoutSessionId);

            Log::info('Order created from webhook', ['order_id' => $order->id]);

            return response()->json(['received' => true, 'order_id' => $order->id]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create order', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Order creation failed'], 500);
        }
    }

    protected function handleCheckoutExpired($session)
    {
        $checkoutSessionId = $session->metadata->checkout_session_id ?? null;
        
        if ($checkoutSessionId) {
            Cache::forget($checkoutSessionId);
            Log::info('Checkout expired, cache cleared');
        }

        return response()->json(['received' => true]);
    }
}