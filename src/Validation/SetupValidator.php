<?php

declare(strict_types=1);

namespace LaravelFunLab\Validation;

use InvalidArgumentException;

/**
 * SetupValidator
 *
 * Validates the 'with' array for LFL::setup() calls.
 * Ensures required fields are present and types are correct.
 */
class SetupValidator
{
    /**
     * Valid entity types and their aliases.
     *
     * @var array<string, array<string>>
     */
    protected const VALID_TYPES = [
        'achievement' => ['achievement'],
        'gamed-metric' => ['gamed-metric', 'metric'],
        'metric-level' => ['metric-level', 'level'],
        'metric-level-group' => ['metric-level-group', 'group'],
        'metric-level-group-level' => ['metric-level-group-level', 'group-level'],
        'metric-level-group-metric' => ['metric-level-group-metric', 'group-metric'],
        'prize' => ['prize'],
    ];

    /**
     * Validation rules for each entity type.
     *
     * Format: [
     *   'field_name' => ['required' => bool, 'type' => 'string|int|float|bool|array', 'allowed_values' => [...]]
     * ]
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected const VALIDATION_RULES = [
        'achievement' => [
            'slug' => ['required' => true, 'type' => 'string'],
            'name' => ['required' => false, 'type' => 'string'],
            'description' => ['required' => false, 'type' => 'string'],
            'icon' => ['required' => false, 'type' => 'string'],
            'for' => ['required' => false, 'type' => 'string'],
            'metadata' => ['required' => false, 'type' => 'array'],
            'active' => ['required' => false, 'type' => 'bool'],
            'hidden' => ['required' => false, 'type' => 'bool'],
            'order' => ['required' => false, 'type' => 'int'],
        ],
        'gamed-metric' => [
            'slug' => ['required' => true, 'type' => 'string'],
            'name' => ['required' => false, 'type' => 'string'],
            'description' => ['required' => false, 'type' => 'string'],
            'icon' => ['required' => false, 'type' => 'string'],
            'active' => ['required' => false, 'type' => 'bool'],
        ],
        'metric-level' => [
            'metric' => ['required' => true, 'type' => 'string'],
            'level' => ['required' => true, 'type' => 'int'],
            'xp' => ['required' => true, 'type' => 'int'],
            'name' => ['required' => false, 'type' => 'string'],
            'description' => ['required' => false, 'type' => 'string'],
        ],
        'metric-level-group' => [
            'slug' => ['required' => true, 'type' => 'string'],
            'name' => ['required' => false, 'type' => 'string'],
            'description' => ['required' => false, 'type' => 'string'],
        ],
        'metric-level-group-level' => [
            'group' => ['required' => true, 'type' => 'string'],
            'level' => ['required' => true, 'type' => 'int'],
            'xp' => ['required' => true, 'type' => 'int'],
            'name' => ['required' => false, 'type' => 'string'],
            'description' => ['required' => false, 'type' => 'string'],
        ],
        'metric-level-group-metric' => [
            'group' => ['required' => true, 'type' => 'string'],
            'metric' => ['required' => true, 'type' => 'string'],
            'weight' => ['required' => false, 'type' => 'float'],
        ],
        'prize' => [
            'slug' => ['required' => true, 'type' => 'string'],
            'name' => ['required' => false, 'type' => 'string'],
            'description' => ['required' => false, 'type' => 'string'],
            'type' => ['required' => false, 'type' => 'string'],
            'cost' => ['required' => false, 'type' => 'int|float'],
            'inventory' => ['required' => false, 'type' => 'int'],
            'metadata' => ['required' => false, 'type' => 'array'],
            'active' => ['required' => false, 'type' => 'bool'],
            'order' => ['required' => false, 'type' => 'int'],
        ],
    ];

    /**
     * Normalize entity type (handle aliases).
     */
    public static function normalizeType(string $type): string
    {
        foreach (self::VALID_TYPES as $canonical => $aliases) {
            if ($canonical === $type || in_array($type, $aliases, true)) {
                return $canonical;
            }
        }

        return $type;
    }

    /**
     * Check if an entity type is valid.
     */
    public static function isValidType(string $type): bool
    {
        $normalized = self::normalizeType($type);

        return isset(self::VALIDATION_RULES[$normalized]);
    }

    /**
     * Get all valid entity types.
     *
     * @return array<string>
     */
    public static function getValidTypes(): array
    {
        return array_keys(self::VALID_TYPES);
    }

    /**
     * Validate the 'with' array for a given entity type.
     *
     * @param  string  $type  Entity type (will be normalized)
     * @param  array<string, mixed>  $with  Configuration array
     * @return array<string, mixed> Validated and normalized array
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $type, array $with): array
    {
        // Normalize type (handle aliases)
        $normalizedType = self::normalizeType($type);

        // Check if type is valid
        if (! self::isValidType($normalizedType)) {
            $validTypes = implode(', ', self::getValidTypes());
            throw new InvalidArgumentException(
                "Unknown entity type '{$type}'. Valid types: {$validTypes}"
            );
        }

        // Get validation rules for this type
        $rules = self::VALIDATION_RULES[$normalizedType];

        // Check for unknown fields
        $unknownFields = array_diff(array_keys($with), array_keys($rules));
        if (! empty($unknownFields)) {
            $validFields = implode(', ', array_keys($rules));
            $unknownList = implode(', ', $unknownFields);
            throw new InvalidArgumentException(
                "LFL::setup('{$normalizedType}'): Unknown field(s): {$unknownList}. Valid fields: {$validFields}"
            );
        }

        // Validate required fields
        foreach ($rules as $field => $rule) {
            if ($rule['required'] && ! array_key_exists($field, $with)) {
                throw new InvalidArgumentException(
                    "LFL::setup('{$normalizedType}') requires '{$field}' in the 'with' array"
                );
            }
        }

        // Validate types
        foreach ($with as $field => $value) {
            if (! isset($rules[$field])) {
                continue; // Already handled by unknown fields check
            }

            // Skip type validation for null values on optional fields
            if ($value === null && ! $rules[$field]['required']) {
                continue;
            }

            $expectedType = $rules[$field]['type'];
            $actualType = self::getValueType($value);

            if (! self::isTypeCompatible($actualType, $expectedType)) {
                throw new InvalidArgumentException(
                    "LFL::setup('{$normalizedType}'): '{$field}' must be {$expectedType}, {$actualType} given"
                );
            }
        }

        return $with;
    }

    /**
     * Get the PHP type of a value.
     */
    protected static function getValueType(mixed $value): string
    {
        if (is_int($value)) {
            return 'int';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_array($value)) {
            return 'array';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_null($value)) {
            return 'null';
        }

        return gettype($value);
    }

    /**
     * Check if actual type is compatible with expected type.
     *
     * Handles union types like 'int|float'.
     */
    protected static function isTypeCompatible(string $actualType, string $expectedType): bool
    {
        // Handle union types (e.g., 'int|float')
        if (str_contains($expectedType, '|')) {
            $expectedTypes = explode('|', $expectedType);
            foreach ($expectedTypes as $expected) {
                if (self::isTypeCompatible($actualType, trim($expected))) {
                    return true;
                }
            }

            return false;
        }

        // Exact match
        if ($actualType === $expectedType) {
            return true;
        }

        // Special cases
        // int is compatible with float (for cost field)
        if ($expectedType === 'float' && $actualType === 'int') {
            return true;
        }

        return false;
    }
}
