<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class DashboardController extends BaseController
{
    public function summary(): ResponseInterface
    {
        $period = $this->request->getGet('period') === 'month' ? 'month' : 'today';
        $date = $this->request->getGet('date') ?: date('Y-m-d');
        $db = \Config\Database::connect();

        $start = $period === 'month' ? date('Y-m-01', strtotime($date)) : $date;
        $end = $period === 'month' ? date('Y-m-t', strtotime($date)) : $date;

        $base = $db->table('checkin_history h')
            ->select('h.*, cp.name as checkpoint_name, t.ticket_category')
            ->join('checkpoints cp', 'cp.id = h.checkpoint_id', 'left')
            ->join('tickets t', 't.id = h.ticket_id', 'left')
            ->where('DATE(h.scanned_at) >=', $start)
            ->where('DATE(h.scanned_at) <=', $end);

        $rows = $base->get()->getResultArray();

        $validRows = array_values(array_filter($rows, static fn(array $row): bool => $row['result'] === 'VALID'));
        $rejectedRows = array_values(array_filter($rows, static fn(array $row): bool => $row['result'] !== 'VALID'));

        $totalVisitors = array_sum(array_map(static fn(array $row): int => (int)$row['visitor_count'], $validRows));
        $validTickets = count($validRows);
        $rejectedTickets = count($rejectedRows);

        $byCheckpoint = [];
        $byVisitorType = [];
        foreach ($validRows as $row) {
            $checkpoint = $row['checkpoint_name'] ?? 'Unknown';
            $category = ($row['ticket_category'] ?? 'individual') === 'group' ? 'Rombongan' : 'Individu';
            $byCheckpoint[$checkpoint] = ($byCheckpoint[$checkpoint] ?? 0) + (int)$row['visitor_count'];
            $byVisitorType[$category] = ($byVisitorType[$category] ?? 0) + (int)$row['visitor_count'];
        }

        $hourly = [];
        $hourStart = 8;
        $hourCount = $period === 'month' ? 13 : 13;
        for ($i = 0; $i < $hourCount; $i++) {
            $hourly[$i] = 0;
        }
        foreach ($validRows as $row) {
            $hour = (int)date('G', strtotime($row['scanned_at']));
            $index = $hour - $hourStart;
            if ($index >= 0 && $index < $hourCount) {
                $hourly[$index] += (int)$row['visitor_count'];
            }
        }

        return $this->response->setJSON([
            'success' => true,
            'data' => [
                'period' => $period,
                'total_visitors' => $totalVisitors,
                'valid_tickets' => $validTickets,
                'rejected_tickets' => $rejectedTickets,
                'by_checkpoint' => $byCheckpoint,
                'by_visitor_type' => $byVisitorType,
                'hourly' => $hourly,
                'hour_start' => $hourStart,
                'date' => $date,
                'last_updated' => date(DATE_ATOM),
            ],
            'message' => 'Dashboard summary retrieved',
        ]);
    }
}
