<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AdminSetting;
use App\Model\Hotspot;
use App\Model\ShizhengCandidate;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Guzzle\ClientFactory;

/**
 * 每日时政 AI 筛选：候选池管理、AI 配置（后台可填）、筛选分析、发布到 hotspots。
 *
 * AI key 只存服务端（admin_settings 表），前台永不返回明文。
 */
class ShizhengScreeningService
{
    private const CONFIG_KEY = 'shizheng.ai';

    private const PRIORITY_LEVEL = [
        '绝高' => 'S', '极高' => 'A', '很高' => 'A',
        '高' => 'B', '中高' => 'C', '中' => 'C',
    ];

    private const PRIORITY_RANK = ['绝高', '极高', '很高', '高', '中高', '中'];

    /** 各供应商的默认接入参数（与错题 AI 的 getAIConfig 预设一致） */
    private const PROVIDER_PRESETS = [
        'deepseek' => ['baseUrl' => 'https://api.deepseek.com', 'model' => 'deepseek-chat'],
        'openai' => ['baseUrl' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini'],
        'claude' => ['baseUrl' => 'https://api.anthropic.com/v1', 'model' => 'claude-opus-4-8'],
        'custom' => ['baseUrl' => '', 'model' => ''],
    ];

    private Client $httpClient;

    public function __construct(ClientFactory $clientFactory)
    {
        $this->httpClient = $clientFactory->create();
    }

    // ---------- AI 配置 ----------

    public function getConfig(): array
    {
        $raw = AdminSetting::getValue(self::CONFIG_KEY, '');
        $config = $raw ? (array) json_decode($raw, true) : [];
        return [
            'provider' => (string) ($config['provider'] ?? 'openai'),
            'apiKey' => (string) ($config['apiKey'] ?? ''),
            'baseUrl' => (string) ($config['baseUrl'] ?? 'https://api.openai.com/v1'),
            'model' => (string) ($config['model'] ?? 'gpt-4o-mini'),
            'subjectId' => (int) ($config['subjectId'] ?? 6),
            'topN' => max(1, (int) ($config['topN'] ?? 10)),
        ];
    }

    /** 返回给前台的配置：apiKey 打码，绝不回传明文 */
    public function getPublicConfig(): array
    {
        $config = $this->getConfig();
        $key = $config['apiKey'];
        $config['hasKey'] = $key !== '';
        $config['apiKey'] = $key !== '' && strlen($key) > 8
            ? substr($key, 0, 4) . '****' . substr($key, -4)
            : ($key !== '' ? '****' : '');
        return $config;
    }

    public function saveConfig(array $data): array
    {
        $current = $this->getConfig();
        // apiKey 传空或掩码值时保留原 key，避免误清空
        $apiKey = trim((string) ($data['apiKey'] ?? ''));
        if ($apiKey === '' || str_contains($apiKey, '*')) {
            $apiKey = $current['apiKey'];
        }
        $provider = self::PROVIDER_PRESETS[$data['provider'] ?? ''] !== null
            ? ($data['provider'])
            : 'openai';
        $preset = self::PROVIDER_PRESETS[$provider];
        $oldPreset = self::PROVIDER_PRESETS[$current['provider']] ?? [];

        $baseUrl = rtrim(trim((string) ($data['baseUrl'] ?? '')), '/');
        // 切换供应商时，空值或仍是旧供应商预设的地址/模型自动换成新预设
        if ($baseUrl === '' || ($provider !== $current['provider'] && $baseUrl === $oldPreset['baseUrl'])) {
            $baseUrl = $preset['baseUrl'];
        }
        if ($baseUrl === '') {
            $baseUrl = $current['baseUrl'];
        }
        $model = trim((string) ($data['model'] ?? ''));
        if ($model === '' || ($provider !== $current['provider'] && $model === $oldPreset['model'])) {
            $model = $preset['model'] !== '' ? $preset['model'] : $current['model'];
        }

        $config = [
            'provider' => $provider,
            'apiKey' => $apiKey,
            'baseUrl' => $baseUrl,
            'model' => $model,
            'subjectId' => max(1, (int) ($data['subjectId'] ?? $current['subjectId'])),
            'topN' => min(30, max(1, (int) ($data['topN'] ?? $current['topN']))),
        ];
        AdminSetting::putValue(self::CONFIG_KEY, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this->getPublicConfig();
    }

    /** 测试 AI 连通性：发一条最小请求 */
    public function testConfig(): array
    {
        $config = $this->getConfig();
        if ($config['apiKey'] === '') {
            return ['ok' => false, 'error' => '未配置 API Key'];
        }
        $result = $this->chat($config, '测试', '只回复两个字：正常');
        return isset($result['error'])
            ? ['ok' => false, 'error' => $result['error']]
            : ['ok' => true, 'reply' => mb_substr((string) $result['content'], 0, 50)];
    }

    // ---------- 候选池 ----------

    /** 爬虫推送当天候选：按 (publish_date, title) 幂等 upsert */
    public function upsertCandidates(string $date, array $items): array
    {
        $created = 0;
        $updated = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 191);
            if ($title === '') {
                continue;
            }
            $row = ShizhengCandidate::query()->firstOrNew([
                'publish_date' => $date,
                'title' => $title,
            ]);
            $row->fill([
                'source' => mb_substr((string) ($item['source'] ?? ''), 0, 32),
                'channel' => mb_substr((string) ($item['channel'] ?? ''), 0, 64),
                'url' => mb_substr((string) ($item['url'] ?? ''), 0, 512),
                'payload' => $item,
            ]);
            if (! $row->exists) {
                $row->status = 'pending';
                $row->save();
                $created++;
            } else {
                // 已发布的不回写，保护筛选结果
                if ($row->status !== 'published') {
                    $row->status = 'pending';
                }
                $row->save();
                $updated++;
            }
        }
        return ['created' => $created, 'updated' => $updated];
    }

