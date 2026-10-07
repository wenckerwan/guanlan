<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\VisitWindow;
use Hyperf\DbConnection\Db;

class VisitService
{
    public function overview(): array
    {
        return Db::transaction(fn () => $this->snapshot(VisitWindow::date(time())));
    }

    public function record(string $browserId): array
    {
        return Db::transaction(function () use ($browserId): array {
            // A common lock orders all writers, including first-time browser inserts.
            Db::table('site_visit_totals')->where('id', 1)->lockForUpdate()->first();
            $now = time();
            $date = VisitWindow::date($now);
            $hash = hash('sha256', $browserId);
            $browser = Db::table('site_visit_browsers')->where('browser_hash', $hash)->first();
            $counted = VisitWindow::shouldCount($browser ? (int) $browser->last_counted_at : null, $now);
            if ($counted) {
                Db::table('site_visit_browsers')->updateOrInsert(
                    ['browser_hash' => $hash], ['last_counted_at' => $now]
                );
                Db::table('site_visit_days')->insertOrIgnore(['date' => $date, 'visits' => 0]);
                Db::table('site_visit_days')->where('date', $date)->increment('visits');
                Db::table('site_visit_totals')->where('id', 1)->increment('visits');
                Db::table('site_visit_totals')->where('id', 1)->whereNull('started_at')->update(['started_at' => $date]);
            }
            return $this->snapshot($date) + ['counted' => $counted];
        }, 3);
    }

    private function snapshot(string $date): array
    {
        $total = Db::table('site_visit_totals')->where('id', 1)->first();
        return [
            'total' => (int) $total->visits,
            'today' => (int) (Db::table('site_visit_days')->where('date', $date)->value('visits') ?? 0),
            'date' => $date,
            'startedAt' => $total->started_at,
        ];
    }
}
