<?php

namespace App\Console\Commands;

use App\Models\Ayah;
use App\Models\Surah;
use App\Services\QuranPlanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncQuranData extends Command
{
    protected $signature = 'app:sync-quran';

    protected $description = 'Sync Quran Surahs and Ayahs from Quran.com API';

    public function handle(QuranPlanService $plans): int
    {
        $this->info('Starting Quran Data Sync...');

        // 1. Fetch Surahs
        $this->info('Fetching Surahs meta-data...');
        $response = Http::get('https://api.quran.com/api/v4/chapters?language=ar');

        if (! $response->successful()) {
            $this->error('Failed to fetch surahs from API. الخادم لم يصل إلى api.quran.com.');

            // Reported as a failure, not merely printed: a deploy script that
            // reads the exit code must not take a sync that fetched nothing for
            // a sync that worked.
            return self::FAILURE;
        }

        $chapters = $response->json()['chapters'];

        $bar = $this->output->createProgressBar(count($chapters));
        $bar->start();

        foreach ($chapters as $chapter) {
            $surah = Surah::updateOrCreate(
                ['id' => $chapter['id']],
                [
                    'number' => $chapter['id'],
                    'name_arabic' => $chapter['name_arabic'],
                    'name_simple' => $chapter['name_simple'],
                    'revelation_place' => $chapter['revelation_place'],
                    'revelation_order' => $chapter['revelation_order'],
                    'verses_count' => $chapter['verses_count'],
                    'start_page' => $chapter['pages'][0],
                    'end_page' => $chapter['pages'][1],
                ]
            );

            // 2. Fetch Verses for this Surah
            $this->syncAyahs($surah);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // The plan wizard reads its surah list through a forever-cache. Without
        // this, a copy of the app where somebody opened the wizard before the
        // first sync keeps serving the empty list it cached then — the data is
        // in the database and the page stays blank.
        $plans->forgetReferenceData();

        $this->info('Quran Data Sync Completed Successfully! ('.Surah::count().' سورة، '.Ayah::count().' آية)');

        return self::SUCCESS;
    }

    private function syncAyahs(Surah $surah)
    {
        // Each surah can have many verses, we might need to handle pagination if total verses > per_page default (usually 10)
        // Quran API v4 verses/by_chapter/{id} usually returns all verses if we set per_page high or just enough
        // Fatiha has 7, Baqara has 286.
        $page = 1;

        do {
            $response = Http::get("https://api.quran.com/api/v4/verses/by_chapter/{$surah->id}", [
                'language' => 'ar',
                'words' => 'true',
                'fields' => 'text_uthmani,juz_number,hizb_number,rub_number,page_number,ruku_number,manzil_number,sajdah_number,line_number',
                'per_page' => 100,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                break;
            }

            $data = $response->json();
            $verses = $data['verses'];

            foreach ($verses as $v) {
                $lineNumberStart = null;
                $lineNumberEnd = null;
                if (isset($v['words']) && is_array($v['words'])) {
                    // Extract line start
                    foreach ($v['words'] as $word) {
                        if ($word['char_type_name'] === 'word') {
                            $lineNumberStart = $word['line_number'];
                            break;
                        }
                    }
                    // Extract line end
                    foreach (array_reverse($v['words']) as $word) {
                        if ($word['char_type_name'] === 'end' || $word['char_type_name'] === 'word') {
                            $lineNumberEnd = $word['line_number'];
                            break;
                        }
                    }
                }

                Ayah::updateOrCreate(
                    ['id' => $v['id']],
                    [
                        'surah_id' => $surah->id,
                        'verse_number' => $v['verse_number'],
                        'verse_key' => $v['verse_key'],
                        'juz_number' => $v['juz_number'],
                        'hizb_number' => $v['hizb_number'],
                        'rub_number' => $v['rub_el_hizb_number'],
                        'page_number' => $v['page_number'],
                        'ruku_number' => $v['ruku_number'],
                        'manzil_number' => $v['manzil_number'],
                        'sajdah_type' => $v['sajdah_number'] ? ($v['sajdah_number'] > 0 ? 'required' : null) : null,
                        'text_uthmani' => $v['text_uthmani'],
                        'line_number_start' => $lineNumberStart,
                        'line_number_end' => $lineNumberEnd,
                    ]
                );
            }

            $page++;
        } while ($page <= $data['pagination']['total_pages']);
    }
}
