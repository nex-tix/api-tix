<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\AuthService;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class AuthController extends BaseController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function login(): ResponseInterface
    {
        $data = $this->request->getJSON(true);

        if (!is_array($data)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'Request harus berupa JSON.',
                ]);
        }

        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;

        if (
            !is_string($username) ||
            !is_string($password) ||
            trim($username) === '' ||
            $password === ''
        ) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'Username dan password wajib diisi.',
                ]);
        }

        try {
            $result = $this->authService->login(
                $username,
                $password
            );

            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'success' => true,
                    'message' => 'Login berhasil.',
                    'data' => $result,
                ]);
        } catch (RuntimeException $e) {
            $statusCode = $e->getMessage() ===
                'Akun petugas tidak aktif.' ? 403 : 401;

            return $this->response
                ->setStatusCode($statusCode)
                ->setJSON([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
        }
    }

    public function logout(): ResponseInterface
    {
        $token = $this->getBearerToken();

        if ($token === null) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Token tidak ditemukan.',
                ]);
        }

        $this->authService->revokeToken($token);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    }

    public function me(): ResponseInterface
    {
        // Ambil user ID yang disimpan filter
        $userId = $this->request->gateUserId ?? null;

        if (!$userId) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ]);
        }

        $db = \Config\Database::connect();
        $user = $db->table('gate_users')
            ->select('id, name, phone, is_active, created_at')
            ->where('id', $userId)
            ->get()
            ->getRowArray();

        if (!$user) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON([
                    'success' => false,
                    'message' => 'User tidak ditemukan.',
                ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'data'    => $user,
        ]);
    }

    private function getBearerToken(): ?string
    {
        $header = $this->request->getHeaderLine(
            'Authorization'
        );

        if (
            !preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $matches)
        ) {
            return null;
        }

        return $matches[1];
    }
}