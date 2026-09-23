<?php

declare(strict_types=1);

use App\Model\Paper;
use App\Model\Question;
use Hyperf\Database\Seeders\Seeder;
use Hyperf\DbConnection\Db;

/**
 * 真题回顾数据源：storage/dataset/{papers,questions}.json
 * 派生数据，每次全量重建（先清空再分批插入），保证与离线导入器一致。
 */
class PaperQuestionSeeder extends Seeder
{
    private const CHUNK = 200;

    public function run(): void
    {
        $papers = $this->read('papers.json');
        $questions = $this->read('questions.json');

        if ($papers === [] || $questions === []) {
            $this->log('跳过：storage/dataset/{papers,questions}.json 不存在。先运行 tools/ingest/build_all.py。');
            return;
        }

        Db::table('questions')->delete();
        Db::table('papers')->delete();

        $now = date('Y-m-d H:i:s');

        foreach (array_chunk($papers, self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $paper) {
                $rows[] = [
                    'pid' => $paper['pid'],
                    'year' => (int) $paper['year'],
                    'label' => (string) ($paper['label'] ?? ''),
                    'kind' => (string) ($paper['kind'] ?? ''),
                    'question_count' => (int) ($paper['question_count'] ?? 0),
                    'total_score' => (int) ($paper['total_score'] ?? 0),
                    'answered_count' => (int) ($paper['answered_count'] ?? 0),
                    'sections' => json_encode($paper['sections'] ?? [], JSON_UNESCAPED_UNICODE),
                    'sort_order' => (int) ($paper['sort_order'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Db::table('papers')->insert($rows);
        }

        foreach (array_chunk($questions, self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $question) {
                $rows[] = [
                    'pid' => (string) $question['pid'],
                    'year' => (int) $question['year'],
                    'label' => (string) ($question['label'] ?? ''),
                    'no' => (int) $question['no'],
                    'type' => (string) ($question['type'] ?? ''),
                    'type_cn' => (string) ($question['type_cn'] ?? ''),
                    'sec' => (string) ($question['sec'] ?? ''),
                    'score' => (float) ($question['score'] ?? 0),
                    'module' => (string) ($question['module'] ?? ''),
                    'module_name' => (string) ($question['module_name'] ?? ''),
                    'super' => (string) ($question['super'] ?? ''),
                    'super_name' => (string) ($question['super_name'] ?? ''),
                    'module_conf' => (string) ($question['module_conf'] ?? ''),
                    'kaodian' => mb_substr((string) ($question['kaodian'] ?? ''), 0, 191),
                    'answer' => (string) ($question['answer'] ?? ''),
                    'trap' => (string) ($question['trap'] ?? ''),
                    'n_opt' => (int) ($question['n_opt'] ?? 0),
                    'options' => json_encode($question['options'] ?? [], JSON_UNESCAPED_UNICODE),
                    'stem' => (string) ($question['stem'] ?? ''),
                    'material' => (string) ($question['material'] ?? ''),
                    'answer_text' => (string) ($question['answer_text'] ?? ''),
                    'analysis' => (string) ($question['analysis'] ?? ''),
                    'accuracy' => (string) ($question['accuracy'] ?? ''),
                    'n_tried' => (string) ($question['n_tried'] ?? ''),
                    'q_src' => mb_substr((string) ($question['q_src'] ?? ''), 0, 191),
                    'in_stats' => (int) ($question['in_stats'] ?? 1),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Db::table('questions')->insert($rows);
        }

        $this->log(sprintf(
            '真题：试卷 %d 份 / 题目 %d 道（Paper %d, Question %d）',
            count($papers),
            count($questions),
            Paper::query()->count(),
            Question::query()->count()
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function read(string $name): array
    {
        $path = BASE_PATH . '/storage/dataset/' . $name;
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function log(string $message): void
    {
        echo '[PaperQuestionSeeder] ' . $message . PHP_EOL;
    }
}
