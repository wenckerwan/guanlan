<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Mock;
use App\Model\MockQuestion;
use App\Resource\MockResource;

final class AdminLibraryService
{
    public function mock(string $slug, int $page = 1, int $perPage = 20): array
    {
        AdminDashboardService::authorize();
        $mock = Mock::query()->where('slug', $slug)->first();
        if (!$mock) throw new \RuntimeException('模拟卷不存在', 404);
        $query = MockQuestion::query()->where('mock_id', (int)$mock->id);
        $total = (int)(clone $query)->count();
        $perPage = min(100, max(1, $perPage));
        $page = min(max(1, $page), max(1, (int)ceil($total / $perPage)));
        $items = MockResource::questions($query->orderBy('no')->orderBy('id')->forPage($page, $perPage)->get());
        return ['mock'=>MockResource::make($mock) + ['sourceFile'=>(string)$mock->source_file], 'questions'=>compact('items', 'total', 'page', 'perPage')];
    }
}
