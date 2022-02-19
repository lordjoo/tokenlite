<?php

namespace App\Http\Controllers\Api\User;
/**
 * Transaction Controller
 *
 *
 * @package TokenLite
 * @author Softnio
 * @version 1.0.0
 */

use App\Helpers\ApiResponse;
use App\Helpers\IcoHandler;
use App\Models\IcoStage;
use App\PayModule\Module;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Notifications\TnxStatus;
use App\Http\Controllers\Controller;

class TransactionController extends Controller
{
    protected $response, $handler;

    public function __construct(ApiResponse $response, IcoHandler $handler)
    {
        $this->middleware('auth:api');
        $this->response = $response;
        $this->handler = $handler;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     * @version 1.0.0
     * @since 1.0
     */
    public function index()
    {
        Transaction::where(['user' => request()->user()->id, 'status' => 'new'])->delete();
        $trnxs = Transaction::where('user', request()->user()->id)
                    ->where('status', '!=', 'deleted')
                    ->where('status', '!=', 'new')
                    ->whereNotIn('tnx_type', ['withdraw'])
                    ->orderBy('created_at', 'DESC')->get();
        $transfers = $trnxs->where(['tnx_type' => 'transfer'])->count();
        $referrals = $trnxs->where(['tnx_type' => 'referral'])->count();
        $bonuses   = $trnxs->where(['tnx_type' => 'bonus'])->count();
        $refunds   = $trnxs->where(['tnx_type' => 'refund'])->count();
        $has_trnxs = (object) [
            'transfer' => ($transfers > 0) ? true : false,
            'referral' => ($referrals > 0) ? true : false,
            'bonus' => ($bonuses > 0) ? true : false,
            'refund' => ($refunds > 0) ? true : false
        ];

        return $this->response
            ->success()
            ->setData([
                'transactions' => $trnxs,
                'has_trnxs' => $has_trnxs
            ])
            ->return();
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     * @version 1.0.0
     * @since 1.0
     * @return void
     *
     * @throws \Throwable
     */
    public function show(Request $request, $id='')
    {
        $module = new Module();
        $tid = ($id == '' ? $request->input('tnx_id') : $id);
        if ($tid != null) {
            $tnx = Transaction::find($tid);
            return $module->show_details($tnx);
        } else {
            return false;
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\Response
     * @version 1.0.0
     * @since 1.0
     */
    public function destroy(Request $request, $id='')
    {
        $tid = ($id == '' ? $request->input('tnx_id') : $id);
        if ($tid != null) {
            $tnx = Transaction::FindOrFail($tid);
            if ($tnx) {
                $old = $tnx->status;
                $tnx->status = 'deleted';
                $tnx->save();
                if ($old == 'pending' || $old == 'onhold') {
                    IcoStage::token_add_to_account($tnx, 'sub');
                }
                $ret['msg'] = 'error';
                $ret['message'] = __('messages.delete.delete', ['what'=>'Transaction']);
            } else {
                $ret['msg'] = 'warning';
                $ret['message'] = 'This transaction is not available now!';
            }
        } else {
            $ret['msg'] = 'warning';
            $ret['message'] = __('messages.delete.failed', ['what'=>'Transaction']);
        }

        if ($request->ajax() || $request->acceptsJson()) {
            return response()->json($ret);
        }
        return back()->with([$ret['msg'] => $ret['message']]);
    }
}
