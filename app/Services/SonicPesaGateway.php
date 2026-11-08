<?php

namespace App\Services;

use SonicPesa\SonicPesa;

class SonicPesaGateway
{
    private SonicPesa $client;

    public function __construct()
    {
        $this->client = new SonicPesa(
            config('services.sonicpesa.api_key'),
            config('services.sonicpesa.secret_key'),
            config('services.sonicpesa.base_url'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createOrder(array $data): array
    {
        return $this->client->payment()->create_order($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function orderStatus(string $orderId): array
    {
        return $this->client->payment()->order_status($orderId);
    }
}
