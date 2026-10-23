<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\PasswordPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Server-side validation with a compact rule syntax, e.g.
 *   'email' => 'required|email|max:190|unique:users,email'
 *
 * Rules: required, nullable, string, min:n, max:n (string length), min_value:n,
 * max_value:n, email, date (Y-m-d), numeric, integer, in:a,b, unique:table,column[,ignoreId],
 * confirmed, same:field, different:field, phone, password.
 * The first failing rule of a field is reported.
 */
final class Validator
{
    /** @var array<string,array<int,string>> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    private function __construct(private array $data, private array $rules, private array $labels)
    {
        $this->run();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string,array<int,string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Only the fields that have rules, with strings trimmed and empty optional values as null.
     *
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = array_filter(explode('|', $ruleString), static fn (string $r): bool => $r !== '');
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            $isEmpty = $value === null || $value === '' || $value === [];
            $required = in_array('required', $rules, true);

            if ($isEmpty) {
                if ($required) {
                    $this->fail($field, 'required');
                } else {
                    $this->validated[$field] = null;
                }
                continue;
            }

            $passed = true;
            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
                if (!$this->check($name, $arg, $field, $value)) {
                    $this->fail($field, $name, $arg);
                    $passed = false;
                    break;
                }
            }
            if ($passed) {
                $this->validated[$field] = $value;
            }
        }
    }

    private function check(string $rule, string $arg, string $field, mixed $value): bool
    {
        $isScalar = is_scalar($value);
        $string = $isScalar ? (string) $value : '';

        return match ($rule) {
            'string' => is_string($value),
            'min' => $isScalar && mb_strlen($string) >= (int) $arg,
            'max' => $isScalar && mb_strlen($string) <= (int) $arg,
            'min_value' => is_numeric($value) && (float) $value >= (float) $arg,
            'max_value' => is_numeric($value) && (float) $value <= (float) $arg,
            'email' => $isScalar && filter_var($string, FILTER_VALIDATE_EMAIL) !== false,
            'date' => $isScalar && self::isDate($string),
            'numeric' => is_numeric($value),
            'integer' => $isScalar && filter_var($string, FILTER_VALIDATE_INT) !== false,
            'in' => $isScalar && in_array($string, explode(',', $arg), true),
            'unique' => $isScalar && $this->isUnique($arg, $string),
            'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $value,
            'same' => ($this->data[$arg] ?? null) === $value,
            'different' => ($this->data[$arg] ?? null) !== $value,
            'phone' => $isScalar && preg_match('/^[0-9+()\-\s.]{7,25}$/', $string) === 1
                && strlen(preg_replace('/\D/', '', $string) ?? '') >= 7,
            'password' => $isScalar && PasswordPolicy::check($string) === null,
            default => throw new InvalidArgumentException("Unknown validation rule: {$rule}"),
        };
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isUnique(string $arg, string $value): bool
    {
        [$table, $column, $ignoreId] = array_pad(explode(',', $arg), 3, '');
        $identifier = '/^[A-Za-z_][A-Za-z0-9_]*$/';
        if (!preg_match($identifier, $table) || !preg_match($identifier, $column)) {
            throw new InvalidArgumentException('Invalid unique rule definition.');
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
        $params = [$value];
        if ($ignoreId !== '') {
            $sql .= ' AND id <> ?';
            $params[] = (int) $ignoreId;
        }
        return (int) Database::fetchValue($sql, $params) === 0;
    }

    private function fail(string $field, string $rule, string $arg = ''): void
    {
        $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
        $this->errors[$field][] = match ($rule) {
            'required' => "{$label} is required.",
            'string' => "{$label} must be text.",
            'min' => "{$label} must be at least {$arg} characters.",
            'max' => "{$label} may not be longer than {$arg} characters.",
            'min_value' => "{$label} must be at least {$arg}.",
            'max_value' => "{$label} may not be greater than {$arg}.",
            'email' => "{$label} must be a valid email address.",
            'date' => "{$label} must be a valid date (YYYY-MM-DD).",
            'numeric' => "{$label} must be a number.",
            'integer' => "{$label} must be a whole number.",
            'in' => "{$label} has an invalid value.",
            'unique' => "{$label} is already in use.",
            'confirmed' => "{$label} confirmation does not match.",
            'same' => "{$label} does not match.",
            'different' => "{$label} must be different.",
            'phone' => "{$label} must be a valid phone number.",
            'password' => PasswordPolicy::check((string) ($this->data[$field] ?? '')) ?? "{$label} is not acceptable.",
            default => "{$label} is invalid.",
        };
    }
}
