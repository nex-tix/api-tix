<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Services\SignatureService;
use App\Services\EncryptionService;

class ApiSignatureFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $signature = $request->getHeaderLine('X-SIGNATURE');
        $timestamp = $request->getHeaderLine('X-TIMESTAMP');
        $channelCd = $request->getHeaderLine('CHANNEL-CD');

        if (empty($signature) || empty($timestamp)) {
            return $this->invalidSignature('Header X-SIGNATURE dan X-TIMESTAMP wajib ada');
        }

        if (!SignatureService::isTimestampValid($timestamp)) {
            return $this->invalidSignature('Timestamp sudah expired (±5 menit)');
        }

        $method = strtoupper($request->getMethod());
        $rawPath = trim($request->getPath(), '/');
        $wireBody = $request->getBody() ?? '';
        $body = $wireBody;

        $wireData = json_decode($wireBody, true);
        if (is_array($wireData) && isset($wireData['encrypted'])) {
            try {
                $body = EncryptionService::decrypt((string) $wireData['encrypted']);
            } catch (\Throwable $e) {
                return $this->invalidSignature('Payload terenkripsi tidak valid');
            }
        }

        $authHeader = $request->getHeaderLine('Authorization');
        $token = '';
        if (!empty($authHeader) && preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }

        // Variasi path yang mungkin dikirim oleh Mobile Dio (dengan/tanpa leading slash, dengan/tanpa prefix api)
        $endpointCandidates = array_unique([
            $rawPath,
            '/' . $rawPath,
            preg_replace('#^api/#', '', $rawPath),
            '/' . preg_replace('#^api/#', '', $rawPath),
            'api/' . preg_replace('#^api/#', '', $rawPath),
            '/api/' . preg_replace('#^api/#', '', $rawPath),
        ]);

        $bodyCandidates = array_unique([$body, $wireBody]);
        log_message('debug', '[ApiSignatureFilter] Body candidates: ' . json_encode([
            'wireBody' => substr($wireBody, 0, 100),
            'decrypted' => substr($body, 0, 100),
            'wireBody_hash' => SignatureService::hashBody($wireBody),
            'decrypted_hash' => SignatureService::hashBody($body),
            'provided_sig' => $signature,
        ]));
        $matched = false;
        try {
            foreach ($endpointCandidates as $ep) {
                foreach ($bodyCandidates as $candidateBody) {
                    if (SignatureService::verify($method, $ep, $token, $candidateBody, $timestamp, $signature)) {
                        $matched = true;
                        break 2;
                    }
                }
            }

            if (!$matched) {
                if (ENVIRONMENT === 'development') {
                    $debug = [];
                    foreach ($endpointCandidates as $ep) {
                        foreach ($bodyCandidates as $candidateBody) {
                            $hashedBody = SignatureService::hashBody($candidateBody);
                            $debug[] = [
                                'base' => $method . '|' . $ep . '|' . $token . '|' . $hashedBody . '|' . $timestamp,
                                'expected' => SignatureService::sign($method, $ep, $token, $hashedBody, $timestamp),
                                'provided' => $signature,
                            ];
                        }
                    }
                    log_message('debug', '[ApiSignatureFilter] Signature mismatch: ' . json_encode($debug));
                }
                return $this->invalidSignature('Signature tidak cocok');
            }
        } catch (\Exception $e) {
            log_message('error', '[ApiSignatureFilter] Error verify: ' . $e->getMessage());
            return $this->invalidSignature('Gagal verifikasi signature');
        }

        return null;
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ): void {
        // Tidak perlu pemrosesan setelah request
    }

    private function invalidSignature(string $message): ResponseInterface
    {
        return service('response')
            ->setStatusCode(401)
            ->setJSON([
                'success' => false,
                'message' => 'Signature tidak valid: ' . $message,
            ]);
    }
}
