<?php

declare(strict_types=1);

namespace Dgame\DataTransferObject;

use Dgame\DataTransferObject\Annotation\Absent;
use Dgame\DataTransferObject\Annotation\Alias;
use Dgame\DataTransferObject\Annotation\Ignore;
use Dgame\DataTransferObject\Annotation\Name;
use InvalidArgumentException;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * @template T of object
 */
final class DataTransferProperty
{
    private ?Ignore $ignore = null;
    /** @var string[] */
    private array $names = [];
    /**
     * @var T
     */
    private object $instance;
    private bool $hasDefaultValue;
    private mixed $defaultValue;

    /**
     * @param ReflectionProperty $property
     * @param DataTransferObject<T> $parent
     *
     * @throws ReflectionException
     */
    public function __construct(private ReflectionProperty $property, DataTransferObject $parent)
    {
        $property->setAccessible(true);
        $this->setIgnore();
        $this->setNames();

        $this->instance = $parent->getInstance();

        if ($property->hasDefaultValue()) {
            $this->hasDefaultValue = true;
            $this->defaultValue = $property->getDefaultValue();
        } else {
            $parameter = $this->getPromotedConstructorParameter($parent->getConstructor(), $property->getName());
            if ($parameter !== null && $parameter->isOptional()) {
                $this->hasDefaultValue = true;
                $this->defaultValue = $parameter->getDefaultValue();
            } else {
                $type = $property->getType();
                $this->hasDefaultValue = $type?->allowsNull() ?? false;
                $this->defaultValue = null;
            }
        }
    }

    public function isIgnored(): bool
    {
        return $this->ignore !== null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws Throwable
     */
    public function ignoreIn(array &$input): void
    {
        foreach ($this->names as $name) {
            if (array_key_exists($name, $input)) {
                $this->ignore?->execute();
            }

            unset($input[$name]);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws Throwable
     */
    public function setValueFrom(array &$input): void
    {
        foreach ($this->names as $name) {
            if (!array_key_exists($name, $input)) {
                continue;
            }

            $value = $input[$name];
            unset($input[$name]);

            $value = new DataTransferValue($value, $this->property);
            $this->assign($value->getValue());

            return;
        }

        if ($this->hasDefaultValue) {
            $this->assign($this->defaultValue);

            return;
        }

        throw $this->getMissingException();
    }

    private function getPromotedConstructorParameter(?ReflectionMethod $constructor, string $name): ?ReflectionParameter
    {
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted() && $parameter->getName() === $name) {
                return $parameter;
            }
        }

        return null;
    }

    private function assign(mixed $value): void
    {
        $instance = $this->property->isStatic() ? null : $this->instance;

        $this->property->setValue($instance, $value);
    }

    private function setIgnore(): void
    {
        foreach ($this->property->getAttributes(Ignore::class) as $attribute) {
            /** @var Ignore $ignore */
            $ignore = $attribute->newInstance();
            $this->ignore = $ignore;
            break;
        }
    }

    private function setNames(): void
    {
        $names = [];
        foreach ($this->property->getAttributes(Name::class) as $attribute) {
            /** @var Name $name */
            $name = $attribute->newInstance();
            $names[$name->getName()] = true;
        }

        if ($names === []) {
            $names[$this->property->getName()] = true;
        }

        foreach ($this->property->getAttributes(Alias::class) as $attribute) {
            /** @var Alias $alias */
            $alias = $attribute->newInstance();
            $names[$alias->getName()] = true;
        }

        $this->names = array_keys($names);
    }

    private function getMissingException(): Throwable
    {
        foreach ($this->property->getAttributes(Absent::class) as $attribute) {
            /** @var Absent $absent */
            $absent = $attribute->newInstance();

            return $absent->getException();
        }

        return match (count($this->names)) {
            0 => new InvalidArgumentException('Expected a value'),
            1 => new InvalidArgumentException('Expected a value for "' . current($this->names) . '"'),
            default => new InvalidArgumentException('Expected one of "' . implode(', ', $this->names) . '"')
        };
    }
}