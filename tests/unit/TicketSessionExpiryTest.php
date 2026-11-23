<?php

use App\Services\TicketValidationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pengujian kedaluwarsa sesi pertunjukan dan batas awal masuk (TicketValidationService).
 *
 * Menguji:
 * 1. Tiket sesi 1 (17:00 - 18:00) kedaluwarsa ketat tepat setelah pukul 18:00:00 WITA.
 * 2. Tiket sesi 1 tidak dapat digunakan sebelum batas awal masuk (misal 30 menit sebelum sesi = 16:30 WITA).
 * 3. Tiket sesi 1 valid digunakan antara 16:30 - 18:00 WITA.
 * 4. Tiket sesi 1 ditolak saat sesi 2 (19:00 - 20:00) berlangsung.
 * 5. Parsing show_time dengan pemisah titik maupun titik dua.
 */
final class TicketSessionExpiryTest extends CIUnitTestCase
{
    private TicketValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TicketValidationService(\Config\Database::connect('default'));
    }

    public function testParseShowTimeRange(): void
    {
        $parsedColon = TicketValidationService::parseShowTimeRange('17:00 - 18:00');
        $this->assertNotNull($parsedColon);
        $this->assertSame('17:00:00', $parsedColon['start']);
        $this->assertSame('18:00:00', $parsedColon['end']);

        $parsedDot = TicketValidationService::parseShowTimeRange('19.00 - 20.00');
        $this->assertNotNull($parsedDot);
        $this->assertSame('19:00:00', $parsedDot['start']);
        $this->assertSame('20:00:00', $parsedDot['end']);

        $this->assertNull(TicketValidationService::parseShowTimeRange(null));
        $this->assertNull(TicketValidationService::parseShowTimeRange('invalid'));
    }

    public function testTiketSesi1DitolakSebelumBatasAwalMasuk(): void
    {
        $ticket = [
            'id' => 1,
            'ticket_code' => 'TEST-001',
            'status' => 'active',
            'valid_date' => '2026-11-23',
            'show_time' => '17:00 - 18:00',
        ];

        // 14:00 WITA (jauh sebelum sesi)
        $now = new DateTimeImmutable('2026-11-23 14:00:00', new DateTimeZone('Asia/Makassar'));
        $res = $this->service->evaluateStatus($ticket, $now);

        $this->assertSame('not_yet_valid', $res['status']);
        $this->assertStringContainsString('Pintu masuk untuk sesi 17:00 - 18:00 dibuka mulai pukul 16:30 WITA', $res['message']);

        // 16:29:59 WITA (1 detik sebelum batas 30 menit)
        $nowJustBefore = new DateTimeImmutable('2026-11-23 16:29:59', new DateTimeZone('Asia/Makassar'));
        $resJustBefore = $this->service->evaluateStatus($ticket, $nowJustBefore);

        $this->assertSame('not_yet_valid', $resJustBefore['status']);
    }

    public function testTiketSesi1ValidPadaJendelaMasukDanPertunjukan(): void
    {
        $ticket = [
            'id' => 1,
            'ticket_code' => 'TEST-001',
            'status' => 'active',
            'valid_date' => '2026-11-23',
            'show_time' => '17:00 - 18:00',
        ];

        // 16:30:00 WITA (tepat batas buffer 30 menit)
        $nowBuffer = new DateTimeImmutable('2026-11-23 16:30:00', new DateTimeZone('Asia/Makassar'));
        $resBuffer = $this->service->evaluateStatus($ticket, $nowBuffer);
        $this->assertSame('valid', $resBuffer['status']);

        // 17:30:00 WITA (pertengahan sesi)
        $nowMid = new DateTimeImmutable('2026-11-23 17:30:00', new DateTimeZone('Asia/Makassar'));
        $resMid = $this->service->evaluateStatus($ticket, $nowMid);
        $this->assertSame('valid', $resMid['status']);

        // 18:00:00 WITA (tepat batas akhir sesi)
        $nowEnd = new DateTimeImmutable('2026-11-23 18:00:00', new DateTimeZone('Asia/Makassar'));
        $resEnd = $this->service->evaluateStatus($ticket, $nowEnd);
        $this->assertSame('valid', $resEnd['status']);
    }

    public function testTiketSesi1KedaluwarsaSetelahJam1800Wita(): void
    {
        $ticket = [
            'id' => 1,
            'ticket_code' => 'TEST-001',
            'status' => 'active',
            'valid_date' => '2026-11-23',
            'show_time' => '17:00 - 18:00',
        ];

        // 18:00:01 WITA (1 detik setelah sesi 1 selesai) - Ketat kedaluwarsa
        $now1SecPast = new DateTimeImmutable('2026-11-23 18:00:01', new DateTimeZone('Asia/Makassar'));
        $res1SecPast = $this->service->evaluateStatus($ticket, $now1SecPast);

        $this->assertSame('expired', $res1SecPast['status']);
        $this->assertStringContainsString('telah berakhir pada pukul 18:00 WITA', $res1SecPast['message']);

        // 18:30:00 WITA (istirahat antar sesi)
        $nowBreak = new DateTimeImmutable('2026-11-23 18:30:00', new DateTimeZone('Asia/Makassar'));
        $resBreak = $this->service->evaluateStatus($ticket, $nowBreak);
        $this->assertSame('expired', $resBreak['status']);

        // 19:15:00 WITA (saat sesi 2 berlangsung)
        $nowSession2 = new DateTimeImmutable('2026-11-23 19:15:00', new DateTimeZone('Asia/Makassar'));
        $resSession2 = $this->service->evaluateStatus($ticket, $nowSession2);
        $this->assertSame('expired', $resSession2['status']);
    }

    public function testTiketSesi2ValidSaatSesi2(): void
    {
        $ticketSesi2 = [
            'id' => 2,
            'ticket_code' => 'TEST-002',
            'status' => 'active',
            'valid_date' => '2026-11-23',
            'show_time' => '19:00 - 20:00',
        ];

        // 19:15 WITA (sesi 2 berjalan)
        $now = new DateTimeImmutable('2026-11-23 19:15:00', new DateTimeZone('Asia/Makassar'));
        $res = $this->service->evaluateStatus($ticketSesi2, $now);
        $this->assertSame('valid', $res['status']);

        // 20:00:05 WITA (sesi 2 selesai)
        $nowPast = new DateTimeImmutable('2026-11-23 20:00:05', new DateTimeZone('Asia/Makassar'));
        $resPast = $this->service->evaluateStatus($ticketSesi2, $nowPast);
        $this->assertSame('expired', $resPast['status']);
    }
}
