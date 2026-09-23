<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fbPage = \App\Models\FacebookPage::where('workspace_id', 9)->where('token_status', 'valid')->first();
$token = $fbPage->page_access_token;
$version = 'v23.0';

$posts = [
    'Post 1' => '115864121526929_982499514861065',
    'Post 2' => '115864121526929_978150271962656',
    'Post 3' => '115864121526929_977067722070911',
];

foreach ($posts as $name => $postId) {
    echo "=============================================\n";
    echo "  {$name} ({$postId})\n";
    echo "=============================================\n";
    
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()
        ->timeout(10)
        ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
        ->get("https://graph.facebook.com/{$version}/{$postId}", [
            'fields' => 'id,message,status_type,permalink_url,shares,attachments{type,media_type,target,media,subattachments{type,media_type,target,media}}',
            'access_token' => $token,
        ]);
        
    $data = $res->json();
    $atts = $data['attachments']['data'] ?? [];
    echo "Status Type: " . ($data['status_type'] ?? 'N/A') . "\n";
    echo "Shares: " . ($data['shares']['count'] ?? 0) . "\n";
    
    foreach ($atts as $idx => $att) {
        echo "Attachment [{$idx}]: type=" . ($att['type'] ?? '') . ", media_type=" . ($att['media_type'] ?? '') . ", target_id=" . ($att['target']['id'] ?? 'none') . "\n";
        
        $subs = $att['subattachments']['data'] ?? [];
        echo "Subattachments count: " . count($subs) . "\n";
        
        $targetsToQuery = [];
        if (!empty($subs)) {
            foreach ($subs as $sIdx => $s) {
                if (!empty($s['target']['id'])) {
                    $targetsToQuery[] = ['id' => $s['target']['id'], 'type' => 'sub_' . $sIdx];
                }
            }
        } elseif (!empty($att['target']['id'])) {
            $targetsToQuery[] = ['id' => $att['target']['id'], 'type' => 'top'];
        }
        
        foreach ($targetsToQuery as $t) {
            $tRes = \Illuminate\Support\Facades\Http::withoutVerifying()
                ->timeout(10)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get("https://graph.facebook.com/{$version}/{$t['id']}", [
                    'fields' => 'id,likes.summary(true),comments.summary(true),reactions.summary(true),views',
                    'access_token' => $token,
                ]);
            $td = $tRes->json();
            $lCount = $td['likes']['summary']['total_count'] ?? 'N/A';
            $cCount = $td['comments']['summary']['total_count'] ?? 'N/A';
            $rCount = $td['reactions']['summary']['total_count'] ?? 'N/A';
            $vCount = $td['views'] ?? 'N/A';
            echo "  Target {$t['type']} ({$t['id']}): Likes={$lCount}, Comments={$cCount}, Reactions={$rCount}, Views={$vCount}\n";
        }
    }
}