    public function candidates(string $date): array
    {
        return ShizhengCandidate::query()
            ->where('publish_date', $date)
            ->orderBy('id')
            ->get()
            ->all();
    }

    // ---------- AI 筛选 ----------

    /**
     * 筛选某天候选：AI 可用走 AI，否则按原始优先级规则兜底。
     * auto=true 时筛完直接发布到 hotspots。
     */
    public function screen(string $date, int $top, bool $auto): array
    {
        $candidates = $this->candidates($date);
        if ($candidates === []) {
            return ['error' => "{$date} 无候选，请先由爬虫推送"];
        }
        $top = min(max(1, $top), count($candidates));
        $config = $this->getConfig();

        $selection = $config['apiKey'] !== ''
            ? $this->screenWithAI($candidates, $top, $config)
            : ['error' => '未配置 API Key'];

        if (isset($selection['error'])) {
            $selection = $this->screenByRule($candidates, $top);
            $selection['fallback'] = $selection['fallback'] ?? true;
        }

        // 先复位再标记，保证重复筛选幂等
        ShizhengCandidate::query()
            ->where('publish_date', $date)
            ->where('status', '!=', 'published')
            ->update(['status' => 'pending', 'ai_priority' => '', 'ai_module' => '', 'ai_reason' => '']);

        $selectedIds = [];
        foreach ($selection['selected'] as $entry) {
            $candidate = $candidates[$entry['index']] ?? null;
            if (! $candidate) {
                continue;
            }
            if ($candidate->status !== 'published') {
                $candidate->status = 'selected';
            }
            $candidate->ai_priority = mb_substr((string) ($entry['priority'] ?? ''), 0, 8);
            $candidate->ai_module = mb_substr((string) ($entry['module'] ?? ''), 0, 16);
            $candidate->ai_reason = mb_substr((string) ($entry['reason'] ?? ''), 0, 255);
            $candidate->save();
            $selectedIds[] = $candidate->id;
        }

        $result = [
            'date' => $date,
            'total' => count($candidates),
            'selected' => $selection['selected'],
            'fallback' => (bool) ($selection['fallback'] ?? false),
            'published' => null,
        ];
        if ($auto) {
            $result['published'] = $this->publish($selectedIds);
        }
        return $result;
    }

