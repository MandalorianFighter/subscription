<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use Illuminate\Notifications\Notifiable;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Laravel\Cashier\Payment;
use Laravel\Cashier\Subscription;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;

class WebhookController extends CashierController
{
    public function handleWebhook(Request $request)
    {
        
        $payload = @file_get_contents('php://input');
        $event = null;

        try {
            $event = \Stripe\Event::constructFrom(
                json_decode($payload, true)
            );
        } catch(\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit();
        }

        // Handle the event
        switch ($event->type) {
            case 'customer.subscription.created':
                $data = $event->data->object; 
                // Then define and call a method to handle the successful payment intent.
                $this->handleCustomerSubscriptionCreated($data);
                break;
            case 'customer.subscription.updated':
                $data = $event->data->object; 
                // Then define and call a method to handle the successful payment intent.
                $this->handleCustomerSubscriptionUpdated($data); 
                break;
            case 'customer.subscription.deleted':
                $data = $event->data->object; 
                // Then define and call a method to handle the successful payment intent.
                $this->handleCustomerSubscriptionDeleted($data);
                break;
            case 'customer.updated':
                $data = $event->data->object; 
                // Then define and call a method to handle the successful payment intent.
                $this->handleCustomerUpdated($data);
                break;
            case 'customer.deleted':
                $data = $event->data->object; 
                // Then define and call a method to handle the successful payment intent.
                $this->handleCustomerDeleted($data);
                break;
            case 'invoice.payment_action_required':
                $data = $event->data->object; 
                // Then define and call a method to handle the successful payment intent.
                $this->handleInvoicePaymentActionRequired($data);
                break;
            // ... handle other event types
            default:
                echo 'Received unknown event type ' . $event->type;
        }
    }

    /**
     * Handle customer subscription created.
     *
     * @param  array  $data
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleCustomerSubscriptionCreated($data)
    {
        $user = $this->getUserByStripeId($data['customer']);

        if ($user) {

            if (! $user->subscriptions->contains('stripe_id', $data['id'])) {
                if (isset($data['trial_end'])) {
                    $trialEndsAt = Carbon::createFromTimestamp($data['trial_end']);
                } else {
                    $trialEndsAt = null;
                }

                $firstItem = $data['items']['data'][0];
                $isSinglePrice = count($data['items']['data']) === 1;

                $subscription = $user->subscriptions()->create([
                    'name' => $data['metadata']['name'] ?? $this->newSubscriptionName($data),
                    'stripe_id' => $data['id'],
                    'stripe_status' => $data['status'],
                    'stripe_price' => $isSinglePrice ? $firstItem['price']['id'] : null,
                    'quantity' => $isSinglePrice && isset($firstItem['quantity']) ? $firstItem['quantity'] : null,
                    'trial_ends_at' => $trialEndsAt,
                    'ends_at' => null,
                ]);

                foreach ($data['items']['data'] as $item) {
                    $data->items()->create([
                        'stripe_id' => $item['id'],
                        'stripe_product' => $item['price']['product'],
                        'stripe_price' => $item['price']['id'],
                        'quantity' => $item['quantity'] ?? null,
                    ]);
                }
            }
        }

        Log::debug('Subscription Created',[$user->email]);
        return $this->successMethod();
    }

    /**
     * Determines the name that should be used when new subscriptions are created from the Stripe dashboard.
     *
     * @param  array  $data
     * @return string
     */
    protected function newSubscriptionName(array $data)
    {
        return 'default';
    }

