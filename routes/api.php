<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ThirdPartyApiMiddleware;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/



// This endpoint is to be consume by flutterwave.
// It is for credting wallet by transfer
Route::namespace('Api')->group(
    function () {
        Route::post('settlement_notif', 'WalletController@creditWalletByTransfer');
    }
);

Route::group(['prefix' => 'api'], function () {

    Route::namespace('Api')->group(function () {

        Route::post('login', 'AuthController@login');
        Route::post('signup', 'AuthController@signup');
        Route::post('signup/referral/{ref}', 'AuthController@refferal_signup');


        Route::get('signup/activate/{token}', 'AuthController@signupActivate');
        Route::get('check/email/{email}', 'AuthController@check_email');
        Route::get('product/{slug}', 'ProductController@get_product');
        Route::get('catalog/{id}', 'ProductController@get_catalog');
        Route::get('catalog/{id}', 'ProductController@get_catalog');


        Route::get('transaction/{T_ref}', 'TransactionController@get_transaction');

        // Fulfillment Route
        Route::get('fulfillment/{hash}', 'FulfilmentController@get_transaction');
        Route::post('fulfillment/{hash}', 'FulfilmentController@confirm_fufilment')->middleware('throttle:fulfilment');

        Route::group(['prefix' => 'password'], function () {
            Route::post('create', 'PasswordResetController@create');
            Route::get('find/{token}', 'PasswordResetController@find');
            Route::post('reset', 'PasswordResetController@reset');
        });

        Route::Post('get-rate', 'PaymentController@get_rate');
        Route::get('get-banks', 'PaymentController@banks');

        // password reset routes
        Route::post('verify-account', 'BankController@verify_account_number');
        Route::group(['middleware' => 'auth:api'], function () {
            Route::get('logout', 'AuthController@logout');
            Route::get('user', 'AuthController@user');
            Route::post('user/update', 'AuthController@update');
            Route::post('rate-user/{id}', 'AuthController@rate_user');
            Route::get('get-user-rate/{id}', 'AuthController@get_user_ratings');

            Route::post('resendVerificationEmail/{email}', 'AuthController@resendVerificationEmail');
            Route::post('verifyEmail/{token}', 'AuthController@verifyEmail');


            Route::post('send-dispute-chat', 'DisputeChatController@store');

            Route::post('open-dispute', 'DisputeController@open_dispute');
            Route::post('resolve-dispute', 'DisputeController@resolve_dispute');
            Route::get('transaction-disputes/{transaction_id}', 'DisputeController@getTransactionDisputes');

            // wallet routes
            Route::get('get-wallet', 'WalletController@index');
            Route::post('create-wallet', 'WalletController@create');
            Route::post('credit-wallet', 'WalletController@creditWalletByFL');
            Route::post('debit-wallet', 'WalletController@debitWallet');
            Route::get('get-wallet-history', 'WalletHistoryController@index');


            Route::resource('product', 'ProductController')->only(['index', 'store', 'show', 'update', 'destroy']);
            Route::post('product/{id}/recommend', 'ProductController@product_recommendation');
            Route::delete('product/{id}/recommend', 'ProductController@remove_product_recommendation');
            Route::post('product/status/{id}', 'ProductController@status');
            Route::post('product/update/{id}', 'ProductController@update');
            Route::post('product/status/delivered/{id}', 'ProductController@delivered');

            Route::post('instant-pay/tranfer', 'InstandpayController@transfer');
            Route::post('instant/tranfer', 'InstandpayController@transfer');
            Route::post('instant-pay/verify', 'InstandpayController@verify')->middleware('throttle:fulfilment');
            Route::post('instant-pay/withdraw', 'InstandpayController@withdraw');
            Route::get('instant-pay/history-send', 'InstandpayController@send');
            Route::get('instant-pay/history-receive', 'InstandpayController@receive');
            Route::get('verify-number/{phone_number}', 'InstandpayController@verify_user');

            Route::post('transaction/accept/{id}', 'TransactionController@accept');
            Route::post('transaction/decline/{id}', 'TransactionController@decline');
            Route::post('transaction/confirm/{id}', 'TransactionController@confirm');
            Route::post('transaction/cancel/{id}', 'TransactionController@cancel');
            Route::post('transaction/report-transaction/{id}', 'TransactionController@reportTransaction');
            Route::post('transaction/reslove-report/{id}/{sellerEmail}', 'TransactionController@resloveReport');

            Route::post('payment/card', 'PaymentController@pay_with_card');
            Route::post('payment/validate', 'PaymentController@validate_payment');
            Route::post('payment/verify', 'PaymentController@verify_payment');
            Route::resource('payment', 'PaymentController')->only(['index', 'show', 'update', 'destroy']);
            Route::post('make-payment', 'PaymentController@make_payment');

            Route::resource('card', 'CardController');
            Route::resource('transaction', 'TransactionController')->only(['index', 'store']);
            Route::resource('account', 'UserAccountController');

            Route::post('fund', 'CardController@fund');

            Route::resource('user-bank', 'BankController');

            //Dashboard Endpoints
            Route::get('dashboard', 'DashboardController@index');


            Route::get('withdraw-requests', 'WithdrawalController@index');
            Route::post('request-withdrawal', 'WithdrawalController@request_withdrawal');
            Route::post('transfer-to-bank', 'WithdrawalController@transfer_to_bank')->middleware('admin');


            Route::get('refund-requests', 'RefundController@index');
            Route::post('request-refund', 'RefundController@request_Refund');
            Route::post('transfer-to-buyer-bank', 'RefundController@transfer_to_bank')->middleware('admin');


            Route::get('referral-bonus', 'RefererController@index');
            Route::get('referral', 'RefererController@get_referer');

            Route::post('request-referral-bonus-withdrawal', 'ReferralWidrawalController@request_withdrawal');
            Route::get('referral-withdraw-requests', 'ReferralWidrawalController@index');
            Route::post('transfer-referral-bonus-to-bank', 'ReferralWidrawalController@transfer_to_bank')->middleware('admin');
        });
    });



    Route::group(['middleware' => ['auth:api', 'admin'], 'prefix' => 'admin'], function () {
        Route::get('users', 'AdminController@users');
        Route::get('users/{startDate}/{endDate}', 'AdminController@userBtwnDate');
        Route::get('dashboard', 'AdminController@numbers_of');
    });



    Route::namespace('ThirdPartyApi')->group(function () {


        Route::group(['prefix' => 'v1'], function () {

            Route::middleware([ThirdPartyApiMiddleware::class])->group(function () {
                Route::get('ping', function () {
                    return response()->json(['status' => 'success', 'message' => 'pong']);
                });
            });
        });
    });
});
