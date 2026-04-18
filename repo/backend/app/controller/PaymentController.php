<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\PaymentService;
use think\Request;

/**
 * PaymentController - Payment recording and refund processing for orders.
 */
class PaymentController
{
    /**
     * POST /orders/{id}/payments
     */
    public function recordPayment(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['tender_type']) || !isset($data['amount'])) {
            $resp = ResponseHelper::validationError('tender_type and amount are required');
            return json($resp['data'], $resp['code']);
        }

        $data['amount'] = (float) $data['amount'];

        $result = PaymentService::recordPayment($id, $data, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'payment.record',
            'entity_type' => 'payment',
            'entity_id'   => $result['data']['payment_id'],
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /orders/{id}/refunds
     */
    public function processRefund(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['original_payment_id']) || !isset($data['amount']) || empty($data['reason'])) {
            $resp = ResponseHelper::validationError('original_payment_id, amount, and reason are required');
            return json($resp['data'], $resp['code']);
        }

        $data['amount'] = (float) $data['amount'];
        $data['original_payment_id'] = (int) $data['original_payment_id'];

        $result = PaymentService::processRefund($id, $data, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'payment.refund',
            'entity_type' => 'refund',
            'entity_id'   => $result['data']['refund_id'],
            'before'      => null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }
}
