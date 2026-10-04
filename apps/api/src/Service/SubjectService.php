<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Subject;

class SubjectService
{
    /**
     * 全部学科（含章节/知识点/热点），按 sort_order 排序。
     *
     * @return array<int, Subject>
     */
    public function list(): array
    {
        return Subject::query()
            ->with(['chapters.knowledgePoints', 'hotspots'])
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function findBySlug(string $slug): ?Subject
    {
        return Subject::query()
            ->with(['chapters.knowledgePoints', 'hotspots'])
            ->where('slug', $slug)
            ->first();
    }
}
