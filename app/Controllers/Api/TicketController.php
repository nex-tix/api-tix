<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\TicketValidationService;
use CodeIgniter\HTTP\ResponseInterface;

class TicketController extends BaseController
{
    private TicketValidationService $validationService;

    public function __construct()
    {
        $this->validationService = new TicketValidationService(\Config\Database::connect());
    }

    public function check(): ResponseInterface
    {
        $data = $this->request->getJSON(true) ?? [];
        $ticketCode = trim((string)($data['ticket_code'] ?? $data['ticketCode'] ?? ''));
        $checkpoint = (string)($data['checkpoint_id'] ?? $data['checkpointId'] ?? $data['checkpoint'] ?? '');

        if ($ticketCode === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Kode tiket tidak boleh kosong',
            ]);
        }

        $result = $this->validationService->check($ticketCode, $checkpoint);

        return $this->response->setJSON([
            'success' => $result['status'] === 'valid',
            'data' => $result['payload'],
            'message' => $result['payload']['message'] ?? 'Validasi tiket selesai',
        ]);
    }

    public function validateAndCheckin(): ResponseInterface
    {
        $data = $this->request->getJSON(true) ?? [];
        $ticketCode = trim((string)($data['ticket_code'] ?? $data['ticketCode'] ?? $data['ticketId'] ?? ''));
        $checkpoint = (string)($data['checkpoint_id'] ?? $data['checkpointId'] ?? $data['checkpoint'] ?? '');

        if ($ticketCode === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Kode tiket tidak boleh kosong',
            ]);
        }

        $gateUserId = (int)($this->request->gateUserId ?? 0);
        $result = $this->validationService->validateAndCheckin($ticketCode, $checkpoint, $gateUserId);

        return $this->response->setJSON([
            'success' => $result['status'] === 'valid',
            'data' => $result['payload'],
            'message' => $result['payload']['message'] ?? 'Proses check-in selesai',
        ]);
    }

    public function history(): ResponseInterface
    {
        $page = max(1, (int)($this->request->getGet('page') ?? 1));
        $pageSize = min(50, max(1, (int)($this->request->getGet('pageSize') ?? 20)));
        $gateUserId = (int)($this->request->gateUserId ?? 0);
        $date = trim((string)($this->request->getGet('date') ?? ''));
        $checkpoint = trim((string)($this->request->getGet('checkpoint') ?? ''));
        $status = trim((string)($this->request->getGet('status') ?? ''));

        $builder = \Config\Database::connect()->table('checkin_history h')
            ->select('h.*, cp.name as checkpoint_name, gu.name as validator_name, t.ticket_category, t.total_visitors, c.name as visitor_name')
            ->join('checkpoints cp', 'cp.id = h.checkpoint_id', 'left')
            ->join('gate_users gu', 'gu.id = h.gate_user_id', 'left')
            ->join('tickets t', 't.id = h.ticket_id', 'left')
            ->join('transactions tr', 'tr.id = t.transaction_id', 'left')
            ->join('customers c', 'c.id = tr.customer_id', 'left')
            ->where('h.gate_user_id', $gateUserId);

        if ($date !== '') {
            $builder->where('DATE(h.scanned_at)', $date);
        }
        if ($checkpoint !== '' && $checkpoint !== 'all') {
            if (is_numeric($checkpoint)) {
                $builder->where('h.checkpoint_id', (int)$checkpoint);
            } else {
                $builder->where('cp.name', $checkpoint);
            }
        }
        if ($status !== '' && $status !== 'all') {
            $statusMap = [
                'valid' => 'VALID',
                'already_used' => 'ALREADY_USED',
                'expired' => 'EXPIRED',
                'not_yet_valid' => 'NOT_YET_VALID',
                'cancelled' => 'CANCELLED',
                'not_found' => 'NOT_FOUND',
            ];
            if ($status === 'rejected') {
                $builder->whereIn('h.result', ['ALREADY_USED', 'EXPIRED', 'NOT_YET_VALID', 'CANCELLED', 'NOT_FOUND']);
            } elseif (isset($statusMap[$status])) {
                $builder->where('h.result', $statusMap[$status]);
            }
        }

        $countBuilder = clone $builder;
        $totalItems = $countBuilder->countAllResults();
        $rows = $builder
            ->orderBy('h.scanned_at', 'DESC')
            ->limit($pageSize, ($page - 1) * $pageSize)
            ->get()
            ->getResultArray();

        $items = array_map(static function (array $row): array {
            $statusMap = [
                'VALID' => 'valid',
                'ALREADY_USED' => 'already_used',
                'EXPIRED' => 'expired',
                'NOT_YET_VALID' => 'not_yet_valid',
                'CANCELLED' => 'cancelled',
                'NOT_FOUND' => 'not_found',
            ];
            return [
                'id' => (int)$row['id'],
                'ticket_code' => $row['scanned_code'],
                'status' => $statusMap[$row['result']] ?? 'not_found',
                'checkpoint' => $row['checkpoint_name'],
                'visitor_name' => $row['visitor_name'],
                'visitor_count' => (int)$row['visitor_count'],
                'category' => $row['ticket_category'] === 'group' ? 'Rombongan' : 'Individu',
                'scanned_at' => $row['scanned_at'],
                'validated_by' => $row['validator_name'],
            ];
        }, $rows);

        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'current_page' => $page,
                'per_page' => $pageSize,
                'total_items' => $totalItems,
                'total_pages' => $totalItems > 0 ? (int)ceil($totalItems / $pageSize) : 0,
                'items' => $items,
            ],
            'message' => 'Ticket history retrieved',
        ]);
    }
}
