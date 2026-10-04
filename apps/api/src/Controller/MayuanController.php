<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\MayuanSummary;
use App\Support\ApiResponse;
use App\Support\Auth;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 马原知识宇宙学习摘要（观澜侧 §三）。
 * 详细学习记录权威在马原；此处仅接收马原 outbox 推送的聚合摘要（PUT），并提供个人中心读取（GET）。
 *
 * 归属安全：用户一律从 Auth::user() 取，请求体不接受 userId（防代写他人）。
 * 幂等与防重：eventId 与库存一致 → 直接回当前 revision；revision 不大于库存 → 409（旧覆盖新被拒）。
 */
class MayuanController
{
    public function __construct(private RequestInterface $request)
    {
    }

    /** PUT /api/v1/integrations/mayuan/summary —— 马原 outbox 推送（人态 token）。 */
    public function putSummary(): ResponseInterface
    {
        $user = Auth::user();
        $body = $this->request->all();
        if (! is_array($body)) {
            return ApiResponse::message('请求体必须是 JSON 对象', 422);
        }

        // 显式拒绝代写他人：body 携带 userId 即 422。
        if (array_key_exists('userId', $body) || array_key_exists('user_id', $body)) {
            return ApiResponse::message('请求体不得携带 userId（归属由登录身份决定）', 422);
        }

        $errors = $this->validateSummary($body);
        if ($errors !== []) {
            return ApiResponse::message('请求校验失败', 422, $errors);
        }

        $revision = (int) $body['revision'];
        $eventId = (string) $body['eventId'];

        $summary = MayuanSummary::query()->where('user_id', $user->id)->first();

        // eventId 幂等：同一事件重复推送，直接回已存 revision（不重复累计）。
        if ($summary && $summary->last_event_id !== '' && hash_equals((string) $summary->last_event_id, $eventId)) {
            return ApiResponse::data(['revision' => (int) $summary->revision]);
        }

        // 防旧覆盖新：revision 必须严格大于库存。
        if ($summary && $revision <= (int) $summary->revision) {
            return ApiResponse::message('revision 不新于服务端已接受版本', 409, ['currentRevision' => (int) $summary->revision]);
        }

        $now = date('Y-m-d H:i:s');
        $attributes = [
            'revision' => $revision,
            'content_version' => mb_substr((string) ($body['contentVersion'] ?? ''), 0, 191),
            'visited_concept_count' => (int) $body['visitedConceptCount'],
            'self_assessed_mastered_count' => (int) $body['selfAssessedMasteredCount'],
            'practice_attempt_count' => (int) $body['practiceAttemptCount'],
            'practice_correct_count' => (int) $body['practiceCorrectCount'],
            'due_review_count' => (int) $body['dueReviewCount'],
            'last_event_id' => $eventId,
            'last_activity_at' => $this->parseDateTime($body['lastActivityAt'] ?? null),
            'resume_target' => $body['resumeTarget'] ?? null,
            'updated_at' => $now,
        ];

        if ($summary) {
            $summary->fill($attributes)->save();
        } else {
            $summary = MayuanSummary::query()->create($attributes + [
                'user_id' => $user->id,
                'created_at' => $now,
            ]);
        }

        return ApiResponse::data(['revision' => (int) $summary->revision]);
    }

    /** GET /api/v1/study/mayuan/summary —— 个人中心读自己的摘要。 */
    public function getSummary(): ResponseInterface
    {
        $summary = MayuanSummary::query()->where('user_id', Auth::user()->id)->first();
        if (! $summary) {
            return ApiResponse::data(null);
        }

        return ApiResponse::data([
            'revision' => (int) $summary->revision,
            'contentVersion' => (string) $summary->content_version,
            'visitedConceptCount' => (int) $summary->visited_concept_count,
            'selfAssessedMasteredCount' => (int) $summary->self_assessed_mastered_count,
            'practiceAttemptCount' => (int) $summary->practice_attempt_count,
            'practiceCorrectCount' => (int) $summary->practice_correct_count,
            'dueReviewCount' => (int) $summary->due_review_count,
            'lastActivityAt' => $summary->last_activity_at,
            'resumeTarget' => $summary->resume_target,
            'updatedAt' => $summary->updated_at,
        ]);
    }

    /** @return array<string, string> 校验错误（字段 → 提示）；空数组表示通过。 */
    private function validateSummary(array $body): array
    {
        $errors = [];
        if (($body['schemaVersion'] ?? null) !== 1) {
            $errors['schemaVersion'] = 'schemaVersion 必须为 1';
        }
        $eventId = $body['eventId'] ?? null;
        if (! is_string($eventId) || $eventId === '' || mb_strlen($eventId) > 191) {
            $errors['eventId'] = 'eventId 必须是非空字符串（≤191）';
        }
        if (! is_int($body['revision'] ?? null) || $body['revision'] < 0) {
            $errors['revision'] = 'revision 必须是非负整数';
        }

        $counts = ['visitedConceptCount', 'selfAssessedMasteredCount', 'practiceAttemptCount', 'practiceCorrectCount', 'dueReviewCount'];
        foreach ($counts as $field) {
            if (! is_int($body[$field] ?? null) || $body[$field] < 0) {
                $errors[$field] = $field . ' 必须是非负整数';
            }
        }
        if (isset($body['practiceAttemptCount'], $body['practiceCorrectCount'])
            && is_int($body['practiceAttemptCount']) && is_int($body['practiceCorrectCount'])
            && $body['practiceCorrectCount'] > $body['practiceAttemptCount']) {
            $errors['practiceCorrectCount'] = 'practiceCorrectCount 不能大于 practiceAttemptCount';
        }

        if (isset($body['resumeTarget']) && $body['resumeTarget'] !== null) {
            $rt = $body['resumeTarget'];
            if (! is_array($rt) || (isset($rt['view']) && ! is_string($rt['view'])) || (isset($rt['nodeId']) && ! is_string($rt['nodeId']))) {
                $errors['resumeTarget'] = 'resumeTarget 结构非法（view/nodeId 须为字符串）';
            }
        }
        if (isset($body['lastActivityAt']) && $body['lastActivityAt'] !== null && $this->parseDateTime($body['lastActivityAt']) === null) {
            $errors['lastActivityAt'] = 'lastActivityAt 必须是合法日期时间';
        }

        return $errors;
    }

    /** 解析 ISO8601/RFC3339 为 'Y-m-d H:i:s'；非法返回 null。 */
    private function parseDateTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
