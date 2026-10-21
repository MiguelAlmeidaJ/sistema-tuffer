<?php
declare(strict_types=1);

namespace App\Services\Messaging;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Mail\QueuedMailService;
use App\Services\Stores\SellerStoreContext;
use Throwable;

final class SellerMessageNotificationService
{
    /** @return array{count:int,items:array<int,array<string,mixed>>} */
    public function unreadForCurrentUser(int $limit=8): array
    {
        $stores=(new SellerStoreContext())->stores();
        $ids=array_map(static fn(array $s):int=>(int)$s['id'],$stores);
        if (!$ids) return ['count'=>0,'items'=>[]];
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $pdo=Database::connection();
        $countSql="SELECT COUNT(*) FROM messages m JOIN users u ON u.id=m.sender_id
            JOIN conversations c ON c.id=m.conversation_id WHERE c.store_id IN ($marks)
            AND u.type='customer' AND m.read_at IS NULL";
        $count=$pdo->prepare($countSql);$count->execute($ids);
        $sql="SELECT c.id conversation_id,c.subject,st.name store_name,u.name customer_name,
            COUNT(*) unread,MAX(m.created_at) last_at
            FROM messages m JOIN users u ON u.id=m.sender_id
            JOIN conversations c ON c.id=m.conversation_id
            JOIN stores st ON st.id=c.store_id WHERE c.store_id IN ($marks)
            AND u.type='customer' AND m.read_at IS NULL
            GROUP BY c.id,c.subject,st.name,u.name ORDER BY last_at DESC LIMIT ".max(1,min(20,$limit));
        $s=$pdo->prepare($sql);$s->execute($ids);
        return ['count'=>(int)$count->fetchColumn(),'items'=>$s->fetchAll()];
    }

    public function notifyOwner(int $conversationId,int $messageId): void
    {
        try {
            $pdo=Database::connection();
            $s=$pdo->prepare("SELECT st.name store_name,u.name recipient_name,u.email recipient_email,c.subject
                FROM conversations c JOIN stores st ON st.id=c.store_id
                JOIN sellers seller ON seller.id=st.seller_id
                JOIN users u ON u.id=seller.user_id
                WHERE c.id=? LIMIT 1");
            $s->execute([$conversationId]);$to=$s->fetch();
            if (!is_array($to)) return;
            $app=rtrim((string)($_ENV['APP_URL']??getenv('APP_URL')?:''),'/');
            $url=$app!=='' ? $app.'/vendedor/mensagens/'.$conversationId : '';
            (new QueuedMailService())->enqueue(
                (string)$to['recipient_name'],(string)$to['recipient_email'],
                'Nova mensagem na loja '.(string)$to['store_name'],
                'Você recebeu uma nova mensagem sobre: '.(string)$to['subject']."\nLoja: ".$to['store_name']."\n\nAcesse o painel para responder: ".$url,
                'seller_new_message','conversation',$conversationId,
                'seller-message-'.$messageId
            );
        } catch (Throwable $e) {
            Logger::exception($e,['conversation_id'=>$conversationId,'message_id'=>$messageId],'mail');
        }
    }
}
