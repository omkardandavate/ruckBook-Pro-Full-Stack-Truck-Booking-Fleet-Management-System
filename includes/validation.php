<?php
/**
 * TruckBook Pro - Server-Side Input Validation & Sanitization Library
 */

declare(strict_types=1);

class Validator {
    private array $data;
    private array $errors = [];

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function required(string $field, string $label): self {
        $val = trim((string)($this->data[$field] ?? ''));
        if ($val === '') {
            $this->errors[$field] = "{$label} is required.";
        }
        return $this;
    }

    public function email(string $field, string $label): self {
        if (!empty($this->data[$field])) {
            $val = trim((string)$this->data[$field]);
            if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $this->errors[$field] = "{$label} must be a valid email address.";
            }
        }
        return $this;
    }

    public function phone(string $field, string $label): self {
        if (!empty($this->data[$field])) {
            $val = trim((string)$this->data[$field]);
            // Allow international format, numbers, spaces, plus, dashes, parentheses
            if (!preg_match('/^[+]?[0-9\s\-()]{7,25}$/', $val)) {
                $this->errors[$field] = "{$label} must be a valid contact phone number.";
            }
        }
        return $this;
    }

    public function numeric(string $field, string $label, ?float $min = null, ?float $max = null): self {
        if (isset($this->data[$field]) && $this->data[$field] !== '') {
            $val = $this->data[$field];
            if (!is_numeric($val)) {
                $this->errors[$field] = "{$label} must be a valid number.";
            } else {
                $num = (float)$val;
                if ($min !== null && $num < $min) {
                    $this->errors[$field] = "{$label} must be at least {$min}.";
                }
                if ($max !== null && $num > $max) {
                    $this->errors[$field] = "{$label} cannot exceed {$max}.";
                }
            }
        }
        return $this;
    }

    public function integer(string $field, string $label, ?int $min = null, ?int $max = null): self {
        if (isset($this->data[$field]) && $this->data[$field] !== '') {
            $val = filter_var($this->data[$field], FILTER_VALIDATE_INT);
            if ($val === false) {
                $this->errors[$field] = "{$label} must be an integer.";
            } else {
                if ($min !== null && $val < $min) {
                    $this->errors[$field] = "{$label} must be at least {$min}.";
                }
                if ($max !== null && $val > $max) {
                    $this->errors[$field] = "{$label} cannot exceed {$max}.";
                }
            }
        }
        return $this;
    }

    public function minLength(string $field, int $min, string $label): self {
        if (!empty($this->data[$field])) {
            if (mb_strlen((string)$this->data[$field]) < $min) {
                $this->errors[$field] = "{$label} must be at least {$min} characters long.";
            }
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self {
        if (isset($this->data[$field]) && $this->data[$field] !== '') {
            if (!in_array($this->data[$field], $allowed, true)) {
                $this->errors[$field] = "Selected {$label} is invalid.";
            }
        }
        return $this;
    }

    public function date(string $field, string $label, string $format = 'Y-m-d'): self {
        if (!empty($this->data[$field])) {
            $d = DateTime::createFromFormat($format, (string)$this->data[$field]);
            if (!$d || $d->format($format) !== (string)$this->data[$field]) {
                $this->errors[$field] = "{$label} must be a valid date in {$format} format.";
            }
        }
        return $this;
    }

    public function dateAfterOrEqual(string $field, string $otherDateField, string $label, string $otherLabel): self {
        if (!empty($this->data[$field]) && !empty($this->data[$otherDateField])) {
            $d1 = strtotime((string)$this->data[$field]);
            $d2 = strtotime((string)$this->data[$otherDateField]);
            if ($d1 < $d2) {
                $this->errors[$field] = "{$label} cannot be earlier than {$otherLabel}.";
            }
        }
        return $this;
    }

    public function isValid(): bool {
        return empty($this->errors);
    }

    public function getErrors(): array {
        return $this->errors;
    }

    public function getFirstError(): ?string {
        return !empty($this->errors) ? reset($this->errors) : null;
    }
}

/**
 * Clean string for safe HTML display
 */
function e(?string $string): string {
    return htmlspecialchars((string)($string ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Clean string for input trimming
 */
function sanitizeInput(mixed $input): mixed {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return is_string($input) ? trim($input) : $input;
}
