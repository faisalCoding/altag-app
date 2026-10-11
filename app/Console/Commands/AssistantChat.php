<?php

namespace App\Console\Commands;

use App\Ai\Agents\PersonlanAssistant;
use App\Ai\Tools\showMagicLinks;
use App\Support\MagicLinkTray;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Ai\Streaming\Events\TextDelta;

use function Laravel\Prompts\stream;
use function Laravel\Prompts\task;
use function Laravel\Prompts\text;
use function Laravel\Prompts\title;

#[Signature('chat')]
#[Description('Command description')]
class AssistantChat extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        title('Chat With Ai Assistant');
        $this->newLine();

        $assistant = PersonlanAssistant::make();

        while (true) {
            $input = text(
                label: 'You',
                placeholder: 'Ask a question...',
                required: true,
            );

            if ($input === 'exit') {
                break;
            }

            $events = task('thinking', function () use ($assistant, $input) {
                $collect = [];

                $assistant->stream($input)->each(function ($event) use (&$collect) {

                    $collect[] = $event;
                });

                return $collect;
            });

            $output = stream();

            foreach ($events as $event) {
                if ($event instanceof TextDelta) {

                    $output->append($event->delta);
                }
            }
            $output->close();
            $this->newLine();

            // Links the assistant chose to show are made here, never passed through the model.
            foreach (app(MagicLinkTray::class)->take() as $batch) {
                $this->table(['الاسم', 'الرابط'], showMagicLinks::linksFor($batch['role'], $batch['ids'])
                    ->map(fn (array $person) => [$person['name'], $person['link'] ?? '—'])
                    ->all());
            }

        }
    }
}
