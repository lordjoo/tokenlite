<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Helpers\IcoHandler;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserMeta;
use App\Notifications\PasswordChange;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    protected $response, $handler;

    public function __construct(ApiResponse $response, IcoHandler $handler)
    {
        $this->middleware('auth:api');
        $this->response = $response;
        $this->handler = $handler;
    }
    public function update_profile()
    {
        $request = request();
        $countries = $this->handler->getCountries();
        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3',
            'email' => 'required|email',
            'dateOfBirth' => 'required|date_format:"m/d/Y"',
            'nationality' => 'required|in:' . implode(',', $countries),
        ]);

        if ($validator->fails()) {
            if ($validator->fails()) {
                return $this->response
                    ->setError($validator->errors()->toArray())
                    ->error('Invalid request',400)
                    ->return();
            }
        }

        else {
            $user = request()->user();
            $user->name = strip_tags($request->input('name'));
            $user->email = $request->input('email');
            $user->mobile = strip_tags($request->input('mobile'));
            $user->dateOfBirth = $request->input('dateOfBirth');
            $user->nationality = strip_tags($request->input('nationality'));
            $user_saved = $user->save();

            if ($user_saved) {
                return $this->response->success(__('messages.update.success', ['what' => 'Account']))
                ->setData(['user'=>$user])
                ->return();

            }
        }
        return $this->response->error(__('messages.update.warning'))->return();
    }

    public function change_password()
    {
        $request = request();
        //validate data
        $validator = Validator::make($request->all(), [
            'old_password' => 'required|min:6',
            'new_password' => 'required|string|confirmed|min:8|max:100|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
        ]);
        if ($validator->fails()) {
            if ($validator->fails()) {
                return $this->response
                    ->setError($validator->errors()->toArray())
                    ->error('Invalid request',400)
                    ->return();            }
        }
        $user = $request->user();
        if ($user) {
            if (! Hash::check($request->input('old_password'), $user->password)) {
                return $this->response->error(__('messages.password.old_err'))->return();
            } else {
                $userMeta = UserMeta::where('userId', $user->id)->first();
                $userMeta->pwd_temp = Hash::make($request->input('new_password'));
                $cd = Carbon::now();
                $userMeta->email_expire = $cd->copy()->addMinutes(60);
                $userMeta->email_token = str_random(65);
                if ($userMeta->save()) {
                    try {
                        $user->notify(new PasswordChange($user, $userMeta));
                        return $this->response->success(__('messages.password.changed'))->return();
                    } catch (\Exception $e) {
                        return $this->response->error(
                            __('messages.email.password_change', ['email' => get_setting('site_email')])
                        )->return();
                    }
                } else {
                    return $this->response->error(__('messages.form.wrong'))->return();
                }
            }
        } else {
            return $this->response->error(__('messages.form.wrong'))->return();
        }

    }


}
