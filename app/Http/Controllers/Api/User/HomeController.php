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
     *@since 1.0
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
                            'amount' =>ico_stage_progress('raised', $sales_raised)
                        ],
                        'total_amount'=> [
                            'title' => __('Total Token'),
                            'amount' =>ico_stage_progress('total', $sales_total)
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

    public function account_update(Request $request)
    {
        $type = $request->input('action_type');
        $ret['msg'] = 'info';
        $ret['message'] = __('messages.nothing');

        if ($type == 'personal_data') {
            $validator = Validator::make($request->all(), [
                'name' => 'required|min:3',
                'email' => 'required|email',
                'dateOfBirth' => 'required|date_format:"m/d/Y"'
            ]);

            if ($validator->fails()) {
                $msg = __('messages.form.wrong');
                if ($validator->errors()->hasAny(['name', 'email', 'dateOfBirth'])) {
                    $msg = $validator->errors()->first();
                }

                $ret['msg'] = 'warning';
                $ret['message'] = $msg;
                return response()->json($ret);
            } else {
                $user = request()->user(); //have to put find or fail here
                $user->name = strip_tags($request->input('name'));
                $user->email = $request->input('email');
                $user->mobile = strip_tags($request->input('mobile'));
                $user->dateOfBirth = $request->input('dateOfBirth');
                $user->nationality = strip_tags($request->input('nationality'));
                $user_saved = $user->save();

                if ($user) {
                    $ret['msg'] = 'success';
                    $ret['message'] = __('messages.update.success', ['what' => 'Account']);
                } else {
                    $ret['msg'] = 'warning';
                    $ret['message'] = __('messages.update.warning');
                }
            }
        }
        if ($type == 'wallet') {
            $validator = Validator::make($request->all(), [
                'wallet_name' => 'required',
                'wallet_address' => 'required|min:10'
            ]);

            if ($validator->fails()) {
                $msg = __('messages.form.wrong');
                if ($validator->errors()->hasAny(['wallet_name', 'wallet_address'])) {
                    $msg = $validator->errors()->first();
                }

                $ret['msg'] = 'warning';
                $ret['message'] = $msg;
                return response()->json($ret);
            } else {
                $is_valid = $this->handler->validate_address($request->input('wallet_address'), $request->input('wallet_name'));
                if ($is_valid) {
                    $user = User::FindOrFail(Auth::id());
                    $user->walletType = $request->input('wallet_name');
                    $user->walletAddress = $request->input('wallet_address');
                    $user_saved = $user->save();

                    if ($user) {
                        $ret['msg'] = 'success';
                        $ret['message'] = __('messages.update.success', ['what' => 'Wallet']);
                    } else {
                        $ret['msg'] = 'warning';
                        $ret['message'] = __('messages.update.warning');
                    }
                } else {
                    $ret['msg'] = 'warning';
                    $ret['message'] = __('messages.invalid.address');
                }
            }
        }
        if ($type == 'wallet_request') {
            $validator = Validator::make($request->all(), [
                'wallet_name' => 'required',
                'wallet_address' => 'required|min:10'
            ]);

            if ($validator->fails()) {
                $msg = __('messages.form.wrong');
                if ($validator->errors()->hasAny(['wallet_name', 'wallet_address'])) {
                    $msg = $validator->errors()->first();
                }

                $ret['msg'] = 'warning';
                $ret['message'] = $msg;
                return response()->json($ret);
            } else {
                $is_valid = $this->handler->validate_address($request->input('wallet_address'), $request->input('wallet_name'));
                if ($is_valid) {
                    $meta_data = ['name' => $request->input('wallet_name'), 'address' => $request->input('wallet_address')];
                    $meta_request = GlobalMeta::save_meta('user_wallet_address_change_request', json_encode($meta_data), auth()->id());

                    if ($meta_request) {
                        $ret['msg'] = 'success';
                        $ret['message'] = __('messages.wallet.change');
                    } else {
                        $ret['msg'] = 'warning';
                        $ret['message'] = __('messages.wallet.failed');
                    }
                } else {
                    $ret['msg'] = 'warning';
                    $ret['message'] = __('messages.invalid.address');
                }
            }
        }
        if ($type == 'notification') {
            $notify_admin = $newsletter = $unusual = 0;

            if (isset($request['notify_admin'])) {
                $notify_admin = 1;
            }
            if (isset($request['newsletter'])) {
                $newsletter = 1;
            }
            if (isset($request['unusual'])) {
                $unusual = 1;
            }

            $user = User::FindOrFail(Auth::id());
            if ($user) {
                $userMeta = UserMeta::where('userId', $user->id)->first();
                if ($userMeta == null) {
                    $userMeta = new UserMeta();
                    $userMeta->userId = $user->id;
                }
                $userMeta->notify_admin = $notify_admin;
                $userMeta->newsletter = $newsletter;
                $userMeta->unusual = $unusual;
                $userMeta->save();
                $ret['msg'] = 'success';
                $ret['message'] = __('messages.update.success', ['what' => 'Notification']);
            } else {
                $ret['msg'] = 'warning';
                $ret['message'] = __('messages.update.warning');
            }
        }
        if ($type == 'security') {
            $save_activity = $mail_pwd = 'FALSE';

            if (isset($request['save_activity'])) {
                $save_activity = 'TRUE';
            }
            if (isset($request['mail_pwd'])) {
                $mail_pwd = 'TRUE';
            }

            $user = User::FindOrFail(Auth::id());
            if ($user) {
                $userMeta = UserMeta::where('userId', $user->id)->first();
                if ($userMeta == null) {
                    $userMeta = new UserMeta();
                    $userMeta->userId = $user->id;
                }
                $userMeta->pwd_chng = $mail_pwd;
                $userMeta->save_activity = $save_activity;
                $userMeta->save();
                $ret['msg'] = 'success';
                $ret['message'] = __('messages.update.success', ['what' => 'Security']);
            } else {
                $ret['msg'] = 'warning';
                $ret['message'] = __('messages.update.warning');
            }
        }
        if ($type == 'account_setting') {
            $notify_admin = $newsletter = $unusual = 0;
            $save_activity = $mail_pwd = 'FALSE';
            $user = User::FindOrFail(Auth::id());

            if (isset($request['save_activity'])) {
                $save_activity = 'TRUE';
            }
            if (isset($request['mail_pwd'])) {
                $mail_pwd = 'TRUE';
            }

            $mail_pwd = 'TRUE'; //by default true
            if (isset($request['notify_admin'])) {
                $notify_admin = 1;
            }
            if (isset($request['newsletter'])) {
                $newsletter = 1;
            }
            if (isset($request['unusual'])) {
                $unusual = 1;
            }


            if ($user) {
                $userMeta = UserMeta::where('userId', $user->id)->first();
                if ($userMeta == null) {
                    $userMeta = new UserMeta();
                    $userMeta->userId = $user->id;
                }

                $userMeta->notify_admin = $notify_admin;
                $userMeta->newsletter = $newsletter;
                $userMeta->unusual = $unusual;

                $userMeta->pwd_chng = $mail_pwd;
                $userMeta->save_activity = $save_activity;

                $userMeta->save();
                $ret['msg'] = 'success';
                $ret['message'] = __('messages.update.success', ['what' => 'Account Settings']);
            } else {
                $ret['msg'] = 'warning';
                $ret['message'] = __('messages.update.warning');
            }
        }
        if ($type == 'pwd_change') {
            //validate data
            $validator = Validator::make($request->all(), [
                'old-password' => 'required|min:6',
                'new-password' => 'required|min:6',
                're-password' => 'required|min:6|same:new-password',
            ]);
            if ($validator->fails()) {
                $msg = __('messages.form.wrong');
                if ($validator->errors()->hasAny(['old-password', 'new-password', 're-password'])) {
                    $msg = $validator->errors()->first();
                }

                $ret['msg'] = 'warning';
                $ret['message'] = $msg;
                return response()->json($ret);
            } else {
                $user = Auth::user();
                if ($user) {
                    if (! Hash::check($request->input('old-password'), $user->password)) {
                        $ret['msg'] = 'warning';
                        $ret['message'] = __('messages.password.old_err');
                    } else {
                        $userMeta = UserMeta::where('userId', $user->id)->first();
                        $userMeta->pwd_temp = Hash::make($request->input('new-password'));
                        $cd = \Carbon\Carbon::now();
                        $userMeta->email_expire = $cd->copy()->addMinutes(60);
                        $userMeta->email_token = str_random(65);
                        if ($userMeta->save()) {
                            try {
                                $user->notify(new PasswordChange($user, $userMeta));
                                $ret['msg'] = 'success';
                                $ret['message'] = __('messages.password.changed');
                            } catch (\Exception $e) {
                                $ret['msg'] = 'warning';
                                $ret['message'] = __('messages.email.password_change',['email' => get_setting('site_email')]);
                            }
                        } else {
                            $ret['msg'] = 'warning';
                            $ret['message'] = __('messages.form.wrong');
                        }
                    }
                } else {
                    $ret['msg'] = 'warning';
                    $ret['message'] = __('messages.form.wrong');
                }
            }
        }
        if($type == 'google2fa_setup'){
            $google2fa = $request->input('google2fa', 0);
            $user = User::FindOrFail(Auth::id());
            if($user){
                // Google 2FA
                $ret['link'] = route('user.account');
                if(!empty($request->google2fa_code)){
                    $g2fa = new Google2FA();
                    if($google2fa == 1){
                        $verify = $g2fa->verifyKey($request->google2fa_secret, $request->google2fa_code);
                    }else{
                        $verify = $g2fa->verify($request->google2fa_code, $user->google2fa_secret);
                    }

                    if($verify){
                        $user->google2fa = $google2fa;
                        $user->google2fa_secret = ($google2fa == 1 ? $request->google2fa_secret : null);
                        $user->save();
                        $ret['msg'] = 'success';
                        $ret['message'] = __('Successfully '.($google2fa == 1 ? 'enable' : 'disable').' 2FA security in your account.');
                    }else{
                        $ret['msg'] = 'error';
                        $ret['message'] = __('You have provide a invalid 2FA authentication code!');
                    }
                }else{
                    $ret['msg'] = 'warning';
                    $ret['message'] = __('Please enter a valid authentication code!');
                }
            }
        }

        if ($request->ajax()) {
            return response()->json($ret);
        }
        return back()->with([$ret['msg'] => $ret['message']]);
    }

}
