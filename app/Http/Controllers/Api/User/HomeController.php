<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Helpers\TokenCalculate as TC;
use App\Http\Controllers\Controller;
use App\Models\GlobalMeta;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMeta;
use App\Notifications\PasswordChange;
use App\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exceptions\APIException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use PragmaRX\Google2FA\Google2FA;

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
            return $this->response
                ->error('Invalid request! Please try again later.',400)
                ->return();
        }
        $user = Auth::user();
        $token_account = Transaction::user_mytoken('balance');
        $token_stages = Transaction::user_mytoken('stages');
        $user_modules = nio_module()->user_modules();

        return $this->response
            ->setData(compact('token_account', 'token_stages', 'user_modules'))
            ->success()
            ->return();
    }


}
