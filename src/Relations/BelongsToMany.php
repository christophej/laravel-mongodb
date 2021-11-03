<?php

namespace Jenssegers\Mongodb\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Support\Arr;

class BelongsToMany extends EloquentBelongsToMany
{
    /**
     * Get the key for comparing against the parent key in "has" query.
     * @return string
     */
    public function getHasCompareKey()
    {
        return $this->getForeignKey();
    }

    /**
     * @inheritdoc
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        return $query;
    }

    /**
     * Get the pivot attributes from a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    protected function migratePivotAttributes(Model $model)
    {
        $keyToUse = $this->getTable() == $model->getTable() ? $this->getForeignKey() : $this->getRelatedKey();
        $pivotKey = $this->parent->{$this->getQualifiedParentKeyName()};
        $pivots = collect($model->{$keyToUse});

        if (empty($pivotKey)) {
            return $pivots->first();
        }

        $pivot = $pivots->firstWhere(
            '_id',
            $pivotKey
        );

        return $pivot;
    }

    /**
     * Set the select clause for the relation query.
     * @param array $columns
     * @return array
     */
    protected function getSelectColumns(array $columns = ['*'])
    {
        return $columns;
    }

    /**
     * @inheritdoc
     */
    protected function shouldSelect(array $columns = ['*'])
    {
        return $columns;
    }

    /**
     * @inheritdoc
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->setWhere();
        }
    }

    /**
     * Set the where clause for the relation query.
     * @return $this
     */
    protected function setWhere()
    {
        $foreign = $this->getForeignKey();

        $key = $this->parent->getKey();
        $this->query
            ->where($foreign, '=', $key)
            ->orWhereRaw([$foreign.'._id' => $key]);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function save(Model $model, array $joining = [], $touch = true)
    {
        $model->save(['touch' => false]);

        $this->attach($model, $joining, $touch);

        return $model;
    }

    /**
     * @inheritdoc
     */
    public function create(array $attributes = [], array $joining = [], $touch = true)
    {
        $instance = $this->related->newInstance($attributes);

        // Once we save the related model, we need to attach it to the base model via
        // through intermediate table so we'll use the existing "attach" method to
        // accomplish this which will insert the record and any more attributes.
        $instance->save(['touch' => false]);

        $this->attach($instance, $joining, $touch);

        return $instance;
    }

        /**
     * @inheritdoc
     */
    public function sync($ids, $detaching = true)
    {
        if (false === $this->parent->fireModelEvent('pivotSyncing', true)) {
            return false;
        }

        $changes = parent::sync($ids, $detaching);

        $this->parent->fireModelEvent('pivotSynced', false);

        return $changes;
    }

    /**
     * @inheritdoc
     */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        if (false === $this->parent->fireModelEvent('pivotUpdating', true)) {
            return false;
        }

        if ($id instanceof Model) {
            $model = $id;
            $id = $model->getKey();
        } elseif ($id instanceof Collection) {
            $id = $id->modelKeys();
        }

        $related = $this->newRelatedQuery()->whereIn($this->related->getKeyName(), (array) $id);
        $filter = ['_id' => $this->parent->getKey()];
        $pivot_x = [array_merge($attributes, $filter)];

        //TODO: Put this in a transaction
        $related->pull($this->getForeignKey(), $this->parent->getKey());
        $related->pull($this->getForeignKey(), $filter);
        $related->push($this->getForeignKey(), $pivot_x, true);

        $filter = ['_id' => $id];
        $pivot_x = [array_merge($attributes, $filter)];
        //TODO: Put this in a transaction
        $this->parent->pull($this->getRelatedKey(), $id);
        $this->parent->pull($this->getRelatedKey(), $filter);
        $this->parent->push($this->getRelatedKey(), $pivot_x, true);

        $this->parent->fireModelEvent('pivotUpdated', false);
    }

    /**
     * @inheritdoc
     */
    public function attach($id, array $attributes = [], $touch = true)
    {
        if (false === $this->parent->fireModelEvent('pivotAttaching', true)) {
            return false;
        }

        if ($id instanceof Model) {
            $model = $id;

            $id = $model->getKey();

            // Attach the new parent id to the related model.
            $model->push($this->foreignPivotKey, [array_merge($attributes, ['_id' => $this->castKey($this->parent->getKey())])], true);
        } else {
            if ($id instanceof Collection) {
                $id = $id->modelKeys();
            }

            $query = $this->newRelatedQuery();

            $query
                ->whereIn($this->related->getKeyName(), (array) $id)
                ->orWhereIn($this->related->getKeyName().'._id', (array) $id);

            // Attach the new parent id to the related model.
            $query->push($this->foreignPivotKey, [array_merge($attributes, ['_id' => $this->castKey($this->parent->getKey())])], true);
        }

        //Pivot Collection
        $pivot_x = [];
        foreach ((array) $id as $item) {
            $pivot_x[] = array_merge($attributes, ['_id' => $item]);
        }

        // Attach the new ids to the parent model.
        $this->parent->push($this->getRelatedKey(), $pivot_x, true);

        if ($touch) {
            $this->touchIfTouching();
        }

        $this->parent->fireModelEvent('pivotAttached', false);
    }

