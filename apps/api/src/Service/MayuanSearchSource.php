<?php
declare(strict_types=1);
namespace App\Service;
use Hyperf\DbConnection\Db;
/** Reads only the current published Mayuan content on the same MySQL instance. */
class MayuanSearchSource
{
    public function published(): ?array
    {
        $database=getenv('MAYUAN_SEARCH_DATABASE') ?: 'mayuan';
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$database)) throw new \RuntimeException('Invalid Mayuan search database');
        $row=Db::table($database.'.mayuan_records')->where('record_key','content')->first(['body']);
        if (!$row) return null;
        $record=json_decode((string)$row->body,true,512,JSON_THROW_ON_ERROR);
        return is_array($record['published']??null) ? $record['published'] : null;
    }
}
