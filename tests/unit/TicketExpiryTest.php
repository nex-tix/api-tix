<?php

use App\Services\TicketValidationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regresi aturan masa berlaku tiket (TicketValidationService).
 *
 * Bug yang dijaga di sini: server pernah memakai Config\App::$appTimezone = 'UTC'
 * sehingga "hari ini" pada pukul 00:00-07:59 WITA masih tanggal kemarin menurut
 * UTC, dan tiket yang sudah lewat masa berlaku ikut dinyatakan valid.
 */
final class TicketExpiryTest extends CIUnitTestCase
{
    public function testTiketKedaluwarsaTetapDitolakSaatDiniHariWita(): void
    {
        // 2026-10-12 16:30 UTC == 2026-10-13 00:30 WITA
        $nowUtc = new DateTimeImmutable('2026-10-12 16:30:00', new DateTimeZone('UTC'));
        $today  = TicketValidationService::todayWita($nowUtc);

        $this->assertSame('2026-10-13', $today);
        $this->assertTrue(
            TicketValidationService::isExpiredDate('2026-10-12', $today),
            'Tiket tanggal 12 Okt harus kedaluwarsa pada 13 Okt pukul 00:30 WITA.'
        );
    }

    public function testTodayWitaTidakIkutZonaWaktuServer(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $nowUtc = new DateTimeImmutable('2026-10-12 16:30:00', new DateTimeZone('UTC'));

            $this->assertSame('2026-10-13', TicketValidationService::todayWita($nowUtc));
            $this->assertNotSame(
                $nowUtc->format('Y-m-d'),
                TicketValidationService::todayWita($nowUtc),
                'Tanggal WITA tidak boleh sama dengan tanggal UTC di jendela dini hari.'
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testTiketBerlakuHariIniTidakKedaluwarsa(): void
    {
        $this->assertFalse(TicketValidationService::isExpiredDate('2026-10-13', '2026-10-13'));
    }

    public function testTiketTanggalMendatangBelumBerlaku(): void
    {
        $today = '2026-10-13';

        // Bukan kedaluwarsa, tapi juga belum boleh dipakai.
        $this->assertFalse(TicketValidationService::isExpiredDate('2026-10-20', $today));
        $this->assertTrue(TicketValidationService::isNotYetValidDate('2026-10-20', $today));
    }

    public function testTiketHariIniBerlakuPenuh(): void
    {
        $today = '2026-10-13';

        $this->assertFalse(TicketValidationService::isExpiredDate($today, $today));
        $this->assertFalse(TicketValidationService::isNotYetValidDate($today, $today));
    }

    public function testTiketTanpaTanggalBerlakuTidakDianggapBelumBerlaku(): void
    {
        $this->assertFalse(TicketValidationService::isNotYetValidDate(null, '2026-10-13'));
        $this->assertFalse(TicketValidationService::isNotYetValidDate('', '2026-10-13'));
    }

    public function testTiketTanpaTanggalBerlakuTidakDianggapKedaluwarsa(): void
    {
        $this->assertFalse(TicketValidationService::isExpiredDate(null, '2026-10-13'));
        $this->assertFalse(TicketValidationService::isExpiredDate('', '2026-10-13'));
    }

    public function testNowWitaSelaluWaktuWita(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');

        try {
            $expected = new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar'));
            $actual   = new DateTimeImmutable(
                TicketValidationService::nowWita(),
                new DateTimeZone('Asia/Makassar')
            );

            $this->assertLessThan(3, abs($expected->getTimestamp() - $actual->getTimestamp()));
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
