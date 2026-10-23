<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tailor_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('profile_name')->default('Default'); 
            
            // Upper Body (পাঞ্জাবী/শার্ট/কামিজ/ব্লাউজ)
            $table->float('length')->nullable();         // লম্বা
            $table->float('body_chest')->nullable();     // বুক
            $table->float('waist')->nullable();          // কোমর
            $table->float('hip')->nullable();            // হিপ/ঘের (মেয়েদের জন্য জরুরি)
            $table->float('shoulder')->nullable();       // পুঁট/কাঁধ
            $table->float('sleeve_length')->nullable();  // হাতা
            $table->float('neck')->nullable();           // গলা/কলার
            $table->float('armhole')->nullable();        // বগল/মোহরা
            
            // Lower Body (প্যান্ট/পায়জামা/সালোয়ার)
            $table->float('pant_length')->nullable();    // প্যান্টের লম্বা
            $table->float('pant_waist')->nullable();     // প্যান্টের কোমর
            $table->float('thigh')->nullable();          // রান
            $table->float('ankle')->nullable();          // মুহুরী/নিচ
            $table->float('knee')->nullable();           // হাঁটু
            $table->float('crotch')->nullable();         // হাই
            
            $table->text('special_note')->nullable();    // বিশেষ কোনো চাহিদা
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tailor_measurements');
    }
};
