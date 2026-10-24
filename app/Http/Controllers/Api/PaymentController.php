<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FlutterwaveService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

/**
 * @group  Payment management
 *
 * APIs for Payment
 */
class PaymentController extends Controller
{
    protected FlutterwaveService $flutterwaveService;

    protected PaymentService $paymentService;

    public function __construct(FlutterwaveService $flutterwaveService, PaymentService $paymentService)
    {
        $this->flutterwaveService = $flutterwaveService;
        $this->paymentService = $paymentService;
    }
    /**
     * Get all payments.
     */
    public function index(): JsonResponse
    {
        return $this->success($this->paymentService->listFor($this->authUser()));
    }

    /**
     * Create Payment
     *
     * the payment creation
     *
     * @bodyParam product_id string required
     * @bodyParam quantity int string
     * @bodyParam type string required  either make-payment/receive-payment
     * @bodyParam status boolean true for paid false un-paid,  false by default
     * @bodyParam expires string expires in a week by default
     * @bodyParam description string
     */
    public function make_payment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => 'required',
            'payment_ref' => 'nullable|string',
            'transaction_id' => 'required|exists:transactions,id',
            'description' => 'nullable|string',
        ]);

        $payment = $this->paymentService->recordVerifiedPayment($this->authUser()->id, $data);

        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $payment
        ]);
    }


    /**
     * Get Single Payment
     *
     * @urlParam id string required
     */
    public function show(string $id): JsonResponse
    {
        return $this->success($this->paymentService->findFor($this->authUser(), (int) $id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['description' => 'nullable|string']);

        return $this->success($this->paymentService->describe($this->authUser(), (int) $id, $data['description'] ?? null));
    }

    /**
     * Delete one of your own payments.
     *
     * @urlParam  id string required the id of the payment
     */
    public function destroy(string $id): JsonResponse
    {
        $this->paymentService->remove($this->authUser(), (int) $id);

        return response()->json(['status' => 'success', 'message' => 'success']);
    }

    // Initiate card payment
    public function pay_with_card(Request $request): JsonResponse
    {
        $data = $this->paymentService->initiateCardPayment($this->authUser(), $request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Payment initiated successfully',
            'data' => $data
        ]);
    }


    public function validate_payment(Request $request): JsonResponse
    {
        $data = $request->validate(['flwRef' => 'required|string', 'otp' => 'required|string']);

        $response = $this->flutterwaveService->validate_payment($data['flwRef'], $data['otp']);

        return $this->success($response['data'] ?? null, 'Payment validation successful');
    }

    public function verify_payment(Request $request): JsonResponse
    {
        $data = $request->validate(['flw_T_id' => 'required|string']);

        return $this->success($this->flutterwaveService->verify_payment($data['flw_T_id'])['data'] ?? null);
    }

    public function get_rate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric',
            'destination_currency' => 'required|string',
            'source_currency' => 'required|string',
        ]);

        $response = $this->flutterwaveService->getRate($data['amount'], $data['destination_currency'], $data['source_currency']);

        return $this->success($response['data'] ?? null);
    }

    public function banks(): JsonResponse
    {
        return $this->success($this->flutterwaveService->banks()['data'] ?? []);
    }
}
