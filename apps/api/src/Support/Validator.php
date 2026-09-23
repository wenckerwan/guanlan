<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 极简请求校验：只覆盖本项目需要的必填、类型、长度、取值规则。
 */
class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $input */
    public function __construct(private array $input)
    {
    }

    public function required(string $field, string $label): self
    {
        $value = $this->input[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->errors[$field] = "{$label}不能为空";
        }
        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = $this->input[$field] ?? null;
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = "{$label}格式不正确";
        }
        return $this;
    }

    public function min(string $field, int $length, string $label): self
    {
        $value = $this->input[$field] ?? null;
        if (is_string($value) && $value !== '' && mb_strlen($value) < $length) {
            $this->errors[$field] = "{$label}至少 {$length} 个字符";
        }
        return $this;
    }

    public function max(string $field, int $length, string $label): self
    {
        $value = $this->input[$field] ?? null;
        if (is_string($value) && mb_strlen($value) > $length) {
            $this->errors[$field] = "{$label}最多 {$length} 个字符";
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        $value = $this->input[$field] ?? null;
        if ($value !== null && $value !== '' && ! in_array($value, $allowed, true)) {
            $this->errors[$field] = "{$label}取值不合法";
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function string(string $field, string $default = ''): string
    {
        $value = $this->input[$field] ?? $default;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $field, int $default = 0): int
    {
        $value = $this->input[$field] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }
}
