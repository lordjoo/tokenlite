<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Helpers\IcoHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
            if ($validator->errors()->hasAny(['name', 'email', 'dateOfBirth'])) {

                return $this->response->error($validator->errors())->return();
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
}
