<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_forwarded_headers_from_an_untrusted_client_are_ignored(): void
    {
        config(['trustedproxy.proxies' => []]);
        $this->registerProbe();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.2'])
            ->getJson('/proxy-probe')
            ->assertJson(['secure' => false, 'ip' => '192.0.2.10']);
    }

    public function test_only_configured_ingress_can_supply_scheme_and_client_address(): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $this->registerProbe();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.2', 'X-Forwarded-Host' => 'attacker.invalid'])
            ->getJson('/proxy-probe')
            ->assertJson(['secure' => true, 'ip' => '198.51.100.2', 'host' => 'localhost']);
    }

    private function registerProbe(): void
    {
        Route::get('/proxy-probe', fn (Request $request): array => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
            'host' => $request->getHost(),
        ]);
    }
}
