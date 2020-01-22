<?php

namespace Themsaid\CashierTool;

use Stripe\Plan;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Dispute;
use Stripe\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Routing\Controller;
use Stripe\Subscription as StripeSubscription;

class CashierToolController extends Controller
{
    /**
     * The model used by Stripe.
     *
     * @var string
     */
    public $stripeModel;

    /**
     * The subscription name.
     *
     * @var string
     */
    public $subscriptionName;

    /**
     * Create a new controller instance.
     *
     * @param \Illuminate\Config\Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->middleware(function ($request, $next) use ($config) {
            Stripe::setApiKey($config->get('services.stripe.secret'));

            $this->stripeModel = $config->get('services.stripe.model');

            $this->subscriptionName = $config->get('nova-cashier-manager.subscription_name');

            return $next($request);
        });
    }

    /**
     * Return the user response.
     *
     * @param int $billableId
     *
     * @return array
     */
    public function user($billableId, $subscriptionId = null)
    {
        /** @var \Laravel\Cashier\Billable|\App\Models\User $billable */
        $billable = (new $this->stripeModel)->find($billableId);

        // Get subscription(s)
        if (is_null($subscriptionId)) {
            $subscriptions = $billable->subscriptions()->get();
        } else {
            $subscriptions = $billable->subscriptions()->where('id', $subscriptionId)->get();
        }

        if (!$subscriptions || is_null($billable->stripe_id)) {
            return [
                'subscriptions' => [],
                'plans' => $this->formatPlans(Plan::all()),
            ];
        }

        // Get ALL subscriptions
        $formattedSubscriptions = [];
        foreach($subscriptions as $cur_subscription) {
            if (!$cur_subscription->stripe_id) {
                $formattedSubscriptions[] = $cur_subscription->toArray();
                continue;
            }

            $stripeSubscription = StripeSubscription::retrieve($cur_subscription->stripe_id);
            $formattedSubscriptions[] = $this->formatSubscription($cur_subscription, $stripeSubscription);
        }

        // Get invoices
        $invoices = request('brief') ? [] : $this->formatInvoices($billable->invoicesIncludingPending(), array_column($subscriptions->toArray(), 'stripe_id'));

        // Return data
        return [
            'user' => $billable->toArray(),
            'cards' => request('brief') ? [] : $this->formatPaymentMethods($billable->paymentMethods(), $billable->defaultPaymentMethod()->id),
            'invoices' => request('brief') ? [] : $this->formatInvoices($billable->invoicesIncludingPending()),
            'charges' => request('brief') ? [] : $this->formatPaymentIntents(PaymentIntent::all(['customer' => $billable->asStripeCustomer()->id])),
            'subscriptions' => $formattedSubscriptions,
            'plans' => request('brief') ? [] : $this->formatPlans(Plan::all(['limit' => 100])),
        ];
    }

    /**
     * Cancel the given subscription.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $billableId
     */
    public function cancelSubscription(Request $request, $billableId, $subscriptionId)
    {
        /** @var \Laravel\Cashier\Billable|\App\Models\User $billable */
        $billable = (new $this->stripeModel)->find($billableId);

        $subscription = $billable->subscriptions()->find($subscriptionId);

        if ($request->input('now')) {
            return $billable->subscription($subscription->name)->cancelNow();
        } else {
            return $billable->subscription($subscription->name)->cancel();
        }
    }

    /**
     * Create subscription.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $billableId
     * @return \Illuminate\Http\Response
     */
    public function createSubscription(Request $request, $billableId = null)
    {
        $plan = $request->input('plan');

        $billable = (new $this->stripeModel)->find($billableId);

        $subscription = $billable->newSubscription($plan['product'], $plan['id'])->create();
    }

    /**
     * Update the given subscription.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $billableId
     */
    public function updateSubscription(Request $request, $billableId, $subscriptionId)
    {
        /** @var \Laravel\Cashier\Billable|\App\Models\User $billable */
        $billable = (new $this->stripeModel)->find($billableId);

        $subscription = $billable->subscriptions()->find($subscriptionId);

        $billable->subscription($subscription->name)->swap($request->input('plan'))->update([
            'name' => $request->input('plan')
        ]);
    }

    /**
     * Resume the given subscription.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $billableId
     */
    public function resumeSubscription(Request $request, $billableId, $subscriptionId)
    {
        /** @var \Laravel\Cashier\Billable|\App\Models\User $billable */
        $billable = (new $this->stripeModel)->find($billableId);

        $subscription = $billable->subscriptions()->find($subscriptionId);

        $billable->subscription($subscription->name)->resume();
    }

    /**
     * Refund the given charge.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $billableId
     * @param string                   $stripeChargeId
     */
    public function refundCharge(Request $request, $billableId, $stripeChargeId)
    {
        $refundParameters = ['charge' => $stripeChargeId];

        if ($request->input('amount')) {
            $refundParameters['amount'] = $request->input('amount');
        }

        if ($request->input('notes')) {
            $refundParameters['metadata'] = ['notes' => $request->input('notes')];
        }

        Refund::create($refundParameters);
    }

