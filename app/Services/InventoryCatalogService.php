<?php

namespace App\Services;

use App\Models\InventoryItem;

class InventoryCatalogService
{
    /**
     * Register a new item in the master catalog.
     */
    public function createItem(array $data): InventoryItem
    {
        return InventoryItem::create($data);
    }
}
