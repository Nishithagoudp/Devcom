<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Challenge;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

class PayPalController extends Controller
{
    /**
     * process transaction.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function processTransaction($amount)
    {
        try {
            // Initialize PayPal client
            $provider = new PayPalClient;
            $provider->setApiCredentials(config('paypal'));
            $paypalToken = $provider->getAccessToken();
            $amount = session('payment_amount');
            // Create the order with PayPal
            $response = $provider->createOrder([
                "intent" => "CAPTURE",
                "application_context" => [
                    "return_url" => route('successTransaction'),
                    "cancel_url" => route('cancelTransaction'),
                ],
                "purchase_units" => [
                    0 => [
                        "amount" => [
                            "currency_code" => "USD",
                            "value" => (int)$amount
                        ]
                    ]
                ]
            ]);

            // Check if PayPal returned a valid response
            if (isset($response['id']) && $response['id'] != null) {

                // Create a Payment record in "pending" status
                $payment = Payment::create([
                    'user_id' => Auth::id(),
                    'amount' => session('payment_amount'),
                    'payment_method' => 'paypal',
                    'transaction_id' => $response['id'], // Store the PayPal transaction ID
                    'status' => 'pending', // Set initial status as pending
                    'payable_type' => session('payment_type'), // Set the payment type, e.g., 'certificate' or 'subscription'
                    'payable_id' => session('payable_id'), // Set the payable ID if available in the session
                ]);

                // Find the approval link and redirect the user to PayPal
                foreach ($response['links'] as $link) {
                    if ($link['rel'] == 'approve') {
                        return redirect()->away($link['href']); // Redirect to PayPal
                    }
                }

                // If no approval link, display error and redirect to homepage
                Session::flash('error', 'Something went wrong.');
                return redirect()->back();
            } else {
                // Handle cases where PayPal fails to create an order
                Session::flash('error', $response['message'] ?? 'Something went wrong.');
                return redirect()->back();
            }
        } catch (\Throwable $throwable) {
            // Handle and log errors
            \Log::error('Error in processTransaction:', ['error' => $throwable->getMessage()]);
            Session::flash('error', $throwable->getMessage() ?? 'Something went wrong.');
            return redirect()->back();
        }
    }




    /**
     * Handle successful transaction.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function successTransaction(Request $request)
    {
        DB::beginTransaction();
        try {
            $provider = new PayPalClient;
            $provider->setApiCredentials(config('paypal'));
            $provider->getAccessToken();
            $response = $provider->capturePaymentOrder($request['token']);

            if (isset($response['status']) && $response['status'] == 'COMPLETED') {
                $paymentType = session('payment_type');
                $amount = session('payment_amount');

                if ($paymentType === 'certificate') {
                    $this->processCertificatePayment($amount);
                } elseif ($paymentType === 'subscription') {
                    $this->processSubscriptionPayment($amount);
                }

                // Find the payment record and update its status to completed
                $payment = Payment::where('transaction_id', $request['token'])->first();
                if ($payment) {
                    $payment->status = 'completed';
                    $payment->save();
                }

                DB::commit();
                Session::flash('success', 'Transaction Successfully Completed.');
                return redirect()->back();
            } else {
                DB::rollBack();
                Session::flash('error', $response['message'] ?? 'Something went wrong.');
                return redirect()->route('homepage');
            }
        } catch (\Throwable $throwable) {
            DB::rollBack();
            Session::flash('error', $throwable->getMessage() ?? 'Something went wrong.');
            return redirect()->back();
        }
    }

    private function processCertificatePayment($amount)
    {
        $challengeId = session('certificate_challenge_id');
        $challenge = Challenge::findOrFail($challengeId);

        $certificate = Certificate::create([
            'user_id' => Auth::id(),
            'challenge_id' => $challengeId,
            'issued_at' => now(),
        ]);

        // Remove challenge ID from session
        session()->forget('certificate_challenge_id');
    }

    private function processSubscriptionPayment($amount)
    {
        $planName = session('subscription_plan');
        $duration = session('subscription_duration');

        $subscription = Subscription::create([
            'user_id' => Auth::id(),
            'plan_name' => $planName,
            'starts_at' => now(),
            'ends_at' => now()->addDays($duration),
        ]);

        // Remove subscription plan and duration from session
        session()->forget(['subscription_plan', 'subscription_duration']);
    }

    /**
     * Handle canceled transaction.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancelTransaction(Request $request)
    {
        Session::flash('error', 'Payment cancelled.');
        return redirect()->back();
    }
}
