<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiContentService
{
    /**
     * Generate multi-variation captions and hashtags for social posts.
     */
    public function generateCaptions(array $params): array
    {
        $topic = trim($params['topic'] ?? '');
        $platform = strtolower($params['platform'] ?? 'instagram');
        $tone = strtolower($params['tone'] ?? 'engaging');
        $includeHashtags = $params['include_hashtags'] ?? true;
        $includeEmojis = $params['include_emojis'] ?? true;
        $language = $params['language'] ?? 'English';
        $workspaceName = $params['workspace_name'] ?? 'Brand';

        if (empty($topic)) {
            throw new \InvalidArgumentException('Please provide a topic or idea for the post.');
        }

        $geminiKey = env('GEMINI_API_KEY');
        $openaiKey = env('OPENAI_API_KEY');

        // 1. Try Google Gemini API if configured
        if (!empty($geminiKey)) {
            try {
                $result = $this->callGemini($geminiKey, $topic, $platform, $tone, $includeHashtags, $includeEmojis, $language, $workspaceName);
                if (!empty($result) && is_array($result)) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('[AiContentService] Gemini API call failed, falling back: ' . $e->getMessage());
            }
        }

        // 2. Try OpenAI API if configured
        if (!empty($openaiKey)) {
            try {
                $result = $this->callOpenAi($openaiKey, $topic, $platform, $tone, $includeHashtags, $includeEmojis, $language, $workspaceName);
                if (!empty($result) && is_array($result)) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('[AiContentService] OpenAI API call failed, falling back: ' . $e->getMessage());
            }
        }

        // 3. Fallback to smart built-in heuristic generator
        return $this->generateHeuristicCaptions($topic, $platform, $tone, $includeHashtags, $includeEmojis, $workspaceName);
    }

    /**
     * Call Google Gemini API (gemini-1.5-flash)
     */
    protected function callGemini(string $apiKey, string $topic, string $platform, string $tone, bool $includeHashtags, bool $includeEmojis, string $language, string $workspaceName): ?array
    {
        $prompt = $this->buildPrompt($topic, $platform, $tone, $includeHashtags, $includeEmojis, $language, $workspaceName);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";
        $response = Http::timeout(15)->post($url, [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.7,
            ]
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $rawJson = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = json_decode($rawJson, true);
            if (!empty($parsed['variations'])) {
                return $parsed['variations'];
            }
            if (is_array($parsed) && isset($parsed[0]['caption'])) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Call OpenAI Chat Completion API (gpt-4o-mini)
     */
    protected function callOpenAi(string $apiKey, string $topic, string $platform, string $tone, bool $includeHashtags, bool $includeEmojis, string $language, string $workspaceName): ?array
    {
        $prompt = $this->buildPrompt($topic, $platform, $tone, $includeHashtags, $includeEmojis, $language, $workspaceName);

        $response = Http::withToken($apiKey)->timeout(15)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => 'You are an elite social media copywriter. Output only valid JSON with a "variations" array containing objects with keys: "label", "caption", "hashtags", "full_text".'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $parsed = json_decode($content, true);
            if (!empty($parsed['variations'])) {
                return $parsed['variations'];
            }
        }

        return null;
    }

    /**
     * Build system prompt for AI models
     */
    protected function buildPrompt(string $topic, string $platform, string $tone, bool $includeHashtags, bool $includeEmojis, string $language, string $workspaceName): string
    {
        $emojiInstruction = $includeEmojis ? 'Use appropriate modern emojis to make it visually engaging.' : 'Do not use emojis.';
        $hashtagInstruction = $includeHashtags ? 'Generate 5-8 relevant, high-traffic, niche-specific hashtags.' : 'Do not include hashtags.';

        return <<<PROMPT
Create 3 high-converting social media caption variations for {$workspaceName}.

Details:
- Topic/Brief: "{$topic}"
- Target Platform: {$platform}
- Tone: {$tone}
- Language: {$language}
- Formatting: {$emojiInstruction} {$hashtagInstruction}

Requirements:
1. Variation 1: Short, hook-driven & punchy.
2. Variation 2: Value-focused with engaging call-to-action (CTA).
3. Variation 3: Storytelling & curiosity-driven.

Return JSON in this EXACT structure:
{
  "variations": [
    {
      "label": "Hook & Punchy",
      "caption": "...",
      "hashtags": ["#tag1", "#tag2", "#tag3"],
      "full_text": "caption text here\\n\\n#tag1 #tag2 #tag3"
    },
    {
      "label": "Value & CTA",
      "caption": "...",
      "hashtags": ["#tag1", "#tag2", "#tag3"],
      "full_text": "caption text here\\n\\n#tag1 #tag2 #tag3"
    },
    {
      "label": "Storytelling & Impact",
      "caption": "...",
      "hashtags": ["#tag1", "#tag2", "#tag3"],
      "full_text": "caption text here\\n\\n#tag1 #tag2 #tag3"
    }
  ]
}
PROMPT;
    }

    /**
     * Built-in template/heuristic engine (works without external API keys)
     */
    protected function generateHeuristicCaptions(string $topic, string $platform, string $tone, bool $includeHashtags, bool $includeEmojis, string $workspaceName): array
    {
        $cleanTopic = rtrim($topic, '.!');
        $em = $includeEmojis;

        // Generate tailored hashtags based on keywords in topic
        $words = preg_split('/[\s,]+/', strtolower($cleanTopic));
        $generatedTags = [];
        foreach ($words as $w) {
            $w = preg_replace('/[^a-z0-9]/', '', $w);
            if (strlen($w) >= 4 && !in_array($w, ['this', 'that', 'with', 'from', 'have', 'your', 'about', 'some', 'what'])) {
                $generatedTags[] = '#' . ucfirst($w);
            }
        }
        $basePlatformTags = [
            'instagram' => ['#Trending', '#ExplorePage', '#DailyInspo', '#InstaGood', '#MarketingStrategy'],
            'facebook'  => ['#BusinessGrowth', '#MarketingTips', '#Updates', '#Community'],
            'twitter'   => ['#Marketing', '#Growth', '#TechTrends', '#Business'],
            'youtube'   => ['#YouTubeShorts', '#Subscribe', '#ContentCreator', '#NewVideo'],
            'linkedin'  => ['#BusinessLeadership', '#GrowthMindset', '#Innovation', '#MarketingInsights'],
        ];

        $platformTags = $basePlatformTags[$platform] ?? $basePlatformTags['instagram'];
        $allTags = array_unique(array_merge($generatedTags, $platformTags));
        $hashtagsList = array_slice($allTags, 0, 6);
        $hashtagString = $includeHashtags ? implode(' ', $hashtagsList) : '';

        // Tone & Style templates
        $emojiHook = $em ? '🔥 ' : '';
        $emojiSpark = $em ? '✨ ' : '';
        $emojiRocket = $em ? '🚀 ' : '';
        $emojiCheck = $em ? '👉 ' : '';
        $emojiTarget = $em ? '🎯 ' : '';

        // Variation 1: Hook & Punchy
        $v1Caption = "{$emojiHook}Looking for a game-changer? {$cleanTopic} is here to take your results to the next level.\n\n{$emojiSpark}Don't sleep on this — experience the difference today!";
        $v1Full = $includeHashtags ? "{$v1Caption}\n\n{$hashtagString}" : $v1Caption;

        // Variation 2: Value & CTA
        $v2Caption = "{$emojiRocket}Supercharge your growth with {$cleanTopic}.\n\nHere is why it matters:\n{$emojiCheck}Designed for high performance & real results\n{$emojiCheck}Simple, seamless, and built for you\n\n{$emojiTarget}Ready to get started? Tap the link in our bio or send us a message!";
        $v2Full = $includeHashtags ? "{$v2Caption}\n\n{$hashtagString}" : $v2Caption;

        // Variation 3: Storytelling & Impact
        $v3Caption = "{$emojiSpark}Success isn't about working harder — it's about having the right strategy.\n\nThat's why {$cleanTopic} is designed to help {$workspaceName} stand out from the crowd.\n\nWhat are your thoughts on this? Drop a comment below! 💬";
        $v3Full = $includeHashtags ? "{$v3Caption}\n\n{$hashtagString}" : $v3Caption;

        return [
            [
                'label'      => 'Hook & Punchy',
                'caption'    => $v1Caption,
                'hashtags'   => $hashtagsList,
                'full_text'  => $v1Full,
            ],
            [
                'label'      => 'Value & CTA',
                'caption'    => $v2Caption,
                'hashtags'   => $hashtagsList,
                'full_text'  => $v2Full,
            ],
            [
                'label'      => 'Storytelling & Engagement',
                'caption'    => $v3Caption,
                'hashtags'   => $hashtagsList,
                'full_text'  => $v3Full,
            ],
        ];
    }
}
