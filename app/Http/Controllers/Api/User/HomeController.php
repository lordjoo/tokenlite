<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\TokenCalculate as TC;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMeta;
use App\Notifications\ResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exceptions\APIException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

class HomeController extends Controller
{
    /**
     * @throws APIException
     */
    public function __construct()
    {
        if( $this->hasKey() === false && !app()->runningInConsole()){
            throw new APIException("Provide valid access key", 401);
        }
        $this->middleware('auth:api', ['except' => ['login','register','reset']]);
    }
    /**
     * Check the API key
     */
    protected function hasKey() : bool
    {
        $api_key = request()->secret;
        return (get_setting('site_api_key', null) == $api_key);
    }

    /**
     * Show the application dashboard.
     *
     * @return JsonResponse
     * @return void
     *@since 1.0
     * @version 1.0.0
     */
    public function index()
    {

        $user = request()->user();
        $stage = active_stage();
        $contribution = Transaction::user_contribution();
        $tc = new \App\Helpers\TokenCalculate();
        $active_bonus = $tc->get_current_bonus('active');

        $base_cur = base_currency();
        $base_con = isset($contribution->$base_cur) ? to_num($contribution->$base_cur, 'auto')  : 0;
        $base_out =  ($base_con > 0 ? $base_con : '~ ') . strtoupper($base_cur);

        if(gws('user_in_cur1', 'eth') != 'hide') {
            $cur1 = gws('user_in_cur1', 'eth');
            $cur1_con = (gws('pmc_active_'.$cur1) == 1) ? to_num($contribution->$cur1, 'auto') : 0;
            $cur1_out = ($cur1 != $base_cur) ? ($cur1_con > 0 ? $cur1_con : '~ ')  . strtoupper($cur1): '';
        }




        if(gws('user_in_cur2', 'btc')!='hide') {
            $cur2 = gws('user_in_cur2', 'btc');
            $cur2_con = (gws('pmc_active_'.$cur2) == 1) ? to_num($contribution->$cur2, 'auto') : 0;
            $cur2_out = ($cur2 != $base_cur) ?  ($cur2_con > 0 ? $cur2_con : '~ '). strtoupper($cur2): '';
        }




        $cards = [
            'token_balance' => [
                'title' => 'Token Balance',
                'value' => to_num_token($user->tokenBalance) .' '. token('symbol'),
                'contributions_card' => [
                    'title' =>  __('Your Contribution in'),
                    'value' => [
                        $base_out , $cur1_out , $cur2_out,
                    ],
                ]
            ],
            'stage'=>[
                'title' => $stage->name,
                'status' => __(ucfirst(active_stage_status())),
                ''
            ]
        ];


        return response()->json(compact('cards') + compact('contribution'));
    }

    /**
     * Display a listing of the resource.
     *
     * @version 1.0.0
     * @since 1.0
     */
    public function contribute()
    {

        $stage = active_stage();
        $tc = new TC();
        $currencies = Setting::active_currency();
        $currencies['base'] = base_currency();
        $bonus = $tc->get_current_bonus(null);
        $bonus_amount = $tc->get_current_bonus('amount');
        $price = Setting::exchange_rate($tc->get_current_price());
        $minimum = $tc->get_current_price('min');
        $active_bonus = $tc->get_current_bonus('active');
        $pm_currency = PaymentMethod::Currency;
        $pm_active = PaymentMethod::where('status', 'active')->get();
        $token_prices = $tc->calc_token(1, 'price');

        $is_price_show = token('price_show');
        $contribution = Transaction::user_contribution();

        if ($price <= 0 || $stage == null || count($pm_active) <= 0 || token_symbol() == '') {
            return response()->json([
                'error' => 'Invalid request',
                'message' => 'Invalid request! Please try again later.',
            ], 400);
        }

        return response()->json(compact('stage', 'currencies', 'bonus', 'bonus_amount', 'price', 'token_prices', 'is_price_show', 'minimum', 'active_bonus', 'pm_currency', 'contribution'));
    }

    /**
     * Show the user account token management page.
     *
     * @return JsonResponse
     * @return void
     * @since 1.1.2
     * @version 1.0.0
     */
    public function mytoken_balance()
    {
        if(gws('user_mytoken_page')!=1) {
            return response()->json([
                'error' => 'Invalid request',
                'message' => 'Invalid request! Please try again later.',
            ], 400);
        }
        $user = Auth::user();
        $token_account = Transaction::user_mytoken('balance');
        $token_stages = Transaction::user_mytoken('stages');
        $user_modules = nio_module()->user_modules();
        return response()->json(compact('user', 'token_account', 'token_stages', 'user_modules'));
    }

    public function account_update()
    {
        
    }

}
