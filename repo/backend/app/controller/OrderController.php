<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\OrderService;
use app\service\CouponService;
use think\Request;

/**
 * OrderController - Full order lifecycle: CRUD, status transitions, technician assignment,
 * work notes, receipts, coupon application and validation.
 */
class OrderController
{
    /**
     * POST /orders
     */
    public function create(Request $request)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['customer_name']) || empty($data['items'])) {
            $resp = ResponseHelper::validationError('customer_name and items are required');
            return json($resp['data'], $resp['code']);
        }

        $result = OrderService::createOrder($data, $userContext);

        if (!$result['success']) {
            $resp = ResponseHelper::error($result['error_code'], $result['message'], 500);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.create',
            'entity_type' => 'order',
            'entity_id'   => $result['data']['id'],
            'before'      => null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /orders
     */
    public function list(Request $request)
    {
        $userContext = $request->userContext;
        $filters = [
            'status'   => $request->get('status', ''),
            'order_no' => $request->get('order_no', ''),
            'from'     => $request->get('from', ''),
            'to'       => $request->get('to', ''),
        ];
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));

        $result = OrderService::listOrders($filters, $userContext, $page, $pageSize);

        $resp = ResponseHelper::paginated($result['items'], $result['total'], $result['page'], $result['page_size']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /orders/{id}
     */
    public function read(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $order = OrderService::getOrder($id, $userContext);

        if (!$order) {
            $resp = ResponseHelper::notFound('Order not found');
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($order);
        return json($resp['data'], $resp['code']);
    }

    /**
     * PATCH /orders/{id}
     */
    public function update(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $data = $request->put();

        $result = OrderService::updateOrder($id, $data, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.update',
            'entity_type' => 'order',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /orders/{id}/confirm
     */
    public function confirm(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $result = OrderService::transitionStatus($id, 'confirmed', $userContext, 'Order confirmed');

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.confirm',
            'entity_type' => 'order',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /orders/{id}/assign-technician
     */
    public function assignTechnician(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $technicianId = (int) $request->post('technician_id', 0);

        if ($technicianId <= 0) {
            $resp = ResponseHelper::validationError('technician_id is required');
            return json($resp['data'], $resp['code']);
        }

        $result = OrderService::assignTechnician($id, $technicianId, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.assign_technician',
            'entity_type' => 'order',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /orders/{id}/accept
     */
    public function accept(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $result = OrderService::acceptOrder($id, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.accept',
            'entity_type' => 'order',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /orders/{id}/work-notes
     */
    public function addWorkNote(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $noteText = $request->post('note', '');

        if (empty(trim($noteText))) {
            $resp = ResponseHelper::validationError('note is required');
            return json($resp['data'], $resp['code']);
        }

        $result = OrderService::addWorkNote($id, $noteText, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.add_work_note',
            'entity_type' => 'order_work_note',
            'entity_id'   => $result['data']['id'],
            'before'      => null,
            'after'       => ['order_id' => $id, 'note' => $noteText],
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /orders/{id}/complete
     */
    public function complete(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $result = OrderService::transitionStatus($id, 'completed', $userContext, 'Order completed');

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.complete',
            'entity_type' => 'order',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /orders/{id}/cancel
     */
    public function cancel(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $reason = $request->post('reason', '');

        if (empty(trim($reason))) {
            $resp = ResponseHelper::validationError('reason is required for cancellation');
            return json($resp['data'], $resp['code']);
        }

        $result = OrderService::cancelOrder($id, $reason, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.cancel',
            'entity_type' => 'order',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /orders/{id}/receipt
     */
    public function receipt(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $receipt = OrderService::getReceipt($id, $userContext);

        if (!$receipt) {
            $resp = ResponseHelper::notFound('Receipt not found or order has not been confirmed');
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($receipt);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /orders/{id}/apply-coupon
     */
    public function applyCoupon(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $code = $request->post('code', '');

        if (empty(trim($code))) {
            $resp = ResponseHelper::validationError('Coupon code is required');
            return json($resp['data'], $resp['code']);
        }

        $result = CouponService::applyCoupon($code, $id, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'order.apply_coupon',
            'entity_type' => 'order',
            'entity_id'   => $id,
            'before'      => null,
            'after'       => ['coupon_code' => $code, 'discount_amount' => $result['discount_amount']],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /coupons/validate
     */
    public function validateCoupon(Request $request)
    {
        $userContext = $request->userContext;
        $code = $request->get('code', '');
        $orderId = (int) $request->get('order_id', 0);

        if (empty(trim($code)) || $orderId <= 0) {
            $resp = ResponseHelper::validationError('code and order_id are required');
            return json($resp['data'], $resp['code']);
        }

        $result = CouponService::validateCoupon($code, $orderId, $userContext);

        // Ownership failures (FORBIDDEN / NOT_FOUND on a foreign order_id)
        // must surface as the corresponding HTTP status, not wrapped inside
        // a 200 success envelope. Anything else is a legitimate validation
        // outcome and still returns the {valid: false, reason} body at 200.
        if (isset($result['status'])) {
            $resp = ResponseHelper::error(
                $result['error_code'] ?? 'FORBIDDEN',
                $result['reason'] ?? 'Access denied',
                $result['status']
            );
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($result);
        return json($resp['data'], $resp['code']);
    }
}
