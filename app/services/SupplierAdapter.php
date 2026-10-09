<?php
namespace App\services;
interface SupplierAdapter {
    /** Authorized supplier APIs and licensed feeds should implement this contract.
     * Returns rows compatible with the documented comparison CSV schema.
     */
    public function offers(string $path): array;
}
