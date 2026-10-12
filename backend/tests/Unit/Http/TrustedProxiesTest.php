<?php

namespace Tests\Unit\Http;

use App\Http\TrustedProxies;
use PHPUnit\Framework\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_nothing_configured_trusts_no_proxy(): void
    {
        $this->assertNull(TrustedProxies::from(''));
        $this->assertNull(TrustedProxies::from('   '));
        $this->assertNull(TrustedProxies::from(',, ,'));
    }

    public function test_a_star_trusts_any_proxy(): void
    {
        $this->assertSame('*', TrustedProxies::from('*'));
        $this->assertSame('*', TrustedProxies::from('  *  '));
    }

    public function test_addresses_are_split_and_trimmed(): void
    {
        $this->assertSame(
            ['10.0.0.1', '192.168.1.0/24'],
            TrustedProxies::from(' 10.0.0.1 , 192.168.1.0/24 '),
        );
    }
}
