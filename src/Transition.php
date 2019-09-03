<?php

namespace Spatie\State;

abstract class Transition
{
    protected function ensureInitialState(Stateful $stateful, string $newState): void
    {
        $initialState = $stateful->getState();

        if (is_a($initialState, $newState)) {
            return;
        }

        throw TransitionException::make($stateful, $initialState, $newState);
    }
}
