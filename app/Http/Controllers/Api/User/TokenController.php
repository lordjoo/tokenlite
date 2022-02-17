<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Helpers\IcoHandler;
use App\Helpers\TokenCalculate as TC;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMeta;
use App\Notifications\PasswordChange;
use App\PayModule\Module;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TokenController extends Controller
{
    protected $response, $handler,$module;

    public function __construct(ApiResponse $response, IcoHandler $handler,Module $module)
    {
        $this->middleware('auth:api');
        $this->response = $response;
        $this->module = $module;
        $this->handler = $handler;

    }

    /**
     * Display a listing of the resource.
     *
     * @version 1.0.0
     * @since 1.0
     */
    public function index(): JsonResponse
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
            return $this->response
                ->error('Invalid request! Please try again later.',400)
                ->return();
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
            "base_bonus_percentage" => $bonus,
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
     * Access the confirm and count
     *
     * @version 1.1
     * @since 1.0
     * @throws \Throwable
     */
    public function contribute_access()
    {
        $request = request();
        $validator = Validator::make($request->all(), [
            'token_amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|max:3',
        ]);

        if ($validator->fails()) {
            return $this->response
                ->setError($validator->errors()->toArray())
                ->error('Invalid request',400)
                ->return();
        }

        $tc = new TC();
        $get = $request->input('req_type');
        $min = $tc->get_current_price('min');
        $currency = $request->input('currency');
        $token = (float) $request->input('token_amount');
        $_data = [];

        $last = (int)get_setting('piks_ger_oin_oci', 0);
        if ($last > 3 ){
            return $this->response->error(__('messages.trnx.wrong'), 400);
        }
        try {
            if (!empty($token) && $token >= $min) {
                $_data = (object) [
                    'currency' => $currency,
                    'currency_rate' => Setting::exchange_rate($tc->get_current_price(), $currency),
                    'token' => round($token, min_decimal()),
                    'bonus_on_base' => $tc->calc_token($token, 'bonus-base'),
                    'bonus_on_token' => $tc->calc_token($token, 'bonus-token'),
                    'total_bonus' => $tc->calc_token($token, 'bonus'),
                    'total_tokens' => $tc->calc_token($token),
                    'base_price' => $tc->calc_token($token, 'price')->base,
                    'amount' => round($tc->calc_token($token, 'price')->$currency, max_decimal()),
                ];
            }

            if ($this->check($token)) {
                // check if method is available
                $payment_methods = $this->module->get_methods($currency, $_data);

                if (empty($payment_methods)) {
                    return $this->response->error(__('Sorry! There is no payment method available for this currency. Please choose another currency or contact our support team.'), 400)
                        ->return();
                }

                if ($token >= $min && $token != null) {
                    $ret['payment_methods'] = $payment_methods;
                    $ret['data'] = [$currency, $_data];
                }
                return $this->response->success([$ret])->return();
            } else {
                return $this->response->error($this->check($request->token_amount, 'err'))->return();
            }

        } catch (\Exception $e) {
            return $this->response->error($e->getMessage(), 400)
                ->setError([
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),

                ])
                ->return();
        }

        return $this->response->success()->return();
    }


    /**
     * Check the state
     *
     * @version 1.0.0
     * @since 1.0
     * @return bool|string
     */
    private function check($token, $extra = '')
    {
        $tc = new TC();
        $stg = active_stage();
        $min = $tc->get_current_price('min');
        $available_token = ( (double) $stg->total_tokens - ($stg->soldout + $stg->soldlock) );
        $symbol = token_symbol();

        if ($extra == 'err') {
            if ($token >= $min && $token <= $stg->max_purchase) {
                if ($token >= $min && $token > $stg->max_purchase) {
                    return __('Maximum amount reached, You can purchase maximum :amount :symbol per transaction.', ['amount' => $stg->max_purchase, 'symbol' =>$symbol]);
                } else {
                    return __('You must purchase minimum :amount :symbol.', ['amount' => $min, 'symbol' =>$symbol]);
                }
            } else {
                if($available_token < $min) {
                    return __('Our sales has been finished. Thank you very much for your interest.');
                } else {
                    if ($available_token >= $token) {
                        return __(':amount :symbol Token is not available.', ['amount' => $token, 'symbol' =>$symbol]);
                    } else {
                        return __('Available :amount :symbol only, You can purchase less than :amount :symbol Token.', ['amount' => $available_token, 'symbol' =>$symbol]);
                    }
                }
            }
        } else {
            if ($token >= $min && $token <= $stg->max_purchase) {
                if ($available_token >= $token) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }
    }

}
