<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Support\Str;

final class ValidationFailures
{
    /**
     * Nama parameter per aturan, urut sesuai posisi yang dikembalikan
     * Validator::failed(). Aturan yang tidak ada di sini jatuh ke positional.
     *
     * @var array<string, array<int, string>>
     */
    private const PARAM_NAMES = [
        'min' => ['min'],
        'max' => ['max'],
        'between' => ['min', 'max'],
        'size' => ['size'],
        'digits' => ['digits'],
        'in' => ['values'],
        'not_in' => ['values'],
        'unique' => ['table', 'column'],
        'exists' => ['table', 'column'],
        'same' => ['other'],
        'different' => ['other'],
        'regex' => ['pattern'],
        'date_format' => ['format'],
        'mimes' => ['values'],
    ];

    /**
     * Aturan yang seluruh parameternya masuk ke satu nama.
     *
     * @var array<int, string>
     */
    private const VARIADIC = ['in', 'not_in', 'mimes'];

    /**
     * @param  array<string, array<string, array<int, mixed>>>  $failed
     * @return array<string, array<int, array{rule: string, params: mixed}>>
     */
    public static function fromFailed(array $failed): array
    {
        $result = [];

        foreach ($failed as $field => $rules) {
            foreach ($rules as $rule => $parameters) {
                $name = Str::snake(class_basename((string) $rule));

                $result[$field][] = [
                    'rule' => $name,
                    'params' => self::params($name, $parameters),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $parameters
     * @return array<string, mixed>|array<int, mixed>
     */
    private static function params(string $rule, array $parameters): array
    {
        if (! isset(self::PARAM_NAMES[$rule])) {
            return array_values($parameters);
        }

        $names = self::PARAM_NAMES[$rule];

        if (in_array($rule, self::VARIADIC, true)) {
            return [$names[0] => array_values($parameters)];
        }

        $named = [];

        foreach ($names as $i => $name) {
            if (array_key_exists($i, $parameters)) {
                $named[$name] = $parameters[$i];
            }
        }

        return $named;
    }
}
