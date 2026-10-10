<?php
declare(strict_types=1);
namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Hotspot;
use App\Resource\ArticleResource;
use App\Seeder\DatasetReader;
use Hyperf\DbConnection\Db;

/** Immutable snapshots are inserted only inside the maintenance/save transaction. */
class AdminArticleHistoryService
{
    public function __construct(private AdminArticleService $articles) {}

    public static function captureBaseline(string $kind, Hotspot|AnalysisArticle $model): void
    {
        if (!self::query($kind, (int)$model->id)->where('revision', (int)$model->revision)->exists()) {
            self::capture($kind, $model, null);
        }
    }

    public static function capture(string $kind, Hotspot|AnalysisArticle $model, ?int $actor): void
    {
        Db::table('article_revisions')->insert([
            'type'=>$kind, 'article_id'=>(int)$model->id, 'revision'=>(int)$model->revision,
            'snapshot'=>json_encode(ArticleResource::adminDetail($model), JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            'admin_id'=>$actor, 'created_at'=>date('Y-m-d H:i:s'),
        ]);
    }

    private static function query(string $kind, int $id): mixed
    {
        return Db::table('article_revisions')->where('type',$kind)->where('article_id',$id);
    }

    private function decode(object $row): array
    {
        return ['revision'=>(int)$row->revision,'createdAt'=>(string)$row->created_at,
            'adminId'=>$row->admin_id===null?null:(int)$row->admin_id,
            'article'=>json_decode($row->snapshot,true,512,JSON_THROW_ON_ERROR)];
    }

    public function revisions(string $kind, int $id, int $page=1, int $perPage=20): array
    {
        $this->articles->detail($kind,$id);
        $q=self::query($kind,$id);$total=$q->count();$perPage=max(1,min(100,$perPage));
        $page=max(1,min($page,max(1,(int)ceil($total/$perPage))));$items=[];
        foreach ($q->orderByDesc('revision')->offset(($page-1)*$perPage)->limit($perPage)->get() as $row) {
            $r=$this->decode($row);$a=$r['article'];unset($r['article']);
            foreach (['title','status','format','contentSource','wordCount'] as $field) $r[$field]=$a[$field];
            $items[]=$r;
        }
        return compact('items','total','page','perPage');
    }

    public function revision(string $kind,int $id,int $revision): array
    {
        $this->articles->detail($kind,$id);
        $row=self::query($kind,$id)->where('revision',$revision)->first();
        if (!$row) throw new \RuntimeException('修订不存在',404);
        return $this->decode($row);
    }

    public function restore(string $kind,int $id,array $data): array
    {
        $this->articles->authorize();
        foreach (['revision','expectedRevision'] as $key) {
            if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key]<1) throw new \RuntimeException('版本号无效',422);
        }
        $old=$this->revision($kind,$id,$data['revision'])['article'];
        $payload=['expectedRevision'=>$data['expectedRevision']];
        $fields=$kind==='hotspot'?['title','summary','body','format','period','level','priority','type','tag','subjectId']:['title','summary','body','format','category','priority','sourceFile','release'];
        foreach ($fields as $field) $payload[$field]=$old[$field];
        // No status/commentMode payload: save reads the current locked row's values.
        return ArticleResource::adminDetail($this->articles->save($kind,$payload,$id));
    }

    public function export(string $kind,int $id,?int $revision=null): array
    {
        $a=$revision===null?$this->articles->detail($kind,$id):$this->revision($kind,$id,$revision)['article'];
        $extension=$a['format']==='markdown'?'md':'html';
        return ['format'=>$a['format'],'body'=>$a['body'],'title'=>$a['title'],'revision'=>$a['revision'],
            'fileName'=>$kind.'-'.$id.'-r'.$a['revision'].'.'.$extension];
    }

    private function sources(string $kind): array
    {
        $this->articles->authorize();$this->articles->model($kind);
        $name=$kind==='hotspot'?'hotspots.json':'analysis_articles.json';
        $sources=[];
        foreach (DatasetReader::list($name) as $row) {
            if (is_array($row)&&isset($row['slug'])&&is_string($row['slug'])) $sources[$row['slug']]=$row;
        }
        return $sources;
    }

    private function compare(string $kind,array $a,?array $source): array
    {
        $fields=[];$same=true;
        foreach (['title','summary','html',$kind==='hotspot'?'period':'category'] as $field) {
            $value=$source===null?null:(string)($source[$field]??'');
            $equal=$a[$field]===$value;$same=$same&&$equal;
            $fields[]=['field'=>$field,'databaseValue'=>$a[$field],'sourceValue'=>$value,'equal'=>$equal];
        }
        return ['sourceStatus'=>$source===null?'database-only':($same?'same':'different'),'fields'=>$fields];
    }

    public function sourceDiff(string $kind,int $id): array
    {
        $a=$this->articles->detail($kind,$id);$sources=$this->sources($kind);
        $table=$kind==='hotspot'?'hotspots':'analysis_articles';
        $marker=Db::table('content_maintenance')->where('table_name',$table)->first();
        return ['revision'=>$a['revision'],'maintained'=>(bool)($marker->maintained??false)]+$this->compare($kind,$a,$sources[$a['slug']]??null);
    }

    public function sourceDiffIndex(string $kind,int $page=1,int $perPage=20): array
    {
        $sources=$this->sources($kind);$class=$this->articles->model($kind);$items=[];
        $counts=['same'=>0,'different'=>0,'databaseOnly'=>0,'sourceOnly'=>0];
        foreach ($class::query()->orderBy('slug')->get() as $model) {
            $a=ArticleResource::adminDetail($model);$status=$this->compare($kind,$a,$sources[$a['slug']]??null)['sourceStatus'];
            $items[]=['slug'=>$a['slug'],'title'=>$a['title'],'articleId'=>$a['id'],'sourceStatus'=>$status];
            ++$counts[$status==='database-only'?'databaseOnly':$status];unset($sources[$a['slug']]);
        }
        foreach ($sources as $slug=>$source) {
            $items[]=['slug'=>$slug,'title'=>(string)($source['title']??''),'articleId'=>null,'sourceStatus'=>'source-only'];++$counts['sourceOnly'];
        }
        usort($items,fn($a,$b)=>strcmp($a['slug'],$b['slug']));
        $total=count($items);$perPage=max(1,min(100,$perPage));$page=max(1,min($page,max(1,(int)ceil($total/$perPage))));
        $items=array_slice($items,($page-1)*$perPage,$perPage);
        return compact('items','total','page','perPage','counts');
    }
}
