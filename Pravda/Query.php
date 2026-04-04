<?php

declare(strict_types=1);

namespace Pravda;

class Query {
    public function __construct(
        private readonly string $statement,
        private readonly array $values,
        private readonly string $operation,
    ) {}

    public function statement(): string {
        return $this->statement;
    }

    public function values(): array {
        return $this->values;
    }

    public function operation(): string {
        return $this->operation;
    }

    public function toDebugString(): string {
        $statement = $this->statement;
        foreach($this->values as $value) {
            $formatted = match(true) {
                is_null($value) => "NULL",
                is_bool($value) => $value ? "TRUE" : "FALSE",
                is_string($value) => "'{$value}'",
                default => (string) $value
            };
            $statement = preg_replace('/\?/', $formatted, $statement, 1);
        }
        return $statement;
    }

    public function toArray(): array {
        return [
            "operation" => $this->operation,
            "statement" => $this->statement,
            "values" => $this->values,
        ];
    }
}