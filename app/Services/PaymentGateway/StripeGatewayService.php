<?php

namespace App\Services\PaymentGateway;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class StripeGatewayService
{
    public function createCheckoutSession(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email',
            'phone'   => 'required|string|max:50',
            'address' => 'required|string|max:500',
            'city'    => 'nullable|string|max:100',
            'zipcode' => 'nullable|string|max:20',
            'gateway' => 'required|string|in:stripe,cod,cash_on_delivery',
            'items'   => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty'        => 'required|integer|min:1',
            'items.*.price'      => 'nullable|numeric|min:0',
            'items.*.name'       => 'required|string',
            'items.*.FinalPDF'     => 'nullable|array',
            'items.*.FinalProduct' => 'nullable|array',
        ]);

        try {
            $validatedItems = [];
            $trustedTotal = 0;

            foreach ($request->items as $item) {
                $product = Product::where('status', 1)
                    ->findOrFail($item['product_id']);

                $sellingPrice = $product->offer_price > 0 
                    ? $product->offer_price 
                    : $product->price;

                $quantity = (int) $item['qty'];

                $lineTotal = $sellingPrice * $quantity;
                $trustedTotal += $lineTotal;

                $validatedItems[] = [
                    'product_id'   => $product->id,
                    'name'         => $product->name,
                    'qty'          => $quantity,
                    'price'        => $sellingPrice,
                    'total'        => $lineTotal,
                    'FinalPDF'     => $item['FinalPDF'] ?? null,
                    'FinalProduct' => $item['FinalProduct'] ?? [],
                ];
            }

            // Handle COD
            if ($request->gateway === 'cod' || $request->gateway === 'cash_on_delivery') {
                return $this->createCODOrder($request, $validatedItems, $trustedTotal);
            }

            // Cache checkout data for Stripe
            $checkoutSessionId = 'checkout_' . uniqid() . '_' . time();

            Cache::put($checkoutSessionId, [
                'user_id'     => auth('api')->id(),
                'name'        => $request->name,
                'email'       => $request->email,
                'phone'       => $request->phone,
                'address'     => $request->address,
                'city'        => $request->city,
                'zipcode'     => $request->zipcode,
                'cart_items'  => $validatedItems,
                'total'       => $trustedTotal,
                'created_at'  => now()->toDateTimeString(),
            ], now()->addHours(24));

            Log::info('Checkout session cached', [
                'session_id' => $checkoutSessionId,
                'email' => $request->email,
                'total' => $trustedTotal,
            ]);

            // Prepare Stripe line items
            $stripeItems = array_map(function ($item) {
                return [
                    'name'  => $item['name'],
                    'qty'   => $item['qty'],
                    'price' => round($item['price'] * 100),
                ];
            }, $validatedItems);

            // Create Stripe session
            $gateway = PaymentGatewayFactory::make('stripe');
            
            $session = $gateway->createCheckout([
                'items'       => $stripeItems,
                'success_url' => env('FRONTEND_URL') . '/payment/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => env('FRONTEND_URL') . '/payment/cancel?session_id={CHECKOUT_SESSION_ID}',
                'currency'    => 'usd',
                'metadata'    => [
                    'checkout_session_id' => $checkoutSessionId,
                ],
                'expires_at' => now()->addHour(1)->timestamp,
                'after_expiration' => [
                    'recovery' => ['enabled' => true],
                ],
            ]);

            Log::info('Stripe checkout session created', [
                'stripe_session_id' => $session->id,
                'checkout_session_id' => $checkoutSessionId,
            ]);

            return response()->json([
                'success'           => true,
                'checkout_url'      => $session->url,
                'session_id'        => $checkoutSessionId,
                'stripe_session_id' => $session->id,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed', [
                'errors' => $e->errors(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
            
        } catch (\Exception $e) {
            Log::error('Checkout session creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    protected function createCODOrder($request, $validatedItems, $trustedTotal)
    {
        DB::beginTransaction();

        try {
            $order = \App\Models\Order::create([
                'user_id'  => auth('api')->id(),
                'name'     => $request->name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'address'  => $request->address,
                'city'     => $request->city,
                'zipcode'  => $request->zipcode,
                'total'    => $trustedTotal,
                'status'   => 'pending',
                'is_paid'  => false,
            ]);

            foreach ($validatedItems as $item) {
                $orderItem = $order->orderItems()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['qty'],
                    'price'      => $item['price'],
                ]);

                // Handle customization
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
                        'is_customized'   => true,
                        'customized_file' => $filePath,
                    ]);
                }
            }

            $order->orderHasPaids()->create([
                'amount' => $trustedTotal,
                'method' => 'cod',
                'status' => 'pending',
                'notes'  => 'Cash on Delivery',
            ]);

            DB::commit();

            Log::info('COD order created', ['order_id' => $order->id]);

            return response()->json([
                'success'  => true,
                'gateway'  => 'cod',
                'message'  => 'Order placed successfully using Cash on Delivery.',
                'order_id' => $order->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('COD order creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}