<?php

declare(strict_types=1);

namespace PublishGo\Core;

/**
 * Validação + sanitização server-side. Regras declarativas estilo "required|email|max:120".
 * Lança HttpException 422 com os erros agregados.
 */
final class Validator
{
    /** @var array<string,string[]> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @return array<string,mixed>  Dados validados e sanitizados.
     */
    public static function validate(array $data, array $rules): array
    {
        $v = new self($data, $rules);
        return $v->run();
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $isRequired = in_array('required', $rules, true);

            if (($value === null || $value === '') && !$isRequired) {
                if (array_key_exists($field, $this->data)) {
                    $this->validated[$field] = $value;
                }
                continue;
            }

            // Campos declarados como string têm min/max medidos por comprimento,
            // mesmo que o valor "pareça" numérico (ex.: senha "123456").
            $forceString = in_array('string', $rules, true);
            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule, $forceString);
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $this->sanitize($value);
            }
        }

        if ($this->errors !== []) {
            throw HttpException::unprocessable('Os dados enviados são inválidos.', $this->errors);
        }

        return $this->validated;
    }

    private function applyRule(string $field, mixed $value, string $rule, bool $forceString = false): void
    {
        [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

        switch ($name) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                    $this->fail($field, 'Campo obrigatório.');
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    $this->fail($field, 'Deve ser um texto.');
                }
                break;
            case 'email':
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->fail($field, 'E-mail inválido.');
                }
                break;
            case 'numeric':
                if (!is_numeric($value)) {
                    $this->fail($field, 'Deve ser numérico.');
                }
                break;
            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->fail($field, 'Deve ser um número inteiro.');
                }
                break;
            case 'min':
                if (is_numeric($value) && !$forceString) {
                    if ((float) $value < (float) $arg) {
                        $this->fail($field, "Mínimo de {$arg}.");
                    }
                } elseif (mb_strlen((string) $value) < (int) $arg) {
                    $this->fail($field, "Mínimo de {$arg} caracteres.");
                }
                break;
            case 'max':
                if (is_numeric($value) && !$forceString) {
                    if ((float) $value > (float) $arg) {
                        $this->fail($field, "Máximo de {$arg}.");
                    }
                } elseif (mb_strlen((string) $value) > (int) $arg) {
                    $this->fail($field, "Máximo de {$arg} caracteres.");
                }
                break;
            case 'in':
                $options = explode(',', (string) $arg);
                if (!in_array((string) $value, $options, true)) {
                    $this->fail($field, 'Valor não permitido.');
                }
                break;
            case 'array':
                if (!is_array($value)) {
                    $this->fail($field, 'Deve ser uma lista.');
                }
                break;
            case 'latitude':
                if (!is_numeric($value) || (float) $value < -90 || (float) $value > 90) {
                    $this->fail($field, 'Latitude inválida.');
                }
                break;
            case 'longitude':
                if (!is_numeric($value) || (float) $value < -180 || (float) $value > 180) {
                    $this->fail($field, 'Longitude inválida.');
                }
                break;
        }
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            // Remove caracteres de controle e normaliza espaços nas pontas.
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
            return trim($value);
        }
        return $value;
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
