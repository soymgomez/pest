<?php

declare(strict_types=1);

namespace Pest;

use BadMethodCallException;
use Closure;
use Pest\Arch\Contracts\ArchExpectation;
use Pest\Arch\Expectations\Targeted;
use Pest\Arch\Expectations\ToBeUsedIn;
use Pest\Arch\Expectations\ToBeUsedInNothing;
use Pest\Arch\Expectations\ToOnlyBeUsedIn;
use Pest\Arch\Expectations\ToOnlyUse;
use Pest\Arch\Expectations\ToUse;
use Pest\Arch\Expectations\ToUseNothing;
use Pest\Arch\PendingArchExpectation;
use Pest\Arch\Support\FileLineFinder;
use Pest\Concerns\Extendable;
use Pest\Concerns\Pipeable;
use Pest\Concerns\Retrievable;
use Pest\Exceptions\ExpectationNotFound;
use Pest\Exceptions\InvalidExpectation;
use Pest\Exceptions\InvalidExpectationValue;
use Pest\Expectations\EachExpectation;
use Pest\Expectations\HigherOrderExpectation;
use Pest\Expectations\OppositeExpectation;
use Pest\Matchers\Any;
use Pest\Support\ExpectationPipeline;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * @internal
 *
 * @template TValue
 *
 * @property OppositeExpectation $not Creates the opposite expectation.
 * @property EachExpectation $each Creates an expectation on each element on the traversable value.
 * @property PendingArchExpectation $classes
 * @property PendingArchExpectation $traits
 * @property PendingArchExpectation $interfaces
 * @property PendingArchExpectation $enums
 *
 * @mixin Mixins\Expectation<TValue>
 * @mixin PendingArchExpectation
 */
final class Expectation
{
    use Extendable;
    use Pipeable;
    use Retrievable;

    /**
     * Creates a new expectation.
     *
     * @param  TValue  $value
     */
    public function __construct(
        public mixed $value
    ) {
        // ..
    }

    /**
     * Creates a new expectation.
     *
     * @template TAndValue
     *
     * @param  TAndValue  $value
     * @return self<TAndValue>
     */
    public function and(mixed $value): Expectation
    {
        return $value instanceof self ? $value : new self($value);
    }

    /**
     * Creates a new expectation with the decoded JSON value.
     *
     * @return self<array<int|string, mixed>|bool>
     */
    public function json(): Expectation
    {
        if (! is_string($this->value)) {
            InvalidExpectationValue::expected('string');
        }

        $this->toBeJson();

        /** @var array<int|string, mixed>|bool $value */
        $value = json_decode($this->value, true, 512, JSON_THROW_ON_ERROR);

        return $this->and($value);
    }

    /**
     * Dump the expectation value.
     *
     * @return self<TValue>
     */
    public function dump(mixed ...$arguments): self
    {
        if (function_exists('dump')) {
            dump($this->value, ...$arguments);
        } else {
            var_dump($this->value);
        }

        return $this;
    }

    /**
     * Dump the expectation value and end the script.
     *
     * @return never
     */
    public function dd(mixed ...$arguments): void
    {
        if (function_exists('dd')) {
            dd($this->value, ...$arguments);
        }

        var_dump($this->value);

        exit(1);
    }

    /**
     * Send the expectation value to Ray along with all given arguments.
     *
     * @return self<TValue>
     */
    public function ray(mixed ...$arguments): self
    {
        if (function_exists('ray')) {
            ray($this->value, ...$arguments);
        }

        return $this;
    }

    /**
     * Creates the opposite expectation for the value.
     *
     * @return OppositeExpectation<TValue>
     */
    public function not(): OppositeExpectation
    {
        return new OppositeExpectation($this);
    }

    /**
     * Creates an expectation on each item of the iterable "value".
     *
     * @return EachExpectation<TValue>
     */
    public function each(callable $callback = null): EachExpectation
    {
        if (! is_iterable($this->value)) {
            throw new BadMethodCallException('Expectation value is not iterable.');
        }

        if (is_callable($callback)) {
            foreach ($this->value as $key => $item) {
                $callback(new self($item), $key);
            }
        }

        return new EachExpectation($this);
    }

