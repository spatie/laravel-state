<?php

namespace Spatie\ModelStates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\ModelStates\Exceptions\InvalidConfig;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 *
 * @method static Builder whereState(string $field, string|string[] $states)
 * @method static Builder whereNotState(string $field, string|string[] $states)
 */
trait HasStates
{
    protected static ?array $stateFields = null;

    abstract protected function registerStates(): void;

    public function __set($name, $value): void
    {
        if ($value instanceof State) {
            $value->setField($name);
        }

        parent::__set($name, $value);
    }

    public static function bootHasStates(): void
    {
        $serializeState = function (StateConfig $stateConfig) {
            return function (Model $model) use ($stateConfig) {
                $value = $model->getAttribute($stateConfig->field);

                if ($value === null) {
                    $value = $stateConfig->defaultStateClass;
                }

                if ($value === null) {
                    return;
                }

                $stateClass = $stateConfig->stateClass::resolveStateClass($value);

                if (! is_subclass_of($stateClass, $stateConfig->stateClass)) {
                    throw InvalidConfig::fieldDoesNotExtendState(
                        $stateConfig->field,
                        $stateConfig->stateClass,
                        $stateClass
                    );
                }

                $model->setAttribute(
                    $stateConfig->field,
                    State::resolveStateName($value)
                );
            };
        };

        $unserializeState = function (StateConfig $stateConfig) {
            return function (Model $model) use ($stateConfig) {
                $stateClass = $stateConfig->stateClass::resolveStateClass($model->getAttribute($stateConfig->field));

                $defaultState = $stateConfig->defaultStateClass
                    ? new $stateConfig->defaultStateClass($model)
                    : null;

                /** @var null|\Spatie\ModelStates\State $state */
                $state = $defaultState;

                if (class_exists($stateClass)) {
                    $state = new $stateClass($model);

                    $state->setField($stateConfig->field);
                }

                $model->setAttribute(
                    $stateConfig->field,
                    $state
                );
            };
        };

        foreach (self::getStateConfig() as $stateConfig) {
            static::retrieved($unserializeState($stateConfig));
            static::created($unserializeState($stateConfig));
            static::saved($unserializeState($stateConfig));

            static::updating($serializeState($stateConfig));
            static::creating($serializeState($stateConfig));
            static::saving($serializeState($stateConfig));
        }
    }

    public function initializeHasStates(): void
    {
        foreach (self::getStateConfig() as $stateConfig) {
            if (! $stateConfig->defaultStateClass) {
                continue;
            }

            $this->{$stateConfig->field} = new $stateConfig->defaultStateClass($this);
        }
    }

    public function scopeWhereState(Builder $builder, string $column, $states): Builder
    {
        $field = Arr::last(explode('.', $column));

        /** @var \Spatie\ModelStates\StateConfig|null $stateConfig */
        $stateConfig = self::getStateConfig()[$field] ?? null;

        if (! $stateConfig) {
            throw InvalidConfig::unknownState($field, $this);
        }

        $abstractStateClass = $stateConfig->stateClass;

        $stateNames = collect((array) $states)->map(
            fn($state) => $abstractStateClass::resolveStateName($state)
        );

        return $builder->whereIn($column ?? $column, $stateNames);
    }

    public function scopeWhereNotState(Builder $builder, string $column, $states): Builder
    {
        $field = Arr::last(explode('.', $column));

        /** @var \Spatie\ModelStates\StateConfig|null $stateConfig */
        $stateConfig = self::getStateConfig()[$field] ?? null;

        if (! $stateConfig) {
            throw InvalidConfig::unknownState($field, $this);
        }

        $stateNames = collect((array) $states)->map(
            fn($state) => $stateConfig->stateClass::resolveStateName($state)
        );

        return $builder->whereNotIn($column ?? $column, $stateNames);
    }

    /**
     * @param \Spatie\ModelStates\State|string $state
     * @param string|null $field
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function transitionTo($state, string $field = null)
    {
        $stateConfig = self::getStateConfig();

        if ($field === null && count($stateConfig) > 1) {
            throw CouldNotPerformTransition::couldNotResolveTransitionField($this);
        }

        $field = $field ?? reset($stateConfig)->field;

        return $this->{$field}->transitionTo($state);
    }

    /**
     * @param string $fromClass
     * @param string $toClass
     *
     * @return \Spatie\ModelStates\Transition|string|null
     */
    public function resolveTransitionClass(string $fromClass, string $toClass)
    {
        foreach (static::getStateConfig() as $stateConfig) {
            $transitionClass = $stateConfig->resolveTransition($this, $fromClass, $toClass);

            if ($transitionClass) {
                return $transitionClass;
            }
        }

        throw CouldNotPerformTransition::notFound($fromClass, $toClass, $this);
    }

    protected function addState(string $field, string $stateClass): StateConfig
    {
        $stateConfig = new StateConfig($field, $stateClass);

        static::$stateFields[$stateConfig->field] = $stateConfig;

        return $stateConfig;
    }

    /**
     * @return \Spatie\ModelStates\StateConfig[]
     */
    public static function getStateConfig(): array
    {
        if (static::$stateFields === null) {
            static::$stateFields = [];

            (new static)->registerStates();
        }

        return static::$stateFields ?? [];
    }

    public static function getStates(): Collection
    {
        return collect(static::getStateConfig())
            ->map(
                fn($state) => $state->stateClass::all()->map(
                    fn($stateClass) => $stateClass::getMorphClass()
                )
            );
    }

    public static function getStatesFor(string $column): Collection
    {
        return static::getStates()->get($column, new Collection);
    }

    public static function getDefaultStates(): Collection
    {
        return collect(static::getStateConfig())->map(
            fn($state) => $state->defaultStateClass
        );
    }

    public static function getDefaultStateFor(string $column): string
    {
        return static::getDefaultStates()->get($column);
    }
}
