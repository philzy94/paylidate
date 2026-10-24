<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Payment;
use App\Services\FlutterwaveService;
use App\Services\PaymentService;
use App\Transaction;
use Exception;

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
        $response = $this->flutterwaveService->validate_payment($request->flwRef, $request->otp);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment validation successful',
            'data' => $response['data']
        ]);
    }

    public function verify_payment(Request $request)
    {

        $response = $this->flutterwaveService->verify_payment($request->flw_T_id);

        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $response['data']
        ]);
    }

    public function get_rate(Request $request)
    {
        $response = $this->flutterwaveService->getRate($request->amount, $request->destination_currency, $request->source_currency);

        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $response['data']
        ]);
    }

    public function banks()
    {
        $response = $this->flutterwaveService->banks();

        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $response['data']
        ]);
    }

    public function payemnts_received()
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json([
                'status'    => 'error',
                'message'   => 'Please Log in'
            ], 401);
        }
        try {
            $response = Transaction::where('user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->get();

            return response()->json([
                'status'    => 'success',
                'message'   => 'success',
                'data'      => $response
            ], 200);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status'    => 'success',
                'message'   => 'An error occured. Please try again'
            ], 400);
        }
    }
}
