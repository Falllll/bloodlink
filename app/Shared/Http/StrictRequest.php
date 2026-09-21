<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator as ValidationValidator;

abstract class StrictRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /** @return array<int, string> */
    protected function allowedExtraKeys(): array
    {
        return ['password_confirmation'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (ValidationValidator $validator): void {
            $ruleKeys = array_keys($this->rules());

            $allowedKeys = [];

            foreach ($ruleKeys as $key) {
                $allowedKeys[] = explode('.', $key, 2)[0];
            }

            $allowedKeys = array_unique([
                ...$allowedKeys,
                ...$this->allowedExtraKeys(),
            ]);

            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, $allowedKeys, true)) {
                    $validator->addFailure((string) $key, 'not_allowed');
                }
            }
        });
    }
}
