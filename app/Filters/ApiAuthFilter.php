<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

class ApiAuthFilter implements FilterInterface
{
    public function before(
        RequestInterface $request,
        $arguments = null
    ) {
        $header = $request->getHeaderLine('Authorization');

        if (
            !preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $matches)
        ) {
            return $this->unauthorized();
        }

        $tokenHash = hash('sha256', $matches[1]);

        $db = Database::connect();

        $token = $db->table('gate_api_tokens t')
            ->select('t.id, t.gate_user_id')
            ->join(
                'gate_users u',
                'u.id = t.gate_user_id'
            )
            ->where('t.token_hash', $tokenHash)
            ->where('t.revoked_at', null)
            ->where('t.expires_at >', gmdate('Y-m-d H:i:s'))
            ->where('u.is_active', 1)
            ->get()
            ->getRowArray();

        if (!$token) {
            return $this->unauthorized();
        }

        // Perbarui last_used_at sesuai PRD 7.1 & PRD 11
        $db->table('gate_api_tokens')
            ->where('id', (int) $token['id'])
            ->update([
                'last_used_at' => gmdate('Y-m-d H:i:s'),
            ]);

        // Simpan data user & token ke header request internal supaya bisa dipakai controller tanpa dynamic property deprecation
        $request->setHeader('X-Gate-User-Id', (string) $token['gate_user_id']);
        $request->setHeader('X-Gate-Token-Id', (string) $token['id']);

        return null;
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ) {
        // Tidak memerlukan pemrosesan setelah request.
    }

    private function unauthorized(): ResponseInterface
    {
        return service('response')
            ->setStatusCode(401)
            ->setJSON([
                'success' => false,
                'message' => 'Token tidak valid atau sudah kedaluwarsa.',
            ]);
    }
}
