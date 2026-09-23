<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Mock;

class MockService
{
    /** @return array<int, Mock> */
    public function list(): array
    {
        return Mock::query()->orderBy('sort_order')->get()->all();
    }

    public function findBySlug(string $slug): ?Mock
    {
        return Mock::query()->where('slug', $slug)->first();
    }

    /** @return array<int, \App\Model\MockQuestion> */
    public function questions(int $mockId): array
    {
        return \App\Model\MockQuestion::query()->where('mock_id', $mockId)->orderBy('no')->get()->all();
    }
}