    /**
     * @inheritdoc
     */
    public function detach($ids = [], $touch = true)
    {
        if (false === $this->parent->fireModelEvent('pivotDetaching', true)) {
            return false;
        }

        if (empty($ids)) {
            $ids = array_map(fn($related) => $related["_id"] ?? $related, $this->parent->{$this->getRelatedKey()});
        } else if ($ids instanceof Model) {
            $ids = (array) $ids->getKey();
        }

        $query = $this->newRelatedQuery();

        // If associated IDs were passed to the method we will only delete those
        // associations, otherwise all of the association ties will be broken.
        // We'll return the numbers of affected rows when we do the deletes.
        $ids = array_values((array) $ids);

        // Detach all ids from the parent model.
        $this->parent->pull($this->getRelatedKey(), $ids);
        $this->parent->pull($this->getRelatedKey(), ['_id' => ['$in' => $ids]]);

        // Prepare the query to select all related objects.
        if (count($ids) > 0) {
            $query->whereIn($this->related->getKeyName(), $ids);
        }

        // Remove the relation to the parent.
        $query->pull($this->foreignPivotKey, $this->parent->getKey());
        $query->pull($this->foreignPivotKey, ['_id' => $this->parent->getKey()]);

        if ($touch) {
            $this->touchIfTouching();
        }

        $this->parent->fireModelEvent('pivotDetached', false);

        return count($ids);
    }

    /**
     * @inheritdoc
     */
    protected function buildDictionary(Collection $results)
    {
        $foreign = $this->foreignPivotKey;

        // First we will build a dictionary of child models keyed by the foreign key
        // of the relation so that we will easily and quickly match them to their
        // parents without having a possibly slow inner loops for every models.
        $dictionary = [];

        foreach ($results as $result) {
            foreach ($result->$foreign as $item) {
                if (is_array($item)) {
                    $dictionary[$item['_id']][] = $result;
                } else {
                    $dictionary[$item][] = $result;
                }
            }
        }

        return $dictionary;
    }
    
    /**
     * Get the pivot models that are currently attached.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getCurrentlyAttachedPivots()
    {
        return $this
            ->newPivotQuery()
            ->where($this->getQualifiedForeignPivotKeyName(), $this->parent->getKey())
            ->orWhere("{$this->getQualifiedForeignPivotKeyName()}._id", $this->parent->getKey())
            ->get()
            ->map(function ($record) {
                $class = $this->using ?: Pivot::class;

                $pivot = $class::fromRawAttributes($this->parent, (array) $record, $this->getTable(), true);
                $pivot->{$this->relatedPivotKey} = $record->getKey();

                return $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey);
            });
    }

    /**
     * @inheritdoc
     */
    public function newPivotQuery()
    {
        return $this->newRelatedQuery();
    }

    /**
     * Create a new query builder for the related model.
     * @return \Illuminate\Database\Query\Builder
     */
    public function newRelatedQuery()
    {
        return $this->related->newQuery();
    }

    /**
     * Get the fully qualified foreign key for the relation.
     * @return string
     */
    public function getForeignKey()
    {
        return $this->foreignPivotKey;
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedForeignPivotKeyName()
    {
        return $this->foreignPivotKey;
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedRelatedPivotKeyName()
    {
        return $this->relatedPivotKey;
    }

    /**
     * Format the sync list so that it is keyed by ID. (Legacy Support)
     * The original function has been renamed to formatRecordsList since Laravel 5.3.
     * @param array $records
     * @return array
     * @deprecated
     */
    protected function formatSyncList(array $records)
    {
        $results = [];
        foreach ($records as $id => $attributes) {
            if (! is_array($attributes)) {
                [$id, $attributes] = [$attributes, []];
            }
            $results[$id] = $attributes;
        }

        return $results;
    }

    /**
     * Get the related key with backwards compatible support.
     * @return string
     */
    public function getRelatedKey()
    {
        return property_exists($this, 'relatedPivotKey') ? $this->relatedPivotKey : $this->relatedKey;
    }

    /**
     * Get the name of the "where in" method for eager loading.
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $key
     * @return string
     */
    protected function whereInMethod(EloquentModel $model, $key)
    {
        return 'whereIn';
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $keys = $this->getKeys($models, $this->parentKey);
        $this->query
            ->whereIn($this->getQualifiedForeignPivotKeyName(), $keys)
            ->orWhereIn($this->getQualifiedForeignPivotKeyName().'._id', $keys);
    }
}
