<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ToggleAiProvider extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:toggle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Toggle between Gemini and DeepSeek in AI config';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = config_path('ai.php');
        if (!File::exists($path)) {
            $this->error('AI config file not found!');
            return 1;
        }

        $content = File::get($path);
        
        // Determine current default by checking the 'default' key
        preg_match("/['\"]default['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $content, $matches);
        $currentProvider = $matches[1] ?? 'unknown';

        if ($currentProvider === 'deepseek') {
            $newProvider = 'gemini';
        } else {
            $newProvider = 'deepseek';
        }

        $this->info("Switching AI providers from [{$currentProvider}] to [{$newProvider}]...");

        // Replacement patterns for all default keys
        $keys = [
            'default',
            'default_for_images',
            'default_for_audio',
            'default_for_transcription',
            'default_for_embeddings',
            'default_for_reranking'
        ];

        foreach ($keys as $key) {
            $content = preg_replace("/(['\"]{$key}['\"]\s*=>\s*['\"])[^'\"]+(['\"])/", "$1{$newProvider}$2", $content);
        }

        File::put($path, $content);

        $this->info("Successfully switched all default AI providers to [{$newProvider}].");
        
        // Clear config cache to ensure the changes take effect
        $this->call('config:clear');

        return 0;
    }
}
