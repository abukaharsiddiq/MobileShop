<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tailor Management module mobile shop er jonno dorkar nei, tai er table gulo muche fela hocche.
     */
    public function up(): void
    {
        Schema::dropIfExists('tailor_virtual_try_ons');
        Schema::dropIfExists('tailor_measurements');
        Schema::dropIfExists('tailor_products');
        Schema::dropIfExists('tailor_categories');
    }

    public function down(): void
    {
        // Tailor module code shoho remove kora hoyeche, tai table abar toiri kora hocche na.
    }
};