    /**
     * Format a a subscription object.
     *
     * @param \Laravel\Cashier\Subscription $subscription
     * @param \Stripe\Subscription          $stripeSubscription
     *
     * @return array
     */
    public function formatSubscription($subscription, $stripeSubscription)
    {
        return array_merge($subscription->toArray(), [
            'plan_amount' => $stripeSubscription->plan->amount,
            'plan_interval' => $stripeSubscription->plan->interval,
            'plan_currency' => $stripeSubscription->plan->currency,
            'plan' => $subscription->stripe_plan,
            'stripe_plan' => $stripeSubscription->plan->id,
            'ended' => $subscription->ended(),
            'cancelled' => $subscription->cancelled(),
            'active' => $subscription->active(),
            'on_trial' => $subscription->onTrial(),
            'on_grace_period' => $subscription->onGracePeriod(),
            'charges_automatically' => $stripeSubscription->collection_method == 'charge_automatically',
            'created_at' => $stripeSubscription->billing_cycle_anchor ? Carbon::createFromTimestamp($stripeSubscription->billing_cycle_anchor)->toDateTimeString() : null,
            'ended_at' => $stripeSubscription->ended_at ? Carbon::createFromTimestamp($stripeSubscription->ended_at)->toDateTimeString() : null,
            'current_period_start' => $stripeSubscription->current_period_start ? Carbon::createFromTimestamp($stripeSubscription->current_period_start)->toDateString() : null,
            'current_period_end' => $stripeSubscription->current_period_end ? Carbon::createFromTimestamp($stripeSubscription->current_period_end)->toDateString() : null,
            'days_until_due' => $stripeSubscription->days_until_due,
            'cancel_at_period_end' => $stripeSubscription->cancel_at_period_end,
            'canceled_at' => $stripeSubscription->canceled_at,
        ]);
    }

    /**
     * Format the cards collection.
     *
     * @param array|\Illuminate\Support\Collection $paymentMethods
     * @param string|null              $defaultPaymentMethodId
     *
     * @return array
     */
    private function formatPaymentMethods($paymentMethods, $defaultPaymentMethodId = null)
    {
        return collect($paymentMethods)->map(function ($paymentMethod) use ($defaultPaymentMethodId) {
            /* @var \Stripe\PaymentMethod $paymentMethod */
            return [
                'id' => $paymentMethod->id,
                'is_default' => $paymentMethod->id == $defaultPaymentMethodId,
                'name' => $paymentMethod->card->name,
                'last4' => $paymentMethod->card->last4,
                'country' => $paymentMethod->card->country,
                'brand' => $paymentMethod->card->brand,
                'exp_month' => $paymentMethod->card->exp_month,
                'exp_year' => $paymentMethod->card->exp_year,
            ];
        })->toArray();
    }

    /**
     * Format the invoices collection.
     *
     * @param array|\Illuminate\Support\Collection $invoices
     *
     * @return array
     */
    private function formatInvoices($invoices, $subscription_ids = [])
    {
        return collect($invoices)->map(function ($invoice) {
            /* @var \Stripe\Invoice $invoice */
            return [
                'id' => $invoice->id,
                'subscription_id' => $invoice->subscription,
                'total' => $invoice->total,
                'attempted' => $invoice->attempted,
                'charge_id' => $invoice->charge,
                'currency' => $invoice->currency,
                'period_start' => $invoice->period_start ? Carbon::createFromTimestamp($invoice->period_start)->toDateTimeString() : null,
                'period_end' => $invoice->period_end ? Carbon::createFromTimestamp($invoice->period_end)->toDateTimeString() : null,
                'metadata' => $invoice->metadata ? $invoice->metadata : null,
            ];
        })->filter(function ($invoice, $key) use ($subscription_ids) {
            return $invoice != null && in_array($invoice['subscription_id'], $subscription_ids);
        })->values()->toArray();
    }

    /**
     * Format the charges collection.
     *
     * @param \Stripe\Collection $paymentIntents
     *
     * @return array
     */
    private function formatPaymentIntents($paymentIntents)
    {
        $charges = collect([]);
        collect($paymentIntents->data)->each(function ($paymentIntent) use ($charges) {
            /** @var \Stripe\PaymentIntent $paymentIntent */
            collect($paymentIntent->charges->data)->each(function ($charge) use ($charges) {
                /** @var \Stripe\Charge $charge */
                $charges->push([
                    'id' => $charge->id,
                    'amount' => $charge->amount,
                    'amount_refunded' => $charge->amount_refunded,
                    'captured' => $charge->captured,
                    'paid' => $charge->paid,
                    'status' => $charge->status,
                    'currency' => $charge->currency,
                    'dispute' => $charge->dispute ? Dispute::retrieve($charge->dispute) : null,
                    'failure_code' => $charge->failure_code,
                    'failure_message' => $charge->failure_message,
                    'created' => $charge->created ? Carbon::createFromTimestamp($charge->created)->toDateTimeString() : null,
                ]);
            });
        });

        return $charges->toArray();
    }

    /**
     * Format the plans collection.
     *
     * @param \Stripe\Collection $plans
     *
     * @return array
     */
    private function formatPlans($plans)
    {
        return collect($plans->data)->map(function ($plan) {
            /* @var Plan $plan */
            return [
                'id' => $plan->id,
                'nickname' => $plan->nickname,
                'price' => $plan->amount,
                'interval' => $plan->interval,
                'currency' => $plan->currency,
                'product' => $plan->product,
            ];
        })->toArray();
    }
}
