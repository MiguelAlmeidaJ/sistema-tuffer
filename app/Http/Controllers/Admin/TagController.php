<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Support\Str;
use Throwable;

final class TagController extends Controller
{
    private const TYPES = ['material','feature','style','occasion','commercial','audience','collection','administrative'];

    public function index(): string
    {
        $tags=Database::connection()->query('SELECT t.*,(SELECT COUNT(*) FROM product_tags pt WHERE pt.tag_id=t.id) products FROM tags t ORDER BY FIELD(t.type,\'audience\',\'material\',\'feature\',\'occasion\',\'style\',\'collection\',\'commercial\',\'administrative\'),t.name')->fetchAll();
        return $this->page('admin/tags/index','layouts/admin',['pageTitle'=>'Tags','tags'=>$tags]);
    }

    public function create(): string { return $this->form(); }

    public function store(): string
    {
        $type=$this->type();
        try {
            Database::connection()->prepare("INSERT INTO tags(name,slug,type,is_admin_only,status) VALUES(?,?,?,?,'active')")->execute([trim((string)$_POST['name']),Str::slug((string)($_POST['slug']?:$_POST['name'])),$type,isset($_POST['is_admin_only'])?1:0]);
            Session::flash('success','Tag criada.');
        } catch(Throwable) { Session::flash('error','Não foi possível criar a tag.'); }
        return Response::redirect('/admin/tags');
    }

    public function edit(string $id): string
    {
        $statement=Database::connection()->prepare('SELECT * FROM tags WHERE id=?');
        $statement->execute([(int)$id]);
        return $this->form($statement->fetch()?:null);
    }

    public function update(string $id): string
    {
        try {
            Database::connection()->prepare('UPDATE tags SET name=?,slug=?,type=?,is_admin_only=?,status=? WHERE id=?')->execute([trim((string)$_POST['name']),Str::slug((string)$_POST['slug']),$this->type(),isset($_POST['is_admin_only'])?1:0,$_POST['status']??'active',(int)$id]);
            Session::flash('success','Tag atualizada.');
        } catch(Throwable) { Session::flash('error','Não foi possível atualizar a tag.'); }
        return Response::redirect('/admin/tags');
    }

    private function type(): string
    {
        return in_array($_POST['type']??'',self::TYPES,true)?(string)$_POST['type']:'feature';
    }

    private function form(?array $tag=null): string
    {
        return $this->page('admin/tags/form','layouts/admin',['pageTitle'=>$tag?'Editar tag':'Criar tag','tag'=>$tag,'types'=>self::TYPES]);
    }
}
