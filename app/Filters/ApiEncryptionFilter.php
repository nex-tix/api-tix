<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Services\EncryptionService;

class ApiEncryptionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $body = $request->getBody();

        if (empty($body)) {
            return null;
        }

        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['encrypted'])) {
            return null;
        }

        try {
            $decrypted = EncryptionService::decrypt($data['encrypted']);
            $request->setBody($decrypted);
            $request->setHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return service('response')
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'Payload terenkripsi tidak valid: gagal dekripsi.',
                    'error' => ENVIRONMENT === 'development' ? $e->getMessage() : null,
                ]);
        }

        return null;
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ): ?ResponseInterface {
        $body = $response->getBody();

        if (empty($body) || $response->getStatusCode() >= 300) {
            return $response;
        }

        try {
            $json = json_decode($body, true);
            if ($json === null) {
                return $response;
            }

            $encrypted = EncryptionService::encrypt($body);
            $response->setBody(json_encode(['encrypted' => $encrypted]));
            $response->setContentType('application/json');
        } catch (\Exception $e) {
            // Jika enkripsi response gagal, kembalikan plain JSON saja
            log_message('error', '[ApiEncryptionFilter] Gagal encrypt response: ' . $e->getMessage());
        }

        return $response;
    }
}
