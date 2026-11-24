<?php

namespace App\Services;

class QaDecisionSchemas
{
    /** @return array<string, mixed> */
    public static function browserAction(): array
    {
        return self::object([
            'tool' => ['type' => 'string', 'enum' => ['click', 'fill', 'select', 'press', 'navigate', 'assert_text', 'assert_url', 'finish']],
            'id' => ['type' => 'string'],
            ...self::actionArguments(),
            'outcome' => ['type' => 'string', 'enum' => ['passed', 'blocked']],
            'summary' => ['type' => 'string'],
        ], ['tool']);
    }

    /** @return array<string, mixed> */
    public static function sourceSelection(): array
    {
        return self::object([
            'files' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], ['files']);
    }

    /** @return array<string, mixed> */
    public static function repair(): array
    {
        return self::object([
            'diagnosis' => ['type' => 'string'],
            'patch_files' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'regression_test' => ['type' => 'string'],
        ], ['diagnosis', 'patch_files', 'regression_test']);
    }

    /** @return array<string, mixed> */
    public static function controlPlan(): array
    {
        $target = self::object([
            'tag' => ['type' => 'string'],
            'role' => ['type' => 'string'],
            'label' => ['type' => 'string'],
            'text' => ['type' => 'string'],
            'type' => ['type' => 'string'],
        ]);
        $action = self::object([
            'tool' => ['type' => 'string', 'enum' => ['click', 'fill', 'select', 'press', 'navigate', 'assert_text', 'assert_url']],
            'target' => $target,
            ...self::actionArguments(),
        ], ['tool']);
        $assertion = self::object([
            'tool' => ['type' => 'string', 'enum' => ['assert_text', 'assert_url']],
            'text' => ['type' => 'string'],
            'url' => ['type' => 'string'],
        ], ['tool']);

        return self::object([
            'actions' => ['type' => 'array', 'items' => $action],
            'original_assertion' => $assertion,
        ], ['actions']);
    }

    /** @return array<string, array<string, mixed>> */
    private static function actionArguments(): array
    {
        return [
            'value' => ['type' => 'string'],
            'key' => ['type' => 'string', 'enum' => ['Enter', 'Tab', 'Escape', 'Space', 'ArrowDown', 'ArrowUp']],
            'text' => ['type' => 'string'],
            'url' => ['type' => 'string'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>, additionalProperties: false}
     */
    private static function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }
}