    /**
     * Allows you to specify a sequential set of expectations for each item in a iterable "value".
     *
     * @template TSequenceValue
     *
     * @param  (callable(self<TValue>, self<string|int>): void)|TSequenceValue  ...$callbacks
     * @return self<TValue>
     */
    public function sequence(mixed ...$callbacks): self
    {
        if (! is_iterable($this->value)) {
            throw new BadMethodCallException('Expectation value is not iterable.');
        }

        $value = is_array($this->value) ? $this->value : iterator_to_array($this->value);
        $keys = array_keys($value);
        $values = array_values($value);
        $callbacksCount = count($callbacks);

        $index = 0;

        while (count($callbacks) < count($values)) {
            $callbacks[] = $callbacks[$index];
            $index = $index < count($values) - 1 ? $index + 1 : 0;
        }

        if ($callbacksCount > count($values)) {
            Assert::assertLessThanOrEqual(count($value), count($callbacks));
        }

        foreach ($values as $key => $item) {
            if ($callbacks[$key] instanceof Closure) {
                call_user_func($callbacks[$key], new self($item), new self($keys[$key]));

                continue;
            }

            (new self($item))->toEqual($callbacks[$key]);
        }

        return $this;
    }

    /**
     * If the subject matches one of the given "expressions", the expression callback will run.
     *
     * @template TMatchSubject of array-key
     *
     * @param  (callable(): TMatchSubject)|TMatchSubject  $subject
     * @param  array<TMatchSubject, (callable(self<TValue>): mixed)|TValue>  $expressions
     * @return self<TValue>
     */
    public function match(mixed $subject, array $expressions): self
    {
        $subject = $subject instanceof Closure ? $subject() : $subject;

        $matched = false;

        foreach ($expressions as $key => $callback) {
            if ($subject != $key) {
                continue;
            }

            $matched = true;

            if (is_callable($callback)) {
                $callback(new self($this->value));

                continue;
            }

            $this->and($this->value)->toEqual($callback);

            break;
        }

        if ($matched === false) {
            throw new ExpectationFailedException('Unhandled match value.');
        }

        return $this;
    }

    /**
     * Apply the callback if the given "condition" is falsy.
     *
     * @param  (callable(): bool)|bool  $condition
     * @param  callable(Expectation<TValue>): mixed  $callback
     * @return self<TValue>
     */
    public function unless(callable|bool $condition, callable $callback): Expectation
    {
        $condition = is_callable($condition)
            ? $condition
            : static fn (): bool => $condition;

        return $this->when(! $condition(), $callback);
    }

    /**
     * Apply the callback if the given "condition" is truthy.
     *
     * @param  (callable(): bool)|bool  $condition
     * @param  callable(self<TValue>): mixed  $callback
     * @return self<TValue>
     */
    public function when(callable|bool $condition, callable $callback): self
    {
        $condition = is_callable($condition)
            ? $condition
            : static fn (): bool => $condition;

        if ($condition()) {
            $callback($this->and($this->value));
        }

        return $this;
    }

    /**
     * Dynamically calls methods on the class or creates a new higher order expectation.
     *
     * @param  array<int, mixed>  $parameters
     * @return Expectation<TValue>|HigherOrderExpectation<Expectation<TValue>, TValue>
     */
    public function __call(string $method, array $parameters): Expectation|HigherOrderExpectation|PendingArchExpectation
    {
        if (! self::hasMethod($method)) {
            if (! is_object($this->value) && method_exists(PendingArchExpectation::class, $method)) {
                $pendingArchExpectation = new PendingArchExpectation($this, []);

                return $pendingArchExpectation->$method(...$parameters); // @phpstan-ignore-line
            }

            if (! is_object($this->value)) {
                throw new BadMethodCallException(sprintf(
                    'Method "%s" does not exist in %s.',
                    $method,
                    gettype($this->value)
                ));
            }

            /* @phpstan-ignore-next-line */
            return new HigherOrderExpectation($this, call_user_func_array($this->value->$method(...), $parameters));
        }

        ExpectationPipeline::for($this->getExpectationClosure($method))
            ->send(...$parameters)
            ->through($this->pipes($method, $this, Expectation::class))
            ->run();

        return $this;
    }

