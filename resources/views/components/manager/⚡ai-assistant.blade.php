<?php

use Livewire\Component;
use App\Ai\Agents\PersonlanAssistant;
use App\Ai\Tools\showMagicLinks;
use App\Support\MagicLinkTray;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * The manager's chat with the assistant. A question is answered in two
 * requests: the first shows it at once with an empty reply beneath, the
 * second streams the reply into that reply — which must already be on the
 * page to be streamed into. The assistant reads the earlier turns, so a
 * follow-up question means what it says; a finished reply is drawn from its
 * markdown, tables and lists included. Login links the assistant chose to
 * show are drawn here, from the server, and never pass through the model.
 */
new class extends Component
{
    /** How many earlier question-and-answer turns the assistant reads. */
    private const HISTORY_TURNS = 10;

    private const NOTICE = '⚠️ ';

    public string $input = '';

    /** @var array<int, array{role: string, content: string, links?: array<int, array{role: string, ids: array<int, int>}>}> */
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

        if ($input === '' || $this->awaitingReply()) {
            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $input];
        $this->messages[] = ['role' => 'ai', 'content' => ''];
        $this->input = '';

        $this->js('$wire.respond()');
    }

    /** Stream the answer to the question just asked into the reply waiting for it. */
    public function respond()
    {
        if (! $this->awaitingReply()) {
            return;
        }

        $replyIndex = array_key_last($this->messages);
        $question = $this->messages[$replyIndex - 1]['content'];
        $assistant = new PersonlanAssistant($this->history($replyIndex - 1));
        $fullReply = '';

        // A provider failure mid-stream must land in the chat bubble as a
        // readable message, not as a 500 that loses the whole conversation.
        try {
            foreach ($assistant->stream($question) as $event) {
                if ($event instanceof TextDelta) {
                    $fullReply .= $event->delta;
                    // Streamed text lands through innerHTML, and the reply can
                    // quote anything the tools read — names, form answers.
                    $this->stream(
                        to: "chat-reply-{$replyIndex}",
                        content: e($event->delta),
                    );
                }
            }
        } catch (RateLimitedException $e) {
            report($e);
            $fullReply = $this->failureNotice(
                $fullReply,
                'تجاوزت حصة الطلبات لدى مزوّد الذكاء الاصطناعي. أعد المحاولة بعد قليل.'
            );
        } catch (AiException | ConnectionException $e) {
            report($e);
            $fullReply = $this->failureNotice(
                $fullReply,
                'تعذّر الاتصال بمزوّد الذكاء الاصطناعي. أعد المحاولة.'
            );
        } catch (\Throwable $e) {
            // The assistant reaches the database through its tools; a failure in
            // any of them should cost one reply, not the whole conversation.
            report($e);
            $fullReply = $this->failureNotice(
                $fullReply,
                'حدث خطأ أثناء تجهيز الإجابة. أعد المحاولة.'
            );
        }

        $this->messages[$replyIndex]['content'] = trim($fullReply) === ''
            ? self::NOTICE.'لم يصل رد من المساعد. أعد المحاولة.'
            : $fullReply;

        $links = app(MagicLinkTray::class)->take();

        if ($links !== []) {
            $this->messages[$replyIndex]['links'] = $links;
        }
    }

    /** A reply drawn from its markdown, with any HTML in it shown as text. */
    public function markdown(string $content): string
    {
        return Str::markdown($content, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /** The last message is an empty reply, waiting on the question before it. */
    private function awaitingReply(): bool
    {
        $last = array_key_last($this->messages);

        return $last !== null && $last > 0
            && $this->messages[$last]['role'] === 'ai' && $this->messages[$last]['content'] === ''
            && ($this->messages[$last - 1]['role'] ?? null) === 'user';
    }

    /**
     * The turns before the question, for the assistant to read: the greeting
     * left out, a turn whose reply failed left out whole, and only the latest.
     *
     * @return array<int, Message>
     */
    private function history(int $before): array
    {
        $turns = [];

        for ($i = 1; $i + 1 < $before; $i++) {
            $question = $this->messages[$i];
            $answer = $this->messages[$i + 1];

            if ($question['role'] !== 'user' || $answer['role'] !== 'ai') {
                continue;
            }

            $reply = trim(Str::before($answer['content'], self::NOTICE));

            if ($reply !== '') {
                $turns[] = [new Message('user', $question['content']), new Message('assistant', $reply)];
            }

            $i++;
        }

        return array_merge(...array_slice($turns, -self::HISTORY_TURNS));
    }

    /**
     * Append the failure notice to whatever text had already streamed, so a
     * partial answer is kept rather than thrown away.
     */
    private function failureNotice(string $partialReply, string $notice): string
    {
        $notice = self::NOTICE.$notice;

        return trim($partialReply) === ''
            ? $notice
            : trim($partialReply)."\n\n".$notice;
    }
};
?>

@php
    $roleLabels = ['student' => 'طلاب', 'teacher' => 'معلمين', 'supervisor' => 'مشرفين', 'guardian' => 'أولياء أمور'];
@endphp

<div class="flex flex-col h-[600px] border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 rounded-xl overflow-hidden shadow-xs" dir="rtl">
    <div class="p-4 border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex items-center gap-3">
        <flux:icon.sparkles class="w-5 h-5 text-indigo-500" />
        <flux:heading size="md" class="font-bold">المساعد الذكي</flux:heading>
    </div>

    <!-- Messages: kept scrolled to the latest, streamed text included. -->
    <div class="flex-1 p-4 overflow-y-auto space-y-4" id="chat-messages" x-data
        x-init="new MutationObserver(() => { $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true, characterData: true })">
        @foreach($messages as $index => $message)
            <div wire:key="message-{{ $index }}" class="flex gap-4 {{ $message['role'] === 'user' ? 'flex-row-reverse' : '' }}">
                <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 {{ $message['role'] === 'user' ? 'bg-zinc-100 dark:bg-zinc-800' : 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-400' }}">
                    @if($message['role'] === 'user')
                        <flux:icon.user class="w-5 h-5 text-zinc-500" />
                    @else
                        <flux:icon.sparkles class="w-5 h-5" />
                    @endif
                </div>

                <div class="flex flex-col gap-2 {{ $message['role'] === 'user' ? 'items-end' : 'items-start' }} max-w-[85%] min-w-0">
                    <div class="px-4 py-3 rounded-2xl max-w-full {{ $message['role'] === 'user' ? 'bg-indigo-600 text-white rounded-tr-none' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 rounded-tl-none' }}">
                        @if($message['role'] === 'user')
                            <div class="text-sm text-right whitespace-pre-wrap wrap-break-word leading-relaxed">{{ $message['content'] }}</div>
                        @elseif($message['content'] === '')
                            {{-- Waiting: the reply streams in here as plain text; the dots show until the first word. --}}
                            <div wire:stream="chat-reply-{{ $index }}" class="peer text-sm text-right whitespace-pre-wrap wrap-break-word leading-relaxed"></div>
                            <div class="hidden peer-empty:flex items-center gap-1 py-1.5" role="status" aria-label="المساعد يكتب">
                                <span class="size-1.5 rounded-full bg-zinc-400 animate-bounce"></span>
                                <span class="size-1.5 rounded-full bg-zinc-400 animate-bounce [animation-delay:150ms]"></span>
                                <span class="size-1.5 rounded-full bg-zinc-400 animate-bounce [animation-delay:300ms]"></span>
                            </div>
                        @else
                            <div wire:stream="chat-reply-{{ $index }}" data-chat-markdown @class([
                                'text-sm text-right wrap-break-word leading-relaxed space-y-2',
                                '[&_p]:my-1 [&_strong]:font-bold [&_h1]:font-bold [&_h2]:font-bold [&_h3]:font-bold [&_h1]:text-base [&_h2]:text-base',
                                '[&_ul]:list-disc [&_ul]:ps-5 [&_ol]:list-decimal [&_ol]:ps-5 [&_li]:my-0.5',
                                '[&_table]:block [&_table]:max-w-full [&_table]:overflow-x-auto [&_table]:border-collapse [&_table]:text-xs',
                                '[&_th]:border [&_th]:border-zinc-300 [&_th]:dark:border-zinc-600 [&_th]:bg-zinc-200/70 [&_th]:dark:bg-zinc-700 [&_th]:px-2 [&_th]:py-1 [&_th]:text-start',
                                '[&_td]:border [&_td]:border-zinc-300 [&_td]:dark:border-zinc-600 [&_td]:px-2 [&_td]:py-1',
                                '[&_code]:font-mono [&_code]:text-xs [&_a]:underline',
                            ])>{!! $this->markdown($message['content']) !!}</div>
                        @endif
                    </div>

                    @foreach($message['links'] ?? [] as $batch)
                        @php
                            // Made here, on the server, for this page alone: never part of the component's state.
                            $people = showMagicLinks::linksFor($batch['role'], $batch['ids']);
                            $all = $people->filter(fn ($person) => $person['link'])->map(fn ($person) => $person['name'].': '.$person['link'])->implode("\n");
                        @endphp
                        <div wire:key="links-{{ $index }}-{{ $loop->index }}" x-data="{ copied: null }" data-magic-links
                            class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-3 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:icon.link class="w-4 h-4 text-indigo-500" />
                                <span class="text-sm font-bold">روابط دخول {{ $roleLabels[$batch['role']] ?? '' }}</span>
                                <span class="text-xs text-zinc-500">{{ \App\Support\HijriDate::arabicDigits($people->count()) }}</span>
                                <span class="flex-1"></span>
                                @if($all !== '')
                                    <flux:button size="xs" variant="ghost" icon="clipboard-document"
                                        x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($all) }}); copied = 'all'">
                                        <span x-text="copied === 'all' ? 'نُسخت' : 'نسخ الكل'"></span>
                                    </flux:button>
                                @endif
                            </div>
                            <p class="text-xs text-zinc-500">تُعرض من الموقع مباشرة، ولم تُرسل إلى مزوّد الذكاء الاصطناعي. كل رابط يُدخل صاحبه دون كلمة مرور.</p>
                            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($people as $person)
                                    <li class="flex items-center gap-2 py-1.5 text-sm">
                                        <span class="font-medium">{{ $person['name'] }}</span>
                                        @if($person['circle'])
                                            <span class="text-xs text-zinc-500">{{ $person['circle'] }}</span>
                                        @endif
                                        <span class="flex-1"></span>
                                        @if($person['link'])
                                            <button type="button" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                                                x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($person['link']) }}); copied = {{ $loop->index }}"
                                                x-text="copied === {{ $loop->index }} ? 'نُسخ' : 'نسخ الرابط'">نسخ الرابط</button>
                                        @else
                                            <span class="text-xs text-zinc-400">سُحب رابطه</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
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
