<?php

    namespace Jenssegers\Mongodb\Eloquent;

    /**
     * @package PivotEventTrait
     */

    trait PivotEventTrait {
        /**
         * Get the observable event names.
         *
         * @return array
         */
        public function getObservableEvents()
        {
            return array_merge(
                parent::getObservableEvents(),
                [
                    'pivotAttaching', 'pivotAttached',
                    'pivotDetaching', 'pivotDetached',
                    'pivotUpdating', 'pivotUpdated',
                    'pivotSyncing', 'pivotSynced',
                ],
                $this->observables
            );
        }

        public static function pivotAttaching($callback, $priority = 0)
        {
            static::registerModelEvent('pivotAttaching', $callback, $priority);
        }

        public static function pivotAttached($callback, $priority = 0)
        {
            static::registerModelEvent('pivotAttached', $callback, $priority);
        }

        public static function pivotDetaching($callback, $priority = 0)
        {
            static::registerModelEvent('pivotDetaching', $callback, $priority);
        }

        public static function pivotDetached($callback, $priority = 0)
        {
            static::registerModelEvent('pivotDetached', $callback, $priority);
        }

        public static function pivotUpdating($callback, $priority = 0)
        {
            static::registerModelEvent('pivotUpdating', $callback, $priority);
        }

        public static function pivotUpdated($callback, $priority = 0)
        {
            static::registerModelEvent('pivotUpdated', $callback, $priority);
        }

        public static function pivotSyncing($callback, $priority = 0)
        {
            static::registerModelEvent('pivotSyncing', $callback, $priority);
        }

        public static function pivotSynced($callback, $priority = 0)
        {
            static::registerModelEvent('pivotSynced', $callback, $priority);
        }

        /**
         * Fire the given event for the model.
         *
         * @param string $event
         * @param bool   $halt
         *
         * @return mixed
         */
        public function fireModelEvent($event, $halt = true, $relationName = null, $ids = [], $idsAttributes = [])
        {
            if (!isset(static::$dispatcher)) {
                return true;
            }

            // First, we will get the proper method to call on the event dispatcher, and then we
            // will attempt to fire a custom, object based event for the given event. If that
            // returns a result we can return that result, or we'll call the string events.
            $method = $halt ? 'until' : 'dispatch';

            $result = $this->filterModelEventResults(
                $this->fireCustomModelEvent($event, $method)
            );

            if (false === $result) {
                return false;
            }

            $payload = [$this, $relationName, $ids, $idsAttributes];

            return !empty($result) ? $result : static::$dispatcher->{$method}(
                "eloquent.{$event}: ".static::class, $payload
            );
        }
    }