    /**
     * Creates a new expectation closure from the given name.
     *
     * @throws ExpectationNotFound
     */
    private function getExpectationClosure(string $name): Closure
    {
        if (method_exists(Mixins\Expectation::class, $name)) {
            // @phpstan-ignore-next-line
            return Closure::fromCallable([new Mixins\Expectation($this->value), $name]);
        }

        if (self::hasExtend($name)) {
            $extend = self::$extends[$name]->bindTo($this, Expectation::class);

            if ($extend != false) {
                return $extend;
            }
        }

        throw ExpectationNotFound::fromName($name);
    }

    /**
     * Dynamically calls methods on the class without any arguments or creates a new higher order expectation.
     *
     * @return Expectation<TValue>|OppositeExpectation<TValue>|EachExpectation<TValue>|HigherOrderExpectation<Expectation<TValue>, TValue|null>|TValue
     */
    public function __get(string $name)
    {
        if (! self::hasMethod($name)) {
            if (! is_object($this->value) && method_exists(PendingArchExpectation::class, $name)) {
                /* @phpstan-ignore-next-line */
                return $this->{$name}();
            }

            /* @phpstan-ignore-next-line */
            return new HigherOrderExpectation($this, $this->retrieve($name, $this->value));
        }

        /* @phpstan-ignore-next-line */
        return $this->{$name}();
    }

    /**
     * Checks if the given expectation method exists.
     */
    public static function hasMethod(string $name): bool
    {
        return method_exists(self::class, $name)
            || method_exists(Mixins\Expectation::class, $name)
            || self::hasExtend($name);
    }

    /**
     * Matches any value.
     */
    public function any(): Any
    {
        return new Any();
    }

    /**
     * Asserts that the given expectation target use the given dependencies.
     *
     * @param  array<int, string>|string  $targets
     */
    public function toUse(array|string $targets): ArchExpectation
    {
        return ToUse::make($this, $targets);
    }

