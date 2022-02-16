<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
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
    // api_response
    protected $response;

    /**
     * @throws APIException
     */
    public function __construct(ApiResponse $response)
    {
        $this->response = $response;

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
     * @since 1.0
     * @version 1.0.0
     */
    public function index()
    {

        $user = request()->user();
        $stage = active_stage();
        $contribution = Transaction::user_contribution();
        $tc = new \App\Helpers\TokenCalculate();
//        $active_bonus = $tc->get_current_bonus('active');

        $base_cur = base_currency();
        $base_con = isset($contribution->$base_cur) ? to_num($contribution->$base_cur, 'auto')  : 0;
        $base_out =  ($base_con > 0 ? $base_con : '~ ') . strtoupper($base_cur);

        $_CUR = base_currency(true);
        $_SYM = token_symbol();
        $base_currency = base_currency();
        $token_1price = token_calc(1, 'price')->$base_currency;
        $token_1rate = token_rate(1, token('default_in_userpanel', 'eth'));
        $token_ratec = token('default_in_userpanel', 'ETH');

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




        $sales_raised = (token('sales_raised')) ? token('sales_raised') : 'token';
        $sales_total = (token('sales_total')) ? token('sales_total') : 'token';
        $sales_caps = (token('sales_cap')) ? token('sales_cap') : 'token';
        if(is_expired() || is_completed()) {
            $sales_state = __('Our token sales has been finished. Thank you very much for your contribution.');
        }elseif(is_upcoming()){
            $sales_state = __('Sales Start in');
        }else{
            $sales_state = __('Sales End in');
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
                'convertor'=>[
                    [
                        'from'=> [
                            'amount' => '1',
                            'symbol' => $_SYM,
                        ],
                        'to'=> [
                            'amount' => to_num($token_1price, 'max', ',', true),
                            'symbol' => $_CUR,
                        ],
                    ],[
                        'from'=> [
                            'amount' => '1',
                            'symbol' => $_CUR,
                        ],
                        'to'=> [
                            'amount' => to_num($token_1rate, 'max', ',', true),
                            'symbol' => $token_ratec,
                        ],
                    ],
                ]
            ],
            'token_sales_progress'=>
                (gws('user_sales_progress', 1) != 1)
                ? null :[
                    'title' => __('Token Sales Progress'),
                    'progress_bar' => [
                        'sales_caps' => $sales_caps,
                        'percentage' => sale_percent(active_stage()),
                        'raised_amount'=> [
                            'title' => __('Raised Amount'),
                            'amount' => ico_stage_progress('raised', $sales_raised)
                        ],
                        'total_amount'=> [
                            'title' => __('Total Token'),
                            'amount' => ico_stage_progress('total', $sales_total)
                        ],
                    ],
                    'sales_state' => [
                        'title' => __('Sales End In'),
                        'start_date' => _date(active_stage()->end_date, 'Y/m/d H:i:s'),
                        'end_date' => _date(active_stage()->start_date, 'Y/m/d H:i:s'),
                        'sales_state' => $sales_state,
                    ],

                ]

        ];

        return $this->response
            ->withCards($cards)
            ->withUser($user)
            ->success()
            ->return();

    }

    /**
     * Display a listing of the resource.
     *
     * @version 1.0.0
     * @since 1.0
     */
    public function contribute(): JsonResponse
    {

        $stage = active_stage();
        $tc = new TC();
        $currencies = Setting::active_currency();
        $method = strtolower(token_method());
        $bonus = $tc->get_current_bonus(null);
        $bonus_amount = $tc->get_current_bonus('amount');
        $amount_bonus = (!empty($bonus_amount)) ? $bonus_amount : [1=>0];

        $decimal_min = (token('decimal_min')) ? token('decimal_min') : 0;
        $decimal_max = (token('decimal_max')) ? token('decimal_max') : 0;

        $price = Setting::exchange_rate($tc->get_current_price());
        $minimum = $tc->get_current_price('min');
        $active_bonus = $tc->get_current_bonus('active');
        $pm_currency = PaymentMethod::Currency;
        $pm_active = PaymentMethod::where('status', 'active')->get();
        $token_prices = $tc->calc_token(1, 'price');

        $is_price_show = token('price_show');
        $contribution = Transaction::user_contribution();


        $token_you_can_buy = collect($pm_currency)->filter(function ($name,$symbol) use ($method){
            if(token('purchase_'.$symbol) == 1 || $method==$symbol){
                return true;
            }
            return false;
        })->map(function($item, $key)use($token_prices){
            return [
                'symbol' => $key,
                'name' => $item,
                'price' => to_num($token_prices->$key, 'max', ','),
            ];
        });


        if ($price <= 0 || $stage == null || count($pm_active) <= 0 || token_symbol() == '') {
            return response()->json([
                'error' => 'Invalid request',
                'message' => 'Invalid request! Please try again later.',
            ], 400);
        }

        $cards = [
            'contributions_card' => [
                'title' => __('Choose currency and calculate :SYMBOL token price', ['symbol' => token_symbol()]),
                'description' => __('You can buy our :SYMBOL token using the below currency choices to become part of our project.', ['symbol' => token_symbol()]),
                'cards' => [
                    $token_you_can_buy,
                ],
            ],
        ];
        $min_token = ($minimum) ? $minimum : active_stage()->min_purchase;
        $info = [
            'max_purchase_token' => [
                'amount'=> $stage->max_purchase,
                'msg' => __('Maximum you can purchase :maximum_token token per contribution.', ['maximum_token' => to_num($stage->max_purchase, 'max', ',')])
            ],
            'min_purchase_token'=>[
                'amount' => $min_token,
                'msg' => __('Enter minimum :minimum_token token and select currency!', ['minimum_token' => to_num(to_num($min_token, 'max',','), 'max', ',')])
            ],
            'token_price'=>$price,
            'token_symbol'=>token_symbol(),
            "base_bonus" =>  $bonus,
            "amount_bonus" =>  $amount_bonus,
            "decimals" => ["min"=>$decimal_min, "max"=> $decimal_max ],
            "base_currency" =>  base_currency(),
            "base_method" => $method,
        ];

        return $this->response
            ->withCards($cards)
            ->withInfo($info)
            ->success()
            ->return();
        //return response()->json(compact('stage', 'currencies', 'bonus', 'bonus_amount', 'price', 'token_prices', 'is_price_show', 'minimum', 'active_bonus', 'pm_currency', 'contribution'));
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




}
