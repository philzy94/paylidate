<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\AddMoneyMail;
use App\Payment;
use App\Services\FlutterwaveService;
use App\Services\PaymentService;
use App\Transaction;
use Auth;
use Illuminate\Validation\ValidationException;
use stdClass;
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

        if ($this->paymentService->exists($data['payment_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'This Payment has been verified',
            ]);
        }

        try {
            $payment = $this->paymentService->recordVerifiedPayment(auth('api')->user()->id, $data);
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occured while trying to verify your payment. Please contact paylidate.com ',
            ]);
        }

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
        $Payment = Payment::where('id', $id)->get();
        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $Payment->load('product')
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }



    public function update(Request $request, $id)
    {
        $input = $request->all();
        $Payment = Payment::where('id', $id)->update($input)->get();
        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $Payment
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
        $payment = Payment::where('id', $id)->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $payment
        ]);
    }



    // Initiate card payment
    public function pay_with_card(Request $request)
    {
        $user = auth('api')->user();
        $meta = new stdClass();
        $meta->consumer_id =  $user->name;
        $meta->consumer_mac = "";
        $name = explode(' ', $user->name);
        $lastName = "";
        if (count($name) > 1) {
            $lastName = $name[0];
        }

        $data = array(

            'PBFPubKey' => config('services.flutterwave.public_key'),
            'cardno' => $request->cardno,
            'currency' => $request->currency,
            'country' => $request->country,
            'cvv' => strval($request->cvv),
            'amount' => $request->amount,
            'expiryyear' => $request->expiryyear,
            'expirymonth' => $request->expirymonth,
            'pin' => $request->pin,
            'email' => $user->email,
            'phonenumber' => $user->phone,
            "firstname" => $name[0],
            "lastname" => $lastName,
            'txRef' => 'PD' . $user->id . date('dmyHis'),
            'meta' => $meta,
            'redirect_url' => $request->redirect_url,

        );


        try {

            $response = $this->flutterwaveService->payviacard($data);


            if (empty($response['data']) || $response["status"] != "success" || isset($response['data']['code'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'An error occured while trying to initiate transaction. Please try again',

                ], 417);
            }

            if ($response["status"] == "success" && isset($response["data"]['authurl']) && $response["data"]['authurl'] != 'N/A') {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment initiated successfully',
                    'data' => $response['data']
                ]);
            }


            if ($response["status"] == "success" && $response["data"]["suggested_auth"] == "PIN") {
                $data["suggested_auth"] = "PIN";
                $response = $this->flutterwaveService->payviacard($data);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment initiated successfully',
                    'data' => $response['data']
                ]);
            }

            if ($response["status"] == "success" && $response["data"]["suggested_auth"] == "NOAUTH_INTERNATIONAL") {

                throw ValidationException::withMessages([
                    'error' => ['This card can not be charge']
                ]);
            }
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occured while trying to initiate transaction. Please try again',

            ], 417);
        }
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
        if(!$user){
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

        } 
        catch(\Exception $e) {
            return response()->json([
                'status'    => 'success',
                'message'   => 'An error occured. Please try again'
            ], 400);
        }
    }
}
