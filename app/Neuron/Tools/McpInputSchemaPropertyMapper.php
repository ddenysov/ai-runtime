<?php

namespace App\Neuron\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use ReflectionException;

final class McpInputSchemaPropertyMapper
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, ToolPropertyInterface>
     */
    public function mapProperties(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        if (! is_array($properties)) {
            return [];
        }

        $required = $schema['required'] ?? [];
        $required = is_array($required) ? $required : [];

        $mapped = [];
        foreach ($properties as $name => $propertySchema) {
            if (! is_string($name) || ! is_array($propertySchema)) {
                continue;
            }

            $property = $this->mapProperty(
                name: $name,
                propertySchema: $propertySchema,
                required: in_array($name, $required, true),
            );

            if ($property instanceof ToolPropertyInterface) {
                $mapped[] = $property;
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $propertySchema
     *
     * @throws ArrayPropertyException
     * @throws ReflectionException
     * @throws ToolException
     */
    private function mapProperty(string $name, array $propertySchema, bool $required): ?ToolPropertyInterface
    {
        $type = $this->resolveType($propertySchema);

        return match ($type) {
            PropertyType::ARRAY => $this->createArrayProperty($name, $propertySchema, $required),
            PropertyType::OBJECT => $this->createObjectProperty($name, $propertySchema, $required),
            default => $this->createScalarProperty($name, $type, $propertySchema, $required),
        };
    }

    /**
     * @param  array<string, mixed>  $propertySchema
     *
     * @throws ArrayPropertyException
     * @throws ReflectionException
     * @throws ToolException
     */
    private function createArrayProperty(string $name, array $propertySchema, bool $required): ArrayProperty
    {
        $items = null;
        $itemsSchema = $propertySchema['items'] ?? null;

        if (is_array($itemsSchema)) {
            $items = $this->mapProperty($name.'_item', $itemsSchema, false);
        }

        $minItems = $propertySchema['minItems'] ?? null;
        $maxItems = $propertySchema['maxItems'] ?? null;

        return new ArrayProperty(
            name: $name,
            description: $this->description($propertySchema),
            required: $required,
            items: $items,
            minItems: is_int($minItems) ? $minItems : null,
            maxItems: is_int($maxItems) ? $maxItems : null,
        );
    }

    /**
     * @param  array<string, mixed>  $propertySchema
     *
     * @throws ArrayPropertyException
     * @throws ReflectionException
     * @throws ToolException
     */
    private function createObjectProperty(string $name, array $propertySchema, bool $required): ObjectProperty
    {
        $nestedProperties = [];
        $nestedSchema = $propertySchema['properties'] ?? [];

        if (is_array($nestedSchema)) {
            $nestedRequired = $propertySchema['required'] ?? [];
            $nestedRequired = is_array($nestedRequired) ? $nestedRequired : [];

            foreach ($nestedSchema as $nestedName => $nestedPropertySchema) {
                if (! is_string($nestedName) || ! is_array($nestedPropertySchema)) {
                    continue;
                }

                $nestedProperty = $this->mapProperty(
                    name: $nestedName,
                    propertySchema: $nestedPropertySchema,
                    required: in_array($nestedName, $nestedRequired, true),
                );

                if ($nestedProperty instanceof ToolPropertyInterface) {
                    $nestedProperties[] = $nestedProperty;
                }
            }
        }

        return new ObjectProperty(
            name: $name,
            description: $this->description($propertySchema),
            required: $required,
            properties: $nestedProperties,
        );
    }

    /**
     * @param  array<string, mixed>  $propertySchema
     */
    private function createScalarProperty(
        string $name,
        PropertyType $type,
        array $propertySchema,
        bool $required,
    ): ToolProperty {
        $enum = $propertySchema['enum'] ?? [];

        return new ToolProperty(
            name: $name,
            type: $type,
            description: $this->description($propertySchema),
            required: $required,
            enum: is_array($enum) ? array_values($enum) : [],
        );
    }

    /**
     * @param  array<string, mixed>  $propertySchema
     */
    private function resolveType(array $propertySchema): PropertyType
    {
        $type = $propertySchema['type'] ?? 'string';

        if (is_array($type)) {
            return PropertyType::fromSchema($type);
        }

        if (is_string($type)) {
            return PropertyType::from($type);
        }

        return PropertyType::STRING;
    }

    /**
     * @param  array<string, mixed>  $propertySchema
     */
    private function description(array $propertySchema): ?string
    {
        $description = $propertySchema['description'] ?? null;

        return is_string($description) ? $description : null;
    }
}
