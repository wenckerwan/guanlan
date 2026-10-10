<?php
declare(strict_types=1);
namespace App\Service;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/** One rendering pipeline for preview and persisted administrator bodies. */
class ArticleRenderer
{
    private const TAGS = ['p','br','hr','h1','h2','h3','h4','h5','h6','strong','b','em','i','s','del','blockquote','ul','ol','li','pre','code','a','img','table','thead','tbody','tr','th','td','sup','sub','div','span','input'];

    public function render(string $format, string $body): array
    {
        if (!in_array($format, ['html','markdown'], true)) throw new \RuntimeException('正文格式无效', 422);
        if (strlen($body)>1000000 || !mb_check_encoding($body, 'UTF-8')) throw new \RuntimeException('正文过长或编码无效', 422);
        $html=$body;
        if ($format==='markdown') {
            $converter=new GithubFlavoredMarkdownConverter([
                'html_input'=>'escape', 'allow_unsafe_links'=>false,
                'max_nesting_level'=>50, 'max_delimiters_per_line'=>500,
            ]);
            $html=(string)$converter->convert($body);
        }
        $document=new \DOMDocument('1.0','UTF-8');
        // LIBXML_NONET and no entity expansion prevent external fetches.
        $previous=libxml_use_internal_errors(true);
        try {$document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);}
        finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
        $root=$document->getElementsByTagName('body')->item(0);
        $this->sanitize($root);
        $outline=[];
        $xpath=new \DOMXPath($document);
        foreach ($xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $root) as $heading) {
            $heading->setAttribute('id','toc-'.count($outline));
            $outline[]=['level'=>(int)substr($heading->nodeName,1),'title'=>trim($heading->textContent)];
        }
        $html='';foreach($root->childNodes as $node) $html.=$document->saveHTML($node);
        return ['html'=>$html,'outline'=>$outline,'wordCount'=>mb_strlen(preg_replace('/\s+/u','',$root->textContent) ?? '')];
    }

    private function sanitize(\DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction) {$parent->removeChild($node);continue;}
            if (!$node instanceof \DOMElement) continue;
            $tag=strtolower($node->tagName);
            if (in_array($tag,['script','style','iframe','object','embed','svg','math','form','textarea','select','button','link','meta','base'],true)) {$parent->removeChild($node);continue;}
            $this->sanitize($node);
            if (!in_array($tag,self::TAGS,true)) {
                while($node->firstChild) $parent->insertBefore($node->firstChild,$node);
                $parent->removeChild($node);continue;
            }
            $allowed=['title'];
            if ($tag==='a') $allowed[]='href';
            if ($tag==='img') $allowed=array_merge($allowed,['src','alt','width','height']);
            if (in_array($tag,['th','td'],true)) $allowed=array_merge($allowed,['colspan','rowspan','align']);
            if ($tag==='ol') $allowed[]='start';
            if ($tag==='code') $allowed[]='class';
            if ($tag==='input') $allowed=array_merge($allowed,['type','checked','disabled']);
            foreach(iterator_to_array($node->attributes) as $attribute) {
                $name=strtolower($attribute->name);$value=$attribute->value;
                if (!in_array($name,$allowed,true)
                    || (in_array($name,['href','src'],true) && !$this->safeUrl($value))
                    || ($name==='class' && !preg_match('/^language-[a-zA-Z0-9_+-]+$/D',$value))
                    || (in_array($name,['width','height','colspan','rowspan','start'],true) && !preg_match('/^\d{1,5}$/D',$value))
                    || ($name==='align' && !in_array($value,['left','center','right'],true))) $node->removeAttribute($attribute->name);
            }
            if ($tag==='input') {
                // GFM task checkboxes are inert; arbitrary form inputs are removed.
                if ($node->getAttribute('type')!=='checkbox') {$parent->removeChild($node);continue;}
                $node->setAttribute('disabled','disabled');
            }
        }
    }

    private function safeUrl(string $url): bool
    {
        $url=preg_replace('/[\x00-\x20\x7f]+/','',html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8')) ?? '';
        if (str_contains($url,'\\')) return false;
        return !preg_match('/^([a-z][a-z0-9+.-]*):/i',$url,$match)
            || in_array(strtolower($match[1]),['https','http','mailto'],true);
    }
}
