<?php
namespace app\controller;

use app\common\ResponseHelper;
use think\facade\Db;
use think\Request;

/**
 * EventController - CRUD for events table plus event tracking/logging endpoint.
 */
class EventController
{
    /**
     * POST /events
     */
    public function create(Request $request)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['event_key']) || empty($data['name'])) {
            $resp = ResponseHelper::validationError('event_key and name are required');
            return json($resp['data'], $resp['code']);
        }

        $id = Db::table('events')->insertGetId([
            'event_key'   => $data['event_key'],
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'category'    => $data['category'] ?? null,
            'active'      => $data['active'] ?? 1,
            'created_by'  => $userContext['user_id'],
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $event = Db::table('events')->where('id', $id)->find();

        $request->auditData = [
            'action'      => 'event.create',
            'entity_type' => 'event',
            'entity_id'   => $id,
            'before'      => null,
            'after'       => $event,
        ];

        $resp = ResponseHelper::success($event, 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /events
     */
    public function list(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));
        $category = $request->get('category');

        $query = Db::table('events');
        if ($category) {
            $query->where('category', $category);
        }

        $total = (clone $query)->count();
        $items = $query->order('created_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $resp = ResponseHelper::paginated($items, $total, $page, $pageSize);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /events/{id}
     */
    public function read(Request $request, int $id)
    {
        $event = Db::table('events')->where('id', $id)->find();

        if (!$event) {
            $resp = ResponseHelper::notFound('Event not found');
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($event);
        return json($resp['data'], $resp['code']);
    }

    /**
     * PATCH /events/{id}
     */
    public function update(Request $request, int $id)
    {
        $event = Db::table('events')->where('id', $id)->find();

        if (!$event) {
            $resp = ResponseHelper::notFound('Event not found');
            return json($resp['data'], $resp['code']);
        }

        $before = $event;
        $data = $request->put();
        $updateData = [];

        $allowedFields = ['name', 'description', 'category', 'active'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            Db::table('events')->where('id', $id)->update($updateData);
        }

        $after = Db::table('events')->where('id', $id)->find();

        $request->auditData = [
            'action'      => 'event.update',
            'entity_type' => 'event',
            'entity_id'   => $id,
            'before'      => $before,
            'after'       => $after,
        ];

        $resp = ResponseHelper::success($after);
        return json($resp['data'], $resp['code']);
    }

    /**
     * DELETE /events/{id}
     */
    public function delete(Request $request, int $id)
    {
        $event = Db::table('events')->where('id', $id)->find();

        if (!$event) {
            $resp = ResponseHelper::notFound('Event not found');
            return json($resp['data'], $resp['code']);
        }

        Db::table('events')->where('id', $id)->delete();

        $request->auditData = [
            'action'      => 'event.delete',
            'entity_type' => 'event',
            'entity_id'   => $id,
            'before'      => $event,
            'after'       => null,
        ];

        $resp = ResponseHelper::success(['message' => 'Event deleted']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /events/track
     */
    public function track(Request $request)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['event_key'])) {
            $resp = ResponseHelper::validationError('event_key is required');
            return json($resp['data'], $resp['code']);
        }

        // Verify event exists and is active
        $event = Db::table('events')->where('event_key', $data['event_key'])->find();
        if (!$event) {
            $resp = ResponseHelper::notFound('Event definition not found');
            return json($resp['data'], $resp['code']);
        }

        if (!$event['active']) {
            $resp = ResponseHelper::error('EVENT_INACTIVE', 'Event is not active', 400);
            return json($resp['data'], $resp['code']);
        }

        $logId = Db::table('event_logs')->insertGetId([
            'event_id'        => $event['id'],
            'event_key'       => $data['event_key'],
            'user_id'         => $userContext['user_id'],
            'role_code'       => $userContext['roles'][0] ?? 'unknown',
            'store_id'        => $userContext['store_id'],
            'workstation_id'  => $userContext['workstation_id'],
            'properties_json' => isset($data['properties']) ? json_encode($data['properties']) : null,
            'session_key'     => $data['session_key'] ?? ($userContext['session_id'] ?? null),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $request->auditData = [
            'action'      => 'event.track',
            'entity_type' => 'event_log',
            'entity_id'   => $logId,
            'before'      => null,
            'after'       => ['event_key' => $data['event_key'], 'event_log_id' => $logId],
        ];

        $resp = ResponseHelper::success(['event_log_id' => $logId], 201);
        return json($resp['data'], $resp['code']);
    }
}
