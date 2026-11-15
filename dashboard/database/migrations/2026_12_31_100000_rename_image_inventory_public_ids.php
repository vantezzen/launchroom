<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Image inventories shared the public-id prefix `inv` with team invitations,
 * so their ids weren't self-describing (docs/07). Inventories now use `img`.
 * An inventory id is only ever output (security page, the read-only API's
 * service inventory, which is a new inventory per scan): no route, audit
 * event or daemon message takes or stores one, so existing rows are renamed.
 * Invitation ids keep `inv`: audit events name them, and those never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE image_inventories SET public_id = 'img_' || substr(public_id, 5) WHERE public_id LIKE 'inv\\_%'");
    }

    public function down(): void
    {
        DB::statement("UPDATE image_inventories SET public_id = 'inv_' || substr(public_id, 5) WHERE public_id LIKE 'img\\_%'");
    }
};
