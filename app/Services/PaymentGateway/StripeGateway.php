<?php

namespace App\Services\PaymentGateway;

use Stripe\Webhook;
use App\Models\Order;
use Stripe\StripeClient;
use App\Mail\AbandonedCartRecovery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Interface\PaymentGateway\PaymentGatewayInterface;

class StripeGateway implements PaymentGatewayInterface
{
    protected $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(env('STRIPE_SECRET'));
    }

    /**
     * This method creates a Stripe checkout session
     * @param array $data
     */
    public function createCheckout(array $data)
    {
        $lineItems = [];

        foreach ($data['items'] as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency'    => $data['currency'] ?? 'usd',
                    'unit_amount' => intval($item['price']),
                    'product_data' => ['name' => $item['name']]
                ],
                'quantity' => intval($item['qty'])
            ];
        }

        // Build metadata - support both old (order_id) and new (checkout_session_id) flows
        $metadata = $data['metadata'] ?? [];
        
        // For backward compatibility with old flow
        if (isset($data['order_id'])) {
            $metadata['order_id'] = $data['order_id'];
        }

        $sessionData = [
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $data['success_url'],
            'cancel_url'  => $data['cancel_url'],
            'metadata' => $metadata,
        ];

        // Add optional parameters if provided
        if (isset($data['expires_at'])) {
            $sessionData['expires_at'] = $data['expires_at'];
        }

        if (isset($data['after_expiration'])) {
            $sessionData['after_expiration'] = $data['after_expiration'];
        }

        $session = $this->stripe->checkout->sessions->create($sessionData);

        return $session;
    }

    /**
     * This method handle webhook payload from Stripe.
     */
    public function handleWebhook(string $payload, ?string $sigHeader = null)
    {
        $webhookSecret = env('STRIPE_WEBHOOK_SECRET');

        try {
            $event = $webhookSecret && $sigHeader
                ? Webhook::constructEvent($payload, $sigHeader, $webhookSecret)
                : json_decode($payload);

            $eventType = $event->type;

            // Successful payment
            if ($eventType === 'checkout.session.completed') {
                $session = $event->data->object;

                if ($session->payment_status === 'paid') {
                    // Check if this is old flow (with order_id) or new flow (with checkout_session_id)
                    $orderId = $session->metadata->order_id ?? null;
                    $checkoutSessionId = $session->metadata->checkout_session_id ?? null;

                    if ($orderId) {
                        // OLD FLOW: Update existing order
                        $this->handleOldFlowPayment($orderId, $session);
                    } elseif ($checkoutSessionId) {
                        // NEW FLOW: Create order from cached data
                        // This is handled by WebhookController now
                        Log::info('New flow checkout session completed', [
                            'checkout_session_id' => $checkoutSessionId,
                            'stripe_session_id' => $session->id,
                        ]);
                    }
                }
            }

            // Session expired (abandoned or canceled)
            if ($eventType === 'checkout.session.expired') {
                $session = $event->data->object;
                $orderId = $session->metadata->order_id ?? null;
                $checkoutSessionId = $session->metadata->checkout_session_id ?? null;

                if ($orderId) {
                    // OLD FLOW: Handle abandoned cart
                    $this->handleOldFlowExpiration($orderId, $session);
                } elseif ($checkoutSessionId) {
                    // NEW FLOW: Just log - order doesn't exist yet
                    Log::info('New flow checkout expired', [
                        'checkout_session_id' => $checkoutSessionId,
                    ]);
                }
            }

            return response('Webhook handled', 200);

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe signature verification failed', ['error' => $e->getMessage()]);
            return response('Invalid signature', 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook error: ' . $e->getMessage());
            return response('Webhook error', 500);
        }
    }

    /**
     * Handle payment for old flow (order already exists)
     */
    protected function handleOldFlowPayment($orderId, $session)
    {
        $order = Order::with('orderHasPaids')->find($orderId);

        if ($order && !$order->is_paid) {
            // Update existing pending payment record
            $payment = $order->orderHasPaids()
                ->where('method', 'stripe')
                ->where('status', 'pending')
                ->latest()
                ->first();

            if ($payment) {
                $payment->update([
                    'status'         => 'completed',
                    'transaction_id' => $session->payment_intent ?? $session->id,
                    'notes'          => 'Payment completed successfully via Stripe.',
                ]);
            }

            // Update order
            $order->update([
                'is_paid' => true,
                'status'  => 'completed',
            ]);

            Log::info('Old flow payment completed', ['order_id' => $orderId]);
        }
    }

    /**
     * Handle expiration for old flow
     */
    protected function handleOldFlowExpiration($orderId, $session)
    {
        $order = Order::find($orderId);

        if ($order) {
            $payment = $order->orderHasPaids()
                ->where('method', 'stripe')
                ->where('status', 'pending')
                ->latest()
                ->first();

            if ($payment) {
                $notes = 'Checkout session expired.';
                $sendRecoveryEmail = false;
                $recoveryUrl = $session->after_expiration->recovery->url ?? null;

                if ($recoveryUrl) {
                    $notes .= ' User abandoned cart — recovery link generated.';
                    $sendRecoveryEmail = true;
                } else {
                    $notes .= ' User likely canceled immediately.';
                }

                $payment->update([
                    'status'         => 'failed',
                    'transaction_id' => $session->id,
                    'notes'          => $notes,
                ]);
                
                if ($sendRecoveryEmail && $recoveryUrl) {
                    Mail::to($order->email)->queue(
                        new AbandonedCartRecovery($order, $recoveryUrl)
                    );
                }

                Log::info('Old flow checkout expired', ['order_id' => $orderId]);
            }
        }
    }

    /**
     * Helper to build the JSON the frontend expects.
     * @param Order $order
     */
    public function buildOrderResponse(Order $order)
    {
        $images = [];

        foreach ($order->orderItems as $item) {
            $imagePath = $item->product->image ?? null;

            if (!$imagePath) {
                continue; 
            }

            $fullPath = public_path($imagePath);

            if (!file_exists($fullPath)) {
                $fullPath = storage_path('app/public/' . ltrim($imagePath, '/'));
            }

            if (file_exists($fullPath)) {
                $mime = mime_content_type($fullPath) ?: 'image/png';
                $b64  = base64_encode(file_get_contents($fullPath));
                $images[] = "data:{$mime};base64,{$b64}";
            } else {
                Log::warning("Product image not found for OrderItem ID {$item->id}: {$imagePath}");
            }
        }

        return [
            'AllProductImage' => $images,
            'City'            => $order->city ?? '',
            'address'         => $order->address,
            'email'           => $order->email,
            'name'            => $order->name,
            'payment_method'  => 'stripe',
            'phone'           => $order->phone,
            'roundTotolPrice' => $order->total,
            'zipcode'         => $order->zipcode ?? '',
        ];
    }
}