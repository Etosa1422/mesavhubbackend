<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = SiteSetting::all()->pluck('value', 'key');
        return response()->json(['status' => 'success', 'data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'font_family'          => 'sometimes|string|in:Fredoka,Poppins,Nunito Sans,Roboto Slab,Sahitya',
            'sms_activate_api_key' => 'sometimes|nullable|string|max:1000',
            'five_sim_api_key'     => 'sometimes|nullable|string|max:1000',
            'twilio_account_sid'   => 'sometimes|nullable|string|max:200',
            'twilio_auth_token'    => 'sometimes|nullable|string|max:200',
            'twilio_webhook_url'   => 'sometimes|nullable|url|max:500',
            'twilio_base_price_usd' => 'sometimes|nullable|numeric|min:0',
            'sms_provider_order'   => 'sometimes|nullable|string|max:200',
            'virtual_number_markup'=> 'sometimes|nullable|numeric|min:1|max:10',
            'smspool_api_key'      => 'sometimes|nullable|string|max:1000',
            'grizzly_sms_api_key'  => 'sometimes|nullable|string|max:1000',
            'onlinesim_api_key'    => 'sometimes|nullable|string|max:1000',
            // Payment settings
            'whatsapp_number'      => 'sometimes|nullable|string|max:20',
            'opay_account_name'    => 'sometimes|nullable|string|max:100',
            'opay_account_number'  => 'sometimes|nullable|string|max:20',
            'opay_bank_name'       => 'sometimes|nullable|string|max:50',
            // KorraPay
            'korrapay_account_name'   => 'sometimes|nullable|string|max:100',
            'korrapay_account_number' => 'sometimes|nullable|string|max:20',
            'korrapay_bank_name'      => 'sometimes|nullable|string|max:50',
            // Crypto
            'crypto_wallet_address'  => 'sometimes|nullable|string|max:200',
            'crypto_network'         => 'sometimes|nullable|string|max:50',
            'crypto_currency'        => 'sometimes|nullable|string|max:20',
            // Payment method visibility
            'payment_flutterwave_enabled' => 'sometimes|nullable|in:0,1',
            'payment_opay_enabled'        => 'sometimes|nullable|in:0,1',
            'payment_korrapay_enabled'    => 'sometimes|nullable|in:0,1',
            'payment_crypto_enabled'     => 'sometimes|nullable|in:0,1',
        ]);

        $allowed = [
            'font_family','sms_activate_api_key','five_sim_api_key',
            'twilio_account_sid','twilio_auth_token','twilio_webhook_url',
            'twilio_base_price_usd','sms_provider_order','virtual_number_markup',
            'smspool_api_key','grizzly_sms_api_key','onlinesim_api_key',
            'whatsapp_number','opay_account_name','opay_account_number','opay_bank_name',
            'korrapay_account_name','korrapay_account_number','korrapay_bank_name',
            'crypto_wallet_address','crypto_network','crypto_currency',
            'payment_flutterwave_enabled','payment_opay_enabled','payment_korrapay_enabled','payment_crypto_enabled',
        ];

        foreach ($request->only($allowed) as $key => $value) {
            SiteSetting::set($key, $value);
        }

        return response()->json(['status' => 'success', 'message' => 'Settings saved.']);
    }
}
