<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use App\Support\AdminDateRange;
use App\Support\Auth;
use Hyperf\DbConnection\Db;

final class AdminDashboardService
{
    public static function authorize(): void
    {
        $snapshot = Auth::user();
        $user = $snapshot ? User::find($snapshot->id) : null;
        if (!$user || $user->role !== 'admin' || !$user->isActive()) throw new \RuntimeException('需要有效的管理员身份', 403);
    }

    public function statistics(AdminDateRange $range): array
    {
        self::authorize();
        $dates=$range->dates();
        $registration=$this->timestampTrend('users',$range,$dates);
        $attempts=$this->timestampTrend('attempts',$range,$dates);
        $visits=$this->dateTrend('site_visit_days','visits',$range,$dates);
        // Study rows contain UTC date buckets only. Exact Beijing attribution is unavailable.
        $study=$this->dateTrend('user_study_stats','seconds',$range,$dates);
        return [
            'from'=>$range->from, 'to'=>$range->to, 'timezone'=>'Asia/Shanghai',
            'timestampTimezone'=>'UTC', 'studyDateTimezone'=>'UTC', 'dates'=>$dates,
            'updatedAt'=>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Shanghai')))->format(DATE_ATOM),
            'totals'=>[
                'registrations'=>array_sum($registration), 'attempts'=>array_sum($attempts),
                'correctAttempts'=>(int)$range->apply(Db::table('attempts')->where('is_right',1),'created_at')->count(),
                'visits'=>array_sum($visits), 'studySeconds'=>array_sum($study),
                'activeStudyAccounts'=>(int)Db::table('user_study_stats')->whereBetween('date',[$range->from,$range->to])->where('seconds','>',0)->distinct()->count('user_id'),
            ],
            'registrationTrend'=>$registration, 'attemptsTrend'=>$attempts, 'visitTrend'=>$visits, 'studyTrend'=>$study,
        ];
    }

    private function timestampTrend(string $table, AdminDateRange $range, array $dates): array
    {
        $expression='DATE(DATE_ADD(created_at, INTERVAL 8 HOUR))';
        $rows=$range->apply(Db::table($table),'created_at')->selectRaw("$expression AS day, COUNT(*) AS total")->groupBy(Db::raw($expression))->get();
        return $this->fill($dates,$rows);
    }

    private function dateTrend(string $table, string $value, AdminDateRange $range, array $dates): array
    {
        $rows=Db::table($table)->whereBetween('date',[$range->from,$range->to])->selectRaw("date AS day, SUM($value) AS total")->groupBy('date')->get();
        return $this->fill($dates,$rows);
    }

    private function fill(array $dates, iterable $rows): array
    {
        $out=array_fill_keys($dates,0);
        foreach($rows as $row) $out[(string)$row->day]=(int)$row->total;
        return $out;
    }
}
