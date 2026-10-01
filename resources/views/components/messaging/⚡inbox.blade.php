<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Services\MessagingService;
use Flux\Flux;
use Livewire\Component;
use Livewire\Attributes\Locked;

new class extends Component
{
    #[Locked]
    public ?int $selectedConversationId = null;

    public string $newMessageBody = '';

    public bool $composing = false;

    public string $recipientSearch = '';

    public function selectConversation(int $id): void
    {
        $this->selectedConversationId = $id;
        $this->composing = false;
    }

    public function startComposing(): void
    {
        $this->composing = true;
        $this->selectedConversationId = null;
        $this->recipientSearch = '';
    }

    public function startConversation(string $type, int $id): void
    {
        $me = MessagingService::currentParticipant();

        if (! MessagingService::isAllowedToMessage($me['type'], $me['id'], $type, $id)) {
            Flux::toast('لا يمكنك مراسلة هذا الشخص — لا توجد علاقة تنظيمية بينكما (نفس الحلقة/الإشراف)', variant: 'danger');

            return;
        }

        $conversation = Conversation::findOrCreateBetween($me['type'], $me['id'], $type, $id);

        $this->selectedConversationId = $conversation->id;
        $this->composing = false;
        $this->recipientSearch = '';
    }

    public function sendMessage(): void
    {
        $this->validate(['newMessageBody' => ['required', 'string', 'max:2000']]);

        $me = MessagingService::currentParticipant();

        $belongs = ConversationParticipant::where('conversation_id', $this->selectedConversationId)
            ->where('participant_type', $me['type'])
            ->where('participant_id', $me['id'])
            ->exists();

        if (! $belongs) {
            Flux::toast('تعذّر إرسال الرسالة — هذه المحادثة غير متاحة لك', variant: 'danger');

            return;
        }

        Message::create([
            'conversation_id' => $this->selectedConversationId,
            'sender_type' => $me['type'],
            'sender_id' => $me['id'],
            'body' => $this->newMessageBody,
        ]);

        $other = ConversationParticipant::where('conversation_id', $this->selectedConversationId)
            ->where(function ($q) use ($me) {
                $q->where('participant_type', '!=', $me['type'])->orWhere('participant_id', '!=', $me['id']);
            })
            ->first();

        if ($other) {
            $senderModel = MessagingService::resolveModel($me['type'], $me['id']);
            \App\Services\NotificationService::notify(
                $other->participant_type,
                $other->participant_id,
                'new_message',
                'رسالة جديدة',
                'رسالة جديدة من '.($senderModel?->name ?? 'مستخدم'),
                route("{$other->participant_type}.messages"),
            );
        }

        $this->newMessageBody = '';
    }

    public function with(): array
    {
        $me = MessagingService::currentParticipant();
        $conversationIds = MessagingService::conversationIdsFor($me['type'], $me['id']);

        $conversations = Conversation::whereIn('id', $conversationIds)
            ->orderByDesc('last_message_at')
            ->with(['participants', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->get()
            ->map(function (Conversation $conversation) use ($me) {
                $other = $conversation->participants->first(fn ($p) => ! ($p->participant_type === $me['type'] && $p->participant_id === $me['id']));
                $otherModel = $other ? MessagingService::resolveModel($other->participant_type, $other->participant_id) : null;
                $mine = $conversation->participants->first(fn ($p) => $p->participant_type === $me['type'] && $p->participant_id === $me['id']);
                $lastMessage = $conversation->messages->first();

                $isFromOther = $lastMessage && ! ($lastMessage->sender_type === $me['type'] && $lastMessage->sender_id === $me['id']);
                $unread = $isFromOther && (! $mine?->last_read_at || $lastMessage->created_at->gt($mine->last_read_at));

                return [
                    'id' => $conversation->id,
                    'other_name' => $otherModel?->name ?? __('مستخدم غير متاح'),
                    'other_role' => $other ? MessagingService::ROLE_LABELS[$other->participant_type] : '',
                    'last_message' => $lastMessage?->body,
                    'last_message_at' => $lastMessage?->created_at,
                    'unread' => (bool) $unread,
                ];
            });

        $messages = collect();
        $selectedConversation = null;

        if ($this->selectedConversationId && in_array($this->selectedConversationId, $conversationIds, true)) {
            $messages = Message::where('conversation_id', $this->selectedConversationId)
                ->orderBy('created_at')
                ->get()
                ->map(function (Message $message) use ($me) {
                    $senderModel = MessagingService::resolveModel($message->sender_type, $message->sender_id);

                    return [
                        'id' => $message->id,
                        'body' => $message->body,
                        'created_at' => $message->created_at,
                        'sender_name' => $senderModel?->name ?? __('مستخدم غير متاح'),
                        'is_mine' => $message->sender_type === $me['type'] && $message->sender_id === $me['id'],
                    ];
                });

            ConversationParticipant::where('conversation_id', $this->selectedConversationId)
                ->where('participant_type', $me['type'])
                ->where('participant_id', $me['id'])
                ->update(['last_read_at' => now()]);

            $selectedConversation = $conversations->firstWhere('id', $this->selectedConversationId);
        }

        $directoryResults = $this->composing
            ? MessagingService::searchDirectory($this->recipientSearch, $me['type'], $me['id'])
            : [];

        return [
            'me' => $me,
            'conversations' => $conversations,
            'messages' => $messages,
            'selectedConversation' => $selectedConversation,
            'directoryResults' => $directoryResults,
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div class="flex items-center gap-3">
        <div class="p-2.5 rounded-xl bg-maroon/10 text-maroon dark:bg-white/10 dark:text-white">
            <flux:icon icon="envelope" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ __('الرسائل') }}</flux:heading>
            <flux:subheading class="text-zinc-400">{{ __('الرئيسية') }}</flux:subheading>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-6 h-[calc(100dvh-280px)] min-h-[500px]" wire:poll.5s>
    {{-- قائمة المحادثات --}}
    <flux:card class="flex flex-col p-0 overflow-hidden">
        <div class="p-4 border-b border-zinc-100 dark:border-zinc-800">
            <flux:button wire:click="startComposing" variant="primary" class="w-full !bg-maroon hover:!bg-burgundy" icon="pencil-square">
                {{ __('محادثة جديدة') }}
            </flux:button>
        </div>

        <div class="flex-1 overflow-y-auto">
            @forelse($conversations as $conversation)
                <button
                    wire:click="selectConversation({{ $conversation['id'] }})"
                    wire:key="conv-{{ $conversation['id'] }}"
                    class="w-full text-start flex items-center gap-3 px-4 py-3 border-b border-zinc-50 dark:border-zinc-800/60 hover:bg-zinc-50 dark:hover:bg-zinc-800/60 transition-colors {{ $selectedConversationId === $conversation['id'] ? 'bg-maroon/5 dark:bg-maroon/10' : '' }}">
                    <div class="size-10 rounded-full bg-maroon/10 dark:bg-white/10 flex items-center justify-center shrink-0 font-bold text-maroon dark:text-white text-sm">
                        {{ mb_substr($conversation['other_name'], 0, 1) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $conversation['other_name'] }}</span>
                            @if($conversation['unread'])
                                <span class="size-2 rounded-full bg-rose-500 shrink-0"></span>
                            @endif
                        </div>
                        <div class="text-xs text-zinc-400 truncate">{{ $conversation['other_role'] }}</div>
                        @if($conversation['last_message'])
                            <div class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">{{ $conversation['last_message'] }}</div>
                        @endif
                    </div>
                </button>
            @empty
                <p class="text-sm text-zinc-400 text-center py-10 px-4">{{ __('لا توجد محادثات بعد. ابدأ محادثة جديدة.') }}</p>
            @endforelse
        </div>
    </flux:card>

    {{-- المحادثة الحالية / بدء محادثة جديدة --}}
    <flux:card class="flex flex-col p-0 overflow-hidden">
        @if($composing)
            <div class="p-4 border-b border-zinc-100 dark:border-zinc-800">
                <flux:input wire:model.live.debounce.300ms="recipientSearch" placeholder="{{ __('ابحث بالاسم...') }}" icon="magnifying-glass" />
            </div>
            <div class="flex-1 overflow-y-auto">
                @forelse($directoryResults as $person)
                    <button
                        wire:click="startConversation('{{ $person['type'] }}', {{ $person['id'] }})"
                        wire:key="person-{{ $person['type'] }}-{{ $person['id'] }}"
                        class="w-full text-start flex items-center gap-3 px-4 py-3 border-b border-zinc-50 dark:border-zinc-800/60 hover:bg-zinc-50 dark:hover:bg-zinc-800/60 transition-colors">
                        <div class="size-9 rounded-full bg-maroon/10 dark:bg-white/10 flex items-center justify-center shrink-0 font-bold text-maroon dark:text-white text-sm">
                            {{ mb_substr($person['name'], 0, 1) }}
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $person['name'] }}</div>
                            <div class="text-xs text-zinc-400">{{ $person['role_label'] }}</div>
                        </div>
                    </button>
                @empty
                    <div class="text-center py-10 px-4">
                        <p class="text-sm text-zinc-400">{{ __('لا توجد نتائج.') }}</p>
                        @if(mb_strlen(trim($recipientSearch)) > 0)
                            <p class="text-xs text-zinc-400 mt-1">{{ __('تقدر بس تراسل مين له علاقة تنظيمية بيك (نفس الحلقة/الإشراف).') }}</p>
                        @endif
                    </div>
                @endforelse
            </div>
        @elseif($selectedConversation)
            <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center gap-3">
                <div class="size-9 rounded-full bg-maroon/10 dark:bg-white/10 flex items-center justify-center shrink-0 font-bold text-maroon dark:text-white text-sm">
                    {{ mb_substr($selectedConversation['other_name'], 0, 1) }}
                </div>
                <div>
                    <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $selectedConversation['other_name'] }}</div>
                    <div class="text-xs text-zinc-400">{{ $selectedConversation['other_role'] }}</div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 space-y-3" x-data x-init="$el.scrollTop = $el.scrollHeight" x-on:livewire:updated="$el.scrollTop = $el.scrollHeight">
                @forelse($messages as $message)
                    <div class="flex {{ $message['is_mine'] ? 'justify-start' : 'justify-end' }}">
                        <div class="max-w-[75%] rounded-2xl px-4 py-2 {{ $message['is_mine'] ? 'bg-maroon text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-100' }}">
                            <p class="text-sm whitespace-pre-wrap break-words">{{ $message['body'] }}</p>
                            <p class="text-[10px] mt-1 {{ $message['is_mine'] ? 'text-white/70' : 'text-zinc-400' }}">
                                {{ $message['created_at']->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400 text-center py-10">{{ __('ابدأ الحديث الآن.') }}</p>
                @endforelse
            </div>

            <form wire:submit="sendMessage" class="p-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center gap-2">
                <flux:input wire:model="newMessageBody" placeholder="{{ __('اكتب رسالتك...') }}" class="flex-1" autocomplete="off" />
                <flux:button type="submit" variant="primary" icon="paper-airplane" class="!bg-maroon hover:!bg-burgundy shrink-0" />
            </form>
        @else
            <div class="flex-1 flex items-center justify-center">
                <p class="text-sm text-zinc-400">{{ __('اختر محادثة أو ابدأ محادثة جديدة.') }}</p>
            </div>
        @endif
    </flux:card>
    </div>
</div>
