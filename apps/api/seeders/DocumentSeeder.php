<?php

declare(strict_types=1);

use App\Model\Document;
use Hyperf\Database\Seeders\Seeder;

class DocumentSeeder extends Seeder
{
    /**
     * 数据源：apps/web/data/home.ts 的 recentDocuments 2 条。
     * category/year/source/file_path/subject_id 本期留空（manifest 导入器后置）。
     * 幂等：按 title firstOrCreate。
     */
    public function run(): void
    {
        $documents = [
            ['title' => '马克思主义基本原理（2023版）', 'meta' => '第 2 章 · 唯物辩证法', 'progress' => 36, 'cover' => '马原', 'tone' => 'jade'],
            ['title' => '2027 考研政治时政热点预测', 'meta' => '六组核心命题包', 'progress' => 68, 'cover' => '时政', 'tone' => 'red'],
        ];

        foreach ($documents as $sortOrder => $data) {
            Document::firstOrCreate(
                ['title' => $data['title']],
                [
                    'meta' => $data['meta'],
                    'progress' => $data['progress'],
                    'cover' => $data['cover'],
                    'tone' => $data['tone'],
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
