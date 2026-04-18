<?php
namespace app\service;

use app\common\AppConfig;
use app\logging\Logger;
use think\facade\Db;

/**
 * OrderService - Order lifecycle management.
 * State machine: Draft -> Confirmed -> Assigned -> InProgress -> Completed
 * Cancelled is terminal from pre-completion states.
 */
class OrderService
{
    // Valid state transitions
    private const TRANSITIONS = [
        'draft'       => ['confirmed', 'cancelled'],
        'confirmed'   => ['assigned', 'cancelled'],
        'assigned'    => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed'   => [],
        'cancelled'   => [],
    ];

    public static function createOrder(array $data, array $userContext): array
    {
        $orderNo = 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $items = $data['items'] ?? [];
        $subtotal = self::calculateSubtotal($items);
        $discountAmount = 0.00;
        $taxRate = AppConfig::get('default_tax_rate', 0.08);
        $taxAmount = self::roundHalfUp(($subtotal - $discountAmount) * $taxRate, 2);
        $totalAmount = self::roundHalfUp($subtotal - $discountAmount + $taxAmount, 2);
        $amountDue = max($totalAmount, 0.00);

        // Handle invoice fields encryption
        $invoiceTaxpayerIdEnc = null;
        $invoiceIdentifierEnc = null;
        if (!empty($data['invoice_requested'])) {
            if (!empty($data['invoice_taxpayer_id'])) {
                $invoiceTaxpayerIdEnc = EncryptionService::encrypt($data['invoice_taxpayer_id']);
            }
            if (!empty($data['invoice_identifier'])) {
                $invoiceIdentifierEnc = EncryptionService::encrypt($data['invoice_identifier']);
            }
        }

        // Encrypt customer phone
        $customerPhoneEnc = null;
        if (!empty($data['customer_phone'])) {
            $customerPhoneEnc = EncryptionService::encrypt($data['customer_phone']);
        }

        Db::startTrans();
        try {
            $orderId = Db::table('orders')->insertGetId([
                'order_no'               => $orderNo,
                'store_id'               => $userContext['store_id'],
                'workstation_id'         => $userContext['workstation_id'],
                'channel'                => $data['channel'] ?? 'front_desk',
                'status'                 => 'draft',
                'customer_name'          => $data['customer_name'],
                'customer_phone_enc'     => $customerPhoneEnc,
                'created_by'             => $userContext['user_id'],
                'subtotal_amount'        => $subtotal,
                'discount_amount'        => $discountAmount,
                'tax_amount'             => $taxAmount,
                'total_amount'           => $totalAmount,
                'amount_due'             => $amountDue,
                'invoice_requested'      => !empty($data['invoice_requested']) ? 1 : 0,
                'invoice_taxpayer_id_enc' => $invoiceTaxpayerIdEnc,
                'invoice_entity_name'    => $data['invoice_entity_name'] ?? null,
                'invoice_identifier_enc' => $invoiceIdentifierEnc,
                'created_at'             => date('Y-m-d H:i:s'),
                'updated_at'             => date('Y-m-d H:i:s'),
            ]);

            // Insert order items
            foreach ($items as $item) {
                $lineSubtotal = self::roundHalfUp($item['qty'] * $item['unit_price'], 2);
                Db::table('order_items')->insert([
                    'order_id'     => $orderId,
                    'service_code' => $item['service_code'],
                    'service_name' => $item['service_name'],
                    'qty'          => $item['qty'],
                    'unit_price'   => $item['unit_price'],
                    'line_subtotal' => $lineSubtotal,
                ]);
            }

            // Status history
            Db::table('order_status_history')->insert([
                'order_id'   => $orderId,
                'from_status' => null,
                'to_status'  => 'draft',
                'changed_by' => $userContext['user_id'],
                'changed_at' => date('Y-m-d H:i:s'),
                'note'       => 'Order created',
            ]);

            Db::commit();

            Logger::info('order', 'create', "Order created: {$orderNo}", [
                'order_id' => $orderId,
                'store_id' => $userContext['store_id'],
            ]);

            $order = Db::table('orders')->where('id', $orderId)->find();
            return ['success' => true, 'data' => $order];
        } catch (\Throwable $e) {
            Db::rollback();
            Logger::error('order', 'create', 'Order creation failed: ' . $e->getMessage());
            return ['success' => false, 'error_code' => 'CREATE_FAILED', 'message' => 'Failed to create order'];
        }
    }

