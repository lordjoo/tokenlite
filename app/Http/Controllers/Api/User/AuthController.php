<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
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

class AuthController extends Controller
{

    protected $response, $handler,$module;
    public function __construct(ApiResponse $response)
    {
        if( $this->hasKey() === false && !app()->runningInConsole()){
            throw new APIException("Provide valid access key", 401);
        }
        $this->middleware('auth:api', ['except' => ['login','register','reset']]);
        $this->response = $response;

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
     * Register user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|string|email|max:100|unique:users',
            // password must be at least 8 characters long and no more than 100 and must contain at least one digit, one lowercase and one uppercase letter
            'password' => 'required|string|confirmed|min:8|max:100|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
        ]);

        if($validator->fails()) {
            return $this->response
                ->setError($validator->errors()->toArray())
                ->setStatusCode(422)
                ->return();
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'lastLogin' => date('Y-m-d H:i:s'),
            'role' => 'user',

        ]);

        if ($user) {
            $user->create_referral_or_not();
            $meta = UserMeta::create([ 'userId' => $user->id ]);
            $type = 'user';
            $meta->notify_admin = ($type !== 'user');
//                ? 0
//                : 1;
            $meta->email_token = str_random(65);
            $cd = Carbon::now(); //->toDateTimeString();
            $meta->email_expire = $cd->copy()->addDays(3);
            $meta->save();

            return $this->response
                ->setMessage('User successfully registered')
                ->withUser($user)
                ->setStatusCode(201)
                ->return();
        } else {
            return $this->response
                ->error('User could not be created')
                ->setStatusCode(500)
                ->return();
        }

    }

    /**
     * Get a JWT via given credentials.
     *
     * @return JsonResponse
     */
    public function login(): JsonResponse
    {
        $validator = Validator::make(request()->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);
        if($validator->fails()) {
            return $this->response
                ->setError($validator->errors()->toArray())
                ->setStatusCode(422)
                ->return();
        }

        $credentials = request(['email', 'password']);

        if (!$token = auth('api')->attempt($credentials)) {
            return $this->response
                ->error('Unauthorized')
                ->setStatusCode(401)
                ->return();
        }

        return $this->respondWithToken($token);
    }


    /**
     * Get the authenticated User.
     *
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
//        return response()->json(auth('api')->user());
        return $this->response
            ->success('User successfully fetched')
            ->withUser(auth('api')->user())
            ->return();
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

//        return response()->json(['message' => 'Successfully logged out']);
        return $this->response
            ->success('Successfully logged out')
            ->return();
    }

    /**
     * Refresh a token.
     *
     * @return JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(auth('api')->refresh());
    }

    /**
     * Get the token array structure.
     * @param  string $token
     * @return JsonResponse
     */
    protected function respondWithToken($token): JsonResponse
    {
        return $this->response->setData([
            'user' => auth('api')->user(),
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60
        ])->success()->return();
    }

    /* reset password function */
    public function reset()
    {
        $validator = Validator::make(request()->all(), [
            'email' => 'required|string|email|exists:users,email',
        ]);
        if($validator->fails()) {
            return $this->response
                ->setError($validator->errors()->toArray())
                ->setStatusCode(422)
                ->return();
        }
        # check if user exists
        $user = User::where('email', request()->email)->first();

        if (!$user) {
            return $this->response
                ->error('User does not exist')
                ->setStatusCode(404)
                ->return();
        }

        $response = $this->broker()->sendResetLink(
            request()->only('email')
        );


        return $response == Password::RESET_LINK_SENT
            ? $this->response->success(__('Reset link sent'))->return() //response()->json(['message' => 'Reset link sent'])
            : $this->response->error(__('Reset link could not be sent'))->setStatusCode(500)->return();
    }

    /**
     * Get the broker to be used during password reset.
     *
     * @return \Illuminate\Contracts\Auth\PasswordBroker
     */
    public function broker()
    {
        return Password::broker();
    }

    /**
     * Get the guard to be used during password reset.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard();
    }
}
