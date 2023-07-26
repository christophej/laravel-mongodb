<?php

    namespace Jenssegers\Mongodb\Bus;

    use Closure;
    use Illuminate\Bus\DatabaseBatchRepository;

    class MongoDatabaseBatchRepository extends DatabaseBatchRepository {
        protected function updateAtomicValues(string $batchId, Closure $callback) {
            return $this->connection->transaction(function () use ($batchId, $callback) {
                $batch = (object)$this->connection->table($this->table)->where('id', $batchId)
                            ->lockForUpdate()
                            ->first();

                return is_null($batch) ? [] : tap($callback($batch), function ($values) use ($batchId) {
                    $this->connection->table($this->table)->where('id', $batchId)->update($values);
                });
            });
        }

        public function incrementTotalJobs(string $batchId, int $amount) {
            $this->connection->table($this->table)->where('id', $batchId)->update([
                '$set' => [
                    'finished_at' => null
                ],
                '$inc' => [
                    'total_jobs' => $amount,
                    'pending_jobs' => $amount
                ]
            ]);
        }

        protected function toBatch($batch) {
            return parent::toBatch((object)$batch);
        }
    }
