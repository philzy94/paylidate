<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Payment;
use App\Services\FlutterwaveService;
use App\Services\PaymentService;

/**
 * @group  Payment management
 *
 * APIs for Payment
 */
class PaymentController extends Controller
{
    protected $flutterwaveService;

    protected $paymentService;

    public function __construct(FlutterwaveService $flutterwaveService, PaymentService $paymentService)
    {
        $this->flutterwaveService = $flutterwaveService;
        $this->paymentService = $paymentService;
    }
    /**
     * Get all payments.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $Payment = Payment::where('user_id', auth('api')->user()->id)->get();
        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $Payment->load('product')
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
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
     *
     *
     * @return [string] message
     */
    public function make_payment(Request $request)
    {
        $data = $request->validate([
            'payment_id' => 'required',
            'payment_ref' => 'nullable|string',
            'transaction_id' => 'required|exists:transactions,id',
            'description' => 'nullable|string',
        ]);

        $payment = $this->paymentService->recordVerifiedPayment(auth('api')->user()->id, $data);

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
     *
     * @return [json] user object
     */
    public function show($id)
    {
        $payment = Payment::where('user_id', auth('api')->user()->id)->with('product')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $payment
        ]);
    }

    public function update(Request $request, $id)
    {
        $payment = Payment::where('user_id', auth('api')->user()->id)->findOrFail($id);
        $payment->update($request->validate(['description' => 'nullable|string']));

        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $payment
        ]);
    }

    /**
     * Delete the specified product.
     *
     * @urlParam  id string required the id of the payment
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $payment = Payment::where('user_id', auth('api')->user()->id)->findOrFail($id)->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $payment
        ]);
    }



    // Initiate card payment
    public function pay_with_card(Request $request)
    {
        $data = $this->paymentService->initiateCardPayment(auth('api')->user(), $request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Payment initiated successfully',
            'data' => $data
        ]);
    }


    public function validate_payment(Request $request)
    {
        $data = $request->validate(['flwRef' => 'required|string', 'otp' => 'required|string']);

        $response = $this->flutterwaveService->validate_payment($data['flwRef'], $data['otp']);

        return $this->success($response['data'] ?? null, 'Payment validation successful');
    }

    public function verify_payment(Request $request)
    {
        $data = $request->validate(['flw_T_id' => 'required|string']);

        return $this->success($this->flutterwaveService->verify_payment($data['flw_T_id'])['data'] ?? null);
    }

    public function get_rate(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric',
            'destination_currency' => 'required|string',
            'source_currency' => 'required|string',
        ]);

        $response = $this->flutterwaveService->getRate($data['amount'], $data['destination_currency'], $data['source_currency']);

        return $this->success($response['data'] ?? null);
    }

    public function banks()
    {
        return $this->success($this->flutterwaveService->banks()['data'] ?? []);
    }
}
