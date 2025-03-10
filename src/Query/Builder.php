<?php

namespace Jenssegers\Mongodb\Query;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Jenssegers\Mongodb\Connection;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

/**
 * Class Builder.
 */
class Builder extends BaseBuilder
{
    /**
     * The database collection.
     * @var \MongoDB\Collection
     */
    protected $collection;

    /**
     * The column projections.
     * @var array
     */
    public $projections;

    /**
     * The cursor timeout value.
     * @var int
     */
    public $timeout;

    /**
     * The cursor hint value.
     * @var int
     */
    public $hint;

    /**
     * Custom options to add to the query.
     * @var array
     */
    public $options = [];

    /**
     * Indicate if we are executing a pagination query.
     * @var bool
     */
    public $paginating = false;

    /**
     * All of the available clause operators.
     * @var array
     */
    public $operators = [
        '=',
        '<',
        '>',
        '<=',
        '>=',
        '<>',
        '!=',
        'like',
        'not like',
        'between',
        'ilike',
        '&',
        '|',
        '^',
        '<<',
        '>>',
        'rlike',
        'regexp',
        'not regexp',
        'exists',
        'type',
        'mod',
        'where',
        'all',
        'size',
        'regex',
        'text',
        'slice',
        'elemmatch',
        'geowithin',
        'geointersects',
        'near',
        'nearsphere',
        'geometry',
        'maxdistance',
        'center',
        'centersphere',
        'box',
        'polygon',
        'uniquedocs',
    ];

    /**
     * Operator conversion.
     * @var array
     */
    protected $conversion = [
        '=' => '=',
        '!=' => '$ne',
        '<>' => '$ne',
        '<' => '$lt',
        '<=' => '$lte',
        '>' => '$gt',
        '>=' => '$gte',
    ];

    /**
     * @inheritdoc
     */
    public function __construct(Connection $connection, Processor $processor)
    {
        $this->grammar = new Grammar($connection);
        $this->connection = $connection;
        $this->processor = $processor;
    }

