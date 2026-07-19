<?php

namespace App\Services;

use App\Models\InventoryVendor;

class VendorService
{
    /**
     * Register a new vendor.
     */
    public function createVendor(array $data): InventoryVendor
    {
        return InventoryVendor::create($data);
    }
}
