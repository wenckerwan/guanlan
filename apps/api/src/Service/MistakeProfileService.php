<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\MistakeProfile;
use App\Model\MistakeStudent;
use App\Model\User;

class MistakeProfileService
{
    public const DEFAULT_MARKDOWN = '错题分析内容将在管理员上传后显示。';

    public function ensureDefault(MistakeStudent $student): MistakeProfile
    {
        $existing = MistakeProfile::query()->where('student_id', $student->id)->first();
        if ($existing) {
            return $existing;
        }

        return MistakeProfile::create([
            'student_id' => (int) $student->id,
            'markdown' => self::DEFAULT_MARKDOWN,
            'html' => $this->render(self::DEFAULT_MARKDOWN),
            'source_file' => 'default.md',
            'is_default' => true,
        ]);
    }

    public function replace(MistakeStudent $student, string $markdown, string $sourceFile, ?User $updatedBy = null): MistakeProfile
    {
        $profile = MistakeProfile::query()->where('student_id', $student->id)->first()
            ?? new MistakeProfile(['student_id' => (int) $student->id]);
        $profile->markdown = trim($markdown);
        $profile->html = $this->render($profile->markdown);
        $profile->source_file = mb_substr($sourceFile, 0, 191);
        $profile->is_default = false;
        $profile->updated_by = $updatedBy?->id;
        $profile->save();

        return $profile;
    }

    /**
     * 当前先使用安全的段落渲染；用户提供正式 skills 后可替换为完整 Markdown renderer，
     * 数据库接口不需要变化。
     */
    public function render(string $markdown): string
    {
        $safe = htmlspecialchars(trim($markdown), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<p>' . nl2br($safe, false) . '</p>';
    }
}
