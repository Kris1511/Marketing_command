<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$integs = App\Models\Integration::where('platform', 'youtube')->get();
echo "Total YouTube Integrations in DB: " . $integs->count() . "\n";
foreach ($integs as $i) {
    echo "ID: {$i->id}, WS: {$i->workspace_id}, Name: {$i->account_name}, status: {$i->connection_status}, connected: {$i->is_connected}, RT len: " . strlen($i->refresh_token ?? '') . ", Updated: {$i->updated_at}\n";
    if (!empty($i->refresh_token)) {
        // Let's test if this RT works
        $client = new \GuzzleHttp\Client();
        try {
            $res = $client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'client_id'     => env('GOOGLE_CLIENT_ID'),
                    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
                    'refresh_token' => $i->refresh_token,
                    'grant_type'    => 'refresh_token',
                ],
            ]);
            echo "   -> Google token response status: " . $res->getStatusCode() . " (VALID!)\n";
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            echo "   -> Google returned: " . $e->getResponse()->getBody()->getContents() . "\n";
        }
    }
}