    /**
     * Handle customer subscription updated.
     *
     * @param  array  $subscription
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleCustomerSubscriptionUpdated($data)
    {
        if ($user = $this->getUserByStripeId($data['customer'])) {

            $subscription = $user->subscriptions()->firstOrNew(['stripe_id' => $data['id']]);

            if (
                isset($data['status']) &&
                $data['status'] === StripeSubscription::STATUS_INCOMPLETE_EXPIRED
            ) {
                $subscription->items()->delete();
                $subscription->delete();

                return;
            }

            $subscription->name = $subscription->name ?? $data['metadata']['name'] ?? $this->newSubscriptionName($data);

            $firstItem = $data['items']['data'][0];
            $isSinglePrice = count($data['items']['data']) === 1;

            // Price...
            $subscription->stripe_price = $isSinglePrice ? $firstItem['price']['id'] : null;

            // Quantity...
            $subscription->quantity = $isSinglePrice && isset($firstItem['quantity']) ? $firstItem['quantity'] : null;

            // Trial ending date...
            if (isset($data['trial_end'])) {
                $trialEnd = Carbon::createFromTimestamp($data['trial_end']);

                if (! $subscription->trial_ends_at || $subscription->trial_ends_at->ne($trialEnd)) {
                    $subscription->trial_ends_at = $trialEnd;
                }
            }

            // Cancellation date...
            if (isset($data['cancel_at_period_end'])) {
                if ($data['cancel_at_period_end']) {
                    $subscription->ends_at = $subscription->onTrial()
                        ? $subscription->trial_ends_at
                        : Carbon::createFromTimestamp($data['current_period_end']);
                } elseif (isset($data['cancel_at'])) {
                    $subscription->ends_at = Carbon::createFromTimestamp($data['cancel_at']);
                } else {
                    $subscription->ends_at = null;
                }
            }

            // Status...
            if (isset($data['status'])) {
                $subscription->stripe_status = $data['status'];
            }

            $subscription->save();

            // Update subscription items...
            if (isset($data['items'])) {
                $prices = [];

                foreach ($data['items']['data'] as $item) {
                    $prices[] = $item['price']['id'];

                    $subscription->items()->updateOrCreate([
                        'stripe_id' => $item['id'],
                    ], [
                        'stripe_product' => $item['price']['product'],
                        'stripe_price' => $item['price']['id'],
                        'quantity' => $item['quantity'] ?? null,
                    ]);
                }

                // Delete items that aren't attached to the subscription anymore...
                $subscription->items()->whereNotIn('stripe_price', $prices)->delete();
            }
        }

        Log::debug('Subscription Updated',[$user->email]);
        return $this->successMethod();
    }

     /**
     * Handle a canceled customer from a Stripe subscription.
     *
     * @param  array  $subscription
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleCustomerSubscriptionDeleted($data)
    {
        if ($user = $this->getUserByStripeId($data['customer'])) {
            $user->subscriptions->filter(function ($subscription) use ($data) {
                return $subscription->stripe_id === $data['id'];
            })->each(function ($subscription) {
                $subscription->markAsCanceled();
            });
        }

        Log::debug('Subscription Deleted',[$user->email]);
        return $this->successMethod();
    }

    /**
     * Handle customer updated.
     *
     * @param  array  $customer
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleCustomerUpdated($data)
    {
        if ($user = $this->getUserByStripeId($data['id'])) {
            $user->updateDefaultPaymentMethodFromStripe();
        }

        Log::debug('Customer Updated',[$user->email]);
        return $this->successMethod();
    }

    /**
     * Handle deleted customer.
     *
     * @param  array  $customer
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleCustomerDeleted($data)
    {
        if ($user = $this->getUserByStripeId($data['id'])) {
            $user->subscriptions->each(function (Subscription $subscription) {
                $subscription->skipTrial()->markAsCanceled();
            });

            $user->forceFill([
                'stripe_id' => null,
                'trial_ends_at' => null,
                'pm_type' => null,
                'pm_last_four' => null,
            ])->save();
        }       
        Log::debug('Customer Deleted',[$user->email]);
        return $this->successMethod();
    }

    /**
     * Handle payment action required for invoice.
     *
     * @param  array  $invoice
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleInvoicePaymentActionRequired($data)
    {
        if (is_null($notification = config('cashier.payment_notification'))) {
            return $this->successMethod();
        }

        if ($user = $this->getUserByStripeId($data['customer'])) {
            if (in_array(Notifiable::class, class_uses_recursive($user))) {
                $payment = new Payment($user->stripe()->paymentIntents->retrieve(
                    $data['payment_intent']
                ));

                $user->notify(new $notification($payment));
            }
        }
        Log::debug('Invoice Payment Action Required',[$user->email]);
        return $this->successMethod();
    }

}
