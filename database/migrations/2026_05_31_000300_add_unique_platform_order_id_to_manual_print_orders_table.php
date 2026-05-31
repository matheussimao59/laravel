<?php

use Illuminate\Database\Migrations\Migration;
return new class() extends Migration
{
    public function up(): void
    {
        // Pedidos agrupados podem compartilhar o mesmo numero da plataforma.
        // A recusa de pedidos ja salvos fica na API, sem apagar registros do grupo.
    }

    public function down(): void
    {
        //
    }
};
