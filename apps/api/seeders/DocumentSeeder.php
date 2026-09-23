<?php

declare(strict_types=1);

use App\Model\Document;
use App\Model\Hotspot;
use App\Seeder\DatasetReader;
use Hyperf\Database\Seeders\Seeder;

/**
 * 首页资料卡：改为反映真实数据集规模，替代 dev.2 的静态两条。
 */
class DocumentSeeder extends Seeder
{
    public function run(): void
    {
        $cards = [
            ['title' => '真题库（1994—2026）', 'meta' => '42 份试卷 · 1479 道题', 'progress' => 100, 'cover' => '真题', 'tone' => 'jade'],
            ['title' => '时政热点（2026）', 'meta' => '1—9 月逐月整理', 'progress' => 100, 'cover' => '时政', 'tone' => 'red'],
            ['title' => '2027 时政考点预测', 'meta' => '纯真题反推', 'progress' => 100, 'cover' => '预测', 'tone' => 'gold'],
            ['title' => '个人错题分析', 'meta' => '2 位考生 · 提分手册', 'progress' => 100, 'cover' => '错题', 'tone' => 'jade'],
        ];

        foreach ($cards as $sortOrder => $data) {
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

        echo '[DocumentSeeder] 资料卡 ' . Document::query()->count() . ' 张' . PHP_EOL;
    }
}
