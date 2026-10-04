<?php
declare(strict_types=1);
namespace Mayuan;
final class Content {
 public static function validate(mixed $c):array{
 if(!is_array($c))throw new ApiError(422,'invalid_content','内容必须为对象');if(!is_string($c['contentVersion']??null)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$c['contentVersion']))throw new ApiError(422,'invalid_content','内容版本无效');
 foreach(['modules','nodes','relations','comparisons','exercises','sources'] as $k)if(!isset($c[$k])||!is_array($c[$k])||!array_is_list($c[$k]))throw new ApiError(422,'invalid_content','内容集合格式无效：'.$k);
 foreach($c['modules']??[] as $m)foreach(['title','subtitle','color'] as $field)if(!is_string($m[$field]??null))throw new ApiError(422,'invalid_content','章节字段无效');$ids=[];foreach(['modules','nodes','relations','comparisons','exercises'] as $k){$ids[$k]=[];foreach($c[$k] as $v){$id=$v['id']??null;if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$id)||isset($ids[$k][$id]))throw new ApiError(422,'invalid_content','对象 ID 无效或重复');$ids[$k][$id]=true;}}
 if(!$c['nodes']||!$c['exercises'])throw new ApiError(422,'invalid_content','知识与练习不能为空');
 $ref=function($id,$kind='nodes')use($ids){if(!is_string($id)||!isset($ids[$kind][$id]))throw new ApiError(422,'invalid_reference','内容引用不存在');};
 foreach($c['nodes'] as $n){$ref($n['module']??null,'modules');foreach(['title','summary','detail','method','trap','example'] as $field)if(!isset($n[$field])||!is_string($n[$field])||strlen($n[$field])>30000)throw new ApiError(422,'invalid_content','知识点字段无效');if(!is_array($n['sources']??null))throw new ApiError(422,'invalid_content','知识来源集合无效');self::sources($n['sources']);}
 foreach($c['relations'] as $r){$ref($r['from']??null);$ref($r['to']??null);foreach(['label','explanation','condition','trap'] as $field)if(!is_string($r[$field]??null))throw new ApiError(422,'invalid_content','关系说明无效');}
 foreach($c['comparisons'] as $r){$ref($r['left']??null);$ref($r['right']??null);if(!is_string($r['title']??null)||!is_array($r['rows']??null))throw new ApiError(422,'invalid_content','辨析格式无效');foreach($r['rows'] as $row)foreach(['label','left','right'] as $field)if(!is_string($row[$field]??null))throw new ApiError(422,'invalid_content','辨析行字段无效');}
 foreach($c['exercises'] as $q){if(!is_array($q['nodes']??null)||!$q['nodes'])throw new ApiError(422,'invalid_content','练习引用无效');foreach($q['nodes'] as $id)$ref($id);if(!is_string($q['prompt']??null)||!is_string($q['explanation']??null)||!is_array($q['options']??null)||count($q['options'])<2||!is_int($q['answer']??null)||$q['answer']<0||$q['answer']>=count($q['options']))throw new ApiError(422,'invalid_content','练习答案或选项无效');foreach($q['options'] as $o)if(!is_string($o))throw new ApiError(422,'invalid_content','选项必须为文本');}
 self::sources($c['sources']);return $c;
 }
 private static function sources(array $sources):void{foreach($sources as $s)if(!is_array($s)||!is_string($s['label']??null)||!is_string($s['locator']??null)||!is_string($s['file']??null))throw new ApiError(422,'invalid_content','来源字段无效');}
 public static function publicContent(array $c):array{foreach($c['exercises'] as &$q)unset($q['answer'],$q['explanation'],$q['reason']);foreach($c['sources'] as &$source)unset($source['file']);foreach($c['nodes'] as &$node)foreach($node['sources'] as &$source)unset($source['file']);return $c;}
}
