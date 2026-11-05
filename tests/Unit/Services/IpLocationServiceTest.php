<?php

namespace Tests\Unit\Services;

use App\Services\Geo\IpLocationService;
use PHPUnit\Framework\TestCase;

class IpLocationServiceTest extends TestCase
{
    public function test_database_belum_diunduh_menghasilkan_null(): void
    {
        $this->assertNull((new IpLocationService('/tidak/ada.mmdb'))->locate('36.86.63.180'));
    }

    public function test_tanpa_ip_atau_ip_privat_menghasilkan_null_sebelum_membuka_file(): void
    {
        // __FILE__ bukan mmdb: bila dibuka, Reader melempar exception.
        $service = new IpLocationService(__FILE__);

        foreach ([null, '127.0.0.1', '10.0.0.5', '192.168.1.10', '::1', 'bukan-ip'] as $ip) {
            $this->assertNull($service->locate($ip));
        }
    }
}