    public static function getOrder(int $orderId, array $userContext): ?array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return null;
        }

        // Object-level scope check: must be same store unless admin
        if (!in_array('administrator', $userContext['roles'])) {
            if ($order['store_id'] != $userContext['store_id']) {
                return null;
            }
        }

        // Technician can only see assigned orders
        if (in_array('technician', $userContext['roles']) && !in_array('administrator', $userContext['roles'])) {
            if ($order['assigned_technician_id'] != $userContext['user_id']) {
                return null;
            }
        }

        // Decrypt sensitive fields for display
        if ($order['customer_phone_enc']) {
            $order['customer_phone'] = EncryptionService::decrypt($order['customer_phone_enc']);
        }

        $order['items'] = Db::table('order_items')->where('order_id', $orderId)->select()->toArray();
        $order['status_history'] = Db::table('order_status_history')
            ->where('order_id', $orderId)
            ->order('changed_at', 'asc')
            ->select()
            ->toArray();

        return $order;
    }

    public static function listOrders(array $filters, array $userContext, int $page = 1, int $pageSize = 20): array
    {
        $query = Db::table('orders');

        // Store-scoped filtering unless administrator
        if (!in_array('administrator', $userContext['roles'])) {
            $query->where('store_id', $userContext['store_id']);
        }

        // Technician sees only assigned orders
        if (in_array('technician', $userContext['roles']) && !in_array('administrator', $userContext['roles'])) {
            $query->where('assigned_technician_id', $userContext['user_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['order_no'])) {
            $query->where('order_no', 'like', '%' . $filters['order_no'] . '%');
        }
        if (!empty($filters['from'])) {
            $from = self::parseMMDDYYYY($filters['from']);
            if ($from) {
                $query->where('created_at', '>=', $from . ' 00:00:00');
            }
        }
        if (!empty($filters['to'])) {
            $to = self::parseMMDDYYYY($filters['to']);
            if ($to) {
                $query->where('created_at', '<=', $to . ' 23:59:59');
            }
        }

        $total = (clone $query)->count();
        $items = $query->order('created_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // Log search
        if (!empty($filters['order_no'])) {
            Db::table('search_logs')->insert([
                'user_id'        => $userContext['user_id'],
                'role_code'      => $userContext['roles'][0] ?? 'unknown',
                'store_id'       => $userContext['store_id'],
                'workstation_id' => $userContext['workstation_id'],
                'query_text'     => $filters['order_no'],
                'target_domain'  => 'order',
                'result_count'   => $total,
            ]);
        }

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
        ];
    }

    public static function updateOrder(int $orderId, array $data, array $userContext): array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Order not found', 'status' => 404];
        }

        // Object scope check
        if (!in_array('administrator', $userContext['roles']) && $order['store_id'] != $userContext['store_id']) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Access denied', 'status' => 403];
        }

        // Technician cannot alter pricing
        if (in_array('technician', $userContext['roles'])) {
            unset($data['subtotal_amount'], $data['discount_amount'], $data['tax_amount'], $data['total_amount'], $data['amount_due']);
            unset($data['items']);
        }

        $before = $order;
        $updateData = [];

        $allowedFields = ['customer_name', 'complaint_flag', 'complaint_reason_code'];
        if (!in_array('technician', $userContext['roles'])) {
            $allowedFields = array_merge($allowedFields, ['invoice_requested', 'invoice_entity_name']);
        }

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        // Handle encrypted fields
        if (isset($data['customer_phone'])) {
            $updateData['customer_phone_enc'] = EncryptionService::encrypt($data['customer_phone']);
        }
        if (isset($data['invoice_taxpayer_id'])) {
            $updateData['invoice_taxpayer_id_enc'] = EncryptionService::encrypt($data['invoice_taxpayer_id']);
        }
        if (isset($data['invoice_identifier'])) {
            $updateData['invoice_identifier_enc'] = EncryptionService::encrypt($data['invoice_identifier']);
        }

        // Recalculate pricing if items updated
        if (isset($data['items']) && !in_array('technician', $userContext['roles'])) {
            Db::table('order_items')->where('order_id', $orderId)->delete();
            $subtotal = self::calculateSubtotal($data['items']);
            foreach ($data['items'] as $item) {
                $lineSubtotal = self::roundHalfUp($item['qty'] * $item['unit_price'], 2);
                Db::table('order_items')->insert([
                    'order_id'     => $orderId,
                    'service_code' => $item['service_code'],
                    'service_name' => $item['service_name'],
                    'qty'          => $item['qty'],
                    'unit_price'   => $item['unit_price'],
                    'line_subtotal' => $lineSubtotal,
                ]);
            }
            $discountAmount = $order['discount_amount'];
            $taxRate = AppConfig::get('default_tax_rate', 0.08);
            $taxAmount = self::roundHalfUp(($subtotal - $discountAmount) * $taxRate, 2);
            $totalAmount = self::roundHalfUp($subtotal - $discountAmount + $taxAmount, 2);
            $paidAmount = self::getPaidAmount($orderId);
            $amountDue = max($totalAmount - $paidAmount, 0.00);

            $updateData['subtotal_amount'] = $subtotal;
            $updateData['tax_amount'] = $taxAmount;
            $updateData['total_amount'] = $totalAmount;
            $updateData['amount_due'] = $amountDue;
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            Db::table('orders')->where('id', $orderId)->update($updateData);
        }

        $after = Db::table('orders')->where('id', $orderId)->find();

        return ['success' => true, 'data' => $after, 'before' => $before];
    }

    public static function transitionStatus(int $orderId, string $targetStatus, array $userContext, ?string $note = null): array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Order not found', 'status' => 404];
        }

        // Object scope check
        if (!in_array('administrator', $userContext['roles']) && $order['store_id'] != $userContext['store_id']) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Access denied', 'status' => 403];
        }

        $currentStatus = $order['status'];
        $allowedTransitions = self::TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($targetStatus, $allowedTransitions)) {
            return [
                'success' => false,
                'error_code' => 'INVALID_TRANSITION',
                'message' => "Cannot transition from '{$currentStatus}' to '{$targetStatus}'",
                'status' => 409,
            ];
        }

        $updateData = ['status' => $targetStatus, 'updated_at' => date('Y-m-d H:i:s')];

        if ($targetStatus === 'confirmed') {
            $updateData['confirmed_at'] = date('Y-m-d H:i:s');
            $updateData['receipt_no'] = 'RCP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
        }
        if ($targetStatus === 'completed') {
            $updateData['completed_at'] = date('Y-m-d H:i:s');
        }

        Db::startTrans();
        try {
            Db::table('orders')->where('id', $orderId)->update($updateData);

            Db::table('order_status_history')->insert([
                'order_id'    => $orderId,
                'from_status' => $currentStatus,
                'to_status'   => $targetStatus,
                'changed_by'  => $userContext['user_id'],
                'changed_at'  => date('Y-m-d H:i:s'),
                'note'        => $note,
            ]);

            Db::commit();

            $updated = Db::table('orders')->where('id', $orderId)->find();
            return ['success' => true, 'data' => $updated, 'before' => $order];
        } catch (\Throwable $e) {
            Db::rollback();
            Logger::error('order', 'transition', 'Status transition failed: ' . $e->getMessage());
            return ['success' => false, 'error_code' => 'TRANSITION_FAILED', 'message' => 'Failed to update order status'];
        }
    }

    public static function cancelOrder(int $orderId, string $reason, array $userContext): array
    {
        if (empty(trim($reason))) {
            return ['success' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'Cancellation reason is required', 'status' => 400];
        }

        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Order not found', 'status' => 404];
        }

        // Object scope check: order must belong to caller's store (admins exempt)
        if (!in_array('administrator', $userContext['roles']) && $order['store_id'] != $userContext['store_id']) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Access denied', 'status' => 403];
        }

        // Only Front Desk and Store Manager (and Admin) can cancel
        $canCancel = array_intersect($userContext['roles'], ['front_desk', 'store_manager', 'administrator']);
        if (empty($canCancel)) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'You do not have permission to cancel orders', 'status' => 403];
        }

        $currentStatus = $order['status'];
        $allowedTransitions = self::TRANSITIONS[$currentStatus] ?? [];
        if (!in_array('cancelled', $allowedTransitions)) {
            return [
                'success' => false,
                'error_code' => 'INVALID_TRANSITION',
                'message' => "Cannot cancel order in '{$currentStatus}' status",
                'status' => 409,
            ];
        }

        Db::startTrans();
        try {
            Db::table('orders')->where('id', $orderId)->update([
                'status' => 'cancelled',
                'cancelled_at' => date('Y-m-d H:i:s'),
                'cancellation_reason' => $reason,
                'cancellation_by' => $userContext['user_id'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            Db::table('order_status_history')->insert([
                'order_id'    => $orderId,
                'from_status' => $currentStatus,
                'to_status'   => 'cancelled',
                'changed_by'  => $userContext['user_id'],
                'changed_at'  => date('Y-m-d H:i:s'),
                'note'        => 'Cancelled: ' . $reason,
            ]);

            Db::commit();

            $updated = Db::table('orders')->where('id', $orderId)->find();
            return ['success' => true, 'data' => $updated, 'before' => $order];
        } catch (\Throwable $e) {
            Db::rollback();
            Logger::error('order', 'cancel', 'Cancellation failed: ' . $e->getMessage());
            return ['success' => false, 'error_code' => 'CANCEL_FAILED', 'message' => 'Failed to cancel order'];
        }
    }

    public static function assignTechnician(int $orderId, int $technicianId, array $userContext): array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Order not found', 'status' => 404];
        }

        // Object scope check: order must belong to caller's store (admins exempt)
        if (!in_array('administrator', $userContext['roles']) && $order['store_id'] != $userContext['store_id']) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Access denied', 'status' => 403];
        }

        // Verify technician belongs to the same store as the order
        $techInStore = Db::table('user_store_workstation_bindings')
            ->where('user_id', $technicianId)
            ->where('store_id', $order['store_id'])
            ->where('active', 1)
            ->count();

        if ($techInStore === 0) {
            return ['success' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'Technician does not belong to this store', 'status' => 400];
        }

        // Verify technician role
        $techRoles = Db::table('user_roles')
            ->alias('ur')
            ->join('roles r', 'ur.role_id = r.id')
            ->where('ur.user_id', $technicianId)
            ->where('r.code', 'technician')
            ->count();

        if ($techRoles === 0) {
            return ['success' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'Selected user is not a technician', 'status' => 400];
        }

        $before = $order;

        Db::startTrans();
        try {
            // Unassign current technician if any
            if ($order['assigned_technician_id']) {
                Db::table('order_assignments')
                    ->where('order_id', $orderId)
                    ->where('technician_id', $order['assigned_technician_id'])
                    ->whereNull('unassigned_at')
                    ->update(['unassigned_at' => date('Y-m-d H:i:s')]);
            }

            // Assign new technician
            Db::table('order_assignments')->insert([
                'order_id'      => $orderId,
                'technician_id' => $technicianId,
                'assigned_by'   => $userContext['user_id'],
                'assigned_at'   => date('Y-m-d H:i:s'),
            ]);

            $updateData = [
                'assigned_technician_id' => $technicianId,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            // Auto-transition to assigned if currently confirmed
            if ($order['status'] === 'confirmed') {
                $updateData['status'] = 'assigned';
                Db::table('order_status_history')->insert([
                    'order_id'    => $orderId,
                    'from_status' => 'confirmed',
                    'to_status'   => 'assigned',
                    'changed_by'  => $userContext['user_id'],
                    'changed_at'  => date('Y-m-d H:i:s'),
                    'note'        => 'Technician assigned',
                ]);
            }

            Db::table('orders')->where('id', $orderId)->update($updateData);

            Db::commit();

            $updated = Db::table('orders')->where('id', $orderId)->find();
            return ['success' => true, 'data' => $updated, 'before' => $before];
        } catch (\Throwable $e) {
            Db::rollback();
            Logger::error('order', 'assign', 'Technician assignment failed: ' . $e->getMessage());
            return ['success' => false, 'error_code' => 'ASSIGN_FAILED', 'message' => 'Failed to assign technician'];
        }
    }

    public static function acceptOrder(int $orderId, array $userContext): array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Order not found', 'status' => 404];
        }

        if ($order['assigned_technician_id'] != $userContext['user_id']) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'You are not assigned to this order', 'status' => 403];
        }

        return self::transitionStatus($orderId, 'in_progress', $userContext, 'Technician accepted job');
    }

    public static function addWorkNote(int $orderId, string $noteText, array $userContext): array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Order not found', 'status' => 404];
        }

        if ($order['assigned_technician_id'] != $userContext['user_id'] && !in_array('administrator', $userContext['roles'])) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Only assigned technician can add work notes', 'status' => 403];
        }

        $noteId = Db::table('order_work_notes')->insertGetId([
            'order_id'      => $orderId,
            'technician_id' => $userContext['user_id'],
            'note'          => $noteText,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'data' => ['id' => $noteId]];
    }

    public static function getReceipt(int $orderId, array $userContext): ?array
    {
        $order = self::getOrder($orderId, $userContext);
        if (!$order || !$order['receipt_no']) {
            return null;
        }

        return [
            'receipt_no'      => $order['receipt_no'],
            'order_no'        => $order['order_no'],
            'store_id'        => $order['store_id'],
            'customer_name'   => $order['customer_name'],
            'items'           => $order['items'],
            'subtotal'        => number_format($order['subtotal_amount'], 2),
            'discount'        => number_format($order['discount_amount'], 2),
            'tax'             => number_format($order['tax_amount'], 2),
            'total'           => number_format($order['total_amount'], 2),
            'amount_due'      => number_format($order['amount_due'], 2),
            'currency'        => 'USD',
            'confirmed_at'    => $order['confirmed_at'],
            'invoice_requested' => $order['invoice_requested'],
        ];
    }

    public static function calculateSubtotal(array $items): float
    {
        $subtotal = 0.00;
        foreach ($items as $item) {
            $subtotal += self::roundHalfUp($item['qty'] * $item['unit_price'], 2);
        }
        return self::roundHalfUp($subtotal, 2);
    }

    public static function roundHalfUp(float $value, int $precision): float
    {
        // The previous `ceil($v * $m - 0.5) / $m` trick was not true half-up
        // rounding: on exact half values it landed BELOW the intended result
        // (e.g. 10.535 → 10.53 instead of 10.54) because `ceil(X - 0.5)`
        // only rounds up when the fractional part is strictly greater than
        // 0.5. PHP's round() with PHP_ROUND_HALF_UP handles the floating
        // point precision dance internally and is the canonical HALF_UP
        // implementation.
        return round($value, $precision, PHP_ROUND_HALF_UP);
    }

    public static function getPaidAmount(int $orderId): float
    {
        $paid = Db::table('payments')->where('order_id', $orderId)->sum('amount');
        $refunded = Db::table('refunds')
            ->where('order_id', $orderId)
            ->where('status', 'processed')
            ->sum('amount');
        return round($paid - $refunded, 2);
    }

    public static function parseMMDDYYYY(string $date): ?string
    {
        $parts = explode('/', $date);
        if (count($parts) !== 3) {
            return null;
        }
        $m = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $d = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $y = $parts[2];
        if (!checkdate((int)$m, (int)$d, (int)$y)) {
            return null;
        }
        return "{$y}-{$m}-{$d}";
    }
}
