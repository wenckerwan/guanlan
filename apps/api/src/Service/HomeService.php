<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Document;
use App\Model\Hotspot;
use App\Model\Subject;

class HomeService
{
    /**
     * 热点：按 level（S>A>B>C）再按 updated_at 倒序。
     *
     * @return array<int, Hotspot>
     */
    public function hotspots(): array
    {
        return Hotspot::query()
            ->with(['subject', 'chapter'])
            ->orderByRaw("FIELD(level, 'S', 'A', 'B', 'C')")
            ->orderByDesc('updated_at')
            ->get()
            ->all();
    }

    /**
     * 资料卡：按 sort_order。
     *
     * @return array<int, Document>
     */
    public function documents(): array
    {
        return Document::query()
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /**
     * 学科摘要：按 sort_order。
     *
     * @return array<int, Subject>
     */
    public function subjects(): array
    {
        return Subject::query()
            ->orderBy('sort_order')
            ->get()
            ->all();
    }
}
