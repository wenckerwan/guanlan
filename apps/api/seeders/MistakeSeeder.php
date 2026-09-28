<?php

declare(strict_types=1);

use App\Model\MistakeHandbook;
use App\Model\MistakeItem;
use App\Model\MistakeStudent;
use App\Model\User;
use App\Seeder\DatasetReader;
use App\Seeder\DatasetManifestVerifier;
use App\Seeder\AdminCredentials;
use Hyperf\Database\Seeders\Seeder;

/**
 * 个人错题分析（考生之间完全隔离）+ 模拟押题 + 管理员账号。
 */
class MistakeSeeder extends Seeder
{
    public function run(): void
    {
        (new DatasetManifestVerifier())->verify();
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

        $now = DatasetReader::now();
        $idByCode = [];

        // 幂等导入考生（仅导入 code 不为纯数字的模板考生，如「A」）
        foreach ($students as $order => $student) {
            $code = (string) $student['code'];

            // 跳过纯数字账号（这些是用户账号，不应被 Seeder 覆盖）
            if (ctype_digit($code)) {
                continue;
            }

            $existing = MistakeStudent::query()->where('code', $code)->where('owner_user_id', null)->first();

            if ($existing) {
                // 更新已有模板考生
                $existing->name = (string) $student['name'];
                $existing->relation = (string) ($student['relation'] ?? '');
                $existing->detail_html = (string) ($student['detailHtml'] ?? '');
                $existing->sort_order = $order;
                $existing->updated_at = $now;
                $existing->save();
                $idByCode[$code] = (int) $existing->id;
            } else {
                // 创建新模板考生
                $record = MistakeStudent::create([
                    'code' => $code,
                    'name' => (string) $student['name'],
                    'relation' => (string) ($student['relation'] ?? ''),
                    'detail_html' => (string) ($student['detailHtml'] ?? ''),
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $idByCode[$code] = (int) $record->id;
            }
        }

        // 幂等导入错题（根据 item_key 判断更新还是新增）
        $sort = 0;
        foreach ($items as $item) {
            $studentCode = (string) ($item['studentCode'] ?? '');
            $studentId = $idByCode[$studentCode] ?? null;

            if (! $studentId) {
                continue;
            }

            $module = (string) ($item['module'] ?? '');
            $chapter = (string) ($item['chapter'] ?? '');
            $sourceNo = (string) ($item['sourceNo'] ?? '');
            $itemKey = sprintf('%s|%s|%s|%s', $studentCode, $module, $chapter, $sourceNo);

            $existing = MistakeItem::query()->where('item_key', $itemKey)->first();

            $itemData = [
                'student_id' => $studentId,
                'item_key' => $itemKey,
                'module' => $module,
                'chapter' => $chapter,
                'chapter_no' => (int) ($item['chapterNo'] ?? 0),
                'source_no' => $sourceNo,
                'kaodian' => mb_substr((string) ($item['kaodian'] ?? ''), 0, 191),
                'stem' => (string) ($item['stem'] ?? ''),
                'options' => $item['options'] ?? [],
                'my_answer' => (string) ($item['myAnswer'] ?? ''),
                'correct_answer' => (string) ($item['correctAnswer'] ?? ''),
                'q_type' => (string) ($item['qType'] ?? ''),
                'error_type' => (string) ($item['errorType'] ?? ''),
                'action' => (string) ($item['action'] ?? ''),
                'sort_order' => $sort++,
                'origin' => 'dataset',
            ];

            if ($existing) {
                // 更新已有错题
                foreach ($itemData as $key => $value) {
                    $existing->{$key} = $value;
                }
                $existing->updated_at = $now;
                $existing->save();
            } else {
                // 创建新错题
                $itemData['created_at'] = $now;
                $itemData['updated_at'] = $now;
                MistakeItem::create($itemData);
            }
        }

        // 幂等导入提分手册
        foreach ($handbooks as $order => $handbook) {
            $studentCode = (string) ($handbook['studentCode'] ?? '');
            $studentId = $idByCode[$studentCode] ?? null;

            if (! $studentId) {
                continue;
            }

            $module = (string) ($handbook['module'] ?? '');
            $sourceFile = (string) ($handbook['sourceFile'] ?? '');

            $existing = MistakeHandbook::query()
                ->where('student_id', $studentId)
                ->where('module', $module)
                ->where('source_file', $sourceFile)
                ->first();

            $handbookData = [
                'student_id' => $studentId,
                'module' => $module,
                'title' => (string) ($handbook['title'] ?? ''),
                'html' => (string) ($handbook['html'] ?? ''),
                'sections' => $handbook['sections'] ?? [],
                'source_file' => $sourceFile,
                'sort_order' => $order,
            ];

            if ($existing) {
                // 更新已有手册
                foreach ($handbookData as $key => $value) {
                    $existing->{$key} = $value;
                }
                $existing->updated_at = $now;
                $existing->save();
            } else {
                // 创建新手册
                $handbookData['created_at'] = $now;
                $handbookData['updated_at'] = $now;
                MistakeHandbook::create($handbookData);
            }
        }

        echo sprintf(
            '[MistakeSeeder] 模板考生 %d 名 / 错题 %d 道 / 提分手册 %d 份' . PHP_EOL,
            MistakeStudent::query()->whereNull('owner_user_id')->count(),
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
     * 初始管理员凭据由 AdminCredentials 从环境变量解析。
     * 本地环境使用文档化的测试账号；生产环境要求显式配置。
     */
    private function seedAdmin(): void
    {
        $credentials = AdminCredentials::fromEnvironment($_ENV + $_SERVER);
        $email = $credentials['email'];
        if (User::query()->where('email', $email)->exists()) {
            echo '[MistakeSeeder] 管理员已存在，跳过' . PHP_EOL;
            return;
        }

        User::create([
            'email' => $email,
            'password_hash' => password_hash($credentials['password'], PASSWORD_DEFAULT),
            'display_name' => $credentials['displayName'],
            'role' => 'admin',
            'status' => 'active',
        ]);

        echo '[MistakeSeeder] 已创建管理员 ' . $email . PHP_EOL;
    }
}
