<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class CheckpointController extends BaseController
{
    public function index(): ResponseInterface
    {
        $db = \Config\Database::connect();
        $checkpoints = $db->table('checkpoints')
            ->select('id, name, is_active')
            ->where('is_active', 1)
            ->get()
            ->getResultArray();

        $data = array_map(static function ($cp) {
            return [
                'id' => (int)$cp['id'],
                'name' => $cp['name'],
                'code' => strtoupper(str_replace(' ', '_', $cp['name'])),
                'location' => 'Pintu ' . $cp['name'],
                'is_active' => (bool)$cp['is_active'],
            ];
        }, $checkpoints);

        return $this->response->setJSON([
            'success' => true,
            'data' => $data,
            'message' => 'Checkpoint list retrieved',
        ]);
    }
}
