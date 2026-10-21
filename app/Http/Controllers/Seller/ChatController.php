<?php
declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Stores\SellerStoreContext;
use App\Services\Messaging\SellerMessageNotificationService;

final class ChatController extends Controller
{
    public function index(): string
    {
        $context = new SellerStoreContext();
        $stores = $context->stores();
        $ids = array_map(static fn(array $store): int => (int)$store['id'], $stores);
        $conversations = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT c.*,u.name customer_name,st.name store_name,
                (SELECT body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_message,
                (SELECT COUNT(*) FROM messages m JOIN users sender ON sender.id=m.sender_id
                  WHERE m.conversation_id=c.id AND sender.type='customer' AND m.read_at IS NULL) unread
                FROM conversations c JOIN users u ON u.id=c.customer_id JOIN stores st ON st.id=c.store_id
                WHERE c.store_id IN ($marks) ORDER BY COALESCE(c.last_message_at,c.created_at) DESC";
            $s = Database::connection()->prepare($sql);
            $s->execute($ids);
            $conversations = $s->fetchAll();
        }
        return $this->page('seller/messages/index','layouts/seller',[
            'pageTitle'=>'Mensagens','conversations'=>$conversations,
            'currentStore'=>$context->current()
        ]);
    }

    public function show(string $id): string
    {
        $conversation = $this->conversation((int)$id);
        $pdo = Database::connection();
        $s = $pdo->prepare('SELECT m.*,u.name sender_name,u.type sender_type FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.created_at,m.id');
        $s->execute([(int)$id]);
        $pdo->prepare("UPDATE messages m JOIN users sender ON sender.id=m.sender_id SET m.read_at=NOW() WHERE m.conversation_id=? AND sender.type='customer' AND m.read_at IS NULL")
            ->execute([(int)$id]);
        return $this->page('seller/messages/show','layouts/seller',[
            'pageTitle'=>$conversation['subject'],'conversation'=>$conversation,
            'messages'=>$s->fetchAll(),'currentStore'=>(new SellerStoreContext())->current()
        ]);
    }

    public function send(string $id): string
    {
        $conversation = $this->conversation((int)$id);
        if ($conversation['status']!=='open') {
            Session::flash('error','Conversa encerrada.');
            return Response::redirect('/vendedor/mensagens/'.$id);
        }
        $body = trim((string)($_POST['body']??''));
        if ($body === '') {
            Session::flash('error','Escreva uma mensagem.');
            return Response::redirect('/vendedor/mensagens/'.$id);
        }
        Database::connection()->prepare('INSERT INTO messages(conversation_id,sender_id,body) VALUES(?,?,?)')
            ->execute([(int)$id,Auth::id(),$body]);
        Database::connection()->prepare('UPDATE conversations SET last_message_at=NOW() WHERE id=?')->execute([(int)$id]);
        return Response::redirect('/vendedor/mensagens/'.$id);
    }

    public function close(string $id): string
    {
        $conversation = $this->conversation((int)$id);
        Database::connection()->prepare("UPDATE conversations SET status='closed' WHERE id=?")->execute([$conversation['id']]);
        Session::flash('success','Conversa encerrada.');
        return Response::redirect('/vendedor/mensagens/'.$id);
    }

    private function conversation(int $id): array
    {
        $context = new SellerStoreContext();
        $ids = array_map(static fn(array $store): int => (int)$store['id'], $context->stores());
        if (!$ids) { http_response_code(403); exit('Acesso negado.'); }
        $marks = implode(',', array_fill(0,count($ids),'?'));
        $s=Database::connection()->prepare("SELECT c.*,u.name customer_name,st.name store_name
            FROM conversations c JOIN users u ON u.id=c.customer_id JOIN stores st ON st.id=c.store_id
            WHERE c.id=? AND c.store_id IN ($marks)");
        $s->execute(array_merge([$id],$ids));
        $data=$s->fetch();
        if(!$data){http_response_code(403);exit('Acesso negado.');}
        $context->select((int)$data['store_id']);
        return $data;
    }
}
