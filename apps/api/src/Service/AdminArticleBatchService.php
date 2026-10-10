<?php
declare(strict_types=1);
namespace App\Service;

use App\Support\Auth;
use App\Support\ContentStatus;
use Hyperf\DbConnection\Db;

/** Status-only batches: each item is atomic, failures are explicit and never auto-retried. */
class AdminArticleBatchService
{
    public function __construct(private AdminArticleService $articles, private AdminAuditService $audit) {}

    public function updateStatus(string $kind, array $data): array
    {
        $this->articles->authorize();
        $this->articles->model($kind);
        $status=$data['status']??null;$items=$data['items']??null;
        if(!is_string($status)||!in_array($status,ContentStatus::ALL,true)||!is_array($items)||count($items)<1||count($items)>100) throw new \RuntimeException('请选择1至100篇文章及有效发布状态',422);
        $seen=[];
        foreach($items as $item) {
            if(!is_array($item)||!isset($item['id'],$item['expectedRevision'])||!is_int($item['id'])||$item['id']<1||!is_int($item['expectedRevision'])||$item['expectedRevision']<1||isset($seen[$item['id']])) throw new \RuntimeException('文章编号或修订号无效、重复',422);
            $seen[$item['id']]=true;
        }
        $results=[];$succeeded=0;
        foreach($items as $item) {
            try {
                $model=Db::transaction(function()use($kind,$item,$status){
                    $model=$this->articles->save($kind,['status'=>$status,'expectedRevision'=>$item['expectedRevision']],$item['id']);
                    $this->audit->log(Auth::user(),'article.status.batch',$kind,(string)$item['id'],['status'=>$status,'revision'=>(int)$model->revision]);
                    return $model;
                });
                $results[]=['id'=>$item['id'],'ok'=>true,'status'=>200,'revision'=>(int)$model->revision];++$succeeded;
            } catch(\Throwable $e) {
                $code=in_array($e->getCode(),[403,404,409,422],true)?$e->getCode():500;
                $results[]=['id'=>$item['id'],'ok'=>false,'status'=>$code,'message'=>$code===500?'更新失败，请刷新列表后重试':$e->getMessage()];
            }
        }
        return ['results'=>$results,'succeeded'=>$succeeded,'failed'=>count($results)-$succeeded];
    }
}