    private function screenWithAI(array $candidates, int $top, array $config): array
    {
        $brief = [];
        foreach ($candidates as $i => $c) {
            $payload = (array) $c->payload;
            $brief[] = [
                'index' => $i,
                'title' => $c->title,
                'type' => (string) ($payload['type'] ?? ''),
                'module' => (string) ($payload['module'] ?? ''),
                'priority' => (string) ($payload['priority'] ?? ''),
                'facts' => array_slice((array) ($payload['facts'] ?? []), 0, 2),
                'phrase' => (string) (((array) ($payload['fixed_phrases'] ?? []))[0] ?? ''),
            ];
        }

        $system = <<<PROMPT
你是考研政治时政编辑。从给定的候选时政素材中选出最适合考研政治复习的 {$top} 条。
选择标准：
1. 与考纲模块（马原/习思想/史纲/思法/当代/时政）的关联度
2. 命题概率：元首外交、中央会议、法律文件、纪念活动、重大部署优先；一般性行程报道、文化活动靠后
3. 固定表述的考试价值（需要逐字背诵的提法优先）

严格输出 JSON，不要任何其他文字：
{"selected":[{"index":候选序号,"priority":"绝高|极高|很高|高|中高|中","module":"主考模块","reason":"一句话入选理由"}]}
正好 {$top} 条；候选不足 {$top} 条则全选。
PROMPT;

        $result = $this->chat($config, $system, json_encode($brief, JSON_UNESCAPED_UNICODE));
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }
        $parsed = $this->extractJson((string) $result['content']);
        $selected = array_values(array_filter(
            (array) ($parsed['selected'] ?? []),
            fn ($e) => is_array($e) && isset($e['index']) && isset($candidates[(int) $e['index']])
        ));
        if ($selected === []) {
            return ['error' => 'AI 返回无法解析'];
        }
        return ['selected' => array_slice($selected, 0, $top), 'fallback' => false];
    }

    /** 规则兜底：按爬虫提炼时的原始优先级排序取前 N */
    private function screenByRule(array $candidates, int $top): array
    {
        $indexed = array_values($candidates);
        usort($indexed, function ($a, $b) {
            $pa = array_search((string) ($a->payload['priority'] ?? '中'), self::PRIORITY_RANK, true);
            $pb = array_search((string) ($b->payload['priority'] ?? '中'), self::PRIORITY_RANK, true);
            return ($pa === false ? 99 : $pa) <=> ($pb === false ? 99 : $pb);
        });
        $selected = [];
        foreach (array_slice($indexed, 0, $top) as $c) {
            $selected[] = [
                'index' => array_search($c, $candidates, true),
                'priority' => (string) ($c->payload['priority'] ?? '中'),
                'module' => (string) ($c->payload['module'] ?? ''),
                'reason' => '规则兜底（AI 不可用，按原始优先级）',
            ];
        }
        return ['selected' => $selected, 'fallback' => true];
    }

    // ---------- 发布 ----------

    /** 把选中的候选发布到 hotspots；标题已存在则跳过（保护人工编辑） */
    public function publish(array $ids): array
    {
        $config = $this->getConfig();
        $created = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $candidate = ShizhengCandidate::find((int) $id);
            if (! $candidate) {
                continue;
            }
            if (Hotspot::query()->where('title', $candidate->title)->exists()) {
                $skipped++;
                continue;
            }
            $payload = (array) $candidate->payload;
            $priority = $candidate->ai_priority !== ''
                ? $candidate->ai_priority
                : (string) ($payload['priority'] ?? '中');
            $level = self::PRIORITY_LEVEL[$priority] ?? 'C';
            $facts = array_values(array_filter((array) ($payload['facts'] ?? [])));

            $hotspot = new Hotspot();
            $html = $this->renderHtml($candidate, $payload);
            $hotspot->fill([
                'title' => $candidate->title,
                'level' => $level,
                'priority' => $level,
                'summary' => mb_substr(implode('；', array_slice($facts, 0, 2)), 0, 500),
                'type' => mb_substr((string) ($payload['type'] ?? ''), 0, 64),
                'tag' => '每日时政',
                'period' => (string) $candidate->publish_date,
                'html' => $html,
                'subject_id' => $config['subjectId'],
                'status' => 'published',
                'outline' => $this->outlineOf($payload),
                'word_count' => mb_strlen(strip_tags($html)),
                'source_file' => 'auto:shizheng-crawler',
                'published_at' => (string) $candidate->publish_date,
            ]);
            $hotspot->slug = 'auto-' . bin2hex(random_bytes(6));
            $hotspot->save();
            $hotspot->slug = "shizheng-{$candidate->publish_date}-{$hotspot->id}";
            $hotspot->save();

            $candidate->status = 'published';
            $candidate->hotspot_id = $hotspot->id;
            $candidate->save();
            $created++;
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    private function outlineOf(array $payload): array
    {
        $outline = [];
        foreach (['facts' => '必背事实', 'fixed_phrases' => '固定表述', 'exam_points' => '出题点', 'traps' => '易混提醒'] as $key => $label) {
            if (! empty($payload[$key])) {
                $outline[] = $label;
            }
        }
        return $outline;
    }

    private function renderHtml(ShizhengCandidate $candidate, array $payload): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $section = function (string $title, array $items, bool $ordered = false) use ($esc) {
            $items = array_values(array_filter($items));
            if ($items === []) {
                return '';
            }
            $tag = $ordered ? 'ol' : 'ul';
            $lis = implode('', array_map(fn ($i) => '<li>' . $esc($i) . '</li>', $items));
            return "<h2>{$esc($title)}</h2><{$tag}>{$lis}</{$tag}>";
        };

        $html = '<p><strong>日期：</strong>' . $esc($candidate->publish_date)
            . '　<strong>来源：</strong>' . $esc($candidate->source) . ' ' . $esc($candidate->channel)
            . '　<strong>优先级：</strong>' . $esc($candidate->ai_priority !== '' ? $candidate->ai_priority : ($payload['priority'] ?? ''))
            . '　<strong>主考模块：</strong>' . $esc($candidate->ai_module !== '' ? $candidate->ai_module : ($payload['module'] ?? '')) . '</p>';
        $html .= $section('必背事实', (array) ($payload['facts'] ?? []), true);
        $html .= $section('固定表述（逐字准确）', (array) ($payload['fixed_phrases'] ?? []));
        $html .= $section('出题点', (array) ($payload['exam_points'] ?? []));
        $html .= $section('易混提醒', (array) ($payload['traps'] ?? []));
        if ($candidate->url !== '') {
            $html .= '<p>原文：<a href="' . $esc($candidate->url) . '" rel="nofollow">' . $esc($candidate->url) . '</a></p>';
        }
        return $html;
    }

    // ---------- AI HTTP ----------

    private function chat(array $config, string $system, string $user): array
    {
        $urlError = $this->assertAllowedUrl((string) $config['baseUrl']);
        if ($urlError !== null) {
            return ['error' => "Base URL 无效: {$urlError}"];
        }
        try {
            if ($config['provider'] === 'claude') {
                $response = $this->httpClient->post("{$config['baseUrl']}/messages", [
                    'headers' => [
                        'x-api-key' => $config['apiKey'],
                        'anthropic-version' => '2023-06-01',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $config['model'],
                        'max_tokens' => 3000,
                        'system' => $system,
                        'messages' => [['role' => 'user', 'content' => $user]],
                    ],
                    'timeout' => 120,
                ]);
                $body = json_decode($response->getBody()->getContents(), true);
                return ['content' => (string) ($body['content'][0]['text'] ?? '')];
            }

            $response = $this->httpClient->post("{$config['baseUrl']}/chat/completions", [
                'headers' => [
                    'Authorization' => "Bearer {$config['apiKey']}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $config['model'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'temperature' => 0.2,
                ],
                'timeout' => 120,
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            return ['content' => (string) ($body['choices'][0]['message']['content'] ?? '')];
        } catch (GuzzleException $e) {
            return ['error' => 'AI 请求失败: ' . $e->getMessage()];
        }
    }

    /** 从 AI 输出中提取第一个 JSON 对象（容忍前后多余文字与 ```json 围栏） */
    private function extractJson(string $text): array
    {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /** URL 出网校验：防 SSRF（与 AIAnalysisService 同一规则） */
    private function assertAllowedUrl(string $url): ?string
    {
        if (! preg_match('~^https?://~i', $url)) {
            return '仅支持 http/https 地址';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return '地址缺少主机名';
        }
        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return '不允许访问内网地址';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($host, FILTER_VALIDATE_IP, $flags) === false) {
                return '不允许访问内网/保留 IP 地址';
            }
        }
        return null;
    }
}
