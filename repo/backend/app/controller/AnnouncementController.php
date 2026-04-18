<?php
namespace app\controller;

use app\common\ResponseHelper;
use think\facade\Db;
use think\Request;

/**
 * AnnouncementController - CRUD for the announcements table.
 */
class AnnouncementController
{
    /**
     * POST /announcements
     */
    public function create(Request $request)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['title']) || empty($data['body'])) {
            $resp = ResponseHelper::validationError('title and body are required');
            return json($resp['data'], $resp['code']);
        }

        // Non-admins are always scoped to their own store; an explicit foreign
        // store_id from a non-admin is rejected rather than silently honored.
        $isAdmin = in_array('administrator', $userContext['roles']);
        if (!$isAdmin && isset($data['store_id']) && (int) $data['store_id'] !== (int) $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Cannot create announcement for a different store');
            return json($resp['data'], $resp['code']);
        }
        $storeId = $isAdmin
            ? (int) ($data['store_id'] ?? $userContext['store_id'])
            : (int) $userContext['store_id'];

        $id = Db::table('announcements')->insertGetId([
            'title'         => $data['title'],
            'body'          => $data['body'],
            'category'      => $data['category'] ?? null,
            'priority'      => $data['priority'] ?? 'normal',
            'store_id'      => $storeId,
            'quality_score' => $data['quality_score'] ?? null,
            'published'     => $data['published'] ?? 0,
            'created_by'    => $userContext['user_id'],
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $announcement = Db::table('announcements')->where('id', $id)->find();

        $request->auditData = [
            'action'      => 'announcement.create',
            'entity_type' => 'announcement',
            'entity_id'   => $id,
            'before'      => null,
            'after'       => $announcement,
        ];

        $resp = ResponseHelper::success($announcement, 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /announcements
     */
    public function list(Request $request)
    {
        $userContext = $request->userContext;
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));

        $query = Db::table('announcements');

        // Non-admins are always scoped to their own store
        if (!in_array('administrator', $userContext['roles'])) {
            $query->where('store_id', $userContext['store_id']);
        } else {
            // Admins may optionally filter by store_id
            $storeId = $request->get('store_id');
            if ($storeId) {
                $query->where('store_id', (int) $storeId);
            }
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
     * GET /announcements/{id}
     */
    public function read(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $announcement = Db::table('announcements')->where('id', $id)->find();

        if (!$announcement) {
            $resp = ResponseHelper::notFound('Announcement not found');
            return json($resp['data'], $resp['code']);
        }

        // Store scope check: non-admins can only read their own store's announcements
        if (!in_array('administrator', $userContext['roles']) && $announcement['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied');
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($announcement);
        return json($resp['data'], $resp['code']);
    }

    /**
     * PATCH /announcements/{id}
     */
    public function update(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $announcement = Db::table('announcements')->where('id', $id)->find();

        if (!$announcement) {
            $resp = ResponseHelper::notFound('Announcement not found');
            return json($resp['data'], $resp['code']);
        }

        // Store scope check: non-admins can only update their own store's announcements
        if (!in_array('administrator', $userContext['roles']) && $announcement['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied');
            return json($resp['data'], $resp['code']);
        }

        $before = $announcement;
        $data = $request->put();
        $updateData = [];

        $isAdmin = in_array('administrator', $userContext['roles']);
        // Non-admins that attempt to mutate store_id get rejected outright so
        // silent drops don't mask a cross-store attempt.
        if (!$isAdmin && isset($data['store_id']) && (int) $data['store_id'] !== (int) $announcement['store_id']) {
            $resp = ResponseHelper::forbidden('Cannot move announcement to a different store');
            return json($resp['data'], $resp['code']);
        }

        $allowedFields = ['title', 'body', 'category', 'priority', 'quality_score', 'published'];
        if ($isAdmin) {
            $allowedFields[] = 'store_id';
        }
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            Db::table('announcements')->where('id', $id)->update($updateData);
        }

        $after = Db::table('announcements')->where('id', $id)->find();

        $request->auditData = [
            'action'      => 'announcement.update',
            'entity_type' => 'announcement',
            'entity_id'   => $id,
            'before'      => $before,
            'after'       => $after,
        ];

        $resp = ResponseHelper::success($after);
        return json($resp['data'], $resp['code']);
    }

    /**
     * DELETE /announcements/{id}
     */
    public function delete(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $announcement = Db::table('announcements')->where('id', $id)->find();

        if (!$announcement) {
            $resp = ResponseHelper::notFound('Announcement not found');
            return json($resp['data'], $resp['code']);
        }

        // Store scope check: non-admins can only delete their own store's announcements
        if (!in_array('administrator', $userContext['roles']) && $announcement['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied');
            return json($resp['data'], $resp['code']);
        }

        Db::table('announcements')->where('id', $id)->delete();

        $request->auditData = [
            'action'      => 'announcement.delete',
            'entity_type' => 'announcement',
            'entity_id'   => $id,
            'before'      => $announcement,
            'after'       => null,
        ];

        $resp = ResponseHelper::success(['message' => 'Announcement deleted']);
        return json($resp['data'], $resp['code']);
    }
}
