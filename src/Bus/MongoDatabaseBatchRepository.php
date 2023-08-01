<?php

namespace Jenssegers\Mongodb\Bus;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Bus\{DatabaseBatchRepository, PendingBatch, UpdatedBatchJobCounts};
use Illuminate\Support\Str;
use MongoDB\Operation\FindOneAndUpdate;

class MongoDatabaseBatchRepository extends DatabaseBatchRepository
{
    protected function updateAtomicValues(string $batchId, Closure $callback)
    {
        return $this->connection->transaction(function () use ($batchId, $callback) {
            $batch = (object) $this->connection->table($this->table)->where('id', $batchId)
                        ->lockForUpdate()
                        ->first();

            return is_null($batch) ? [] : tap($callback($batch), function ($values) use ($batchId) {
                $this->connection->table($this->table)->where('id', $batchId)->update($values);
            });
        });
    }

    public function decrementPendingJobs(string $batchId, string $jobId)
    {
        $values = $this->connection->table($this->table)->raw(function($collection) use ($batchId, $jobId) {
            return $collection->findOneAndUpdate(
                ['id' => $batchId],
                ['$inc' => ['pending_jobs' => -1]],
                ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
        });

        return new UpdatedBatchJobCounts(
            $values['pending_jobs'],
            $values['failed_jobs']
        );
    }

    /**
     * Increment the total number of failed jobs for the batch.
     *
     * @param  string  $batchId
     * @param  string  $jobId
     * @return \Illuminate\Bus\UpdatedBatchJobCounts
     */
    public function incrementFailedJobs(string $batchId, string $jobId)
    {
        $values = $this->connection->table($this->table)->raw(function($collection) use ($batchId, $jobId) {
            return $collection->findOneAndUpdate(
                ['id' => $batchId],
                [
                    '$push' => ['failed_job_ids' => $jobId],
                    '$inc' => ['failed_jobs' => 1]
                ],
                ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
        });
        
        return new UpdatedBatchJobCounts(
            $values['pending_jobs'],
            $values['failed_jobs']
        );
    }

    public function incrementTotalJobs(string $batchId, int $amount)
    {
        $this->connection->table($this->table)->where('id', $batchId)->update([
            '$set' => ['finished_at' => null],
            '$inc' => ['total_jobs' => $amount, 'pending_jobs' => $amount]
        ]);
    }

    public function cancel(string $batchId)
    {
        $this->connection->table($this->table)->where('id', $batchId)->update([
            '$set' => ['cancelled_at' => time(), 'finished_at' => time()]
        ]);
    }

    public function store(PendingBatch $batch)
    {
        $id = (string) Str::orderedUuid();

        $this->connection->table($this->table)->insert([
            'id' => $id,
            'name' => $batch->name,
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => [],
            'options' => $this->serialize($batch->options),
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ]);

        return $this->find($id);
    }

    protected function toBatch($batch)
    {
        $batch = (object) $batch;
        return $this->factory->make(
            $this,
            $batch->id,
            $batch->name,
            (int) $batch->total_jobs,
            (int) $batch->pending_jobs,
            (int) $batch->failed_jobs,
            (array) $batch->failed_job_ids,
            $this->unserialize($batch->options),
            CarbonImmutable::createFromTimestamp($batch->created_at),
            $batch->cancelled_at ? CarbonImmutable::createFromTimestamp($batch->cancelled_at) : $batch->cancelled_at,
            $batch->finished_at ? CarbonImmutable::createFromTimestamp($batch->finished_at) : $batch->finished_at
        );
    }
}
