<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;use App\Core\Database;use App\Core\Response;use App\Core\Session;use App\Http\Controllers\Controller;use App\Services\Stores\SellerStoreContext;

final class ChatController extends Controller
{
    public function index(): string{$store=(new SellerStoreContext())->current();$s=Database::connection()->prepare('SELECT c.*,u.name customer_name,(SELECT body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_message,(SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_id<>? AND m.read_at IS NULL) unread FROM conversations c JOIN users u ON u.id=c.customer_id WHERE c.store_id=? ORDER BY COALESCE(c.last_message_at,c.created_at) DESC');$s->execute([Auth::id(),$store['id']]);return $this->page('seller/messages/index','layouts/seller',['pageTitle'=>'Mensagens','conversations'=>$s->fetchAll(),'currentStore'=>$store]);}
    public function show(string $id): string{$conversation=$this->conversation((int)$id);$s=Database::connection()->prepare('SELECT m.*,u.name sender_name,u.type sender_type FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.created_at');$s->execute([(int)$id]);Database::connection()->prepare('UPDATE messages SET read_at=NOW() WHERE conversation_id=? AND sender_id<>? AND read_at IS NULL')->execute([(int)$id,Auth::id()]);return $this->page('seller/messages/show','layouts/seller',['pageTitle'=>$conversation['subject'],'conversation'=>$conversation,'messages'=>$s->fetchAll(),'currentStore'=>(new SellerStoreContext())->current()]);}
    public function send(string $id): string{$conversation=$this->conversation((int)$id);if($conversation['status']!=='open'){Session::flash('error','Conversa encerrada.');return Response::redirect('/vendedor/mensagens/'.$id);}Database::connection()->prepare('INSERT INTO messages(conversation_id,sender_id,body) VALUES(?,?,?)')->execute([(int)$id,Auth::id(),trim((string)$_POST['body'])]);Database::connection()->prepare('UPDATE conversations SET last_message_at=NOW() WHERE id=?')->execute([(int)$id]);return Response::redirect('/vendedor/mensagens/'.$id);}
    public function close(string $id): string{$conversation=$this->conversation((int)$id);Database::connection()->prepare("UPDATE conversations SET status='closed' WHERE id=?")->execute([$conversation['id']]);Session::flash('success','Conversa encerrada.');return Response::redirect('/vendedor/mensagens/'.$id);}
    private function conversation(int $id): array{$store=(new SellerStoreContext())->current();$s=Database::connection()->prepare('SELECT c.*,u.name customer_name,st.name store_name FROM conversations c JOIN users u ON u.id=c.customer_id JOIN stores st ON st.id=c.store_id WHERE c.id=? AND c.store_id=?');$s->execute([$id,$store['id']]);$data=$s->fetch();if(!$data){http_response_code(403);exit('Acesso negado.');}return $data;}
}
