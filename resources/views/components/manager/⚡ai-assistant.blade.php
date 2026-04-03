<?php

use Livewire\Component;
use App\Ai\Agents\PersonlanAssistant;
use Laravel\Ai\Streaming\Events\TextDelta;

new class extends Component
{
    public string $input = '';
    public array $messages = [];

    public function mount()
    {
        $this->messages[] = [
            'role' => 'ai',
            'content' => 'مرحباً بك! أنا المساعد الذكي، كيف يمكنني مساعدتك اليوم؟',
        ];
    }

    public function ask()
    {
        $input = trim($this->input);
        if (empty($input)) {
            return;
        }

        $this->messages[] = [
            'role' => 'user',
            'content' => $input,
        ];
        
        $this->input = '';

        // Add empty AI message
        $this->messages[] = [
            'role' => 'ai',
            'content' => '',
        ];
        $replyIndex = count($this->messages) - 1;

        $assistant = new PersonlanAssistant();
        
        $stream = $assistant->stream($input);
        
        $fullReply = '';

        $this->stream(
            to: "chat-reply-{$replyIndex}",
            content: '',
            replace: true
        );

        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                $fullReply .= $event->delta;
                $this->stream(
                    to: "chat-reply-{$replyIndex}",
                    content: $event->delta,
                );
            }
        }
        
        $this->messages[$replyIndex]['content'] = $fullReply;
    }
};
?>

<div class="flex flex-col h-[600px] border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 rounded-xl overflow-hidden shadow-xs" dir="rtl">
    <div class="p-4 border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex items-center gap-3">
        <flux:icon.sparkles class="w-5 h-5 text-indigo-500" />
        <flux:heading size="md" class="font-bold">المساعد الذكي</flux:heading>
    </div>

    <!-- Messages -->
    <div class="flex-1 p-4 overflow-y-auto space-y-4" id="chat-messages" x-data x-init="$watch('$wire.messages', () => { setTimeout(() => { $el.scrollTop = $el.scrollHeight; }, 100); })">
        @foreach($messages as $index => $message)
            <div class="flex gap-4 {{ $message['role'] === 'user' ? 'flex-row-reverse' : '' }}">
                <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 {{ $message['role'] === 'user' ? 'bg-zinc-100 dark:bg-zinc-800' : 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-400' }}">
                    @if($message['role'] === 'user')
                        <flux:icon.user class="w-5 h-5 text-zinc-500" />
                    @else
                        <flux:icon.sparkles class="w-5 h-5" />
                    @endif
                </div>

                <div class="flex flex-col {{ $message['role'] === 'user' ? 'items-end' : 'items-start' }} max-w-[80%]">
                    <div class="px-4 py-3 rounded-2xl {{ $message['role'] === 'user' ? 'bg-indigo-600 text-white rounded-tr-none' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 rounded-tl-none' }}">
                        <div class="prose prose-sm dark:prose-invert max-w-none text-right whitespace-pre-wrap wrap-break-word leading-relaxed" @if($message['role'] === 'ai') wire:stream="chat-reply-{{ $index }}" @endif>{!! $message['content'] !!}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Input -->
    <div class="p-4 bg-white dark:bg-zinc-900 border-t border-zinc-200 dark:border-zinc-800">
        <form wire:submit="ask" class="relative flex items-center gap-3">
            <div class="flex-1 relative">
                <flux:input 
                    wire:model="input" 
                    placeholder="اكتب رسالتك هنا..." 
                    class="w-full"
                    autocomplete="off"
                />
            </div>
            
            <flux:button 
                type="submit" 
                class="!bg-indigo-500 !hover:bg-indigo-600 text-white shrink-0" 
                aria-label="إرسال"
                wire:loading.attr="disabled"
            >
                <flux:icon.paper-airplane class="w-4 h-4 rtl:rotate-180" />
            </flux:button>
            
        </form>
    </div>
</div>