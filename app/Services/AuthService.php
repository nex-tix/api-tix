<?php
namespace App\Services;

use App\Models\GateUserModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class AuthService
{
    private GateUserModel $users;
    private BaseConnection $db;

    // Masa berlaku token: 7 hari.
    private const TOKEN_TTL = 604800;

    public function __construct()
    {
        $this->users = new GateUserModel();
        $this->db = Database::connect();
    }

    public function login(
        string $username,
        string $password
    ): array {
        $user = $this->users
            ->where('username', trim($username))
            ->first();

        // Pesan yang sama untuk username/password salah.
        if (
            !$user ||
            !password_verify($password, $user['password_hash'])
        ) {
            throw new RuntimeException(
                'Username atau password salah.'
            );
        }

        if ((int) $user['is_active'] !== 1) {
            throw new RuntimeException(
                'Akun petugas tidak aktif.'
            );
        }

        // Token acak 256-bit.
        $plainToken = bin2hex(random_bytes(32));

        // Simpan hash, bukan token asli.
        $tokenHash = hash('sha256', $plainToken);

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );

        $expiresAt = $now->modify(
            '+' . self::TOKEN_TTL . ' seconds'
        );

        $this->db->table('gate_api_tokens')->insert([
            'gate_user_id' => $user['id'],
            'token_hash'   => $tokenHash,
            'expires_at'   => $expiresAt->format('Y-m-d H:i:s'),
            'revoked_at'   => null,
            'created_at'   => $now->format('Y-m-d H:i:s'),
        ]);

        return [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            'user' => [
                'id'       => (int) $user['id'],
                'name'     => $user['name'],
                'username' => $user['username'],
                'phone'    => $user['phone'],
            ],
        ];
    }

    public function revokeToken(string $plainToken): void
    {
        $tokenHash = hash('sha256', $plainToken);

        $this->db->table('gate_api_tokens')
            ->where('token_hash', $tokenHash)
            ->where('revoked_at', null)
            ->update([
                'revoked_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }
}
