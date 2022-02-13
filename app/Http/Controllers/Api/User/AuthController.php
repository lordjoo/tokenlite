<?php

namespace App\Http\Controllers\Api\User;

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
            'password' => 'required|string|confirmed|min:6',
        ]);

        if($validator->fails()) {
            return response()->json($validator->errors(), 400);
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
            $type='user';
            $meta->notify_admin = ($type=='user')?0:1;
            $meta->email_token = str_random(65);
            $cd = Carbon::now(); //->toDateTimeString();
            $meta->email_expire = $cd->copy()->addDays(3);
            $meta->save();
            return response()->json([
                'message' => 'User successfully registered',
                'user' => $user
            ], 201);
        } else {
            return response()->json(['message' => 'User could not be created'], 500);
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
            return response()->json($validator->errors(), 400);
        }

        $credentials = request(['email', 'password']);

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
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
        return response()->json(auth('api')->user());
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json(['message' => 'Successfully logged out']);
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
     *
     * @param  string $token
     *
     * @return JsonResponse
     */
    protected function respondWithToken($token): JsonResponse
    {
        return response()->json([
            'user' => auth('api')->user(),
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60
        ]);
    }

    /* reset password function */
    public function reset()
    {
        $validator = Validator::make(request()->all(), [
            'email' => 'required|string|email',
        ]);
        if($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }
        # check if user exists
        $user = User::where('email', request()->email)->first();
        if (!$user) {
            return response()->json(['message' => 'User does not exist'], 404);
        }
        $response = $this->broker()->sendResetLink([request()->email,]);
        return $response == Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Reset link sent'], 200)
            : response()->json(['message' => 'Reset link could not be sent',$response], 500);
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
