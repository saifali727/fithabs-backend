<?php

namespace App\Console\Commands;

use App\Models\UserFavorite;
use Illuminate\Console\Command;

class CheckFavorites extends Command
{
    protected $signature = 'favorites:check';
    protected $description = 'Check user favorites data integrity';

    public function handle()
    {
        $this->info('Checking user favorites...');
        
        $favorites = UserFavorite::all();
        
        $this->info("Total favorites: {$favorites->count()}");
        
        foreach ($favorites as $favorite) {
            $exists = $favorite->favoritable ? '✓' : '✗';
            $this->line("{$exists} ID: {$favorite->id} | Type: {$favorite->favoritable_type} | Item ID: {$favorite->favoritable_id}");
            
            if (!$favorite->favoritable) {
                $this->warn("  → Item not found in database");
            }
        }
        
        return 0;
    }
}