    /**
     * Set the projections.
     * @param array $columns
     * @return $this
     */
    public function project($columns)
    {
        $this->projections = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * Set the cursor timeout in seconds.
     * @param int $seconds
     * @return $this
     */
    public function timeout($seconds)
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the cursor hint.
     * @param mixed $index
     * @return $this
     */
    public function hint($index)
    {
        $this->hint = $index;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function find($id, $columns = [])
    {
        return $this->where('_id', '=', $this->convertKey($id))->first($columns);
    }

    /**
     * @inheritdoc
     */
    public function value($column)
    {
        $result = (array) $this->first([$column]);

        return Arr::get($result, $column);
    }

    /**
     * @inheritdoc
     */
    public function get($columns = [])
    {
        return $this->getFresh($columns);
    }

    /**
     * @inheritdoc
     */
    public function cursor($columns = [])
    {
        $result = $this->getFresh($columns, true);
        if ($result instanceof LazyCollection) {
            return $result;
        }
        throw new RuntimeException('Query not compatible with cursor');
    }

    /**
     * Execute the query as a fresh "select" statement.
     * @param array $columns
     * @param bool $returnLazy
     * @return array|static[]|Collection|LazyCollection
     */
    public function getFresh($columns = [], $returnLazy = false)
    {
        // If no columns have been specified for the select statement, we will set them
        // here to either the passed columns, or the standard default of retrieving
        // all of the columns on the table using the "wildcard" column character.
        if ($this->columns === null) {
            $this->columns = $columns;
        }

        // Drop all columns if * is present, MongoDB does not work this way.
        if (in_array('*', $this->columns)) {
            $this->columns = [];
        }

        // Compile wheres
        $wheres = $this->compileWheres();

        // Use MongoDB's aggregation framework when using grouping or aggregation functions.
        if ($this->groups || $this->aggregate) {
            $group = [];
            $unwinds = [];

            // Add grouping columns to the $group part of the aggregation pipeline.
            if ($this->groups) {
                foreach ($this->groups as $column) {
                    $group['_id'][$column] = '$'.$column;

                    // When grouping, also add the $last operator to each grouped field,
                    // this mimics MySQL's behaviour a bit.
                    $group[$column] = ['$last' => '$'.$column];
                }

                // Do the same for other columns that are selected.
                foreach ($this->columns as $column) {
                    $key = str_replace('.', '_', $column);

                    $group[$key] = ['$last' => '$'.$column];
                }
            }

            // Add aggregation functions to the $group part of the aggregation pipeline,
            // these may override previous aggregations.
            if ($this->aggregate) {
                $function = $this->aggregate['function'];

                foreach ($this->aggregate['columns'] as $column) {
                    // Add unwind if a subdocument array should be aggregated
                    // column: subarray.price => {$unwind: '$subarray'}
                    if (count($splitColumns = explode('.*.', $column)) == 2) {
                        $unwinds[] = $splitColumns[0];
                        $column = implode('.', $splitColumns);
                    }

                    // Null coalense only > 7.2

                    $aggregations = blank($this->aggregate['columns']) ? [] : $this->aggregate['columns'];

                    if (in_array('*', $aggregations) && $function == 'count') {
                        // When ORM is paginating, count doesnt need a aggregation, just a cursor operation
                        // elseif added to use this only in pagination
                        // https://docs.mongodb.com/manual/reference/method/cursor.count/
                        // count method returns int

                        $totalResults = $this->collection->count($wheres);
                        // Preserving format expected by framework
                        $results = [
                            [
                                '_id'       => null,
                                'aggregate' => $totalResults,
                            ],
                        ];

                        return new Collection($results);
                    } elseif ($function == 'count') {
                        // Translate count into sum.
                        $group['aggregate'] = ['$sum' => 1];
                    } else {
                        $group['aggregate'] = ['$'.$function => '$'.$column];
                    }
                }
            }

            // The _id field is mandatory when using grouping.
            if ($group && empty($group['_id'])) {
                $group['_id'] = null;
            }

            // Build the aggregation pipeline.
            $pipeline = [];
            if ($wheres) {
                $pipeline[] = ['$match' => $wheres];
            }

            // apply unwinds for subdocument array aggregation
            foreach ($unwinds as $unwind) {
                $pipeline[] = ['$unwind' => '$'.$unwind];
            }

            if ($group) {
                $pipeline[] = ['$group' => $group];
            }

            // Apply order and limit
            if ($this->orders) {
                $pipeline[] = ['$sort' => $this->orders];
            }
            if ($this->offset) {
                $pipeline[] = ['$skip' => $this->offset];
            }
            if ($this->limit) {
                $pipeline[] = ['$limit' => $this->limit];
            }
            if ($this->projections) {
                $pipeline[] = ['$project' => $this->projections];
            }

            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ];

            // Add custom query options
            if (count($this->options)) {
                $options = array_merge($options, $this->options);
            }

            // Execute aggregation
            $results = iterator_to_array($this->collection->aggregate($pipeline, $options));

            // Return results
            return new Collection($results);
        } // Distinct query
        elseif ($this->distinct) {
            // Return distinct results directly
            $column = isset($this->columns[0]) ? $this->columns[0] : '_id';

            // Execute distinct
            if ($wheres) {
                $result = $this->collection->distinct($column, $wheres);
            } else {
                $result = $this->collection->distinct($column);
            }

            return new Collection($result);
        } // Normal query
        else {
            $columns = [];

            // Convert select columns to simple projections.
            foreach ($this->columns as $column) {
                $columns[$column] = true;
            }

            // Add custom projections.
            if ($this->projections) {
                $columns = array_merge($columns, $this->projections);
            }
            $options = [];

            // Apply order, offset, limit and projection
            if ($this->timeout) {
                $options['maxTimeMS'] = $this->timeout;
            }
            if ($this->orders) {
                $options['sort'] = $this->orders;
            }
            if ($this->offset) {
                $options['skip'] = $this->offset;
            }
            if ($this->limit) {
                $options['limit'] = $this->limit;
            }
            if ($this->hint) {
                $options['hint'] = $this->hint;
            }
            if ($columns) {
                $options['projection'] = $columns;
            }

            // Fix for legacy support, converts the results to arrays instead of objects.
            $options['typeMap'] = ['root' => 'array', 'document' => 'array'];

            // Add custom query options
            if (count($this->options)) {
                $options = array_merge($options, $this->options);
            }

            // Execute query and get MongoCursor
            $cursor = $this->collection->find($wheres, $options);

            if ($returnLazy) {
                return LazyCollection::make(function () use ($cursor) {
                    foreach ($cursor as $item) {
                        yield $item;
                    }
                });
            }

            // Return results as an array with numeric keys
            $results = iterator_to_array($cursor, false);

            return new Collection($results);
        }
    }

    /**
     * Generate the unique cache key for the current query.
     * @return string
     */
    public function generateCacheKey()
    {
        $key = [
            'connection' => $this->collection->getDatabaseName(),
            'collection' => $this->collection->getCollectionName(),
            'wheres' => $this->wheres,
            'columns' => $this->columns,
            'groups' => $this->groups,
            'orders' => $this->orders,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'aggregate' => $this->aggregate,
        ];

        return md5(serialize(array_values($key)));
    }

    /**
     * @inheritdoc
     */
    public function aggregate($function, $columns = [])
    {
        $this->aggregate = compact('function', 'columns');

        $previousColumns = $this->columns;

        // We will also back up the select bindings since the select clause will be
        // removed when performing the aggregate function. Once the query is run
        // we will add the bindings back onto this query so they can get used.
        $previousSelectBindings = $this->bindings['select'];

        $this->bindings['select'] = [];

        $results = $this->get($columns);

        // Once we have executed the query, we will reset the aggregate property so
        // that more select queries can be executed against the database without
        // the aggregate value getting in the way when the grammar builds it.
        $this->aggregate = null;
        $this->columns = $previousColumns;
        $this->bindings['select'] = $previousSelectBindings;

        if (isset($results[0])) {
            $result = (array) $results[0];

            return $result['aggregate'];
        }
    }

    /**
     * @inheritdoc
     */
    public function exists()
    {
        return $this->first() !== null;
    }

    /**
     * @inheritdoc
     */
    public function distinct($column = false)
    {
        $this->distinct = true;

        if ($column) {
            $this->columns = [$column];
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function orderBy($column, $direction = 'asc')
    {
        if (is_string($direction)) {
            $direction = (strtolower($direction) == 'asc' ? 1 : -1);
        }

        if ($column == 'natural') {
            $this->orders['$natural'] = $direction;
        } else {
            $this->orders[$column] = $direction;
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        $type = 'between';

        $this->wheres[] = compact('column', 'type', 'boolean', 'values', 'not');

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function forPage($page, $perPage = 15)
    {
        $this->paginating = true;

        return $this->skip(($page - 1) * $perPage)->take($perPage);
    }

    /**
     * @inheritdoc
     */
    public function insert(array $values)
    {
        if (empty($values)) {
            return true;
        }

        // Since every insert gets treated like a batch insert, we will have to detect
        // if the user is inserting a single document or an array of documents.
        $batch = true;

        foreach ($values as $value) {
            // As soon as we find a value that is not an array we assume the user is
            // inserting a single document.
            if (! is_array($value)) {
                $batch = false;
                break;
            }
        }

        if (! $batch) {
            $values = [$values];
        }

        $values = $this->castDateValues($values);

        // Batch insert
        $result = $this->collection->insertMany($values);

        return 1 == (int) $result->isAcknowledged();
    }

    /**
     * @inheritdoc
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $values = $this->castDateValues($values);

        $result = $this->collection->insertOne($values);

        if (1 == (int) $result->isAcknowledged()) {
            if ($sequence === null) {
                $sequence = '_id';
            }

            // Return id
            return $sequence == '_id' ? $result->getInsertedId() : $values[$sequence];
        }
    }

    /**
     * @inheritdoc
     */
    public function update(array $values, array $options = [])
    {
        // Use $set as default operator.
        if (! Str::startsWith(key($values), '$')) {
            $values = ['$set' => $values];
        }

        $values = $this->castDateValues($values);

        return $this->performUpdate($values, $options);
    }

    /**
     * @inheritdoc
     */
    public function increment($column, $amount = 1, array $extra = [], array $options = [])
    {
        $query = ['$inc' => [$column => $amount]];

        if (! empty($extra)) {
            $query['$set'] = $extra;
        }

        // Protect
        $this->where(function ($query) use ($column) {
            $query->where($column, 'exists', false);

            $query->orWhereNotNull($column);
        });

        return $this->performUpdate($query, $options);
    }

    /**
     * @inheritdoc
     */
    public function decrement($column, $amount = 1, array $extra = [], array $options = [])
    {
        return $this->increment($column, -1 * $amount, $extra, $options);
    }

    /**
     * @inheritdoc
     */
    public function chunkById($count, callable $callback, $column = '_id', $alias = null)
    {
        return parent::chunkById($count, $callback, $column, $alias);
    }

    /**
     * @inheritdoc
     */
    public function forPageAfterId($perPage = 15, $lastId = 0, $column = '_id')
    {
        return parent::forPageAfterId($perPage, $lastId, $column);
    }

    /**
     * @inheritdoc
     */
    public function pluck($column, $key = null)
    {
        $results = $this->get($key === null ? [$column] : [$column, $key]);

        // Convert ObjectID's to strings
        if ($key == '_id') {
            $results = $results->map(function ($item) {
                $item['_id'] = (string) $item['_id'];

                return $item;
            });
        }

        $p = Arr::pluck($results, $column, $key);

        return new Collection($p);
    }

    /**
     * @inheritdoc
     */
    public function delete($id = null)
    {
        // If an ID is passed to the method, we will set the where clause to check
        // the ID to allow developers to simply and quickly remove a single row
        // from their database without manually specifying the where clauses.
        if ($id !== null) {
            $this->where('_id', '=', $id);
        }

        $wheres = $this->compileWheres();
        $result = $this->collection->DeleteMany($wheres);
        if (1 == (int) $result->isAcknowledged()) {
            return $result->getDeletedCount();
        }

        return 0;
    }

    /**
     * @inheritdoc
     */
    public function from($collection, $as = null)
    {
        if ($collection) {
            $this->collection = $this->connection->getCollection($collection);
        }

        return parent::from($collection);
    }

    /**
     * @inheritdoc
     */
    public function truncate(): bool
    {
        $result = $this->collection->deleteMany([]);

        return 1 === (int) $result->isAcknowledged();
    }

    /**
     * Get an array with the values of a given column.
     * @param string $column
     * @param string $key
     * @return array
     * @deprecated
     */
    public function lists($column, $key = null)
    {
        return $this->pluck($column, $key);
    }

    /**
     * @inheritdoc
     */
    public function raw($expression = null)
    {
        // Execute the closure on the mongodb collection
        if ($expression instanceof Closure) {
            return call_user_func($expression, $this->collection);
        }

        // Create an expression for the given value
        if ($expression !== null) {
            return new Expression($expression);
        }

        // Quick access to the mongodb collection
        return $this->collection;
    }

    /**
     * Append one or more values to an array.
     * @param mixed $column
     * @param mixed $value
     * @param bool $unique
     * @return int
     */
    public function push($column, $value = null, $unique = false)
    {
        // Use the addToSet operator in case we only want unique items.
        $operator = $unique ? '$addToSet' : '$push';

        // Check if we are pushing multiple values.
        $batch = (is_array($value) && array_keys($value) === range(0, count($value) - 1));

        if (is_array($column)) {
            $query = [$operator => $column];
        } elseif ($batch) {
            $query = [$operator => [$column => ['$each' => $value]]];
        } else {
            $query = [$operator => [$column => $value]];
        }

        return $this->performUpdate($query);
    }

    /**
     * Remove one or more values from an array.
     * @param mixed $column
     * @param mixed $value
     * @return int
     */
    public function pull($column, $value = null)
    {
        // Check if we passed an associative array.
        $batch = (is_array($value) && array_keys($value) === range(0, count($value) - 1));

        // If we are pulling multiple values, we need to use $pullAll.
        $operator = $batch ? '$pullAll' : '$pull';

        if (is_array($column)) {
            $query = [$operator => $column];
        } else {
            $query = [$operator => [$column => $value]];
        }

        return $this->performUpdate($query);
    }

    /**
     * Remove one or more fields.
     * @param mixed $columns
     * @return int
     */
    public function drop($columns)
    {
        if (! is_array($columns)) {
            $columns = [$columns];
        }

        $fields = [];

        foreach ($columns as $column) {
            $fields[$column] = 1;
        }

        $query = ['$unset' => $fields];

        return $this->performUpdate($query);
    }

    /**
     * @inheritdoc
     */
    public function newQuery()
    {
        return new self($this->connection, $this->processor);
    }

    /**
     * Perform an update query.
     * @param array $query
     * @param array $options
     * @return int
     */
    protected function performUpdate($query, array $options = [])
    {
        // Update multiple items by default.
        if (! array_key_exists('multiple', $options)) {
            $options['multiple'] = true;
        }

        $wheres = $this->compileWheres();
        $result = $this->collection->UpdateMany($wheres, $query, $options);
        if (1 == (int) $result->isAcknowledged()) {
            return $result->getModifiedCount() ? $result->getModifiedCount() : $result->getUpsertedCount();
        }

        return 0;
    }

    /**
     * Convert a key to ObjectID if needed.
     * @param mixed $id
     * @return mixed
     */
    public function convertKey($id)
    {
        if (is_string($id) && strlen($id) === 24 && ctype_xdigit($id)) {
            return new ObjectID($id);
        }

        if (is_string($id) && strlen($id) === 16 && preg_match('~[^\x20-\x7E\t\r\n]~', $id) > 0) {
            return new Binary($id, Binary::TYPE_UUID);
        }

        return $id;
    }

    /**
     * @inheritdoc
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $params = func_get_args();

        // Remove the leading $ from operators.
        if (func_num_args() == 3) {
            $operator = &$params[1];

            if (Str::startsWith($operator, '$')) {
                $operator = substr($operator, 1);
            }
        }

        return call_user_func_array('parent::where', $params);
    }

    /**
     * Compile the where array.
     * @return array
     */
    protected function compileWheres()
    {
        // The wheres to compile.
        $wheres = $this->wheres ?: [];

        // We will add all compiled wheres to this array.
        $compiled = [];

        foreach ($wheres as $i => &$where) {
            // Make sure the operator is in lowercase.
            if (isset($where['operator'])) {
                $where['operator'] = strtolower($where['operator']);

                // Operator conversions
                $convert = [
                    'regexp' => 'regex',
                    'elemmatch' => 'elemMatch',
                    'geointersects' => 'geoIntersects',
                    'geowithin' => 'geoWithin',
                    'nearsphere' => 'nearSphere',
                    'maxdistance' => 'maxDistance',
                    'centersphere' => 'centerSphere',
                    'uniquedocs' => 'uniqueDocs',
                ];

                if (array_key_exists($where['operator'], $convert)) {
                    $where['operator'] = $convert[$where['operator']];
                }
            }

            // Convert id's.
            if (isset($where['column']) && ($where['column'] == '_id')) {
                // Multiple values.
                if (isset($where['values'])) {
                    foreach ($where['values'] as &$value) {
                        $value = $this->convertKey($value);
                    }
                } // Single value.
                elseif (isset($where['value'])) {
                    $where['value'] = $this->convertKey($where['value']);
                }
            }

            // Convert DateTime values to UTCDateTime.
            if (isset($where['value'])) {
                if (is_array($where['value'])) {
                    array_walk_recursive($where['value'], function (&$item, $key) {
                        if ($item instanceof DateTimeInterface) {
                            $item = new UTCDateTime($item->format('Uv'));
                        }
                    });
                } else {
                    if ($where['value'] instanceof DateTimeInterface) {
                        $where['value'] = new UTCDateTime($where['value']->format('Uv'));
                    }
                }
            } elseif (isset($where['values'])) {
                array_walk_recursive($where['values'], function (&$item, $key) {
                    if ($item instanceof DateTimeInterface) {
                        $item = new UTCDateTime($item->format('Uv'));
                    }
                });
            }

            // The next item in a "chain" of wheres devices the boolean of the
            // first item. So if we see that there are multiple wheres, we will
            // use the operator of the next where.
            if ($i == 0 && count($wheres) > 1 && $where['boolean'] == 'and') {
                $where['boolean'] = $wheres[$i + 1]['boolean'];
            }

            // We use different methods to compile different wheres.
            $method = "compileWhere{$where['type']}";
            $result = $this->{$method}($where);

            // Wrap the where with an $or operator.
            if ($where['boolean'] == 'or') {
                $result = ['$or' => [$result]];
            }

            // If there are multiple wheres, we will wrap it with $and. This is needed
            // to make nested wheres work.
            elseif (count($wheres) > 1) {
                $result = ['$and' => [$result]];
            }

            // Merge the compiled where with the others.
            $compiled = array_merge_recursive($compiled, $result);
        }

        return $compiled;
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereAll(array $where)
    {
        extract($where);

        return [$column => ['$all' => array_values($values)]];
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereBasic(array $where)
    {
        extract($where);

        // Replace like or not like with a Regex instance.
        if (in_array($operator, ['like', 'not like'])) {
            if ($operator === 'not like') {
                $operator = 'not';
            } else {
                $operator = '=';
            }

            // Convert to regular expression.
            $regex = preg_replace('#(^|[^\\\])%#', '$1.*', preg_quote($value));

            // Convert like to regular expression.
            if (! Str::startsWith($value, '%')) {
                $regex = '^'.$regex;
            }
            if (! Str::endsWith($value, '%')) {
                $regex .= '$';
            }

            $value = new Regex($regex, 'i');
        } // Manipulate regexp operations.
        elseif (in_array($operator, ['regexp', 'not regexp', 'regex', 'not regex'])) {
            // Automatically convert regular expression strings to Regex objects.
            if (! $value instanceof Regex) {
                $e = explode('/', $value);
                $flag = end($e);
                $regstr = substr($value, 1, -(strlen($flag) + 1));
                $value = new Regex($regstr, $flag);
            }

            // For inverse regexp operations, we can just use the $not operator
            // and pass it a Regex instence.
            if (Str::startsWith($operator, 'not')) {
                $operator = 'not';
            }
        }

        if (! isset($operator) || $operator == '=') {
            $query = [$column => $value];
        } elseif (array_key_exists($operator, $this->conversion)) {
            $query = [$column => [$this->conversion[$operator] => $value]];
        } else {
            $query = [$column => ['$'.$operator => $value]];
        }

        return $query;
    }

    /**
     * @param array $where
     * @return mixed
     */
    protected function compileWhereNested(array $where)
    {
        extract($where);

        return $query->compileWheres();
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereIn(array $where)
    {
        extract($where);

        return [$column => ['$in' => array_values($values)]];
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereNotIn(array $where)
    {
        extract($where);

        return [$column => ['$nin' => array_values($values)]];
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereNull(array $where)
    {
        $where['operator'] = '=';
        $where['value'] = null;

        return $this->compileWhereBasic($where);
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereNotNull(array $where)
    {
        $where['operator'] = '!=';
        $where['value'] = null;

        return $this->compileWhereBasic($where);
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereBetween(array $where)
    {
        extract($where);

        if ($not) {
            return [
                '$or' => [
                    [
                        $column => [
                            '$lte' => $values[0],
                        ],
                    ],
                    [
                        $column => [
                            '$gte' => $values[1],
                        ],
                    ],
                ],
            ];
        }

        return [
            $column => [
                '$gte' => $values[0],
                '$lte' => $values[1],
            ],
        ];
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereDate(array $where)
    {
        extract($where);

        $where['operator'] = $operator;
        $where['value'] = $value;

        return $this->compileWhereBasic($where);
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereMonth(array $where)
    {
        extract($where);

        $where['operator'] = $operator;
        $where['value'] = $value;

        return $this->compileWhereBasic($where);
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereDay(array $where)
    {
        extract($where);

        $where['operator'] = $operator;
        $where['value'] = $value;

        return $this->compileWhereBasic($where);
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereYear(array $where)
    {
        extract($where);

        $where['operator'] = $operator;
        $where['value'] = $value;

        return $this->compileWhereBasic($where);
    }

    /**
     * @param array $where
     * @return array
     */
    protected function compileWhereTime(array $where)
    {
        extract($where);

        $where['operator'] = $operator;
        $where['value'] = $value;

        return $this->compileWhereBasic($where);
    }

    /**
     * @param array $where
     * @return mixed
     */
    protected function compileWhereRaw(array $where)
    {
        return $where['sql'];
    }

    /**
     * Set custom options for the query.
     * @param array $options
     * @return $this
     */
    public function options(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function __call($method, $parameters)
    {
        if ($method == 'unset') {
            return call_user_func_array([$this, 'drop'], $parameters);
        }

        return parent::__call($method, $parameters);
    }

    /**
     * Cast 
     */
    public function castDateValues(array|object $values): array|object
    {
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->castDateValues($value);
            } elseif ($value instanceof DateTimeInterface) {
                $value = new UTCDateTime($value);
            }
        }

        return $values;
    }
}
