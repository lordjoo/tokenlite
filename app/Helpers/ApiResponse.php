<?php

namespace App\Helpers;

use App\Models\User;
use Exception;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Str;

/**
 * @method ApiResponse withCards(array $cards)
 * @method ApiResponse withUser(User $user)
 */
class ApiResponse
{

    /**
     * @var ResponseFactory
     */
    private $response;

    /**
     * @var int
     */
    private $status;

    /**
     * @var array
     */
    private $data;

    /**
     * @var string
     */
    private $message;
    /**
     * @var null
     */
    private $error;
    /**
     * @var null
     */
    private $pagination;

    public function __construct(ResponseFactory $response)
    {
        $this->response = $response;
        $this->status = 200;
        $this->data = [];
        $this->error = null;
        $this->pagination = null;
        $this->message = "";
    }

    public function success($message = "")
    {
        $this->message = $message;
        $this->status = 200;
        return $this;
    }

    public function error($message = "",$statusCode = 500): ApiResponse
    {
        $this->message = $message;
        $this->status = $statusCode;
        return $this;
    }

    /**
     * @param $code integer
     */
    public function setStatusCode(int $code): ApiResponse
    {
        $this->status = $code;
        return $this;
    }

    /**
     * @param $data array | object
     */
    public function setData($data): ApiResponse
    {
        $this->data =  $data;
        return $this;
    }

    /**
     * @param $message string
     */
    public function setMessage(string $message): ApiResponse
    {
        $this->message = $message;
        return $this;
    }

    public function setError(array $array): ApiResponse
    {
        $this->error = $array;
        return $this;
    }

    public function setPagination(array $array): ApiResponse
    {
        $this->pagination = $array;
        return $this;
    }


    public function return(): \Illuminate\Http\JsonResponse
    {
        $returnedArray = [
            "status" => $this->status == 200,
            "message"   => $this->message,
            "data"      => $this->data
        ];
        if (is_array($this->error)) {
            $returnedArray["error"] = $this->error;
        }
        return $this->response->json(
            $returnedArray,
            $this->status
        );
    }


    /**
     * @throws Exception
     */
    public function __call($name, $arguments)
    {
       // get all class methods
       $methods = get_class_methods($this);
       // check if method exists
       if (in_array($name, $methods)) {
           // call method with arguments
           return call_user_func_array([$this, $name], $arguments);
       }elseif(Str::startsWith($name, 'with')){
           $this->data[Str::snake(substr($name, 4))] = $arguments[0];
           return $this;
       }else{
           throw new Exception("Method [$name] does not exist");
       }
    }

    public function __get($name)
    {
       dd($name);
    }
}
