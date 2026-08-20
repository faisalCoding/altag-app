<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">إعدادات الملف الشخصي</flux:heading>

    <x-settings.layout heading="الملف الشخصي" subheading="تحديث اسمك وبريدك الإلكتروني">
        <div class="flex items-center gap-4 my-6 pb-6 border-b border-zinc-100 dark:border-zinc-800">
            <flux:avatar size="xl" circle :src="auth()->user()->avatarUrl()" :name="$name" />

            <div class="flex-1 space-y-2">
                <flux:input type="file" wire:model="avatarFile" label="الصورة الشخصية" accept="image/*" />

                @if (auth()->user()->avatarUrl())
                    <flux:button size="sm" variant="ghost" wire:click="deleteAvatar" wire:confirm="هل أنت متأكد من حذف الصورة الشخصية؟">
                        حذف الصورة
                    </flux:button>
                @endif
            </div>
        </div>

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="الاسم" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" label="البريد الإلكتروني" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            بريدك الإلكتروني غير موثّق.

                            <flux:link :accent="false" class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                اضغط هنا لإعادة إرسال رابط التوثيق.
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                تم إرسال رابط توثيق جديد إلى بريدك الإلكتروني.
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">حفظ</flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    تم الحفظ.
                </x-action-message>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