    /**
     * Asserts that the given expectation target use the "declare(strict_types=1)" declaration.
     */
    public function toUseStrictTypes(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => str_contains((string) file_get_contents($object->path), 'declare(strict_types=1);'),
            'to use strict types',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, '<?php')),
        );
    }

    /**
     * Asserts that the given expectation target is final.
     */
    public function toBeFinal(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => ! enum_exists($object->name) && $object->reflectionClass->isFinal(),
            'to be final',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target is readonly.
     */
    public function toBeReadonly(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => ! enum_exists($object->name) && $object->reflectionClass->isReadOnly() && assert(true), // @phpstan-ignore-line
            'to be readonly',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target is trait.
     */
    public function toBeTrait(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => $object->reflectionClass->isTrait(),
            'to be trait',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation targets are traits.
     */
    public function toBeTraits(): ArchExpectation
    {
        return $this->toBeTrait();
    }

    /**
     * Asserts that the given expectation target is abstract.
     */
    public function toBeAbstract(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => $object->reflectionClass->isAbstract(),
            'to be abstract',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target is enum.
     */
    public function toBeEnum(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => $object->reflectionClass->isEnum(),
            'to be enum',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation targets are enums.
     */
    public function toBeEnums(): ArchExpectation
    {
        return $this->toBeEnum();
    }

    /**
     * Asserts that the given expectation targets is an class.
     */
    public function toBeClass(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => class_exists($object->name) && ! enum_exists($object->name),
            'to be class',
            FileLineFinder::where(fn (string $line): bool => true),
        );
    }

    /**
     * Asserts that the given expectation targets are classes.
     */
    public function toBeClasses(): ArchExpectation
    {
        return $this->toBeClass();
    }

    /**
     * Asserts that the given expectation target is interface.
     */
    public function toBeInterface(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => $object->reflectionClass->isInterface(),
            'to be interface',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation targets are interfaces.
     */
    public function toBeInterfaces(): ArchExpectation
    {
        return $this->toBeInterface();
    }

    /**
     * Asserts that the given expectation target to be subclass of the given class.
     *
     * @param  class-string  $class
     */
    public function toExtend(string $class): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => $class === $object->reflectionClass->getName() || $object->reflectionClass->isSubclassOf($class),
            sprintf("to extend '%s'", $class),
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target to be have a parent class.
     */
    public function toExtendNothing(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => $object->reflectionClass->getParentClass() === false,
            'to extend nothing',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target to not implement any interfaces.
     */
    public function toImplementNothing(): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => $object->reflectionClass->getInterfaceNames() === [],
            'to implement nothing',
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target to only implement the given interfaces.
     *
     * @param  array<int, class-string>|class-string  $interfaces
     */
    public function toOnlyImplement(array|string $interfaces): ArchExpectation
    {
        $interfaces = is_array($interfaces) ? $interfaces : [$interfaces];

        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => count($interfaces) === count($object->reflectionClass->getInterfaceNames())
                && array_diff($interfaces, $object->reflectionClass->getInterfaceNames()) === [],
            "to only implement '".implode("', '", $interfaces)."'",
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target to have the given suffix.
     */
    public function toHavePrefix(string $prefix): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => str_starts_with($object->reflectionClass->getShortName(), $prefix),
            "to have prefix '{$prefix}'",
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target to have the given suffix.
     */
    public function toHaveSuffix(string $suffix): ArchExpectation
    {
        return Targeted::make(
            $this,
            fn (ObjectDescription $object): bool => str_ends_with($object->reflectionClass->getName(), $suffix),
            "to have suffix '{$suffix}'",
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target to implement the given interfaces.
     *
     * @param  array<int, class-string>|class-string  $interfaces
     */
    public function toImplement(array|string $interfaces): ArchExpectation
    {
        $interfaces = is_array($interfaces) ? $interfaces : [$interfaces];

        return Targeted::make(
            $this,
            function (ObjectDescription $object) use ($interfaces): bool {
                foreach ($interfaces as $interface) {
                    if (! $object->reflectionClass->implementsInterface($interface)) {
                        return false;
                    }
                }

                return true;
            },
            "to implement '".implode("', '", $interfaces)."'",
            FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
        );
    }

    /**
     * Asserts that the given expectation target "only" use on the given dependencies.
     *
     * @param  array<int, string>|string  $targets
     */
    public function toOnlyUse(array|string $targets): ArchExpectation
    {
        return ToOnlyUse::make($this, $targets);
    }

    /**
     * Asserts that the given expectation target does not use any dependencies.
     */
    public function toUseNothing(): ArchExpectation
    {
        return ToUseNothing::make($this);
    }

    public function toBeUsed(): never
    {
        throw InvalidExpectation::fromMethods(['toBeUsed']);
    }

    /**
     * Asserts that the given expectation dependency is used by the given targets.
     *
     * @param  array<int, string>|string  $targets
     */
    public function toBeUsedIn(array|string $targets): ArchExpectation
    {
        return ToBeUsedIn::make($this, $targets);
    }

    /**
     * Asserts that the given expectation dependency is "only" used by the given targets.
     *
     * @param  array<int, string>|string  $targets
     */
    public function toOnlyBeUsedIn(array|string $targets): ArchExpectation
    {
        return ToOnlyBeUsedIn::make($this, $targets);
    }

    /**
     * Asserts that the given expectation dependency is not used.
     */
    public function toBeUsedInNothing(): ArchExpectation
    {
        return ToBeUsedInNothing::make($this);
    }
}
