<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::middleware(['nonPayingCustomer'])->get('/subscribe', function () {
        return view('subscribe',[ 
            'intent' => auth()->user()->createSetupIntent(),
        ]);
    })->name('subscribe');

    Route::middleware(['nonPayingCustomer'])->post('/subscribe', function (Request $request) {
        // dd($request->all());
        auth()->user()->newSubscription('cashier', $request->plan)->create($request->paymentMethod);
        return redirect('dashboard');
    })->name('subscribe.post');

    Route::middleware(['payingCustomer'])->get('/members', function () {
        return view('members');
    })->name('members');

    Route::get('/charge', function () {
        return view('charge',[ 
            'intent' => auth()->user()->createSetupIntent(),
        ]);
    })->name('charge');

    Route::post('/charge', function (Request $request) {
        // dd($request->all());
        // auth()->user()->charge(1000, $request->paymentMethod);
        auth()->user()->createAsStripeCustomer();
        auth()->user()->updateDefaultPaymentMethod($request->paymentMethod);
        auth()->user()->invoiceFor('One Time Fee', 1500);

        return redirect('dashboard');
    })->name('charge.post');

    Route::get('/invoices', function () {
        return view('invoices',[ 
            'invoices' => auth()->user()->invoices(),
        ]);
    })->name('invoices');

    Route::middleware(['payingCustomer'])->get('/user/invoice/{invoice}', function (Request $request, $invoiceId) {
        return $request->user()->downloadInvoice($invoiceId, [
            'vendor' => 'AREY',
            'product' => 'One Time Fee',
        ]);
    });
});

