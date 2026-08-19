<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use RuntimeException;
use Throwable;

final class CartService
{
    public function id(bool $create = false): ?int
    {
        $pdo = Database::connection();
        $token = $this->token();
        $userId = (Auth::user()['type'] ?? null) === 'customer' ? Auth::id() : null;
        $cartType = $this->mode();

        if ($userId) {
            $userCart = $pdo->prepare("SELECT id FROM carts WHERE user_id=? AND cart_type=? AND status='active' ORDER BY id DESC LIMIT 1");
            $userCart->execute([$userId, $cartType]);
            $userCartId = (int) $userCart->fetchColumn();
            $guestCart = $pdo->prepare("SELECT id FROM carts WHERE session_id=? AND user_id IS NULL AND status='active' ORDER BY id DESC LIMIT 1");
            $guestCart->execute([$token]);
            $guestCartId = (int) $guestCart->fetchColumn();

            if ($cartType === 'retail' && $guestCartId && !$userCartId) {
                $pdo->prepare('UPDATE carts SET user_id=? WHERE id=?')->execute([$userId, $guestCartId]);
                return $guestCartId;
            }
            if ($cartType === 'retail' && $guestCartId && $userCartId && $guestCartId !== $userCartId) {
                $this->merge($guestCartId, $userCartId);
            }
            if ($userCartId) {
                return $userCartId;
            }
        } else {
            $statement = $pdo->prepare("SELECT id FROM carts WHERE session_id=? AND status='active' ORDER BY id DESC LIMIT 1");
            $statement->execute([$token]);
            $cartId = (int) $statement->fetchColumn();
            if ($cartId) {
                return $cartId;
            }
        }

        if (!$create) {
            return null;
        }
        $pdo->prepare("INSERT INTO carts(user_id,session_id,currency,cart_type,status,expires_at) VALUES(?,?,'BRL',?,'active',DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$userId, $token, $cartType]);
        return (int) $pdo->lastInsertId();
    }

    public function add(int $variantId, int $quantity): void
    {
        $quantity = max(1, min(99, $quantity));
        $pdo = Database::connection();
        $statement = $pdo->prepare("SELECT pv.id,pv.price,pv.promotional_price,pv.wholesale_price,p.id product_id,p.seller_id,p.store_id,p.wholesale_enabled,p.wholesale_min_quantity,COALESCE(SUM(sk.quantity-sk.reserved_quantity),0) available FROM product_variants pv JOIN products p ON p.id=pv.product_id JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id LEFT JOIN stocks sk ON sk.product_variant_id=pv.id WHERE pv.id=? AND pv.status='active' AND p.status='active' AND p.platform_paused=0 AND st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL GROUP BY pv.id,p.id,p.seller_id,p.store_id");
        $statement->execute([$variantId]);
        $variant = $statement->fetch();
        if (!$variant) {
            throw new RuntimeException('Produto indisponível.');
        }
        $cartId = $this->id(true);
        $existing = $pdo->prepare('SELECT quantity FROM cart_items WHERE cart_id=? AND product_variant_id=?');
        $existing->execute([$cartId, $variantId]);
        $newQuantity = min((int) $variant['available'], (int) $existing->fetchColumn() + $quantity);
        if ($newQuantity < 1) {
            throw new RuntimeException('Produto sem estoque disponível.');
        }
        if ($this->mode() === 'wholesale') {
            if (!(bool) $variant['wholesale_enabled'] || $variant['wholesale_price'] === null) throw new RuntimeException('Este produto não está disponível no atacado.');
            $minimum = max(1, (int) ($variant['wholesale_min_quantity'] ?? 1));
            if ($newQuantity < $minimum) throw new RuntimeException("A quantidade mínima deste produto no atacado é {$minimum}.");
            $tier = $pdo->prepare('SELECT unit_price FROM product_wholesale_tiers WHERE product_id=? AND (variant_id IS NULL OR variant_id=?) AND minimum_quantity<=? AND (maximum_quantity IS NULL OR maximum_quantity>=?) ORDER BY minimum_quantity DESC,variant_id DESC LIMIT 1');
            $tier->execute([$variant['product_id'], $variantId, $newQuantity, $newQuantity]);
            $price = $tier->fetchColumn() ?: $variant['wholesale_price'];
        } else {
            $price = $variant['promotional_price'] ?? $variant['price'];
        }
        $pdo->prepare('INSERT INTO cart_items(cart_id,seller_id,store_id,product_variant_id,quantity,unit_price) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),unit_price=VALUES(unit_price),updated_at=NOW()')->execute([$cartId,$variant['seller_id'],$variant['store_id'],$variantId,$newQuantity,$price]);
    }

    public function update(int $itemId, int $quantity): void
    {
        $cartId = $this->id();
        if (!$cartId) return;
        if ($quantity <= 0) { $this->remove($itemId); return; }
        $pdo = Database::connection();
        $stock = $pdo->prepare('SELECT COALESCE(SUM(sk.quantity-sk.reserved_quantity),0) FROM cart_items ci JOIN stocks sk ON sk.product_variant_id=ci.product_variant_id WHERE ci.id=? AND ci.cart_id=?');
        $stock->execute([$itemId,$cartId]);
        $available = (int) $stock->fetchColumn();
        if ($available < 1) throw new RuntimeException('Produto sem estoque disponível.');
        $quantity = min(99, $available, $quantity);
        $price = null;
        if ($this->mode() === 'wholesale') {
            $details = $pdo->prepare('SELECT p.id product_id,p.wholesale_min_quantity,pv.id variant_id,pv.wholesale_price FROM cart_items ci JOIN product_variants pv ON pv.id=ci.product_variant_id JOIN products p ON p.id=pv.product_id WHERE ci.id=? AND ci.cart_id=?');
            $details->execute([$itemId, $cartId]); $product = $details->fetch();
            $minimum = max(1, (int) ($product['wholesale_min_quantity'] ?? 1));
            if ($quantity < $minimum) throw new RuntimeException("A quantidade mínima deste produto no atacado é {$minimum}.");
            $tier = $pdo->prepare('SELECT unit_price FROM product_wholesale_tiers WHERE product_id=? AND (variant_id IS NULL OR variant_id=?) AND minimum_quantity<=? AND (maximum_quantity IS NULL OR maximum_quantity>=?) ORDER BY minimum_quantity DESC,variant_id DESC LIMIT 1');
            $tier->execute([$product['product_id'], $product['variant_id'], $quantity, $quantity]);
            $price = $tier->fetchColumn() ?: $product['wholesale_price'];
        }
        $pdo->prepare('UPDATE cart_items SET quantity=?,unit_price=COALESCE(?,unit_price),updated_at=NOW() WHERE id=? AND cart_id=?')->execute([$quantity,$price,$itemId,$cartId]);
    }

    public function remove(int $itemId): void
    {
        $cartId=$this->id();if($cartId)Database::connection()->prepare('DELETE FROM cart_items WHERE id=? AND cart_id=?')->execute([$itemId,$cartId]);
    }

    public function removePaymentBlockedItems(): int
    {
        $cartId = $this->id();
        if (!$cartId) return 0;
        $statement = Database::connection()->prepare(
            "DELETE ci FROM cart_items ci JOIN sellers s ON s.id=ci.seller_id
             WHERE ci.cart_id=? AND (
                s.status<>'active' OR s.payment_enabled<>1
                OR s.payment_onboarding_status<>'active'
                OR s.pagarme_recipient_id IS NULL
             )"
        );
        $statement->execute([$cartId]);
        return $statement->rowCount();
    }

    public function saveForLater(int $itemId): void
    {
        if ((Auth::user()['type'] ?? null) !== 'customer') throw new RuntimeException('Entre como cliente para salvar produtos.');$cartId=$this->id();if(!$cartId)throw new RuntimeException('Item não encontrado.');$pdo=Database::connection();$statement=$pdo->prepare('SELECT p.id FROM cart_items ci JOIN product_variants pv ON pv.id=ci.product_variant_id JOIN products p ON p.id=pv.product_id WHERE ci.id=? AND ci.cart_id=?');$statement->execute([$itemId,$cartId]);$productId=(int)$statement->fetchColumn();if(!$productId)throw new RuntimeException('Item não encontrado.');$pdo->beginTransaction();try{$pdo->prepare('INSERT IGNORE INTO favorites(user_id,product_id) VALUES(?,?)')->execute([Auth::id(),$productId]);$pdo->prepare('DELETE FROM cart_items WHERE id=? AND cart_id=?')->execute([$itemId,$cartId]);$pdo->commit();}catch(Throwable $exception){$pdo->rollBack();throw new RuntimeException('Não foi possível salvar o produto.',0,$exception);}
    }

    /** @return array<int,array<string,mixed>> */
    public function items(): array
    {
        $cartId=$this->id();if(!$cartId)return [];
        $statement=Database::connection()->prepare("SELECT ci.id,ci.quantity,ci.unit_price,ci.seller_id,ci.store_id,p.id product_id,p.name,p.slug,p.short_description,p.wholesale_min_quantity,p.package_count,p.allow_variant_mix,pv.id variant_id,pv.sku,pv.name variant_name,COALESCE(pv.weight,p.weight,0.1) shipping_weight,COALESCE(pv.width,p.width,11) shipping_width,COALESCE(pv.height,p.height,2) shipping_height,COALESCE(pv.length,p.length,16) shipping_length,st.name store_name,st.slug store_slug,st.wholesale_min_quantity store_min_quantity,st.wholesale_min_total store_min_total,COALESCE(SUM(sk.quantity-sk.reserved_quantity),0) available,(SELECT pm.secure_url FROM product_media pm WHERE pm.product_id=p.id ORDER BY pm.is_cover DESC,pm.sort_order LIMIT 1) image_url,(SELECT GROUP_CONCAT(CONCAT(ps.name,': ',ps.value) ORDER BY ps.sort_order,ps.id SEPARATOR '||') FROM product_specifications ps WHERE ps.product_id=p.id) specifications,COALESCE((SELECT sa.postal_code FROM store_addresses sa WHERE sa.store_id=COALESCE(st.shipping_source_store_id,st.id) AND sa.is_shipping_origin=1 ORDER BY sa.id LIMIT 1),(SELECT w.postal_code FROM warehouses w WHERE w.seller_id=p.seller_id AND w.status='active' ORDER BY w.id LIMIT 1)) origin_postal_code FROM cart_items ci JOIN product_variants pv ON pv.id=ci.product_variant_id JOIN products p ON p.id=pv.product_id JOIN stores st ON st.id=ci.store_id JOIN sellers s ON s.id=ci.seller_id LEFT JOIN stocks sk ON sk.product_variant_id=pv.id WHERE ci.cart_id=? AND p.status='active' AND p.platform_paused=0 AND pv.status='active' AND st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL GROUP BY ci.id,p.id,pv.id,st.id ORDER BY st.name,ci.created_at");
        $statement->execute([$cartId]);return $statement->fetchAll();
    }

    /** @return array{items:array<int,array<string,mixed>>,groups:array<int,array<string,mixed>>,count:int,subtotal:float} */
    public function summary(): array
    {
        $items=$this->items();$groups=[];$count=0;$subtotal=0.0;
        foreach($items as $item){$line=(float)$item['unit_price']*(int)$item['quantity'];$count+=(int)$item['quantity'];$subtotal+=$line;$storeId=(int)$item['store_id'];if(!isset($groups[$storeId]))$groups[$storeId]=['store_id'=>$storeId,'store_name'=>$item['store_name'],'store_slug'=>$item['store_slug'],'origin_postal_code'=>$item['origin_postal_code'],'items'=>[],'subtotal'=>0.0,'discount'=>0.0,'coupon'=>null,'quantity'=>0,'minimum_quantity'=>(int)($item['store_min_quantity']??0),'minimum_total'=>(float)($item['store_min_total']??0)];$item['line_total']=$line;$item['piece_count']=max(1,(int)($item['package_count']??1))*(int)$item['quantity'];$item['piece_price']=$line/$item['piece_count'];$item['specification_rows']=array_values(array_filter(explode('||',(string)($item['specifications']??''))));$groups[$storeId]['items'][]=$item;$groups[$storeId]['subtotal']+=$line;$groups[$storeId]['quantity']+=(int)$item['quantity'];}
        foreach($groups as &$group)$group['minimum_met']=$this->mode()==='retail'||($group['quantity']>=($group['minimum_quantity']?:0)&&$group['subtotal']>=($group['minimum_total']?:0));unset($group);
        $discountTotal=0.0;$cartId=$this->id();
        if($cartId&&$groups){$coupons=Database::connection()->prepare("SELECT cc.store_id,c.id,c.code,c.name,c.discount_type,c.discount_value,c.funding_source,c.minimum_total,c.status,c.starts_at,c.expires_at,c.usage_limit,c.usage_count FROM cart_coupons cc JOIN coupons c ON c.id=cc.coupon_id WHERE cc.cart_id=?");$coupons->execute([$cartId]);foreach($coupons->fetchAll() as $coupon){$storeId=(int)$coupon['store_id'];if(!isset($groups[$storeId])||$coupon['status']!=='active'||($coupon['starts_at']&&strtotime($coupon['starts_at'])>time())||($coupon['expires_at']&&strtotime($coupon['expires_at'])<time())||($coupon['usage_limit']!==null&&(int)$coupon['usage_count']>=(int)$coupon['usage_limit'])||$groups[$storeId]['subtotal']<(float)$coupon['minimum_total'])continue;$discount=$coupon['discount_type']==='percentage'?$groups[$storeId]['subtotal']*((float)$coupon['discount_value']/100):min($groups[$storeId]['subtotal'],(float)$coupon['discount_value']);$discount=round(min($discount,$groups[$storeId]['subtotal']),2);$groups[$storeId]['discount']=$discount;$groups[$storeId]['coupon']=$coupon;$discountTotal+=$discount;}}
        return ['items'=>$items,'groups'=>array_values($groups),'count'=>$count,'subtotal'=>$subtotal,'discount_total'=>$discountTotal,'total'=>max(0,$subtotal-$discountTotal),'type'=>$this->mode(),'postal_code'=>$this->postalCode(),'minimums_met'=>!array_filter($groups,fn($group)=>!$group['minimum_met'])];
    }

    public function count(): int
    {
        try{$id=$this->id();if(!$id)return 0;$s=Database::connection()->prepare("SELECT COALESCE(SUM(ci.quantity),0) FROM cart_items ci JOIN products p ON p.store_id=ci.store_id AND p.seller_id=ci.seller_id JOIN product_variants pv ON pv.id=ci.product_variant_id AND pv.product_id=p.id JOIN stores st ON st.id=ci.store_id JOIN sellers se ON se.id=ci.seller_id WHERE ci.cart_id=? AND p.status='active' AND p.platform_paused=0 AND pv.status='active' AND st.status='active' AND se.status='active' AND se.payment_enabled=1 AND se.payment_onboarding_status='active' AND se.pagarme_recipient_id IS NOT NULL");$s->execute([$id]);return (int)$s->fetchColumn();}catch(Throwable){return 0;}
    }

    public function applyCoupon(string $code): void
    {
        $code=mb_strtoupper(trim($code));if($code==='')throw new RuntimeException('Informe o código do cupom.');$cartId=$this->id();if(!$cartId)throw new RuntimeException('Seu carrinho está vazio.');$pdo=Database::connection();
        $statement=$pdo->prepare("SELECT DISTINCT c.*,(SELECT SUM(ci2.quantity*ci2.unit_price) FROM cart_items ci2 WHERE ci2.cart_id=? AND ci2.store_id=c.store_id) store_subtotal FROM coupons c JOIN cart_items ci ON ci.cart_id=? AND ci.store_id=c.store_id WHERE UPPER(c.code)=? AND c.status='active' AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.expires_at IS NULL OR c.expires_at>=NOW()) AND (c.usage_limit IS NULL OR c.usage_count<c.usage_limit)");
        $statement->execute([$cartId,$cartId,$code]);$matches=$statement->fetchAll();if(!$matches)throw new RuntimeException('Cupom inválido ou indisponível para os itens do carrinho.');$applied=0;$upsert=$pdo->prepare('INSERT INTO cart_coupons(cart_id,store_id,coupon_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE coupon_id=VALUES(coupon_id),created_at=NOW()');foreach($matches as $coupon){if((float)$coupon['store_subtotal']<(float)$coupon['minimum_total'])continue;$upsert->execute([$cartId,$coupon['store_id'],$coupon['id']]);$applied++;}if(!$applied)throw new RuntimeException('O subtotal da loja ainda não atingiu o mínimo deste cupom.');
    }

    public function removeCoupon(int $storeId): void
    {
        $cartId=$this->id();if($cartId)Database::connection()->prepare('DELETE FROM cart_coupons WHERE cart_id=? AND store_id=?')->execute([$cartId,$storeId]);
    }

    public function setPostalCode(string $postalCode): void
    {
        $postalCode=preg_replace('/\D+/','',$postalCode)??'';if(strlen($postalCode)!==8)throw new RuntimeException('Informe um CEP válido com 8 números.');$cartId=$this->id();if(!$cartId)throw new RuntimeException('Seu carrinho está vazio.');Database::connection()->prepare('UPDATE carts SET shipping_postal_code=? WHERE id=?')->execute([$postalCode,$cartId]);
    }

    public function postalCode(): ?string
    {
        $cartId=$this->id();if(!$cartId)return null;$statement=Database::connection()->prepare('SELECT shipping_postal_code FROM carts WHERE id=?');$statement->execute([$cartId]);$value=$statement->fetchColumn();return is_string($value)&&$value!==''?$value:null;
    }

    public function mode(): string
    {
        if (Session::get('cart_type') !== 'wholesale') return 'retail';
        if ((Auth::user()['type'] ?? null) !== 'customer') return 'retail';
        try { $statement=Database::connection()->prepare("SELECT COUNT(*) FROM wholesale_accounts WHERE user_id=? AND status='approved'");$statement->execute([Auth::id()]);return (int)$statement->fetchColumn()===1?'wholesale':'retail'; } catch(Throwable) { return 'retail'; }
    }

    public function switchMode(string $mode): void
    {
        if ($mode === 'wholesale') {
            Session::put('cart_type', 'wholesale');
            if ($this->mode() !== 'wholesale') { Session::put('cart_type', 'retail'); throw new RuntimeException('Sua conta ainda não possui acesso aprovado ao atacado.'); }
            return;
        }
        Session::put('cart_type', 'retail');
    }

    private function token(): string
    {
        $token=Session::get('cart_token');if(!is_string($token)||$token===''){$token=bin2hex(random_bytes(32));Session::put('cart_token',$token);}return $token;
    }

    private function merge(int $sourceId,int $targetId): void
    {
        $pdo=Database::connection();$items=$pdo->prepare('SELECT * FROM cart_items WHERE cart_id=?');$items->execute([$sourceId]);foreach($items->fetchAll() as $item){$pdo->prepare('INSERT INTO cart_items(cart_id,seller_id,store_id,product_variant_id,quantity,unit_price) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE quantity=LEAST(99,quantity+VALUES(quantity)),unit_price=VALUES(unit_price)')->execute([$targetId,$item['seller_id'],$item['store_id'],$item['product_variant_id'],$item['quantity'],$item['unit_price']]);}$pdo->prepare("UPDATE carts SET status='converted' WHERE id=?")->execute([$sourceId]);
    }
}
