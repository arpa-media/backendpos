<?php

namespace App\Support;

final class Permissions
{
    /**
     * Daftar permission untuk Fase 1.
     * Catatan: ini boleh bertambah di milestone berikutnya (M2+),
     * tapi jangan ubah nama yang sudah dipakai untuk menjaga stabilitas.
     */
    public const LIST = [
        // Auth
        'auth.me',

        // Admin / system
        'admin.access',

        // Fase 1 (akan dipakai di milestone berikutnya)
        'outlet.view',
        'outlet.update',

        'category.view',
        'category.create',
        'category.update',
        'category.delete',

        'product.view',
        'product.create',
        'product.update',
        'product.delete',

        'pos.checkout',

        'sales.view',
        'dashboard.view',
    ];

    private function __construct()
    {
        // static only
    }
}
