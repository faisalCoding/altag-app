<?php

namespace App\Concerns;

use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null, ?string $modelClass = null): array
    {
        $modelClass = $modelClass ?? (\Illuminate\Support\Facades\Auth::check() ? get_class(\Illuminate\Support\Facades\Auth::user()) : \App\Models\Manager::class);

        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique($modelClass)
                : Rule::unique($modelClass)->ignore($userId),
        ];
    }
}
