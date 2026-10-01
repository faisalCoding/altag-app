<x-layouts::auth :title="__('التحقق بخطوتين')">
    <div class="flex flex-col gap-6">
        <div class="relative w-full h-auto" x-cloak x-data="{
            showRecoveryInput: @js($errors->has('recovery_code')),
            code: '',
            recovery_code: '',
            toggleInput() {
                this.showRecoveryInput = !this.showRecoveryInput;
        
                this.code = '';
                this.recovery_code = '';
        
                $dispatch('clear-2fa-auth-code');
        
                $nextTick(() => {
                    this.showRecoveryInput ?
                        this.$refs.recovery_code?.focus() :
                        $dispatch('focus-2fa-auth-code');
                });
            },
        }">
            <div x-show="!showRecoveryInput">
                <x-auth-header :title="__('رمز التحقق')" :description="__('أدخل الرمز المكوّن من ستة أرقام الذي يظهر في تطبيق المصادقة.')" />
            </div>

            <div x-show="showRecoveryInput">
                <x-auth-header :title="__('رمز الاسترداد')" :description="__('أكّد دخولك إلى حسابك بإدخال أحد رموز الاسترداد الاحتياطية.')" />
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}">
                @csrf

                <div class="space-y-5 text-center">
                    <div x-show="!showRecoveryInput">
                        <div class="flex items-center justify-center my-5">
                            <flux:otp x-model="code" length="6" name="code" dir="ltr" label="{{ __('رمز التحقق') }}" label:sr-only
                                class="mx-auto" />
                        </div>
                    </div>

                    <div x-show="showRecoveryInput">
                        <div class="my-5">
                            <flux:input type="text" name="recovery_code" x-ref="recovery_code"
                                x-bind:required="showRecoveryInput" autocomplete="one-time-code"
                                x-model="recovery_code" />
                        </div>

                        @error('recovery_code')
                            <flux:text color="red">
                                {{ $message }}
                            </flux:text>
                        @enderror
                    </div>

                    <flux:button variant="primary" type="submit" class="w-full">
                        {{ __('متابعة') }}
                    </flux:button>
                </div>

                <div class="mt-5 space-x-0.5 text-sm leading-5 text-center">
                    <span class="opacity-50">{{ __('أو يمكنك') }}</span>
                    <div class="inline font-medium underline cursor-pointer opacity-80">
                        <span x-show="!showRecoveryInput"
                            @click="toggleInput()">{{ __('الدخول برمز استرداد') }}</span>
                        <span x-show="showRecoveryInput"
                            @click="toggleInput()">{{ __('الدخول برمز التحقق') }}</span>
                    </div>
                </div>
            </form>
        </div>
    </div>
    </x-layouts::auth>
