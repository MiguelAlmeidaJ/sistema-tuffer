<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Media\SiteUploadService;
use App\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

final class BrandController extends Controller
{
    public function index(): string
    {
        $brands = Database::connection()->query(
            "SELECT b.*,(SELECT COUNT(*) FROM products p WHERE p.brand_id=b.id) products
             FROM brands b ORDER BY CASE WHEN LOWER(b.slug)='tuffer' THEN 0 WHEN LOWER(b.slug)='linda-flor' THEN 1 ELSE 2 END,b.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        return $this->page('admin/brands/index','layouts/admin',['pageTitle'=>'Marcas','brands'=>$brands]);
    }

    public function create(): string
    {
        return $this->form();
    }

    public function edit(string $id): string
    {
        $stmt=Database::connection()->prepare('SELECT * FROM brands WHERE id=?');
        $stmt->execute([(int)$id]);
        $brand=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$brand) {
            http_response_code(404);
            return $this->page('errors/404','layouts/admin',['pageTitle'=>'Marca não encontrada','path'=>'marcas']);
        }
        return $this->form($brand);
    }

    public function store(): string
    {
        $pdo=Database::connection();
        $uploads=new SiteUploadService();
        $newCover='';
        try {
            [$name,$slug,$status]=$this->validated();
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO brands(name,slug,status) VALUES(?,?,?)')->execute([$name,$slug,$status]);
            $id=(int)$pdo->lastInsertId();
            if ($this->hasUpload()) {
                $newCover=$uploads->store($_FILES['cover'],'platform/site',3145728);
                $pdo->prepare('UPDATE brands SET cover_image_path=? WHERE id=?')->execute([$newCover,$id]);
            }
            $pdo->commit();
            Session::flash('success','Marca cadastrada.');
            return Response::redirect('/admin/marcas/'.$id.'/editar');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newCover!=='') $uploads->deleteManaged($newCover);
            Session::flash('old',$_POST);
            Session::flash('error',$exception instanceof RuntimeException?$exception->getMessage():'Não foi possível salvar a marca.');
            return Response::redirect('/admin/marcas/nova');
        }
    }

    public function update(string $id): string
    {
        $pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT * FROM brands WHERE id=?');
        $stmt->execute([(int)$id]);
        $current=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            Session::flash('error','Marca não encontrada.');
            return Response::redirect('/admin/marcas');
        }
        $newCover='';
        $uploads=new SiteUploadService();
        try {
            [$name,$slug,$status]=$this->validated();
            $cover=!empty($_POST['remove_cover'])?null:($current['cover_image_path']??null);
            if ($this->hasUpload()) {
                $newCover=$uploads->store($_FILES['cover'],'platform/site',3145728);
                $cover=$newCover;
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE brands SET name=?,slug=?,status=?,cover_image_path=? WHERE id=?')
                ->execute([$name,$slug,$status,$cover,(int)$id]);
            $pdo->commit();
            if (!empty($current['cover_image_path']) && $current['cover_image_path']!==$cover) {
                $uploads->deleteManaged((string)$current['cover_image_path']);
            }
            Session::flash('success','Marca atualizada.');
        } catch(Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newCover!=='') $uploads->deleteManaged($newCover);
            Session::flash('old',$_POST);
            Session::flash('error',$exception instanceof RuntimeException?$exception->getMessage():'Não foi possível atualizar a marca.');
        }
        return Response::redirect('/admin/marcas/'.(int)$id.'/editar');
    }

    private function form(?array $brand=null): string
    {
        return $this->page('admin/brands/form','layouts/admin',[
            'pageTitle'=>$brand?'Editar marca':'Nova marca',
            'brand'=>$brand,
        ]);
    }

    private function validated(): array
    {
        $name=trim((string)($_POST['name']??''));
        if ($name==='' || mb_strlen($name)>150) throw new RuntimeException('Informe o nome da marca (até 150 caracteres).');
        $slug=Str::slug((string)($_POST['slug']??'')?:$name);
        if ($slug==='' || mb_strlen($slug)>170) throw new RuntimeException('Não foi possível gerar o endereço da marca.');
        $status=(string)($_POST['status']??'active');
        if (!in_array($status,['active','inactive'],true)) throw new RuntimeException('Status inválido.');
        return [$name,$slug,$status];
    }

    private function hasUpload(): bool
    {
        return isset($_FILES['cover']) && is_array($_FILES['cover'])
            && ($_FILES['cover']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
    }
}
