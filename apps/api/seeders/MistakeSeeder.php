<?php

declare(strict_types=1);

use App\Model\MistakeHandbook;
use App\Model\MistakeItem;
use App\Model\MistakeStudent;
use App\Model\User;
use App\Seeder\DatasetReader;
use Hyperf\Database\Seeders\Seeder;

/**
 * 个人错题分析（考生之间完全隔离）+ 模拟押题 + 管理员账号。
 */
class MistakeSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMistakes();
        $this->seedMocks();
        $this->seedAdmin();
    }

    private function seedMistakes(): void
    {
        if (DatasetReader::missing('mistakes.json')) {
            echo '[MistakeSeeder] 跳过错题：dataset 缺失' . PHP_EOL;
            return;
        }

        $payload = json_decode((string) file_get_contents(BASE_PATH . '/storage/dataset/mistakes.json'), true) ?: [];
        $students = $payload['students'] ?? [];
        $items = $payload['items'] ?? [];
        $handbooks = $payload['handbooks'] ?? [];

        // 先删子表再删主表（外键 cascade）
        \Hyperf\DbConnection\Db::table('mistake_handbooks')->delete();
        \Hyperf\DbConnection\Db::table('mistake_items')->delete();
        \Hyperf\DbConnection\Db::table('mistake_students')->delete();

        $now = DatasetReader::now();
        $idByCode = [];

        foreach ($students as $order => $student) {
            $record = MistakeStudent::create([
                'code' => (string) $student['code'],
                'name' => (string) $student['name'],
                'relation' => (string) ($student['relation'] ?? ''),
                'detail_html' => (string) ($student['detailHtml'] ?? ''),
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $idByCode[(string) $student['code']] = (int) $record->id;
        }

        $sort = 0;
        foreach ($items as $item) {
            $studentId = $idByCode[(string) ($item['studentCode'] ?? '')] ?? null;
            if (! $studentId) {
                continue;
            }
            MistakeItem::create([
                'student_id' => $studentId,
                'module' => (string) ($item['module'] ?? ''),
                'chapter' => (string) ($item['chapter'] ?? ''),
                'chapter_no' => (int) ($item['chapterNo'] ?? 0),
                'source_no' => (string) ($item['sourceNo'] ?? ''),
                'kaodian' => mb_substr((string) ($item['kaodian'] ?? ''), 0, 191),
                'stem' => (string) ($item['stem'] ?? ''),
                'options' => $item['options'] ?? [],
                'my_answer' => (string) ($item['myAnswer'] ?? ''),
                'correct_answer' => (string) ($item['correctAnswer'] ?? ''),
                'q_type' => (string) ($item['qType'] ?? ''),
                'error_type' => (string) ($item['errorType'] ?? ''),
                'action' => (string) ($item['action'] ?? ''),
                'sort_order' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($handbooks as $order => $handbook) {
            $studentId = $idByCode[(string) ($handbook['studentCode'] ?? '')] ?? null;
            if (! $studentId) {
                continue;
            }
            MistakeHandbook::create([
                'student_id' => $studentId,
                'module' => (string) ($handbook['module'] ?? ''),
                'title' => (string) ($handbook['title'] ?? ''),
                'html' => (string) ($handbook['html'] ?? ''),
                'sections' => $handbook['sections'] ?? [],
                'source_file' => (string) ($handbook['sourceFile'] ?? ''),
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        echo sprintf(
            '[MistakeSeeder] 考生 %d 名 / 错题 %d 道 / 提分手册 %d 份' . PHP_EOL,
            MistakeStudent::query()->count(),
            MistakeItem::query()->count(),
            MistakeHandbook::query()->count()
        );
    }

    private function seedMocks(): void
    {
        $rows = DatasetReader::list('mocks.json');
        if ($rows === []) {
            echo '[MistakeSeeder] 跳过模拟卷：dataset 缺失' . PHP_EOL;
            return;
        }

        \Hyperf\DbConnection\Db::table('mock_questions')->delete();
        \Hyperf\DbConnection\Db::table('mocks')->delete();

        $now = DatasetReader::now();

        foreach ($rows as $order => $mock) {
            $record = \App\Model\Mock::create([
                'slug' => (string) $mock['slug'],
                'title' => (string) $mock['title'],
                'summary' => mb_substr((string) ($mock['summary'] ?? ''), 0, 500),
                'total_score' => (int) ($mock['totalScore'] ?? 100),
                'duration_minutes' => (int) ($mock['durationMinutes'] ?? 180),
                'question_count' => (int) ($mock['questionCount'] ?? 0),
                'answered_count' => (int) ($mock['answeredCount'] ?? 0),
                'source_file' => (string) ($mock['sourceFile'] ?? ''),
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rowsToInsert = [];
            foreach ($mock['questions'] ?? [] as $question) {
                $rowsToInsert[] = [
                    'mock_id' => (int) $record->id,
                    'no' => (int) $question['no'],
                    'type' => (string) ($question['type'] ?? ''),
                    'type_cn' => (string) ($question['typeCn'] ?? ''),
                    'score' => (float) ($question['score'] ?? 0),
                    'module' => (string) ($question['module'] ?? ''),
                    'module_name' => (string) ($question['moduleName'] ?? ''),
                    'kaodian' => mb_substr((string) ($question['kaodian'] ?? ''), 0, 191),
                    'stem' => (string) ($question['stem'] ?? ''),
                    'options' => DatasetReader::json($question['options'] ?? []),
                    'answer' => (string) ($question['answer'] ?? ''),
                    'analysis' => (string) ($question['analysis'] ?? ''),
                ];
            }
            if ($rowsToInsert !== []) {
                \Hyperf\DbConnection\Db::table('mock_questions')->insert($rowsToInsert);
            }
        }

        echo sprintf(
            '[MistakeSeeder] 模拟卷 %d 份 / 题 %d 道' . PHP_EOL,
            \App\Model\Mock::query()->count(),
            \App\Model\MockQuestion::query()->count()
        );
    }

    /**
     * 初始管理员：admin@guanlan.local / guanlan2027。
     * 首次登录后请在后台改密（见 CHANGELOG「已知限制」）。
     */
    private function seedAdmin(): void
    {
        $email = 'admin@guanlan.local';
        if (User::query()->where('email', $email)->exists()) {
            echo '[MistakeSeeder] 管理员已存在，跳过' . PHP_EOL;
            return;
        }

        User::create([
            'email' => $email,
            'password_hash' => password_hash('guanlan2027', PASSWORD_DEFAULT),
            'display_name' => '管理员',
            'role' => 'admin',
            'status' => 'active',
        ]);

        echo '[MistakeSeeder] 已创建管理员 admin@guanlan.local' . PHP_EOL;
    }
}
