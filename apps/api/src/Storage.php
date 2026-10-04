<?php
declare(strict_types=1);
namespace Mayuan;
final class Storage {
 public function __construct(public readonly \PDO $db){$db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'){$db->exec('PRAGMA busy_timeout=5000');}$bodyType=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'LONGTEXT':'TEXT';$db->exec('CREATE TABLE IF NOT EXISTS mayuan_records (record_key VARCHAR(191) PRIMARY KEY, body '.$bodyType.' NOT NULL)');}
 public function get(string $key,mixed $default=null):mixed{$s=$this->db->prepare('SELECT body FROM mayuan_records WHERE record_key=?');$s->execute([$key]);$v=$s->fetchColumn();return $v===false?$default:json_decode($v,true,512,JSON_THROW_ON_ERROR);}
 public function put(string $key,mixed $value):void{$body=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$sql=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT INTO mayuan_records(record_key,body) VALUES (?,?) ON DUPLICATE KEY UPDATE body=VALUES(body)':'INSERT INTO mayuan_records(record_key,body) VALUES (?,?) ON CONFLICT(record_key) DO UPDATE SET body=excluded.body';$this->db->prepare($sql)->execute([$key,$body]);}
 public function transaction(callable $f):mixed{$this->db->beginTransaction();try{$this->put('lock','write');$r=$f();$this->db->commit();return $r;}catch(\Throwable $e){$this->db->rollBack();throw $e;}}
}
