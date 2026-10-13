<?php

namespace App\Services;

use App\Models\CheckpointModel;
use App\Models\TicketModel;
use App\Models\CheckinHistoryModel;
use App\Models\GateUserModel;
use CodeIgniter\Database\ConnectionInterface;

class TicketValidationService
{
    private ConnectionInterface $db;
    private TicketModel $ticketModel;
    private CheckpointModel $checkpointModel;
    private CheckinHistoryModel $checkinHistoryModel;
    private GateUserModel $gateUserModel;

    /**
     * Zona waktu bisnis (WITA). Satu-satunya acuan "hari ini" saat memvalidasi
     * masa berlaku tiket dan saat menstempel waktu check-in.
     */
    private const TIMEZONE = 'Asia/Makassar';

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
        $this->ticketModel = new TicketModel();
        $this->checkpointModel = new CheckpointModel();
        $this->checkinHistoryModel = new CheckinHistoryModel();
        $this->gateUserModel = new GateUserModel();
    }

    /**
     * Tanggal "hari ini" menurut WITA.
     *
     * Sengaja memakai zona waktu eksplisit, bukan date('Y-m-d') mentah: kalau
     * Config\App::$appTimezone kembali ke UTC, pukul 00:00-07:59 WITA tanggal
     * versi server masih tanggal kemarin sehingga tiket yang sudah kedaluwarsa
     * ikut dianggap valid. Parameter $now hanya dipakai unit test.
     */
    public static function todayWita(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now');

        return $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    /**
     * Stempel waktu sekarang (WITA) untuk ditulis ke database.
     */
    public static function nowWita(): string
    {
        return (new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Aturan kedaluwarsa: tiket sudah lewat masa berlaku bila tanggal
     * berlakunya sebelum hari ini (WITA). Tiket berlaku sampai pukul 24.00 WITA
     * di tanggal tersebut.
     */
    public static function isExpiredDate(?string $validDate, ?string $today = null): bool
    {
        if (empty($validDate)) {
            return false;
        }

        return $validDate < ($today ?? self::todayWita());
    }

    /**
     * Aturan sebaliknya: tiket belum boleh dipakai bila tanggal berlakunya masih
     * di depan hari ini (WITA). Tiket harian hanya sah pada tanggalnya sendiri,
     * jadi tiket untuk tanggal lain tidak boleh masuk lebih awal.
     */
    public static function isNotYetValidDate(?string $validDate, ?string $today = null): bool
    {
        if (empty($validDate)) {
            return false;
        }

        return $validDate > ($today ?? self::todayWita());
    }

    /**
     * Cari detail tiket dari database (lengkap dengan relasi transaksi, customer, checkpoint).
     */
    private function findTicketDetails(string $ticketCode): ?array
    {
        return $this->db->table('tickets t')
            ->select('
                t.*,
                tr.invoice_code,
                c.name as customer_name,
                c.origin as customer_origin,
                c.origin_type as customer_origin_type,
                gu.name as used_by_name,
                cp.name as used_checkpoint_name
            ')
            ->join('transactions tr', 'tr.id = t.transaction_id', 'left')
            ->join('customers c', 'c.id = tr.customer_id', 'left')
            ->join('gate_users gu', 'gu.id = t.used_by_gate_user_id', 'left')
            ->join('checkpoints cp', 'cp.id = t.used_checkpoint_id', 'left')
            ->where('t.ticket_code', $ticketCode)
            ->get()
            ->getRowArray();
    }

    /**
     * Hitung status logika tiket (valid, already_used, expired, cancelled, not_found).
     */
    private function evaluateStatus(?array $ticket): array
    {
        if (!$ticket) {
            return [
                'status' => 'not_found',
                'message' => 'Tiket tidak ditemukan',
            ];
        }

        if ($ticket['status'] === 'cancelled') {
            return [
                'status' => 'cancelled',
                'message' => 'Tiket dibatalkan',
            ];
        }

        if ($ticket['status'] === 'used') {
            return [
                'status' => 'already_used',
                'message' => 'Tiket sudah pernah digunakan',
            ];
        }

        $validDate = $ticket['valid_date'] ?? null;

        // Cek kedaluwarsa tanggal (acuan zona waktu WITA — lihat isExpiredDate())
        if (self::isExpiredDate($validDate)) {
            return [
                'status' => 'expired',
                'message' => 'Tiket sudah kedaluwarsa',
            ];
        }

        // Cek tiket yang belum masuk masa berlaku (tanggalnya masih di depan)
        if (self::isNotYetValidDate($validDate)) {
            return [
                'status' => 'not_yet_valid',
                'message' => 'Tiket belum berlaku (berlaku ' . $validDate . ')',
            ];
        }

        return [
            'status' => 'valid',
            'message' => 'Tiket valid',
        ];
    }

    /**
     * Format payload tiket seragam untuk mobile client (ScanResult & TicketCheckResponse).
     */
    private function formatPayload(array $ticket, string $status, string $checkpointName, bool $checkedIn, ?string $message = null): array
    {
        $category = ($ticket['ticket_category'] ?? 'individual') === 'group' ? 'Rombongan' : 'Individu';
        $visitorCount = (int)($ticket['total_visitors'] ?? 1);
        $hasHolder = in_array($status, ['valid', 'already_used']);

        return [
            'id' => (string)$ticket['id'],
            'ticket_id' => (string)$ticket['id'],
            'ticket_code' => $ticket['ticket_code'],
            'ticketCode' => $ticket['ticket_code'],
            'status' => $status,
            'visitor_name' => $hasHolder ? ($ticket['customer_name'] ?? 'Pengunjung') : null,
            'buyer_name' => $hasHolder ? ($ticket['customer_name'] ?? 'Pengunjung') : null,
            'visitor_count' => $hasHolder ? $visitorCount : 0,
            'category' => $hasHolder ? $category : null,
            'ticket_type' => $hasHolder ? $category : null,
            'used_at' => $ticket['used_at'] ?? null,
            'used_by' => $ticket['used_by_name'] ?? null,
            'used_at_checkpoint' => $ticket['used_checkpoint_name'] ?? null,
            'checkpoint' => $checkpointName,
            'expiry_date' => $ticket['valid_date'] ? $ticket['valid_date'] . ' 23:59:59' : null,
            'is_checked_in' => $checkedIn,
            'message' => $message ?? $this->defaultMessage($status, $checkedIn),
        ];
    }

    private function defaultMessage(string $status, bool $checkedIn): string
    {
        return match ($status) {
            'valid' => $checkedIn ? 'Check-in berhasil' : 'Tiket valid',
            'already_used' => 'Tiket sudah pernah digunakan',
            'expired' => 'Tiket sudah kedaluwarsa',
            'not_yet_valid' => 'Tiket belum berlaku',
            'cancelled' => 'Tiket dibatalkan',
            default => 'Tiket tidak ditemukan',
        };
    }

    /**
     * Mode Cek — membaca status tiket tanpa mengubah data tiket.
     */
    public function check(string $ticketCode, string $checkpointParam): array
    {
        $ticket = $this->findTicketDetails($ticketCode);
        $eval = $this->evaluateStatus($ticket);
        $status = $eval['status'];

        // Dapatkan nama gate jika parameter berupa ID numerik atau string
        $checkpointName = $this->resolveCheckpointName($checkpointParam);

        if ($status === 'not_found') {
            return [
                'status' => 'not_found',
                'payload' => [
                    'ticket_code' => $ticketCode,
                    'status' => 'not_found',
                    'message' => 'Tiket tidak ditemukan',
                    'visitor_count' => 0,
                    'is_checked_in' => false,
                ],
            ];
        }

        return [
            'status' => $status,
            'payload' => $this->formatPayload($ticket, $status, $checkpointName, false, $eval['message']),
        ];
    }

    /**
     * Mode Check-in — validasi sekaligus ubah status tiket secara atomik dengan SELECT FOR UPDATE.
     */
    public function validateAndCheckin(string $ticketCode, string $checkpointParam, int $gateUserId): array
    {
        $checkpointId = $this->resolveCheckpointId($checkpointParam);
        $checkpointName = $this->resolveCheckpointName($checkpointParam);

        $this->db->transStart();

        try {
            // Lock baris tiket dengan FOR UPDATE untuk mencegah double-spend secara konkuren
            $sql = $this->db->table('tickets')
                ->where('ticket_code', $ticketCode)
                ->getCompiledSelect(false) . ' FOR UPDATE';

            $ticketRow = $this->db->query($sql)->getRowArray();

            if (!$ticketRow) {
                // Catat log scan gagal
                $this->recordHistory(null, $ticketCode, $checkpointId, $gateUserId, 0, 'NOT_FOUND');
                $this->db->transComplete();

                return [
                    'status' => 'not_found',
                    'payload' => [
                        'ticket_code' => $ticketCode,
                        'status' => 'not_found',
                        'message' => 'Tiket tidak ditemukan',
                        'visitor_count' => 0,
                        'is_checked_in' => false,
                    ],
                ];
            }

            // Ambil relasi detail untuk response
            $ticket = $this->findTicketDetails($ticketCode);
            $eval = $this->evaluateStatus($ticket);
            $status = $eval['status'];

            if ($status !== 'valid') {
                $historyResult = match ($status) {
                    'already_used' => 'ALREADY_USED',
                    'expired' => 'EXPIRED',
                    'not_yet_valid' => 'NOT_YET_VALID',
                    'cancelled' => 'CANCELLED',
                    default => 'NOT_FOUND',
                };

                $this->recordHistory($ticket['id'], $ticketCode, $checkpointId, $gateUserId, 0, $historyResult);
                $this->db->transComplete();

                return [
                    'status' => $status,
                    'payload' => $this->formatPayload($ticket, $status, $checkpointName, false, $eval['message']),
                ];
            }

            // Tiket VALID: update tiket menjadi 'used'
            $now = self::nowWita();
            $this->db->table('tickets')
                ->where('id', $ticket['id'])
                ->update([
                    'status' => 'used',
                    'used_at' => $now,
                    'used_by_gate_user_id' => $gateUserId,
                    'used_checkpoint_id' => $checkpointId,
                    'updated_at' => $now,
                ]);

            // Simpan riwayat check-in sukses
            $visitorCount = (int)($ticket['total_visitors'] ?? 1);
            $this->recordHistory($ticket['id'], $ticketCode, $checkpointId, $gateUserId, $visitorCount, 'VALID');

            $this->db->transComplete();

            // Refresh data setelah update
            $updatedTicket = $this->findTicketDetails($ticketCode);

            return [
                'status' => 'valid',
                'payload' => $this->formatPayload($updatedTicket, 'valid', $checkpointName, true, 'Check-in berhasil'),
            ];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    private function recordHistory(?int $ticketId, string $scannedCode, ?int $checkpointId, int $gateUserId, int $visitorCount, string $result): void
    {
        $this->db->table('checkin_history')->insert([
            'ticket_id' => $ticketId,
            'scanned_code' => $scannedCode,
            'checkpoint_id' => $checkpointId ?? 1,
            'gate_user_id' => $gateUserId,
            'visitor_count' => $visitorCount,
            'result' => $result,
            'scanned_at' => self::nowWita(),
        ]);
    }

    private function resolveCheckpointId(string $param): int
    {
        if (is_numeric($param) && (int)$param > 0) {
            return (int)$param;
        }

        $row = $this->db->table('checkpoints')
            ->select('id')
            ->where('name', $param)
            ->get()
            ->getRowArray();

        return (int)($row['id'] ?? 1);
    }

    private function resolveCheckpointName(string $param): string
    {
        if (is_numeric($param)) {
            $row = $this->db->table('checkpoints')
                ->select('name')
                ->where('id', (int)$param)
                ->get()
                ->getRowArray();

            if ($row) {
                return $row['name'];
            }
        }

        return !empty($param) ? $param : 'Gate A';
    }
}
