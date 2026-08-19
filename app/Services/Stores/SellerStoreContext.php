<?php

declare(strict_types=1);

namespace App\Services\Stores;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;

final class SellerStoreContext
{
    /** @return array<int, array<string, mixed>> */
    public function stores(): array
    {
        if ((Auth::user()['type'] ?? null) === 'operator') {
            $statement = Database::connection()->prepare('SELECT st.*, s.id seller_id, s.trade_name, su.role operator_role FROM store_users su JOIN stores st ON st.id=su.store_id JOIN sellers s ON s.id=st.seller_id WHERE su.user_id=? ORDER BY st.name');
        } else {
            $statement = Database::connection()->prepare('SELECT st.*, s.id seller_id, s.trade_name FROM stores st JOIN sellers s ON s.id=st.seller_id WHERE s.user_id=? ORDER BY st.name');
        }
        $statement->execute([Auth::id()]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $stores = $this->stores();
        if (!$stores) {
            return null;
        }

        $selectedId = (int) Session::get('seller_store_id', 0);
        foreach ($stores as $store) {
            if ((int) $store['id'] === $selectedId) {
                return $store;
            }
        }

        Session::put('seller_store_id', (int) $stores[0]['id']);
        return $stores[0];
    }

    public function select(int $storeId): bool
    {
        foreach ($this->stores() as $store) {
            if ((int) $store['id'] === $storeId) {
                Session::put('seller_store_id', $storeId);
                return true;
            }
        }
        return false;
    }
}
